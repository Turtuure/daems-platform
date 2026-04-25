<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventStatsTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlEventRepository $repo;

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

        $this->repo = new SqlEventRepository($this->conn);
    }

    public function test_event_stats_returns_upcoming_forward_30d_drafts_backward_30d(): void
    {
        $tenantId = $this->tenantId('daems');

        // 3 published events upcoming: today, +5d, +10d
        $this->insertEvent($tenantId, 'published', eventDateOffsetDays: 0);
        $this->insertEvent($tenantId, 'published', eventDateOffsetDays: 5);
        $this->insertEvent($tenantId, 'published', eventDateOffsetDays: 10);

        // 1 published past event (-5d) — must NOT count in upcoming
        $this->insertEvent($tenantId, 'published', eventDateOffsetDays: -5);

        // 2 draft events: created today, created -3d
        $this->insertEvent($tenantId, 'draft', eventDateOffsetDays: 0, createdDaysAgo: 0);
        $this->insertEvent($tenantId, 'draft', eventDateOffsetDays: 0, createdDaysAgo: 3);

        $stats = $this->repo->statsForTenant($tenantId);

        self::assertSame(3, $stats['upcoming']['value']);
        self::assertSame(2, $stats['drafts']['value']);

        // upcoming.sparkline = forward 30 days (today..today+29)
        self::assertCount(30, $stats['upcoming']['sparkline']);
        self::assertSame(['date', 'value'], array_keys($stats['upcoming']['sparkline'][0]));

        $today        = new \DateTimeImmutable('today');
        $expectFirst  = $today->format('Y-m-d');
        $expectLast   = $today->modify('+29 days')->format('Y-m-d');
        self::assertSame($expectFirst, $stats['upcoming']['sparkline'][0]['date']);
        self::assertSame($expectLast,  $stats['upcoming']['sparkline'][29]['date']);

        // Bucket for today: 1 published event (the +0d one)
        self::assertSame(1, $stats['upcoming']['sparkline'][0]['value']);
        // Bucket for +5d: 1 published event
        self::assertSame(1, $stats['upcoming']['sparkline'][5]['value']);
        // Bucket for +10d: 1 published event
        self::assertSame(1, $stats['upcoming']['sparkline'][10]['value']);
        // Other buckets are zero (spot check +1d)
        self::assertSame(0, $stats['upcoming']['sparkline'][1]['value']);

        // drafts.sparkline = backward 30 days (today-29..today)
        self::assertCount(30, $stats['drafts']['sparkline']);
        $expectDraftFirst = $today->modify('-29 days')->format('Y-m-d');
        $expectDraftLast  = $today->format('Y-m-d');
        self::assertSame($expectDraftFirst, $stats['drafts']['sparkline'][0]['date']);
        self::assertSame($expectDraftLast,  $stats['drafts']['sparkline'][29]['date']);

        // Today's draft bucket: 1
        self::assertSame(1, $stats['drafts']['sparkline'][29]['value']);
        // 3 days ago bucket: 1
        self::assertSame(1, $stats['drafts']['sparkline'][26]['value']);
    }

    public function test_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Seed sahe only
        $this->insertEvent($sahe, 'published', eventDateOffsetDays: 0);
        $this->insertEvent($sahe, 'published', eventDateOffsetDays: 7);
        $this->insertEvent($sahe, 'draft',     eventDateOffsetDays: 0);

        $daemsStats = $this->repo->statsForTenant($daems);
        $saheStats  = $this->repo->statsForTenant($sahe);

        self::assertSame(0, $daemsStats['upcoming']['value']);
        self::assertSame(0, $daemsStats['drafts']['value']);

        self::assertSame(2, $saheStats['upcoming']['value']);
        self::assertSame(1, $saheStats['drafts']['value']);
    }

    public function test_archived_events_excluded(): void
    {
        $tenantId = $this->tenantId('daems');

        // 1 published upcoming, 1 draft created today, 2 archived
        $this->insertEvent($tenantId, 'published', eventDateOffsetDays: 3);
        $this->insertEvent($tenantId, 'draft',     eventDateOffsetDays: 0, createdDaysAgo: 0);
        $this->insertEvent($tenantId, 'archived',  eventDateOffsetDays: 0, createdDaysAgo: 0);
        $this->insertEvent($tenantId, 'archived',  eventDateOffsetDays: 14);

        $stats = $this->repo->statsForTenant($tenantId);

        self::assertSame(1, $stats['upcoming']['value']);
        self::assertSame(1, $stats['drafts']['value']);

        // No archived row leaks into upcoming sparkline at +14d
        self::assertSame(0, $stats['upcoming']['sparkline'][14]['value']);
        // Drafts sparkline today: exactly 1 (the draft created today). The archived-today
        // event does NOT count toward drafts.
        self::assertSame(1, $stats['drafts']['sparkline'][29]['value']);
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
     * Insert events row with controllable event_date and created_at offsets.
     * Also inserts a minimal events_i18n row (fi_FI) so admin reads stay sane.
     *
     * @param int $eventDateOffsetDays  Days from today for event_date (negative = past).
     * @param int $createdDaysAgo       Days before today for created_at (default 0 = today).
     */
    private function insertEvent(
        TenantId $tenantId,
        string $status,
        int $eventDateOffsetDays,
        int $createdDaysAgo = 0,
    ): string {
        $id        = Uuid7::generate()->value();
        $slug      = 'evt-' . substr(str_replace('-', '', $id), 0, 24);
        $today     = new \DateTimeImmutable('today');
        $sign      = $eventDateOffsetDays >= 0 ? '+' : '-';
        $abs       = abs($eventDateOffsetDays);
        $eventDate = $today->modify("{$sign}{$abs} days")->format('Y-m-d');
        $createdAt = $today->modify("-{$createdDaysAgo} days")->format('Y-m-d H:i:s');

        // 'type' is ENUM('upcoming','past','online') — orthogonal to status; keep simple.
        $type = 'upcoming';

        $this->pdo()->prepare(
            'INSERT INTO events
                (id, tenant_id, slug, type, status, event_date, event_time, is_online, hero_image, gallery_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 0, NULL, NULL, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            $slug,
            $type,
            $status,
            $eventDate,
            $createdAt,
        ]);

        // i18n companion (FK is from events_i18n.event_id -> events.id; needed for read repos
        // but stats does not touch events_i18n. Insert anyway for fidelity.)
        $this->pdo()->prepare(
            'INSERT INTO events_i18n (event_id, locale, title, location, description)
             VALUES (?, ?, ?, NULL, NULL)'
        )->execute([
            $id,
            'fi_FI',
            'Event ' . substr($id, 0, 8),
        ]);

        return $id;
    }
}
