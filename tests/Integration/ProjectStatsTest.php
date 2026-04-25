<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ProjectStatsTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlProjectRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->repo = new SqlProjectRepository($this->conn);
    }

    public function test_project_stats_returns_active_drafts_featured_with_sparklines(): void
    {
        $tenantId = $this->tenantId('daems');

        // 3 active rows (today, -3d, -29d). Two of them set featured=1.
        $a1 = $this->insertProject($tenantId, 'active', createdDaysAgo: 0,  featured: true);
        $a2 = $this->insertProject($tenantId, 'active', createdDaysAgo: 3,  featured: false);
        $a3 = $this->insertProject($tenantId, 'active', createdDaysAgo: 29, featured: false);

        // 1 featured but with -5d created_at (and active) — adds the 2nd featured.
        $f2 = $this->insertProject($tenantId, 'active', createdDaysAgo: 5, featured: true);

        // Wait — re-reading the brief: 3 active total, 2 of which are featured. Drop $f2.
        $this->pdo()->prepare('DELETE FROM projects WHERE id = ?')->execute([$f2]);

        // Make $a2 featured so we have exactly 2 featured among the 3 active rows.
        $this->pdo()->prepare('UPDATE projects SET featured = 1 WHERE id = ?')->execute([$a2]);

        // 1 draft (today)
        $this->insertProject($tenantId, 'draft', createdDaysAgo: 0);

        // 1 archived — must not count anywhere
        $this->insertProject($tenantId, 'archived', createdDaysAgo: 0);

        $stats = $this->repo->statsForTenant($tenantId);

        // Top-level KPI counts.
        self::assertSame(3, $stats['active']['value']);
        self::assertSame(1, $stats['drafts']['value']);
        self::assertSame(2, $stats['featured']['value']);

        // active.sparkline: BACKWARD 30 days.
        self::assertCount(30, $stats['active']['sparkline']);
        self::assertSame(['date', 'value'], array_keys($stats['active']['sparkline'][0]));

        $today = new \DateTimeImmutable('today');
        self::assertSame($today->modify('-29 days')->format('Y-m-d'), $stats['active']['sparkline'][0]['date']);
        self::assertSame($today->format('Y-m-d'),                      $stats['active']['sparkline'][29]['date']);

        // Today bucket (index 29) = 1 active (the today row).
        self::assertSame(1, $stats['active']['sparkline'][29]['value']);
        // -3d bucket (index 26) = 1 active.
        self::assertSame(1, $stats['active']['sparkline'][26]['value']);
        // -29d bucket (index 0) = 1 active.
        self::assertSame(1, $stats['active']['sparkline'][0]['value']);
        // -1d bucket (index 28): zero.
        self::assertSame(0, $stats['active']['sparkline'][28]['value']);

        // drafts.sparkline: BACKWARD 30 days, 1 draft today.
        self::assertCount(30, $stats['drafts']['sparkline']);
        self::assertSame(1, $stats['drafts']['sparkline'][29]['value']);
        self::assertSame(0, $stats['drafts']['sparkline'][26]['value']);

        // featured.sparkline: empty list (curation toggle has no temporal series).
        self::assertSame([], $stats['featured']['sparkline']);
    }

    public function test_archived_excluded_from_all_kpis(): void
    {
        $tenantId = $this->tenantId('daems');

        // Only an archived project. Featured flag intentionally true to confirm
        // featured KPI requires status='active'.
        $this->insertProject($tenantId, 'archived', createdDaysAgo: 0, featured: true);

        $stats = $this->repo->statsForTenant($tenantId);

        self::assertSame(0, $stats['active']['value']);
        self::assertSame(0, $stats['drafts']['value']);
        self::assertSame(0, $stats['featured']['value']);
    }

    public function test_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // daems: 1 active, 1 draft.
        $this->insertProject($daems, 'active', createdDaysAgo: 0);
        $this->insertProject($daems, 'draft',  createdDaysAgo: 0);

        // sahegroup: 2 active (1 featured), 0 drafts.
        $sahe1 = $this->insertProject($sahe, 'active', createdDaysAgo: 0);
        $this->insertProject($sahe, 'active', createdDaysAgo: 1);
        $this->pdo()->prepare('UPDATE projects SET featured = 1 WHERE id = ?')->execute([$sahe1]);

        $daemsStats = $this->repo->statsForTenant($daems);
        $saheStats  = $this->repo->statsForTenant($sahe);

        self::assertSame(1, $daemsStats['active']['value']);
        self::assertSame(1, $daemsStats['drafts']['value']);
        self::assertSame(0, $daemsStats['featured']['value']);

        self::assertSame(2, $saheStats['active']['value']);
        self::assertSame(0, $saheStats['drafts']['value']);
        self::assertSame(1, $saheStats['featured']['value']);
    }

    private function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return TenantId::fromString((string) $row['id']);
    }

    /**
     * Insert a row into projects with controllable created_at and featured flag.
     * Also inserts a minimal projects_i18n companion (fi_FI) so admin reads stay sane —
     * stats does not touch projects_i18n but parity with EventStatsTest.
     */
    private function insertProject(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo = 0,
        bool $featured = false,
    ): string {
        $id        = Uuid7::generate()->value();
        $slug      = 'project-' . substr(str_replace('-', '', $id), 0, 24);
        $createdAt = (new \DateTimeImmutable('today'))
            ->modify("-{$createdDaysAgo} days")
            ->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO projects
                (id, tenant_id, slug, category, icon, status, sort_order, featured, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            $slug,
            'community',
            'bi-folder',
            $status,
            0,
            $featured ? 1 : 0,
            $createdAt,
        ]);

        $this->pdo()->prepare(
            'INSERT INTO projects_i18n (project_id, locale, title, summary, description)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $id,
            'fi_FI',
            'Project ' . substr($id, 0, 8),
            '',
            '',
        ]);

        return $id;
    }
}
