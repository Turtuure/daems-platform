<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Forum\ForumCategoryId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ForumTenantIsolationTest extends IsolationTestCase
{
    private SqlForumRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlForumRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedCategory(string $tenantSlug, string $slug, string $name): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'chat', 'desc', 1)"
        );
        $stmt->execute([ForumCategoryId::generate()->value(), $tenantSlug, $slug, $name]);
    }

    public function test_list_isolates_categories_by_tenant(): void
    {
        $this->seedCategory('sahegroup', 'sg-cat', 'SG Category');
        $this->seedCategory('daems', 'd-cat', 'D Category');

        $results = $this->repo->findAllCategoriesForTenant($this->tenantId('daems'));

        $names = array_map(static fn($c): string => $c->name(), $results);
        self::assertContains('D Category', $names);
        self::assertNotContains('SG Category', $names);
    }

    public function test_repository_has_no_legacy_non_scoped_methods(): void
    {
        self::assertFalse(method_exists(SqlForumRepository::class, 'findAllCategories'));
        self::assertFalse(method_exists(SqlForumRepository::class, 'findCategoryBySlug'));
        self::assertFalse(method_exists(SqlForumRepository::class, 'findTopicBySlug'));
        self::assertTrue(method_exists(SqlForumRepository::class, 'findAllCategoriesForTenant'));
        self::assertTrue(method_exists(SqlForumRepository::class, 'findCategoryBySlugForTenant'));
        self::assertTrue(method_exists(SqlForumRepository::class, 'findTopicBySlugForTenant'));
    }

    public function test_find_category_requires_matching_tenant(): void
    {
        $this->seedCategory('sahegroup', 'shared', 'SG');

        self::assertNull($this->repo->findCategoryBySlugForTenant('shared', $this->tenantId('daems')));
        self::assertNotNull($this->repo->findCategoryBySlugForTenant('shared', $this->tenantId('sahegroup')));
    }
}
