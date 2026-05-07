<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\RevokeModuleAvailability;

use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailability;
use Daems\Application\Backstage\Platform\RevokeModuleAvailability\RevokeModuleAvailabilityInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryModuleAuditRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
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
