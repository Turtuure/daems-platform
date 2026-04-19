<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Insight\InsightId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class InsightTenantIsolationTest extends IsolationTestCase
{
    private SqlInsightRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlInsightRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedInsight(string $tenantSlug, string $slug, string $title): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, content)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'c', 'C', 0, '2026-01-01', 'a', 1, 'x', '<p>y</p>')"
        );
        $stmt->execute([InsightId::generate()->value(), $tenantSlug, $slug, $title]);
    }

    public function test_list_isolates_by_tenant(): void
    {
        $this->seedInsight('sahegroup', 'sg-i', 'SG Insight');
        $this->seedInsight('daems', 'd-i', 'D Insight');

        $results = $this->repo->listForTenant($this->tenantId('daems'));

        $titles = array_map(static fn($i): string => $i->title(), $results);
        self::assertContains('D Insight', $titles);
        self::assertNotContains('SG Insight', $titles);
    }

    public function test_repository_has_no_legacy_non_scoped_methods(): void
    {
        self::assertFalse(method_exists(SqlInsightRepository::class, 'findAll'));
        self::assertFalse(method_exists(SqlInsightRepository::class, 'findBySlug'));
        self::assertTrue(method_exists(SqlInsightRepository::class, 'listForTenant'));
        self::assertTrue(method_exists(SqlInsightRepository::class, 'findBySlugForTenant'));
    }

    public function test_find_by_slug_requires_matching_tenant(): void
    {
        $this->seedInsight('sahegroup', 'shared', 'SG');

        self::assertNull($this->repo->findBySlugForTenant('shared', $this->tenantId('daems')));
        self::assertNotNull($this->repo->findBySlugForTenant('shared', $this->tenantId('sahegroup')));
    }
}
