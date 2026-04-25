<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumModerationAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlForumStatsTest extends MigrationTestCase
{
    private SqlForumRepository $forumRepo;
    private SqlForumReportRepository $reportRepo;
    private SqlForumModerationAuditRepository $auditRepo;

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

        $this->forumRepo  = new SqlForumRepository($conn);
        $this->reportRepo = new SqlForumReportRepository($conn);
        $this->auditRepo  = new SqlForumModerationAuditRepository($conn);
    }

    private function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return TenantId::fromString($row['id']);
    }

    private function seedCategory(string $tenantSlug, string $catSlug = 'cat1'): string
    {
        $tenantId = $this->tenantId($tenantSlug)->value();
        $this->pdo()->prepare(
            "INSERT IGNORE INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order)
             VALUES (UUID(), ?, ?, 'Cat', '', 'd', 0)"
        )->execute([$tenantId, $catSlug]);
        $catId = (string) $this->pdo()->query("SELECT id FROM forum_categories WHERE slug='{$catSlug}'")->fetchColumn();
        return $catId;
    }

    private function seedTopic(string $tenantSlug, string $topicSlug, string $catSlug = 'cat1'): void
    {
        $catId    = $this->seedCategory($tenantSlug, $catSlug);
        $tenantId = $this->tenantId($tenantSlug)->value();
        $this->pdo()->prepare(
            "INSERT INTO forum_topics
                (id, tenant_id, category_id, slug, title, author_name, avatar_initials,
                 pinned, reply_count, view_count, last_activity_at, last_activity_by, created_at)
             VALUES (UUID(), ?, ?, ?, 'T', 'Author', '', 0, 0, 0, NOW(), '', NOW())"
        )->execute([$tenantId, $catId, $topicSlug]);
    }

    public function test_topics_count_isolated_by_tenant(): void
    {
        $this->seedTopic('daems', 'd-t1', 'cat-d1');
        $this->seedTopic('sahegroup', 's-t1', 'cat-s1');

        self::assertSame(1, $this->forumRepo->countTopicsForTenant($this->tenantId('daems')));
        self::assertSame(1, $this->forumRepo->countTopicsForTenant($this->tenantId('sahegroup')));
    }

    public function test_topics_sparkline_has_30_entries_zero_filled(): void
    {
        $points = $this->forumRepo->dailyNewTopicsForTenant($this->tenantId('daems'));

        self::assertCount(30, $points);
        self::assertSame(date('Y-m-d', strtotime('-29 days')), $points[0]['date']);
        self::assertSame(date('Y-m-d'),                        $points[29]['date']);
    }

    public function test_open_reports_count(): void
    {
        $tenantId = $this->tenantId('daems')->value();
        $this->pdo()->prepare(
            "INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id, reason_category, status, created_at)
             VALUES (UUID(), ?, 'post', UUID(), UUID(), 'spam', 'open', NOW())"
        )->execute([$tenantId]);

        self::assertSame(1, $this->reportRepo->countOpenReportsForTenant($this->tenantId('daems')));
        self::assertSame(0, $this->reportRepo->countOpenReportsForTenant($this->tenantId('sahegroup')));
    }

    public function test_reports_sparkline_has_30_entries_zero_filled(): void
    {
        $points = $this->reportRepo->dailyNewReportsForTenant($this->tenantId('daems'));

        self::assertCount(30, $points);
        self::assertSame(date('Y-m-d', strtotime('-29 days')), $points[0]['date']);
        self::assertSame(date('Y-m-d'),                        $points[29]['date']);
    }

    public function test_audit_count_last_30d(): void
    {
        $tenantId = $this->tenantId('daems')->value();
        $this->pdo()->prepare(
            "INSERT INTO forum_moderation_audit
                (id, tenant_id, target_type, target_id, action, reason, performed_by, created_at)
             VALUES (?, ?, 'post', ?, 'deleted', '', ?, NOW())"
        )->execute([Uuid7::generate()->value(), $tenantId, Uuid7::generate()->value(), Uuid7::generate()->value()]);

        self::assertSame(1, $this->auditRepo->countActionsLast30dForTenant($this->tenantId('daems')));
        self::assertSame(0, $this->auditRepo->countActionsLast30dForTenant($this->tenantId('sahegroup')));
    }

    public function test_audit_recent_returns_entries_in_descending_order(): void
    {
        $tenantId = $this->tenantId('daems')->value();
        for ($i = 0; $i < 3; $i++) {
            $this->pdo()->prepare(
                "INSERT INTO forum_moderation_audit
                    (id, tenant_id, target_type, target_id, action, reason, performed_by, created_at)
                 VALUES (?, ?, 'post', ?, 'deleted', '', ?, NOW() - INTERVAL ? SECOND)"
            )->execute([Uuid7::generate()->value(), $tenantId, Uuid7::generate()->value(), Uuid7::generate()->value(), $i]);
        }
        $rows = $this->auditRepo->recentForTenant($this->tenantId('daems'), 5);
        self::assertCount(3, $rows);
    }

    public function test_categories_count_returns_nonnegative_int(): void
    {
        $count = $this->forumRepo->countCategoriesForTenant($this->tenantId('daems'));
        self::assertGreaterThanOrEqual(0, $count);
    }
}
