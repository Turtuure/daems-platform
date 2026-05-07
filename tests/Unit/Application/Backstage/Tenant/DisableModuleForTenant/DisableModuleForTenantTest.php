<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Tenant\DisableModuleForTenant;

use Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenant;
use Daems\Application\Backstage\Tenant\DisableModuleForTenant\DisableModuleForTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\ModuleDependentEnabledException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryModuleAuditRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DisableModuleForTenantTest extends TestCase
{
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    /** @var list<string> */
    private array $tempDirs = [];

    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryModuleAuditRepository $audits;
    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->audits        = new InMemoryModuleAuditRepository();
        $this->userTenants   = new InMemoryUserTenantRepository();
        $this->users         = new InMemoryUserRepository();
        $this->clock         = FrozenClock::at('2026-05-07T10:00:00+00:00');
        $this->seedUser(self::ADMIN_ID, false);
        $this->seedUser(self::NORMAL_ID, false);
        $this->userTenants->attach(
            UserId::fromString(self::ADMIN_ID),
            TenantId::fromString(self::TENANT_ID),
            UserTenantRole::Admin,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_tenant_admin_disables_an_enabled_module(): void
    {
        $uc = $this->makeUseCase([['name' => 'events']]);
        $this->seedRow('events', enabled: true);

        $uc->execute(new DisableModuleForTenantInput(
            actingUserId: UserId::fromString(self::ADMIN_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
        ));
        $row = $this->tenantModules->find(TenantId::fromString(self::TENANT_ID), 'events');
        self::assertNotNull($row);
        self::assertNull($row->enabledAt());
        self::assertCount(1, $this->audits->entries);
    }

    public function test_dependent_enabled_blocks_disable(): void
    {
        $uc = $this->makeUseCase([
            ['name' => 'events'],
            ['name' => 'analytics', 'dependsOn' => ['events']],
        ]);
        $this->seedRow('events', enabled: true);
        $this->seedRow('analytics', enabled: true);

        $this->expectException(ModuleDependentEnabledException::class);
        $uc->execute(new DisableModuleForTenantInput(
            actingUserId: UserId::fromString(self::ADMIN_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
        ));
    }

    public function test_disable_already_disabled_is_noop(): void
    {
        $uc = $this->makeUseCase([['name' => 'events']]);
        $this->seedRow('events', enabled: false);

        $uc->execute(new DisableModuleForTenantInput(
            actingUserId: UserId::fromString(self::ADMIN_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
        ));
        self::assertCount(0, $this->audits->entries);
    }

    public function test_rejects_non_admin(): void
    {
        $uc = $this->makeUseCase([['name' => 'events']]);
        $this->seedRow('events', enabled: true);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new DisableModuleForTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
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
            availableBy: UserId::fromString(self::ADMIN_ID),
            enabledAt: $enabledAt,
            enabledBy: $enabledAt !== null ? UserId::fromString(self::ADMIN_ID) : null,
            disabledAt: null,
            createdAt: $availableAt,
            updatedAt: $enabledAt ?? $availableAt,
        ));
    }

    /**
     * @param list<array{name: string, isCore?: bool, defaultAvailable?: bool, dependsOn?: list<string>}> $specs
     */
    private function makeUseCase(array $specs): DisableModuleForTenant
    {
        [$registry, $root] = ModuleRegistryFactory::build($specs);
        $this->tempDirs[] = $root;
        return new DisableModuleForTenant(
            $this->tenantModules,
            $this->audits,
            $this->userTenants,
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
