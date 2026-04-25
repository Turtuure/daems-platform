<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ForumStatsTenantIsolationTest extends IsolationTestCase
{
    private SqlForumReportRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlForumReportRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedReport(string $tenantSlug, string $status): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id, reason_category, status, created_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), 'post', ?, ?, 'spam', ?, NOW())"
        );
        $stmt->execute([
            Uuid7::generate()->value(),
            $tenantSlug,
            Uuid7::generate()->value(),
            Uuid7::generate()->value(),
            $status,
        ]);
    }

    public function test_open_reports_count_isolated_by_tenant(): void
    {
        $this->seedReport('daems',     'open');
        $this->seedReport('sahegroup', 'open');

        self::assertSame(1, $this->repo->countOpenReportsForTenant($this->tenantId('daems')));
        self::assertSame(1, $this->repo->countOpenReportsForTenant($this->tenantId('sahegroup')));
    }
}
