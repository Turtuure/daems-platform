<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventProposalStatsTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlEventProposalRepository $repo;

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

        $this->repo = new SqlEventProposalRepository($this->conn);
    }

    public function test_pending_stats_value_and_30d_incoming_sparkline(): void
    {
        $tenantId = $this->tenantId('daems');

        // 3 pending proposals created in window: today, -3d, -29d
        $this->insertProposal($tenantId, status: 'pending', createdDaysAgo: 0);
        $this->insertProposal($tenantId, status: 'pending', createdDaysAgo: 3);
        $this->insertProposal($tenantId, status: 'pending', createdDaysAgo: 29);

        // 1 approved proposal created today (in window) — should NOT count toward
        // pending VALUE, but should appear in incoming sparkline.
        $this->insertProposal($tenantId, status: 'approved', createdDaysAgo: 0);

        // 1 pending proposal OUTSIDE window (-40d) — counts toward pending VALUE
        // (no time filter on value), but NOT in 30d sparkline.
        $this->insertProposal($tenantId, status: 'pending', createdDaysAgo: 40);

        // 1 rejected proposal OUTSIDE window (-50d) — neither value nor sparkline.
        $this->insertProposal($tenantId, status: 'rejected', createdDaysAgo: 50);

        $stats = $this->repo->pendingStatsForTenant($tenantId);

        // Value: all-time pending = 3 in-window + 1 outside-window = 4
        self::assertSame(4, $stats['value']);

        // Sparkline shape: 30 buckets, backward (today-29 first, today last)
        self::assertCount(30, $stats['sparkline']);
        self::assertSame(['date', 'value'], array_keys($stats['sparkline'][0]));

        $today = new \DateTimeImmutable('today');
        self::assertSame($today->modify('-29 days')->format('Y-m-d'), $stats['sparkline'][0]['date']);
        self::assertSame($today->format('Y-m-d'),                    $stats['sparkline'][29]['date']);

        // Incoming volume buckets:
        //  index 29 (today)       -> 2 (1 pending + 1 approved)
        //  index 26 (-3d)         -> 1 (pending)
        //  index 0  (-29d)        -> 1 (pending)
        //  out-of-window rows do NOT bleed into sparkline
        self::assertSame(2, $stats['sparkline'][29]['value']);
        self::assertSame(1, $stats['sparkline'][26]['value']);
        self::assertSame(1, $stats['sparkline'][0]['value']);

        // Spot-check empty bucket
        self::assertSame(0, $stats['sparkline'][1]['value']);

        // Sum of sparkline = 4 (3 pending in-window + 1 approved in-window)
        $sum = array_sum(array_column($stats['sparkline'], 'value'));
        self::assertSame(4, $sum);
    }

    public function test_pending_stats_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Seed sahe only
        $this->insertProposal($sahe, status: 'pending', createdDaysAgo: 0);
        $this->insertProposal($sahe, status: 'pending', createdDaysAgo: 5);
        $this->insertProposal($sahe, status: 'approved', createdDaysAgo: 1);

        $daemsStats = $this->repo->pendingStatsForTenant($daems);
        $saheStats  = $this->repo->pendingStatsForTenant($sahe);

        self::assertSame(0, $daemsStats['value']);
        self::assertSame(2, $saheStats['value']);

        // Daems sparkline should be entirely zero
        $daemsSum = array_sum(array_column($daemsStats['sparkline'], 'value'));
        self::assertSame(0, $daemsSum);

        // Sahe sparkline includes 2 pending + 1 approved (incoming volume) = 3
        $saheSum = array_sum(array_column($saheStats['sparkline'], 'value'));
        self::assertSame(3, $saheSum);
    }

    public function test_pending_stats_zero_when_no_proposals(): void
    {
        $tenantId = $this->tenantId('daems');

        $stats = $this->repo->pendingStatsForTenant($tenantId);

        self::assertSame(0, $stats['value']);
        self::assertCount(30, $stats['sparkline']);
        $sum = array_sum(array_column($stats['sparkline'], 'value'));
        self::assertSame(0, $sum);
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
     * Insert one event_proposals row with controllable status + created_at offset.
     * Also inserts a minimal users row so user_id (CHAR(36)) carries a real UUID.
     */
    private function insertProposal(
        TenantId $tenantId,
        string $status,
        int $createdDaysAgo,
    ): string {
        $id     = Uuid7::generate()->value();
        $userId = Uuid7::generate()->value();

        // Minimal user row (proposals.user_id has no explicit FK in schema, but
        // we keep the UUIDs valid for fidelity).
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

        $today      = new \DateTimeImmutable('today');
        $createdAt  = $today->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');
        // event_date is required NOT NULL — pick "tomorrow" arbitrarily.
        $eventDate  = $today->modify('+1 day')->format('Y-m-d');

        $decidedAt  = null;
        $decidedBy  = null;
        if ($status === 'approved' || $status === 'rejected') {
            $decidedAt = $createdAt; // close enough for tests
            $decidedBy = Uuid7::generate()->value();
        }

        $this->pdo()->prepare(
            'INSERT INTO event_proposals
                (id, tenant_id, user_id, author_name, author_email, title,
                 event_date, event_time, location, is_online, description,
                 source_locale, status, created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?, ?, ?, ?, NULL)'
        )->execute([
            $id,
            $tenantId->value(),
            $userId,
            'Author ' . substr($id, 0, 8),
            'author-' . substr(str_replace('-', '', $id), 0, 12) . '@example.test',
            'Proposal ' . substr($id, 0, 8),
            $eventDate,
            'A short description for the proposal.',
            'fi_FI',
            $status,
            $createdAt,
            $decidedAt,
            $decidedBy,
        ]);

        return $id;
    }
}
