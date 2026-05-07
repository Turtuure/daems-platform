<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\RevokeModuleAvailability;

use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability;
use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailabilityInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use Daems\Tests\Support\Fake\InMemoryModuleAuditRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\SnapshotTransactionManager;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RevokeModuleAvailabilityTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    /** @var list<string> */
    private array $tempDirs = [];

    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryModuleAuditRepository $audits;
    private InMemoryUserRepository $users;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->audits        = new InMemoryModuleAuditRepository();
        $this->users         = new InMemoryUserRepository();
        $this->clock         = FrozenClock::at('2026-05-07T10:00:00+00:00');
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_simple_revoke_no_dependents(): void
    {
        $uc = $this->makeUseCase([['name' => 'events']]);
        $this->seedRow('events', enabled: true);

        $uc->execute(new RevokeModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 'admin-decision',
        ));

        $row = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'events');
        self::assertNotNull($row);
        self::assertNull($row->availableAt());
        self::assertNotNull($row->disabledAt());

        // Root audit lives on the tenantModules fake's own audit list.
        self::assertCount(1, $this->tenantModules->audits);
        self::assertSame(ModuleAuditAction::REVOKED_AVAILABILITY, $this->tenantModules->audits[0]->action());
        // No cascade audits.
        self::assertCount(0, $this->audits->entries);
    }

    public function test_cascade_through_one_dependent(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
            ['name' => 'analytics', 'dependsOn' => ['events']],
        ]);
        $this->seedRow('events', enabled: true);
        $this->seedRow('analytics', enabled: true);

        $uc->execute(new RevokeModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 'breakage',
        ));

        $analytics = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'analytics');
        self::assertNotNull($analytics);
        self::assertNull($analytics->enabledAt());
        self::assertNotNull($analytics->disabledAt());

        // 1 cascade audit (analytics DISABLED) + 1 root audit (events REVOKED).
        self::assertCount(1, $this->audits->entries);
        self::assertSame(ModuleAuditAction::DISABLED, $this->audits->entries[0]->action());
        self::assertSame('analytics', $this->audits->entries[0]->moduleSlug());
        self::assertSame('cascade from events', $this->audits->entries[0]->reason());
        self::assertCount(1, $this->tenantModules->audits);
        self::assertSame(ModuleAuditAction::REVOKED_AVAILABILITY, $this->tenantModules->audits[0]->action());
    }

    public function test_cascade_through_chain_a_b_c(): void
    {
        // C depends on B, B depends on A. Revoking A should disable B and C.
        $uc = $this->makeUseCase([
            ['name' => 'a'],
            ['name' => 'b', 'dependsOn' => ['a']],
            ['name' => 'c', 'dependsOn' => ['b']],
        ]);
        $this->seedRow('a', enabled: true);
        $this->seedRow('b', enabled: true);
        $this->seedRow('c', enabled: true);

        $uc->execute(new RevokeModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'a',
            reason: 'kaboom',
        ));

        self::assertNull($this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'b')?->enabledAt());
        self::assertNull($this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'c')?->enabledAt());

        // Cascade audits: b + c disabled.
        $cascadedSlugs = array_map(fn ($e) => $e->moduleSlug(), $this->audits->entries);
        sort($cascadedSlugs);
        self::assertSame(['b', 'c'], $cascadedSlugs);
    }

    public function test_disabled_dependents_not_touched(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
            ['name' => 'analytics', 'dependsOn' => ['events']],
        ]);
        $this->seedRow('events', enabled: true);
        $this->seedRow('analytics', enabled: false); // available but not enabled

        $uc->execute(new RevokeModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 'cleanup',
        ));

        // Cascade only fires for ENABLED dependents — analytics was just available.
        self::assertCount(0, $this->audits->entries);
    }

    public function test_rejects_non_platform_admin(): void
    {
        $uc = $this->makeUseCase([['name' => 'events']]);
        $this->seedRow('events', enabled: true);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new RevokeModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 'r',
        ));
    }

    /**
     * Spec AC-8: cascade and root revoke must be in the same transaction.
     * If Phase 2 (root revokeAvailability) fails, Phase 1's dependent disables
     * must be rolled back so the system never sees a partial cascade.
     */
    public function test_phase2_failure_rolls_back_phase1_cascade(): void
    {
        // Use a TenantModules repo decorator that throws on the root revokeAvailability call.
        $failingRepo = new FailingRevokeTenantModulesRepository($this->tenantModules);

        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'events'],
            ['name' => 'analytics', 'dependsOn' => ['events']],
        ]);
        $this->tempDirs[] = $root;

        $this->seedRow('events', enabled: true);
        $this->seedRow('analytics', enabled: true);

        // Snapshot the underlying tenantModules + audits state so we can rollback on throw.
        $tx = new SnapshotTransactionManager([$this->tenantModules, $this->audits]);
        $uc = new RevokeModuleAvailability(
            $failingRepo,
            $this->audits,
            $this->users,
            $registry,
            $this->clock,
            $tx,
        );

        $threw = false;
        try {
            $uc->execute(new RevokeModuleAvailabilityInput(
                actingUserId: UserId::fromString(self::GSA_ID),
                tenantId: TenantId::fromString(self::TENANT_ID),
                moduleSlug: 'events',
                reason: 'kaboom',
            ));
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'expected phase2 failure to propagate');

        // Rollback assertions: analytics is still enabled, no cascade audit was persisted.
        $analytics = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'analytics');
        self::assertNotNull($analytics);
        self::assertNotNull($analytics->enabledAt(), 'analytics should still be enabled after rollback');
        self::assertNull($analytics->disabledAt(), 'analytics should not be disabled after rollback');
        self::assertCount(0, $this->audits->entries, 'cascade audit entries should be rolled back');
    }

    private function seedRow(string $slug, bool $enabled): void
    {
        $availableAt = new DateTimeImmutable('2026-04-01T00:00:00+00:00');
        $enabledAt = $enabled ? new DateTimeImmutable('2026-04-02T00:00:00+00:00') : null;
        $this->tenantModules->save(new TenantModule(
            id: '01958000-0000-7000-8000-' . substr(md5($slug), 0, 12),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: $slug,
            availableAt: $availableAt,
            availableBy: UserId::fromString(self::GSA_ID),
            enabledAt: $enabledAt,
            enabledBy: $enabledAt !== null ? UserId::fromString(self::GSA_ID) : null,
            disabledAt: null,
            createdAt: $availableAt,
            updatedAt: $enabledAt ?? $availableAt,
        ));
    }

    /**
     * @param list<array{name: string, isCore?: bool, defaultAvailable?: bool, dependsOn?: list<string>}> $specs
     */
    private function makeUseCase(array $specs): RevokeModuleAvailability
    {
        [$registry, $root] = ModuleRegistryFactory::build($specs);
        $this->tempDirs[] = $root;
        return new RevokeModuleAvailability(
            $this->tenantModules,
            $this->audits,
            $this->users,
            $registry,
            $this->clock,
            new ImmediateTransactionManager(),
        );
    }

    private function seedUser(string $id, bool $isPlatformAdmin): void
    {
        $user = new User(
            id: UserId::fromString($id),
            name: 'Test',
            email: 'u-' . substr($id, -4) . '@test.local',
            passwordHash: 'hash',
            dateOfBirth: null,
            country: 'FI',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $this->users->save($user);
    }
}

/**
 * Test decorator that delegates everything except revokeAvailability(),
 * which throws — used to simulate a Phase 2 failure mid-cascade so the
 * use case's transactional wrapper has something to rollback.
 */
final class FailingRevokeTenantModulesRepository implements TenantModulesRepositoryInterface
{
    public function __construct(private readonly TenantModulesRepositoryInterface $inner) {}

    public function findByTenant(TenantId $tenantId): array
    {
        return $this->inner->findByTenant($tenantId);
    }

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
    {
        return $this->inner->find($tenantId, $moduleSlug);
    }

    public function save(TenantModule $tm): void
    {
        $this->inner->save($tm);
    }

    public function revokeAvailability(
        TenantModule $tm,
        \DateTimeImmutable $now,
        array $auditEntries,
    ): void {
        throw new \RuntimeException('simulated phase2 failure');
    }

    public function findEnabledByTenant(TenantId $tenantId): array
    {
        return $this->inner->findEnabledByTenant($tenantId);
    }
}
