<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats;
use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStatsInput;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventsStatsTenantIsolationTest extends IsolationTestCase
{
    private ListEventsStats $usecase;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->usecase = new ListEventsStats(
            new SqlEventRepository($conn),
            new SqlEventProposalRepository($conn),
        );
    }

    /**
     * Insert one events row + companion events_i18n row.
     * eventDateOffsetDays: signed offset from today (negative = past).
     */
    private function seedEvent(
        TenantId $tenantId,
        string $status,
        int $eventDateOffsetDays,
    ): string {
        $id        = Uuid7::generate()->value();
        $slug      = 'evt-' . substr(str_replace('-', '', $id), 0, 24);
        $today     = new \DateTimeImmutable('today');
        $sign      = $eventDateOffsetDays >= 0 ? '+' : '-';
        $abs       = abs($eventDateOffsetDays);
        $eventDate = $today->modify("{$sign}{$abs} days")->format('Y-m-d');

        $this->pdo()->prepare(
            'INSERT INTO events
                (id, tenant_id, slug, type, status, event_date, event_time, is_online, hero_image, gallery_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 0, NULL, NULL, NOW())'
        )->execute([
            $id,
            $tenantId->value(),
            $slug,
            'upcoming',
            $status,
            $eventDate,
        ]);

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

    /**
     * Insert one event_registrations row + a minimal users row (FK target).
     * registered_at = today (within 30d window).
     */
    private function seedRegistration(TenantId $tenantId, string $eventId): void
    {
        $userId = Uuid7::generate()->value();
        $regId  = Uuid7::generate()->value();

        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $userId,
            'Reg ' . substr($userId, 0, 6),
            'reg-' . str_replace('-', '', $userId) . '@example.test',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);

        $this->pdo()->prepare(
            'INSERT INTO event_registrations (id, tenant_id, event_id, user_id, registered_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([
            $regId,
            $tenantId->value(),
            $eventId,
            $userId,
        ]);
    }

    /**
     * Insert one event_proposals row (pending) + a minimal users row.
     * created_at = today (within 30d window).
     */
    private function seedPendingProposal(TenantId $tenantId): void
    {
        $id     = Uuid7::generate()->value();
        $userId = Uuid7::generate()->value();

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

        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $this->pdo()->prepare(
            'INSERT INTO event_proposals
                (id, tenant_id, user_id, author_name, author_email, title,
                 event_date, event_time, location, is_online, description,
                 source_locale, status, created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?, NOW(), NULL, NULL, NULL)'
        )->execute([
            $id,
            $tenantId->value(),
            $userId,
            'Author ' . substr($id, 0, 8),
            'author-' . substr(str_replace('-', '', $id), 0, 12) . '@example.test',
            'Proposal ' . substr($id, 0, 8),
            $tomorrow,
            'A short description for the proposal.',
            'fi_FI',
            'pending',
        ]);
    }

    public function test_event_stats_isolated_per_tenant_with_asymmetric_seeds(): void
    {
        // Asymmetric seeds — a leaky impl (e.g. ignoring tenant_id) cannot pass.
        //
        // daems:
        //   2 published events upcoming (+5d, +10d)
        //   1 draft event
        //   3 registrations (today)
        //   2 pending event_proposals (today)
        //   → upcoming = 2, drafts = 1, registrations_30d = 3, pending_proposals = 2
        //
        // sahegroup:
        //   1 published event upcoming (+3d)
        //   0 drafts
        //   1 registration (today)
        //   0 pending proposals
        //   → upcoming = 1, drafts = 0, registrations_30d = 1, pending_proposals = 0
        $daemsTenant = $this->tenantId('daems');
        $saheTenant  = $this->tenantId('sahegroup');

        // daems seeds
        $daemsEvent1 = $this->seedEvent($daemsTenant, 'published', eventDateOffsetDays: 5);
        $this->seedEvent($daemsTenant, 'published', eventDateOffsetDays: 10);
        $this->seedEvent($daemsTenant, 'draft',     eventDateOffsetDays: 0);
        $this->seedRegistration($daemsTenant, $daemsEvent1);
        $this->seedRegistration($daemsTenant, $daemsEvent1);
        $this->seedRegistration($daemsTenant, $daemsEvent1);
        $this->seedPendingProposal($daemsTenant);
        $this->seedPendingProposal($daemsTenant);

        // sahegroup seeds
        $saheEvent1 = $this->seedEvent($saheTenant, 'published', eventDateOffsetDays: 3);
        $this->seedRegistration($saheTenant, $saheEvent1);

        $daemsAdmin = $this->makeActingUser(
            'daems',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000e0001',
            email:  'admin-daems-evt@test',
        );
        $saheAdmin = $this->makeActingUser(
            'sahegroup',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000e0002',
            email:  'admin-sahe-evt@test',
        );

        $daems = $this->usecase->execute(
            new ListEventsStatsInput(acting: $daemsAdmin, tenantId: $daemsTenant),
        )->stats;
        $sahe = $this->usecase->execute(
            new ListEventsStatsInput(acting: $saheAdmin, tenantId: $saheTenant),
        )->stats;

        // daems
        self::assertSame(2, $daems['upcoming']['value']);
        self::assertSame(1, $daems['drafts']['value']);
        self::assertSame(3, $daems['registrations_30d']['value']);
        self::assertSame(2, $daems['pending_proposals']['value']);

        // sahegroup — must NOT see daems' rows.
        self::assertSame(1, $sahe['upcoming']['value']);
        self::assertSame(0, $sahe['drafts']['value']);
        self::assertSame(1, $sahe['registrations_30d']['value']);
        self::assertSame(0, $sahe['pending_proposals']['value']);
    }
}
