<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlInsightRepositoryStatsTest extends MigrationTestCase
{
    private SqlInsightRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);

        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->repo = new SqlInsightRepository($conn);
    }

    private function seedInsight(string $tenantSlug, string $slug, string $publishedDate, bool $featured = false): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, tags_json, content, created_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'c', 'C', ?, ?, 'a', 1, 'x', '[]', '<p>y</p>', NOW())"
        );
        $stmt->execute([
            Uuid7::generate()->value(),
            $tenantSlug,
            $slug,
            'T-' . $slug,
            $featured ? 1 : 0,
            $publishedDate,
        ]);
    }

    private function tenantId(string $slug): \Daems\Domain\Tenant\TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return \Daems\Domain\Tenant\TenantId::fromString($row['id']);
    }

    public function test_published_count_includes_today_and_past_only(): void
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('daems', 'p1', $yesterday);
        $this->seedInsight('daems', 'p2', $today);
        $this->seedInsight('daems', 's1', $tomorrow);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(2, $stats['published']['value']);
        self::assertSame(1, $stats['scheduled']['value']);
    }

    public function test_featured_only_counts_already_published_featured(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('daems', 'f1', $yesterday, true);
        $this->seedInsight('daems', 'f2', $tomorrow,  true);  // future-dated featured does NOT count
        $this->seedInsight('daems', 'np', $yesterday, false);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(1, $stats['featured']['value']);
    }

    public function test_published_sparkline_has_exactly_30_entries_zero_filled(): void
    {
        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertCount(30, $stats['published']['sparkline']);
        self::assertCount(30, $stats['scheduled']['sparkline']);
        self::assertCount(30, $stats['featured']['sparkline']);

        // First entry = 29 days ago, last entry = today
        $expectedFirst = date('Y-m-d', strtotime('-29 days'));
        $expectedLast  = date('Y-m-d');
        self::assertSame($expectedFirst, $stats['published']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['published']['sparkline'][29]['date']);
    }

    public function test_scheduled_sparkline_starts_tomorrow(): void
    {
        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        $expectedFirst = date('Y-m-d', strtotime('+1 day'));
        $expectedLast  = date('Y-m-d', strtotime('+30 days'));
        self::assertSame($expectedFirst, $stats['scheduled']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['scheduled']['sparkline'][29]['date']);
    }

    public function test_published_sparkline_records_correct_day(): void
    {
        $threeDaysAgo = date('Y-m-d', strtotime('-3 days'));
        $this->seedInsight('daems', 'd', $threeDaysAgo);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));
        // Index 26 = 29 - 3 (first entry is 29 days ago, index 0)
        self::assertSame($threeDaysAgo, $stats['published']['sparkline'][26]['date']);
        self::assertSame(1,             $stats['published']['sparkline'][26]['value']);
    }

    public function test_other_tenant_rows_do_not_leak(): void
    {
        $today = date('Y-m-d');
        $this->seedInsight('sahegroup', 'sg', $today);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(0, $stats['published']['value']);
    }
}
