<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant;

use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant;
use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListTenantModulesForCurrentTenantTest extends TestCase
{
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000cc';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    /** @var list<string> */
    private array $tempDirs = [];

    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->userTenants   = new InMemoryUserTenantRepository();
        $this->users         = new InMemoryUserRepository();
        $this->seedUser(self::ADMIN_ID, isPlatformAdmin: false);
        $this->seedUser(self::GSA_ID, isPlatformAdmin: true);
        $this->seedUser(self::NORMAL_ID, isPlatformAdmin: false);
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

    public function test_returns_three_buckets_for_tenant_admin(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'core-mod',  'isCore' => true],
            ['name' => 'events',    'defaultAvailable' => true],   // available, not enabled
            ['name' => 'projects',  'defaultAvailable' => true],   // enabled
            ['name' => 'analytics', 'defaultAvailable' => false],  // disabled (no row)
        ]);
        $this->tempDirs[] = $root;

        $this->seedRow('events', enabled: false);
        $this->seedRow('projects', enabled: true);

        $resolver = new TenantModuleResolver($registry, $this->tenantModules);
        $uc = new ListTenantModulesForCurrentTenant(
            $resolver,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
            $registry,
        );

        $output = $uc->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: UserId::fromString(self::ADMIN_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));

        $enabledSlugs = array_map(fn ($e) => $e['slug'], $output->enabled);
        sort($enabledSlugs);
        self::assertSame(['core-mod', 'projects'], $enabledSlugs);

        self::assertCount(1, $output->availableNotEnabled);
        self::assertSame('events', $output->availableNotEnabled[0]['slug']);

        self::assertCount(1, $output->disabled);
        self::assertSame('analytics', $output->disabled[0]['slug']);
    }

    public function test_orphan_row_visible_only_to_platform_admin(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'events', 'defaultAvailable' => true],
        ]);
        $this->tempDirs[] = $root;

        // Insert an orphan tenant_modules row pointing at an unknown slug.
        $this->tenantModules->save(new TenantModule(
            id: '01958000-0000-7000-8000-000000000099',
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'ghost',
            availableAt: null,
            availableBy: null,
            enabledAt: null,
            enabledBy: null,
            disabledAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));

        $resolver = new TenantModuleResolver($registry, $this->tenantModules);
        $uc = new ListTenantModulesForCurrentTenant(
            $resolver,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
            $registry,
        );

        // Tenant admin: no orphan in disabled list.
        $tenantOut = $uc->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: UserId::fromString(self::ADMIN_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
        $disabledForTenant = array_map(fn ($e) => $e['slug'], $tenantOut->disabled);
        self::assertNotContains('ghost', $disabledForTenant);

        // Platform admin: orphan IS visible.
        $gsaOut = $uc->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
        $disabledForGsa = array_map(fn ($e) => $e['slug'], $gsaOut->disabled);
        self::assertContains('ghost', $disabledForGsa);
    }

    public function test_rejects_unauthorised_actor(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([]);
        $this->tempDirs[] = $root;
        $resolver = new TenantModuleResolver($registry, $this->tenantModules);
        $uc = new ListTenantModulesForCurrentTenant(
            $resolver,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
            $registry,
        );

        $this->expectException(ForbiddenException::class);
        $uc->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
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
            enabledBy: $enabledAt !== null ? UserId::fromString(self::ADMIN_ID) : null,
            disabledAt: null,
            createdAt: $availableAt,
            updatedAt: $enabledAt ?? $availableAt,
        ));
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
