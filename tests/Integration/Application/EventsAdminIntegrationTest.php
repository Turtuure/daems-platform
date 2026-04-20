<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Backstage\ArchiveEvent\ArchiveEvent;
use Daems\Application\Backstage\ArchiveEvent\ArchiveEventInput;
use Daems\Application\Backstage\CreateEvent\CreateEvent;
use Daems\Application\Backstage\CreateEvent\CreateEventInput;
use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations;
use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrationsInput;
use Daems\Application\Backstage\PublishEvent\PublishEvent;
use Daems\Application\Backstage\PublishEvent\PublishEventInput;
use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Application\Event\ListEvents\ListEvents;
use Daems\Application\Event\ListEvents\ListEventsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use Daems\Tests\Support\Fake\InMemoryImageStorage;

final class EventsAdminIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(43);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'events-int', 'Events Integration Test']);

        $this->adminId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $this->adminId, 'Admin User', 'admin-evts@test.com',
            password_hash('pass1234', PASSWORD_BCRYPT), '1980-01-01',
        ]);

        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->adminId, $this->tenantId, 'admin']);
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($this->adminId),
            email:              'admin-evts@test.com',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function makeIdGenerator(): \Daems\Domain\Shared\IdGeneratorInterface
    {
        return new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };
    }

    private function eventRepo(): SqlEventRepository
    {
        return new SqlEventRepository($this->conn);
    }

    private function buildCreateEvent(): CreateEvent
    {
        return new CreateEvent($this->eventRepo(), $this->makeIdGenerator());
    }

    public function test_full_lifecycle_via_real_sql(): void
    {
        $createUc = $this->buildCreateEvent();
        $out = $createUc->execute(new CreateEventInput(
            acting:             $this->actingAdmin(),
            title:              'Integration Lifecycle Event',
            type:               'upcoming',
            eventDate:          '2026-09-01',
            eventTime:          '18:00',
            location:           'Helsinki',
            isOnline:           false,
            description:        'This is a description with enough characters for the validation to pass.',
            publishImmediately: false,
        ));

        $eventId = $out->id;

        // Assert DB has row with status='draft'
        $stmt = $this->pdo()->prepare('SELECT status, title FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('draft', $row['status']);
        self::assertSame('Integration Lifecycle Event', $row['title']);

        // UpdateEvent: change title
        $updateUc = new UpdateEvent($this->eventRepo());
        $updateUc->execute(new UpdateEventInput(
            acting:      $this->actingAdmin(),
            eventId:     $eventId,
            title:       'Updated Title for Event',
            type:        null,
            eventDate:   null,
            eventTime:   null,
            location:    null,
            isOnline:    null,
            description: null,
            heroImage:   null,
            gallery:     null,
        ));

        $stmt2 = $this->pdo()->prepare('SELECT title FROM events WHERE id = ?');
        $stmt2->execute([$eventId]);
        self::assertSame('Updated Title for Event', $stmt2->fetchColumn());

        // PublishEvent
        $publishUc = new PublishEvent($this->eventRepo());
        $publishUc->execute(new PublishEventInput($this->actingAdmin(), $eventId));

        $stmt3 = $this->pdo()->prepare('SELECT status FROM events WHERE id = ?');
        $stmt3->execute([$eventId]);
        self::assertSame('published', $stmt3->fetchColumn());

        // ArchiveEvent
        $archiveUc = new ArchiveEvent($this->eventRepo());
        $archiveUc->execute(new ArchiveEventInput($this->actingAdmin(), $eventId));

        $stmt4 = $this->pdo()->prepare('SELECT status FROM events WHERE id = ?');
        $stmt4->execute([$eventId]);
        self::assertSame('archived', $stmt4->fetchColumn());
    }

    public function test_registrations_list_joins_user_data(): void
    {
        $createUc = $this->buildCreateEvent();
        $createOut = $createUc->execute(new CreateEventInput(
            acting:             $this->actingAdmin(),
            title:              'Registration Join Test Event',
            type:               'upcoming',
            eventDate:          '2026-10-01',
            eventTime:          null,
            location:           'Tampere',
            isOnline:           false,
            description:        'This event is for testing the registration join query path.',
            publishImmediately: true,
        ));
        $eventId = $createOut->id;

        // Seed a non-admin user
        $userId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([$userId, 'Regular User', 'regular@test.com', 'x', '1995-01-01']);

        // INSERT a registration row directly
        $regId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO event_registrations (id, tenant_id, event_id, user_id, registered_at)
             VALUES (?, ?, ?, ?, '2026-09-15 10:00:00')"
        )->execute([$regId, $this->tenantId, $eventId, $userId]);

        $listRegsUc = new ListEventRegistrations($this->eventRepo());
        $out = $listRegsUc->execute(new ListEventRegistrationsInput(
            $this->actingAdmin(), $eventId,
        ));

        self::assertCount(1, $out->items);
        $item = $out->items[0];
        self::assertSame($userId, $item['user_id']);
        self::assertSame('Regular User', $item['name']);
        self::assertSame('regular@test.com', $item['email']);
        self::assertNotEmpty($item['registered_at']);
    }

    public function test_public_list_excludes_drafts_and_archived(): void
    {
        $tenantId = TenantId::fromString($this->tenantId);
        $idGen    = $this->makeIdGenerator();
        $repo     = $this->eventRepo();

        // Insert events in all three statuses directly so we can control status precisely
        $draftId = $idGen->generate();
        $this->pdo()->prepare(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date, status, description, is_online)
             VALUES (?, ?, 'draft-evt', 'Draft Event', 'upcoming', '2026-11-01', 'draft', 'draft description text here', 0)"
        )->execute([$draftId, $this->tenantId]);

        $publishedId = $idGen->generate();
        $this->pdo()->prepare(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date, status, description, is_online)
             VALUES (?, ?, 'published-evt', 'Published Event', 'upcoming', '2026-11-02', 'published', 'published description text here', 0)"
        )->execute([$publishedId, $this->tenantId]);

        $archivedId = $idGen->generate();
        $this->pdo()->prepare(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date, status, description, is_online)
             VALUES (?, ?, 'archived-evt', 'Archived Event', 'upcoming', '2026-11-03', 'archived', 'archived description text here', 0)"
        )->execute([$archivedId, $this->tenantId]);

        $publicListUc = new ListEvents($repo);
        $out = $publicListUc->execute(new ListEventsInput($tenantId));

        $titles = array_column($out->events, 'title');
        self::assertContains('Published Event', $titles);
        self::assertNotContains('Draft Event', $titles);
        self::assertNotContains('Archived Event', $titles);
    }
}
