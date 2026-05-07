<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\GrantModuleAvailability;

use Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailability;
use Daems\Application\Backstage\Platform\GrantModuleAvailability\GrantModuleAvailabilityInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\ModuleDependencyUnmetException;
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
use DomainException;
use PHPUnit\Framework\TestCase;

final class GrantModuleAvailabilityTest extends TestCase
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

    public function test_grants_availability_writes_row_and_audit(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
        ]);

        $uc->execute(new GrantModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 'rollout',
        ));

        $row = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'events');
        self::assertNotNull($row);
        self::assertNotNull($row->availableAt());
        self::assertNull($row->enabledAt());
        self::assertCount(1, $this->audits->entries);
        self::assertSame(ModuleAuditAction::MADE_AVAILABLE, $this->audits->entries[0]->action());
        self::assertSame('platform_admin', $this->audits->entries[0]->actorRole());
        self::assertSame('rollout', $this->audits->entries[0]->reason());
    }

    public function test_idempotent_re_grant_writes_audit_only(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
        ]);

        // Pre-seed an already-available row.
        $this->tenantModules->save(new TenantModule(
            id: '01958000-0000-7000-8000-000000000099',
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            availableAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            availableBy: UserId::fromString(self::GSA_ID),
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));

        $uc->execute(new GrantModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: 're-confirm',
        ));

        $row = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'events');
        self::assertNotNull($row);
        // Original available_at preserved (idempotent — no overwrite).
        self::assertSame('2026-04-01', $row->availableAt()?->format('Y-m-d'));
        self::assertCount(1, $this->audits->entries);
    }

    public function test_dependency_unmet_rejected(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
            ['name' => 'analytics', 'dependsOn' => ['events']],
        ]);

        $this->expectException(ModuleDependencyUnmetException::class);
        $uc->execute(new GrantModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'analytics',
            reason: null,
        ));
    }

    public function test_unknown_module_rejected(): void
    {
        $uc = $this->makeUseCase([]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/not in this deployment/i');
        $uc->execute(new GrantModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'ghost',
            reason: null,
        ));
    }

    public function test_rejects_non_platform_admin(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
        ]);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new GrantModuleAvailabilityInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            reason: null,
        ));
    }

    /**
     * @param list<array{name: string, isCore?: bool, defaultAvailable?: bool, dependsOn?: list<string>}> $specs
     */
    private function makeUseCase(array $specs): GrantModuleAvailability
    {
        [$registry, $root] = ModuleRegistryFactory::build($specs);
        $this->tempDirs[] = $root;
        return new GrantModuleAvailability(
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
