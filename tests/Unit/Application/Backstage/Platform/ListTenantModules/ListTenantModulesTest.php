<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\ListTenantModules;

use Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModules;
use Daems\Application\Backstage\Platform\ListTenantModules\ListTenantModulesInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListTenantModulesTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    /** @var list<string> */
    private array $tempDirs = [];

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->tenants       = new InMemoryTenantRepository();
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->users         = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_returns_combined_manifest_plus_state(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'core-mod', 'isCore' => true],
            ['name' => 'events',   'defaultAvailable' => true],
            ['name' => 'projects', 'defaultAvailable' => false],
        ]);
        $this->tempDirs[] = $root;
        // Seed events as enabled.
        $this->tenantModules->save(new TenantModule(
            id: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            availableAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            availableBy: UserId::fromString(self::GSA_ID),
            enabledAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
            enabledBy: UserId::fromString(self::GSA_ID),
            disabledAt: null,
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
        ));

        $resolver = new TenantModuleResolver($registry, $this->tenantModules);
        $uc = new ListTenantModules($this->tenants, $this->tenantModules, $resolver, $registry, $this->users);

        $output = $uc->execute(new ListTenantModulesInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));

        $bySlug = [];
        foreach ($output->modules as $row) {
            $bySlug[$row['slug']] = $row;
        }
        self::assertSame('core', $bySlug['core-mod']['state']);
        self::assertTrue($bySlug['core-mod']['isCore']);
        self::assertSame('enabled', $bySlug['events']['state']);
        self::assertNotNull($bySlug['events']['availableAt']);
        self::assertNotNull($bySlug['events']['enabledAt']);
        self::assertSame('disabled', $bySlug['projects']['state']);
        self::assertNull($bySlug['projects']['availableAt']);
    }

    public function test_rejects_non_platform_admin(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([]);
        $this->tempDirs[] = $root;
        $resolver = new TenantModuleResolver($registry, $this->tenantModules);
        $uc = new ListTenantModules($this->tenants, $this->tenantModules, $resolver, $registry, $this->users);

        $this->expectException(ForbiddenException::class);
        $uc->execute(new ListTenantModulesInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
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
