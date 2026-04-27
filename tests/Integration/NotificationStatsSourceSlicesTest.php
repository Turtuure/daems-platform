<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use DaemsModule\Projects\Infrastructure\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Forum\Infrastructure\SqlForumReportRepository;

/**
 * Exercises the `notificationStatsForTenant()` slice on all 4 source repos that
 * feed the unified Notifications inbox KPI strip:
 *
 *   - member_applications      (status='pending')
 *   - supporter_applications   (status='pending')
 *   - project_proposals        (status='pending')
 *   - forum_reports            (status='open')
 *
 * The shape is identical across all 4 repos:
 *   { pending_count: int, created_at_daily_30d: [{date, value}, …30], oldest_pending_age_days: int }
 */
final class NotificationStatsSourceSlicesTest extends MigrationTestCase
{
    private Connection $conn;

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
    }

    // --- member_applications ----------------------------------------------------

    public function test_member_app_pending_slice(): void
    {
        $repo     = new SqlMemberApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        // 2 pending: today + 3 days ago. 1 approved 2 days ago — incoming volume only.
        $this->insertMemberApp($tenantId, 'pending', createdDaysAgo: 0);
        $this->insertMemberApp($tenantId, 'pending', createdDaysAgo: 3);
        $this->insertMemberApp($tenantId, 'approved', createdDaysAgo: 2);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(2, $stats['pending_count']);
        self::assertCount(30, $stats['created_at_daily_30d']);
        self::assertSame(['date', 'value'], array_keys($stats['created_at_daily_30d'][0]));

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $past  = (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
        self::assertSame($past,  $stats['created_at_daily_30d'][0]['date']);
        self::assertSame($today, $stats['created_at_daily_30d'][29]['date']);

        // Today's bucket: 1 pending + 1 approved-doesn't-count? Actually approved is 2d ago.
        // index 29 (today)       -> 1 pending
        // index 27 (-2d, approved) -> 1
        // index 26 (-3d, pending)  -> 1
        self::assertSame(1, $stats['created_at_daily_30d'][29]['value']);
        self::assertSame(1, $stats['created_at_daily_30d'][27]['value']);
        self::assertSame(1, $stats['created_at_daily_30d'][26]['value']);

        // Total incoming volume in window = 3
        $sum = array_sum(array_column($stats['created_at_daily_30d'], 'value'));
        self::assertSame(3, $sum);

        // oldest_pending_age_days = 3 (the -3d row is the oldest *pending* row)
        self::assertSame(3, $stats['oldest_pending_age_days']);
    }

    // --- supporter_applications -------------------------------------------------

    public function test_supporter_app_pending_slice(): void
    {
        $repo     = new SqlSupporterApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertSupporterApp($tenantId, 'pending', createdDaysAgo: 0);
        $this->insertSupporterApp($tenantId, 'pending', createdDaysAgo: 7);
        $this->insertSupporterApp($tenantId, 'rejected', createdDaysAgo: 1);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(2, $stats['pending_count']);
        self::assertCount(30, $stats['created_at_daily_30d']);

        // index 29 (today) = 1 pending
        // index 28 (-1d)   = 1 rejected
        // index 22 (-7d)   = 1 pending
        self::assertSame(1, $stats['created_at_daily_30d'][29]['value']);
        self::assertSame(1, $stats['created_at_daily_30d'][28]['value']);
        self::assertSame(1, $stats['created_at_daily_30d'][22]['value']);

        $sum = array_sum(array_column($stats['created_at_daily_30d'], 'value'));
        self::assertSame(3, $sum);

        // oldest_pending_age_days = 7
        self::assertSame(7, $stats['oldest_pending_age_days']);
    }

    // --- project_proposals ------------------------------------------------------

    public function test_project_proposal_pending_slice(): void
    {
        $repo     = new SqlProjectProposalRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertProjectProposal($tenantId, 'pending', createdDaysAgo: 0);
        $this->insertProjectProposal($tenantId, 'pending', createdDaysAgo: 4);
        $this->insertProjectProposal($tenantId, 'pending', createdDaysAgo: 12);
        $this->insertProjectProposal($tenantId, 'approved', createdDaysAgo: 1);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(3, $stats['pending_count']);
        self::assertCount(30, $stats['created_at_daily_30d']);

        // 4 incoming events in window
        $sum = array_sum(array_column($stats['created_at_daily_30d'], 'value'));
        self::assertSame(4, $sum);

        // oldest_pending_age_days = 12
        self::assertSame(12, $stats['oldest_pending_age_days']);
    }

    // --- forum_reports ----------------------------------------------------------

    public function test_forum_report_open_slice(): void
    {
        $repo     = new SqlForumReportRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        // forum_reports uses status='open' (not 'pending') as the unresolved state.
        $this->insertForumReport($tenantId, 'open', createdDaysAgo: 0);
        $this->insertForumReport($tenantId, 'open', createdDaysAgo: 5);
        $this->insertForumReport($tenantId, 'resolved', createdDaysAgo: 2);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(2, $stats['pending_count']);
        self::assertCount(30, $stats['created_at_daily_30d']);

        // 3 incoming events in window (open + open + resolved)
        $sum = array_sum(array_column($stats['created_at_daily_30d'], 'value'));
        self::assertSame(3, $sum);

        self::assertSame(5, $stats['oldest_pending_age_days']);
    }

    // --- focused: oldest_pending_age_days computation ---------------------------

    public function test_oldest_pending_age_member_app(): void
    {
        $repo     = new SqlMemberApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertMemberApp($tenantId, 'pending', createdDaysAgo: 5);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(1, $stats['pending_count']);
        self::assertSame(5, $stats['oldest_pending_age_days']);
    }

    public function test_oldest_pending_age_zero_when_no_pending(): void
    {
        $repo     = new SqlMemberApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        // Only an approved row — no pending rows at all.
        $this->insertMemberApp($tenantId, 'approved', createdDaysAgo: 4);

        $stats = $repo->notificationStatsForTenant($tenantId);

        self::assertSame(0, $stats['pending_count']);
        self::assertSame(0, $stats['oldest_pending_age_days']);
    }

    // --- isolation across tenants ----------------------------------------------

    public function test_isolated_per_tenant(): void
    {
        $memberRepo    = new SqlMemberApplicationRepository($this->conn);
        $supporterRepo = new SqlSupporterApplicationRepository($this->conn);
        $proposalRepo  = new SqlProjectProposalRepository($this->conn);
        $reportRepo    = new SqlForumReportRepository($this->conn);

        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Asymmetric: only sahe gets seeded.
        $this->insertMemberApp($sahe, 'pending', createdDaysAgo: 2);
        $this->insertSupporterApp($sahe, 'pending', createdDaysAgo: 4);
        $this->insertProjectProposal($sahe, 'pending', createdDaysAgo: 1);
        $this->insertForumReport($sahe, 'open', createdDaysAgo: 6);

        // daems sees nothing
        self::assertSame(0, $memberRepo->notificationStatsForTenant($daems)['pending_count']);
        self::assertSame(0, $memberRepo->notificationStatsForTenant($daems)['oldest_pending_age_days']);
        self::assertSame(0, $supporterRepo->notificationStatsForTenant($daems)['pending_count']);
        self::assertSame(0, $proposalRepo->notificationStatsForTenant($daems)['pending_count']);
        self::assertSame(0, $reportRepo->notificationStatsForTenant($daems)['pending_count']);

        // sahe sees its own seeds
        self::assertSame(1, $memberRepo->notificationStatsForTenant($sahe)['pending_count']);
        self::assertSame(2, $memberRepo->notificationStatsForTenant($sahe)['oldest_pending_age_days']);
        self::assertSame(1, $supporterRepo->notificationStatsForTenant($sahe)['pending_count']);
        self::assertSame(4, $supporterRepo->notificationStatsForTenant($sahe)['oldest_pending_age_days']);
        self::assertSame(1, $proposalRepo->notificationStatsForTenant($sahe)['pending_count']);
        self::assertSame(1, $proposalRepo->notificationStatsForTenant($sahe)['oldest_pending_age_days']);
        self::assertSame(1, $reportRepo->notificationStatsForTenant($sahe)['pending_count']);
        self::assertSame(6, $reportRepo->notificationStatsForTenant($sahe)['oldest_pending_age_days']);
    }

    // --- helpers ---------------------------------------------------------------

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

    private function insertMemberApp(TenantId $tenantId, string $status, int $createdDaysAgo): string
    {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'Applicant ' . substr($id, 0, 8),
            'app+' . substr($id, 0, 8) . '@example.test',
            '1990-01-01',
            'FI',
            'motivation text',
            'word of mouth',
            $status,
            $createdAt,
        ]);

        return $id;
    }

    private function insertSupporterApp(TenantId $tenantId, string $status, int $createdDaysAgo): string
    {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO supporter_applications
                (id, tenant_id, org_name, contact_person, email, motivation, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'Org ' . substr($id, 0, 8),
            'Contact ' . substr($id, 0, 8),
            'sup+' . substr($id, 0, 8) . '@example.test',
            'motivation text',
            $status,
            $createdAt,
        ]);

        return $id;
    }

    private function insertProjectProposal(TenantId $tenantId, string $status, int $createdDaysAgo): string
    {
        $id     = Uuid7::generate()->value();
        $userId = Uuid7::generate()->value();

        // FK requires a real users row.
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $userId,
            'Proposer ' . substr($userId, 0, 6),
            'proposer-' . str_replace('-', '', $userId) . '@example.test',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);

        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');

        $decidedAt = null;
        $decidedBy = null;
        if ($status === 'approved' || $status === 'rejected') {
            $decidedAt = $createdAt;
            $decidedBy = Uuid7::generate()->value();
        }

        $this->pdo()->prepare(
            'INSERT INTO project_proposals
                (id, tenant_id, user_id, author_name, author_email, title, category, summary, description,
                 source_locale, status, created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        )->execute([
            $id,
            $tenantId->value(),
            $userId,
            'Author ' . substr($id, 0, 8),
            'author-' . substr(str_replace('-', '', $id), 0, 12) . '@example.test',
            'Proposal ' . substr($id, 0, 8),
            'category-' . substr($id, 0, 8),
            'Short summary ' . substr($id, 0, 8) . '.',
            'Longer description for the proposal.',
            'fi_FI',
            $status,
            $createdAt,
            $decidedAt,
            $decidedBy,
        ]);

        return $id;
    }

    private function insertForumReport(TenantId $tenantId, string $status, int $createdDaysAgo): string
    {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id, reason_category, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'post',
            Uuid7::generate()->value(),
            Uuid7::generate()->value(),
            'spam',
            $status,
            $createdAt,
        ]);

        return $id;
    }
}
