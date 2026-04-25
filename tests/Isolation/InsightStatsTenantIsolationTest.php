<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class InsightStatsTenantIsolationTest extends IsolationTestCase
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

    private function seedInsight(string $tenantSlug, string $slug, string $publishedDate, bool $featured): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, content)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'c', 'C', ?, ?, 'a', 1, 'x', '<p>y</p>')"
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

    public function test_stats_isolate_published_count_by_tenant(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->seedInsight('sahegroup', 'sg-p', $yesterday, false);
        $this->seedInsight('daems',     'd-p',  $yesterday, false);

        $daems = $this->repo->statsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->statsForTenant($this->tenantId('sahegroup'));

        self::assertSame(1, $daems['published']['value']);
        self::assertSame(1, $sahe['published']['value']);
    }

    public function test_stats_isolate_featured_count_by_tenant(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->seedInsight('sahegroup', 'sg-f', $yesterday, true);
        $this->seedInsight('daems',     'd-p',  $yesterday, false);

        $daems = $this->repo->statsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->statsForTenant($this->tenantId('sahegroup'));

        self::assertSame(0, $daems['featured']['value']);
        self::assertSame(1, $sahe['featured']['value']);
    }

    public function test_stats_isolate_scheduled_count_by_tenant(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('sahegroup', 'sg-s', $tomorrow, false);
        $this->seedInsight('daems',     'd-s',  $tomorrow, false);

        $daems = $this->repo->statsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->statsForTenant($this->tenantId('sahegroup'));

        self::assertSame(1, $daems['scheduled']['value']);
        self::assertSame(1, $sahe['scheduled']['value']);

        // The sahegroup row must NOT appear in daems sparkline
        $tomorrowEntry = array_values(array_filter(
            $daems['scheduled']['sparkline'],
            static fn(array $e): bool => $e['date'] === $tomorrow,
        ))[0];
        self::assertSame(1, $tomorrowEntry['value']);
    }
}
