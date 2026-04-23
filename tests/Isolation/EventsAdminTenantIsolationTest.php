<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin;
use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdminInput;
use Daems\Application\Backstage\PublishEvent\PublishEvent;
use Daems\Application\Backstage\PublishEvent\PublishEventInput;
use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Application\Backstage\UploadEventImage\UploadEventImage;
use Daems\Application\Backstage\UploadEventImage\UploadEventImageInput;
use Daems\Domain\Event\EventId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Support\Fake\InMemoryImageStorage;

final class EventsAdminTenantIsolationTest extends IsolationTestCase
{
    private SqlEventRepository $repo;
    private Connection $conn;

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
        $this->repo = new SqlEventRepository($this->conn);
    }

    /**
     * Seed an event row for the given tenant slug; returns the generated event ID.
     * Translated columns now live in events_i18n (post-migration 054).
     */
    private function seedEvent(string $tenantSlug, string $slug, string $title): string
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO events (id, tenant_id, slug, type, event_date, status, is_online)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, 'upcoming', '2026-12-01', 'published', 0)"
        )->execute([$id, $tenantSlug, $slug]);
        $this->pdo()->prepare(
            "INSERT INTO events_i18n (event_id, locale, title, description)
             VALUES (?, 'fi_FI', ?, 'Isolation test description text.')"
        )->execute([$id, $title]);
        return $id;
    }

    public function test_admin_a_cannot_list_events_in_tenant_b(): void
    {
        $this->seedEvent('daems', 'daems-ev-1', 'Daems Event 1');
        $this->seedEvent('sahegroup', 'sahe-ev-1', 'Sahe Event 1');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-ad00000da001',
            'admin-a-iso@test',
        );

        $uc  = new ListEventsForAdmin($this->repo);
        $out = $uc->execute(new ListEventsForAdminInput($adminA, null, null));

        $titles = array_column($out->items, 'title');
        self::assertContains('Daems Event 1', $titles);
        self::assertNotContains('Sahe Event 1', $titles);
    }

    public function test_admin_a_cannot_publish_tenant_b_event(): void
    {
        $saheEventId = $this->seedEvent('sahegroup', 'sahe-pub-ev', 'Sahe Publish Event');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-ad00000da002',
            'admin-a-pub@test',
        );

        $uc = new PublishEvent($this->repo);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('event_not_found');
        $uc->execute(new PublishEventInput($adminA, $saheEventId));
    }

    public function test_admin_a_cannot_update_tenant_b_event(): void
    {
        $saheEventId = $this->seedEvent('sahegroup', 'sahe-upd-ev', 'Sahe Update Event');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-ad00000da003',
            'admin-a-upd@test',
        );

        $uc = new UpdateEvent($this->repo);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('event_not_found');
        $uc->execute(new UpdateEventInput(
            acting:      $adminA,
            eventId:     $saheEventId,
            title:       'Malicious Title',
            type:        null,
            eventDate:   null,
            eventTime:   null,
            location:    null,
            isOnline:    null,
            description: null,
            heroImage:   null,
            gallery:     null,
        ));
    }

    public function test_admin_a_cannot_upload_to_tenant_b_event(): void
    {
        $saheEventId = $this->seedEvent('sahegroup', 'sahe-upl-ev', 'Sahe Upload Event');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-ad00000da004',
            'admin-a-upl@test',
        );

        $uc = new UploadEventImage($this->repo, new InMemoryImageStorage());

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('event_not_found');
        $uc->execute(new UploadEventImageInput(
            $adminA, $saheEventId, '/tmp/fake.png', 'image/png',
        ));
    }
}
