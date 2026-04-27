<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Forum\Infrastructure\SqlForumReportRepository;

/**
 * Exercises the `clearedDailyForTenant()` slice on all 4 closure-tracking repos
 * that feed the `cleared_30d` KPI on the unified Notifications page:
 *
 *   - member_applications      decided_at  (approved | rejected)
 *   - supporter_applications   decided_at  (approved | rejected)
 *   - project_proposals        decided_at  (approved | rejected)
 *   - forum_reports            resolved_at (resolved  | dismissed)
 *
 * The shape returned is identical across all 4 repos: a 30-entry zero-filled
 * BACKWARD daily series (today-29 first, today last). Out-of-window decisions
 * (e.g. -40 days) MUST be excluded.
 */
final class ClearedDailySlicesTest extends MigrationTestCase
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

    public function test_member_app_cleared_daily_30d(): void
    {
        $repo     = new SqlMemberApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        // 2 decided today, 1 decided 5d ago, 1 decided 40d ago (out of window),
        // 1 still pending (no decided_at, must be excluded).
        $this->insertMemberApp($tenantId, 'approved', createdDaysAgo: 0, decidedDaysAgo: 0);
        $this->insertMemberApp($tenantId, 'rejected', createdDaysAgo: 0, decidedDaysAgo: 0);
        $this->insertMemberApp($tenantId, 'approved', createdDaysAgo: 5, decidedDaysAgo: 5);
        $this->insertMemberApp($tenantId, 'rejected', createdDaysAgo: 40, decidedDaysAgo: 40);
        $this->insertMemberApp($tenantId, 'pending',  createdDaysAgo: 1, decidedDaysAgo: null);

        $series = $repo->clearedDailyForTenant($tenantId);

        self::assertCount(30, $series);
        self::assertSame(['date', 'value'], array_keys($series[0]));

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $past  = (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
        self::assertSame($past,  $series[0]['date']);
        self::assertSame($today, $series[29]['date']);

        // index 29 (today) = 2 decisions
        // index 24 (-5d)   = 1 decision
        // -40d is out of window, pending has no decided_at — both excluded.
        self::assertSame(2, $series[29]['value']);
        self::assertSame(1, $series[24]['value']);

        $sum = array_sum(array_column($series, 'value'));
        self::assertSame(3, $sum);
    }

    // --- supporter_applications -------------------------------------------------

    public function test_supporter_app_cleared_daily_30d(): void
    {
        $repo     = new SqlSupporterApplicationRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertSupporterApp($tenantId, 'approved', createdDaysAgo: 0, decidedDaysAgo: 0);
        $this->insertSupporterApp($tenantId, 'rejected', createdDaysAgo: 3, decidedDaysAgo: 3);
        $this->insertSupporterApp($tenantId, 'approved', createdDaysAgo: 35, decidedDaysAgo: 35);
        $this->insertSupporterApp($tenantId, 'pending',  createdDaysAgo: 1, decidedDaysAgo: null);

        $series = $repo->clearedDailyForTenant($tenantId);

        self::assertCount(30, $series);

        // index 29 (today) = 1 decision
        // index 26 (-3d)   = 1 decision
        self::assertSame(1, $series[29]['value']);
        self::assertSame(1, $series[26]['value']);

        $sum = array_sum(array_column($series, 'value'));
        self::assertSame(2, $sum);
    }

    // --- project_proposals ------------------------------------------------------

    public function test_project_proposal_cleared_daily_30d(): void
    {
        $repo     = new SqlProjectProposalRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertProjectProposal($tenantId, 'approved', createdDaysAgo: 0, decidedDaysAgo: 0);
        $this->insertProjectProposal($tenantId, 'rejected', createdDaysAgo: 7, decidedDaysAgo: 7);
        $this->insertProjectProposal($tenantId, 'approved', createdDaysAgo: 7, decidedDaysAgo: 7);
        $this->insertProjectProposal($tenantId, 'rejected', createdDaysAgo: 60, decidedDaysAgo: 60);
        $this->insertProjectProposal($tenantId, 'pending',  createdDaysAgo: 1, decidedDaysAgo: null);

        $series = $repo->clearedDailyForTenant($tenantId);

        self::assertCount(30, $series);

        // index 29 (today) = 1 decision
        // index 22 (-7d)   = 2 decisions
        self::assertSame(1, $series[29]['value']);
        self::assertSame(2, $series[22]['value']);

        $sum = array_sum(array_column($series, 'value'));
        self::assertSame(3, $sum);
    }

    // --- forum_reports ----------------------------------------------------------

    public function test_forum_report_cleared_daily_30d(): void
    {
        // forum_reports tracks closure via `resolved_at` (set when status moves
        // from 'open' -> 'resolved' or 'dismissed').
        $repo     = new SqlForumReportRepository($this->conn);
        $tenantId = $this->tenantId('daems');

        $this->insertForumReport($tenantId, 'resolved',  createdDaysAgo: 1, resolvedDaysAgo: 0);
        $this->insertForumReport($tenantId, 'dismissed', createdDaysAgo: 1, resolvedDaysAgo: 0);
        $this->insertForumReport($tenantId, 'resolved',  createdDaysAgo: 6, resolvedDaysAgo: 4);
        $this->insertForumReport($tenantId, 'resolved',  createdDaysAgo: 50, resolvedDaysAgo: 45);
        $this->insertForumReport($tenantId, 'open',      createdDaysAgo: 0, resolvedDaysAgo: null);

        $series = $repo->clearedDailyForTenant($tenantId);

        self::assertCount(30, $series);

        // index 29 (today) = 2 closures
        // index 25 (-4d)   = 1 closure
        self::assertSame(2, $series[29]['value']);
        self::assertSame(1, $series[25]['value']);

        $sum = array_sum(array_column($series, 'value'));
        self::assertSame(3, $sum);
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
        $this->insertMemberApp($sahe, 'approved', createdDaysAgo: 0, decidedDaysAgo: 0);
        $this->insertSupporterApp($sahe, 'rejected', createdDaysAgo: 1, decidedDaysAgo: 1);
        $this->insertProjectProposal($sahe, 'approved', createdDaysAgo: 2, decidedDaysAgo: 2);
        $this->insertForumReport($sahe, 'resolved', createdDaysAgo: 3, resolvedDaysAgo: 3);

        // daems sees nothing
        self::assertSame(0, array_sum(array_column($memberRepo->clearedDailyForTenant($daems), 'value')));
        self::assertSame(0, array_sum(array_column($supporterRepo->clearedDailyForTenant($daems), 'value')));
        self::assertSame(0, array_sum(array_column($proposalRepo->clearedDailyForTenant($daems), 'value')));
        self::assertSame(0, array_sum(array_column($reportRepo->clearedDailyForTenant($daems), 'value')));

        // sahe sees its own seeds
        self::assertSame(1, array_sum(array_column($memberRepo->clearedDailyForTenant($sahe), 'value')));
        self::assertSame(1, array_sum(array_column($supporterRepo->clearedDailyForTenant($sahe), 'value')));
        self::assertSame(1, array_sum(array_column($proposalRepo->clearedDailyForTenant($sahe), 'value')));
        self::assertSame(1, array_sum(array_column($reportRepo->clearedDailyForTenant($sahe), 'value')));
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

    private function insertMemberApp(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $decidedDaysAgo,
    ): string {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');
        $decidedAt = $decidedDaysAgo === null
            ? null
            : (new \DateTimeImmutable('now'))->modify("-{$decidedDaysAgo} days")->format('Y-m-d H:i:s');
        // decided_by is nullable + has FK to users(id); leave NULL to avoid FK churn.

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

    private function insertSupporterApp(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $decidedDaysAgo,
    ): string {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');
        $decidedAt = $decidedDaysAgo === null
            ? null
            : (new \DateTimeImmutable('now'))->modify("-{$decidedDaysAgo} days")->format('Y-m-d H:i:s');

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

    private function insertProjectProposal(
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

        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');
        $decidedAt = $decidedDaysAgo === null
            ? null
            : (new \DateTimeImmutable('now'))->modify("-{$decidedDaysAgo} days")->format('Y-m-d H:i:s');
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

    private function insertForumReport(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
        ?int $resolvedDaysAgo,
    ): string {
        $id         = Uuid7::generate()->value();
        $createdAt  = (new \DateTimeImmutable('now'))->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');
        $resolvedAt = $resolvedDaysAgo === null
            ? null
            : (new \DateTimeImmutable('now'))->modify("-{$resolvedDaysAgo} days")->format('Y-m-d H:i:s');
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
}
