<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenant;
use Daems\Application\Backstage\Tenant\EnableModuleForTenant\EnableModuleForTenantInput;
use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenant;
use Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant\ListTenantModulesForCurrentTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlModuleAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantModulesRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Cross-tenant isolation for the tenant_modules read/write surface.
 *
 * Verifies that:
 *   - Tenant A's tenant_modules rows DO NOT leak into Tenant B's
 *     ListTenantModulesForCurrentTenant response. A leaky implementation
 *     (e.g. forgetting tenant_id in the WHERE clause) fails this test.
 *   - A GSA user (is_platform_admin = true) can read every tenant's
 *     module list across tenant boundaries.
 *   - Tenant B's admin attempting to enable a module FOR Tenant A through
 *     the use case receives ForbiddenException — the use case verifies
 *     the actor's role in the TARGET tenant, not their own home tenant.
 *
 * STATUS: written; awaits MySQL for live verification (extends
 * IsolationTestCase → MigrationTestCase, which requires a running
 * `daems_db_test` on 127.0.0.1:3306). PHPStan-clean against level 9.
 */
final class TenantModulesIsolationTest extends IsolationTestCase
{
    private Connection $conn;
    private SqlTenantModulesRepository $tenantModules;
    private SqlModuleAuditRepository $audits;
    private SqlUserTenantRepository $userTenants;
    private SqlUserRepository $users;
    private SqlTenantRepository $tenants;
    private ModuleRegistry $registry;
    private TenantModuleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $pdo = $this->conn->pdo();
        $this->tenantModules = new SqlTenantModulesRepository($pdo);
        $this->audits        = new SqlModuleAuditRepository($pdo);
        $this->userTenants   = new SqlUserTenantRepository($pdo);
        $this->users         = new SqlUserRepository($this->conn);
        $this->tenants       = new SqlTenantRepository($pdo);

        // Real platform registry — same one production wires.
        $this->registry = new ModuleRegistry();
        $this->registry->discover(
            __DIR__ . '/../../../modules',
            __DIR__ . '/../../config/modules.php',
        );

        $this->resolver = new TenantModuleResolver($this->registry, $this->tenantModules);
    }

    /** Seed an available + (optionally enabled) tenant_modules row directly. */
    private function seedRow(string $tenantSlug, string $moduleSlug, bool $enabled): void
    {
        $tenantId = $this->tenantId($tenantSlug);
        $now = new \DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $row = new TenantModule(
            id: TenantId::generate()->value(),
            tenantId: $tenantId,
            moduleSlug: $moduleSlug,
            availableAt: $now,
            availableBy: null,
            enabledAt: $enabled ? $now : null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->tenantModules->save($row);
    }

    /**
     * Wipe migration 072's seed rows so each test starts from a known empty
     * tenant_modules table. Without this, every tenant has all 5 modules
     * available+enabled by default and per-test seedRow() additions are
     * indistinguishable in cross-tenant assertions.
     */
    private function clearAllTenantModules(): void
    {
        $this->conn->pdo()->exec('DELETE FROM tenant_modules');
    }

    private function makeListUseCase(): ListTenantModulesForCurrentTenant
    {
        return new ListTenantModulesForCurrentTenant(
            $this->resolver,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
            $this->registry,
        );
    }

    private function makeEnableUseCase(): EnableModuleForTenant
    {
        return new EnableModuleForTenant(
            $this->tenants,
            $this->tenantModules,
            $this->audits,
            $this->userTenants,
            $this->users,
            $this->registry,
            new SystemClock(),
        );
    }

    public function test_tenant_a_modules_do_not_leak_into_tenant_b_list(): void
    {
        // Wipe migration 072's default seeds so the assertions below test only
        // the rows we explicitly insert.
        $this->clearAllTenantModules();

        // Asymmetric seeds — daems gets `forum` enabled, sahegroup gets `events`
        // enabled. A leaky impl (e.g. dropping tenant_id from the WHERE) would
        // surface forum in sahegroup's enabled bucket.
        $this->seedRow('daems', 'forum', enabled: true);
        $this->seedRow('sahegroup', 'events', enabled: true);

        // sahegroup admin queries via the use case.
        $saheAdmin = $this->makeActingUser(
            tenantSlug: 'sahegroup',
            role: UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-1501a7e5a4e0',
            email: 'sahe-admin@isolation.test',
        );

        $out = $this->makeListUseCase()->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: $saheAdmin->id,
            tenantId:     $this->tenantId('sahegroup'),
        ));

        $enabledSlugs = array_column($out->enabled, 'slug');
        self::assertContains('events', $enabledSlugs, 'sahegroup admin should see events enabled');
        self::assertNotContains('forum', $enabledSlugs, 'sahegroup admin must NOT see daems forum');
    }

    public function test_gsa_can_read_every_tenant_module_list_across_boundaries(): void
    {
        $this->clearAllTenantModules();
        $this->seedRow('daems', 'forum', enabled: true);
        $this->seedRow('sahegroup', 'events', enabled: true);

        $gsa = $this->makeActingUser(
            tenantSlug:      'daems',  // role is irrelevant — is_platform_admin grants global
            role:            null,
            isPlatformAdmin: true,
            userId:          '01958000-0000-7000-8000-1501a7e95aaa',
            email:           'gsa@isolation.test',
        );

        // GSA reads daems list.
        $daemsOut = $this->makeListUseCase()->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: $gsa->id,
            tenantId:     $this->tenantId('daems'),
        ));
        $daemsEnabled = array_column($daemsOut->enabled, 'slug');
        self::assertContains('forum', $daemsEnabled);

        // SAME GSA reads sahegroup list — no error, sees its modules.
        $saheOut = $this->makeListUseCase()->execute(new ListTenantModulesForCurrentTenantInput(
            actingUserId: $gsa->id,
            tenantId:     $this->tenantId('sahegroup'),
        ));
        $saheEnabled = array_column($saheOut->enabled, 'slug');
        self::assertContains('events', $saheEnabled);
        self::assertNotContains('forum', $saheEnabled, 'sahegroup view still scoped to its own modules');
    }

    public function test_tenant_b_admin_cannot_toggle_tenant_a_modules(): void
    {
        $this->clearAllTenantModules();
        // Seed an available-but-not-enabled row in daems so there's something
        // a daems admin could legitimately enable.
        $this->seedRow('daems', 'forum', enabled: false);

        // Sahegroup admin (NOT daems admin) tries to enable daems' forum.
        $saheAdmin = $this->makeActingUser(
            tenantSlug: 'sahegroup',
            role:       UserTenantRole::Admin,
            userId:     '01958000-0000-7000-8000-1501a7e5a4e2',
            email:      'sahe-admin2@isolation.test',
        );

        $this->expectException(ForbiddenException::class);

        $this->makeEnableUseCase()->execute(new EnableModuleForTenantInput(
            actingUserId: $saheAdmin->id,
            tenantId:     $this->tenantId('daems'), // crossing tenants
            moduleSlug:   'forum',
        ));
    }
}
