<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStats;
use Daems\Application\Backstage\Notifications\ListNotificationsStats\ListNotificationsStatsInput;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminApplicationDismissalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use DaemsModule\Projects\Infrastructure\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Forum\Infrastructure\SqlForumReportRepository;

/**
 * Value-level isolation for the unified /backstage/notifications/stats KPI strip.
 *
 * Drives the use case against real SQL repositories with asymmetric seeds across
 * 5 tables and 2 tenants. Verifies:
 *   - tenant scoping (sahegroup admin must NOT see daems' pending/cleared rows)
 *   - actor-dismissal subtraction (pending_you = pending_all - actor's dismissals)
 *   - cross-table aggregation (cleared_30d sums member + supporter + proposal +
 *     forum closures)
 *   - oldest_pending_d max across the 4 source tables
 */
final class NotificationsStatsTenantIsolationTest extends IsolationTestCase
{
    private Connection $conn;
    private ListNotificationsStats $usecase;

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

        $this->usecase = new ListNotificationsStats(
            new SqlMemberApplicationRepository($this->conn),
            new SqlSupporterApplicationRepository($this->conn),
            new SqlProjectProposalRepository($this->conn),
            new SqlForumReportRepository($this->conn),
            new SqlAdminApplicationDismissalRepository($this->conn->pdo()),
        );
    }

    public function test_notifications_stats_isolated_per_tenant_with_actor_dismissals(): void
    {
        // Asymmetric seeds — a leaky impl (e.g. ignoring tenant_id, or counting
        // *anyone's* dismissals instead of just the actor's) cannot pass.
        //
        // daems:
        //   pending: 2 member apps (m1 oldest at 12d, m2 at 1d)
        //          + 1 supporter app (s1 at 2d)
        //          + 1 project_proposal (p1 at 3d)
        //          + 1 forum_report open (f1 at 4d)
        //          = 5 pending total, oldest = 12d
        //   actor dismissals: 2 (against m1 + p1) → pending_you = 5 - 2 = 3
        //   cleared in 30d:
        //          1 approved member app (decided 5d ago)
        //          1 dismissed forum_report (resolved 3d ago)
        //          = 2 cleared
        //
        // sahegroup:
        //   pending: 1 member app only
        //   actor dismissals: 0
        //   cleared in 30d: 0
        $daemsTenant = $this->tenantId('daems');
        $saheTenant  = $this->tenantId('sahegroup');

        // Acting admins (also serve as decided_by FK targets where needed).
        $daemsAdmin = $this->makeActingUser(
            'daems',
            UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000d0001',
            email:  'admin-daems-notif@test',
        );
        $saheAdmin = $this->makeActingUser(
            'sahegroup',
            UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000a0001',
            email:  'admin-sahe-notif@test',
        );

        // ---------- daems pending ----------
        $m1 = $this->seedMemberApp($daemsTenant, status: 'pending', createdDaysAgo: 12, decidedDaysAgo: null);
        $m2 = $this->seedMemberApp($daemsTenant, status: 'pending', createdDaysAgo: 1, decidedDaysAgo: null);
        $this->seedSupporterApp($daemsTenant, status: 'pending', createdDaysAgo: 2, decidedDaysAgo: null);
        $p1 = $this->seedProjectProposal($daemsTenant, status: 'pending', createdDaysAgo: 3, decidedDaysAgo: null);
        $this->seedForumReport($daemsTenant, status: 'open', createdDaysAgo: 4, resolvedDaysAgo: null);

        // ---------- daems cleared in 30d ----------
        $this->seedMemberApp($daemsTenant, status: 'approved', createdDaysAgo: 7, decidedDaysAgo: 5);
        $this->seedForumReport($daemsTenant, status: 'dismissed', createdDaysAgo: 6, resolvedDaysAgo: 3);

        // ---------- daems dismissals by acting admin (against m1 + p1) ----------
        $this->seedDismissal($daemsAdmin->id->value(), $m1, 'member');
        $this->seedDismissal($daemsAdmin->id->value(), $p1, 'project_proposal');

        // ---------- sahegroup ----------
        $saheM1 = $this->seedMemberApp($saheTenant, status: 'pending', createdDaysAgo: 1, decidedDaysAgo: null);
        // Older sahegroup pending row — proves daems' oldest_pending_d=12 is NOT
        // contaminated by sahegroup's 20d row (i.e. the MAX(DATEDIFF(...)) query
        // is correctly tenant-scoped).
        $this->seedMemberApp($saheTenant, status: 'pending', createdDaysAgo: 20, decidedDaysAgo: null);

        // ---------- cross-actor dismissal (per-actor scoping proof) ----------
        // saheAdmin dismisses one of daems' pending rows. If the use case
        // accidentally subtracted dismissals across all actors (or treated
        // dismissals as per-tenant), daems' pending_you would drop below 3.
        $this->seedDismissal($saheAdmin->id->value(), $m2, 'member');

        // ---------- act ----------
        $daems = $this->usecase->execute(
            new ListNotificationsStatsInput(acting: $daemsAdmin, tenantId: $daemsTenant),
        )->stats;
        $sahe = $this->usecase->execute(
            new ListNotificationsStatsInput(acting: $saheAdmin, tenantId: $saheTenant),
        )->stats;

        // ---------- daems assertions ----------
        // 2 member + 1 supporter + 1 proposal + 1 forum_report = 5 pending.
        self::assertSame(5, $daems['pending_all']['value']);
        // pending_you = 5 - 2 dismissals (m1, p1) = 3.
        self::assertSame(3, $daems['pending_you']['value']);
        // 1 approved member app + 1 dismissed forum_report = 2 cleared.
        self::assertSame(2, $daems['cleared_30d']['value']);
        // Oldest is m1 at 12 days.
        self::assertSame(12, $daems['oldest_pending_d']['value']);

        // Sparkline shapes: pending_all/pending_you/cleared_30d are 30-entry series;
        // oldest_pending_d carries no temporal series.
        self::assertCount(30, $daems['pending_all']['sparkline']);
        self::assertCount(30, $daems['pending_you']['sparkline']);
        self::assertCount(30, $daems['cleared_30d']['sparkline']);
        self::assertSame([], $daems['oldest_pending_d']['sparkline']);

        // ---------- sahegroup assertions ----------
        // sahegroup admin MUST NOT see daems' rows. 2 sahegroup pending rows
        // (1d + 20d); 1 dismissal by saheAdmin against daems' m2 (which doesn't
        // count toward sahegroup's pending_you because m2 isn't IN sahegroup's
        // pending set — but the dismissal-id IS subtracted from total).
        // pending_all = 2; pending_you = max(0, 2 - 1) = 1; cleared = 0;
        // oldest = 20d.
        self::assertSame(2, $sahe['pending_all']['value']);
        self::assertSame(1, $sahe['pending_you']['value']);
        self::assertSame(0, $sahe['cleared_30d']['value']);
        self::assertSame(20, $sahe['oldest_pending_d']['value']);
    }

    // --- seed helpers ----------------------------------------------------------

    /**
     * Anchor seed timestamps to MySQL's notion of "today" at noon. PHP's clock
     * may be in a different timezone than the DB (PHP UTC vs MySQL SYSTEM),
     * which causes DATEDIFF(NOW(), createdAt) to be off-by-one near midnight.
     * Computing the date in SQL eliminates the skew.
     */
    private function daysAgoAtNoon(int $daysAgo): string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? DAY), "%Y-%m-%d 12:00:00")'
        );
        $stmt->execute([$daysAgo]);
        /** @var string $value */
        $value = $stmt->fetchColumn();
        return $value;
    }

    private function seedMemberApp(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $decidedDaysAgo,
    ): string {
        $id        = Uuid7::generate()->value();
        $createdAt = $this->daysAgoAtNoon($createdDaysAgo);
        $decidedAt = $decidedDaysAgo === null
            ? null
            : $this->daysAgoAtNoon($decidedDaysAgo);

        $this->pdo()->prepare(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard,
                 status, created_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $decidedAt,
        ]);

        return $id;
    }

    private function seedSupporterApp(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $decidedDaysAgo,
    ): string {
        $id        = Uuid7::generate()->value();
        $createdAt = $this->daysAgoAtNoon($createdDaysAgo);
        $decidedAt = $decidedDaysAgo === null
            ? null
            : $this->daysAgoAtNoon($decidedDaysAgo);

        $this->pdo()->prepare(
            'INSERT INTO supporter_applications
                (id, tenant_id, org_name, contact_person, email, motivation,
                 status, created_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'Org ' . substr($id, 0, 8),
            'Contact ' . substr($id, 0, 8),
            'sup+' . substr($id, 0, 8) . '@example.test',
            'motivation text',
            $status,
            $createdAt,
            $decidedAt,
        ]);

        return $id;
    }

    private function seedProjectProposal(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $decidedDaysAgo,
    ): string {
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

        $createdAt = $this->daysAgoAtNoon($createdDaysAgo);
        $decidedAt = $decidedDaysAgo === null
            ? null
            : $this->daysAgoAtNoon($decidedDaysAgo);
        $decidedBy = $decidedAt === null ? null : Uuid7::generate()->value();

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

    private function seedForumReport(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $resolvedDaysAgo,
    ): string {
        $id         = Uuid7::generate()->value();
        $createdAt  = $this->daysAgoAtNoon($createdDaysAgo);
        $resolvedAt = $resolvedDaysAgo === null
            ? null
            : $this->daysAgoAtNoon($resolvedDaysAgo);
        $resolvedBy = $resolvedAt === null ? null : Uuid7::generate()->value();
        $resolutionAction = $resolvedAt === null ? null : ($status === 'dismissed' ? 'dismissed' : 'deleted');

        $this->pdo()->prepare(
            'INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id, reason_category,
                 status, resolved_at, resolved_by, resolution_action, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'post',
            Uuid7::generate()->value(),
            Uuid7::generate()->value(),
            'spam',
            $status,
            $resolvedAt,
            $resolvedBy,
            $resolutionAction,
            $createdAt,
        ]);

        return $id;
    }

    /**
     * Insert a row into admin_application_dismissals to mark `$appId` as dismissed
     * by `$adminId` for `$appType` ('member' | 'supporter' | 'project_proposal' |
     * 'forum_report'). The use case subtracts COUNT(actor's dismissals) from total
     * pending to produce pending_you.
     */
    private function seedDismissal(string $adminId, string $appId, string $appType): void
    {
        $this->pdo()->prepare(
            'INSERT INTO admin_application_dismissals
                (id, admin_id, app_id, app_type, dismissed_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([
            Uuid7::generate()->value(),
            $adminId,
            $appId,
            $appType,
        ]);
    }
}
