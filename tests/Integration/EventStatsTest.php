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

    public function test_event_registration_stats_shape_and_value_count(): void
    {
        $tenantId = $this->tenantId('daems');
        $eventId  = $this->insertEvent($tenantId, 'published', eventDateOffsetDays: 5);

        // 5 registrations within last 30d: 1 today, 4 spread across 1d/7d/15d/29d ago.
        $this->insertRegistration($tenantId, $eventId, daysAgo: 0);
        $this->insertRegistration($tenantId, $eventId, daysAgo: 1);
        $this->insertRegistration($tenantId, $eventId, daysAgo: 7);
        $this->insertRegistration($tenantId, $eventId, daysAgo: 15);
        $this->insertRegistration($tenantId, $eventId, daysAgo: 29);

        // 2 registrations OUTSIDE the 30d window (40d ago) — must NOT count.
        $this->insertRegistration($tenantId, $eventId, daysAgo: 40);
        $this->insertRegistration($tenantId, $eventId, daysAgo: 40);

        $stats = $this->repo->dailyRegistrationsForTenant($tenantId);

        self::assertSame(5, $stats['value']);

        self::assertCount(30, $stats['sparkline']);
        self::assertSame(['date', 'value'], array_keys($stats['sparkline'][0]));

        $today = new \DateTimeImmutable('today');
        // Sparkline is BACKWARD: index 0 = today-29, index 29 = today.
        self::assertSame($today->modify('-29 days')->format('Y-m-d'), $stats['sparkline'][0]['date']);
        self::assertSame($today->format('Y-m-d'),                     $stats['sparkline'][29]['date']);

        // Today bucket: 1 registration (the days_ago=0 row).
        self::assertSame(1, $stats['sparkline'][29]['value']);
        // -1d bucket (index 28): 1.
        self::assertSame(1, $stats['sparkline'][28]['value']);
        // -7d bucket (index 22): 1.
        self::assertSame(1, $stats['sparkline'][22]['value']);
        // -29d bucket (index 0): 1.
        self::assertSame(1, $stats['sparkline'][0]['value']);
        // -2d bucket (index 27): zero.
        self::assertSame(0, $stats['sparkline'][27]['value']);
    }

    public function test_event_registration_stats_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        $daemsEvent = $this->insertEvent($daems, 'published', eventDateOffsetDays: 0);
        $saheEvent  = $this->insertEvent($sahe,  'published', eventDateOffsetDays: 0);

        // 3 registrations in daems, 2 in sahegroup, all within last 30d.
        $this->insertRegistration($daems, $daemsEvent, daysAgo: 0);
        $this->insertRegistration($daems, $daemsEvent, daysAgo: 3);
        $this->insertRegistration($daems, $daemsEvent, daysAgo: 10);

        $this->insertRegistration($sahe, $saheEvent, daysAgo: 1);
        $this->insertRegistration($sahe, $saheEvent, daysAgo: 5);

        $daemsStats = $this->repo->dailyRegistrationsForTenant($daems);
        $saheStats  = $this->repo->dailyRegistrationsForTenant($sahe);

        self::assertSame(3, $daemsStats['value']);
        self::assertSame(2, $saheStats['value']);
    }

    /**
     * Insert a row into event_registrations + an accompanying users row,
     * with registered_at set to N days before today.
     */
    private function insertRegistration(TenantId $tenantId, string $eventId, int $daysAgo): string
    {
        $regId  = Uuid7::generate()->value();
        $userId = Uuid7::generate()->value();

        // Minimal user row (users.id is FK target for event_registrations.user_id).
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $userId,
            'Reg User ' . substr($userId, 0, 6),
            'reg-' . str_replace('-', '', $userId) . '@example.test',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);

        $when = (new \DateTimeImmutable('today'))
            ->modify("-{$daysAgo} days")
            ->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO event_registrations (id, tenant_id, event_id, user_id, registered_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $regId,
            $tenantId->value(),
            $eventId,
            $userId,
            $when,
        ]);

        return $regId;
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
