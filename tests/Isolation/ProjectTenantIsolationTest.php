<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\Project\ProjectId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ProjectTenantIsolationTest extends IsolationTestCase
{
    private SqlProjectRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlProjectRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedProject(string $tenantSlug, string $slug, string $title): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO projects (id, tenant_id, slug, title, category, icon, summary, description, status, sort_order)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'cat', 'icon', 'sum', 'desc', 'active', 0)"
        );
        $stmt->execute([ProjectId::generate()->value(), $tenantSlug, $slug, $title]);
    }

    public function test_admin_of_daems_cannot_see_sahegroup_projects(): void
    {
        $this->seedProject(tenantSlug: 'sahegroup', slug: 'secret', title: 'Top Secret');
        $this->seedProject(tenantSlug: 'daems', slug: 'public', title: 'Public');

        $daemsAdmin = $this->makeActingUser(
            tenantSlug: 'daems',
            role: UserTenantRole::Admin,
            isPlatformAdmin: false,
        );

        $results = $this->repo->listForTenant($daemsAdmin->activeTenant);

        $titles = array_map(static fn($p): string => $p->title(), $results);
        self::assertContains('Public', $titles);
        self::assertNotContains('Top Secret', $titles);
    }

    public function test_repository_has_no_legacy_non_scoped_methods(): void
    {
        self::assertFalse(method_exists(SqlProjectRepository::class, 'findAll'), 'legacy findAll() must be removed');
        self::assertFalse(method_exists(SqlProjectRepository::class, 'findBySlug'), 'legacy findBySlug() must be removed');
        self::assertTrue(method_exists(SqlProjectRepository::class, 'listForTenant'));
        self::assertTrue(method_exists(SqlProjectRepository::class, 'findBySlugForTenant'));
    }

    public function test_find_by_slug_requires_matching_tenant(): void
    {
        $this->seedProject(tenantSlug: 'sahegroup', slug: 'secret', title: 'Top Secret');

        $daemsTenant = $this->tenantId('daems');
        $saheTenant = $this->tenantId('sahegroup');

        self::assertNull($this->repo->findBySlugForTenant('secret', $daemsTenant));
        self::assertNotNull($this->repo->findBySlugForTenant('secret', $saheTenant));
    }
}
