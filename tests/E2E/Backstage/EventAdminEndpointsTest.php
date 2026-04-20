<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class EventAdminEndpointsTest extends TestCase
{
    private KernelHarness $h;
    private string $token;

    protected function setUp(): void
    {
        $this->h     = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
        $admin       = $this->h->seedUser('admin-ev@x.com', 'pass1234', 'admin');
        $this->token = $this->h->tokenFor($admin);
    }

    /** Helper: POST /backstage/events with sane defaults. Returns decoded body. */
    private function createEventViaApi(?string $title = null): array
    {
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/events', $this->token, [
            'title'       => $title ?? 'Test Event Title Here',
            'type'        => 'upcoming',
            'event_date'  => '2026-11-15',
            'event_time'  => '19:00',
            'location'    => 'Helsinki Hall',
            'is_online'   => false,
            'description' => 'This description has more than twenty characters needed.',
        ]);
        $this->assertSame(201, $resp->status(), 'createEvent failed: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        return $body;
    }

    public function test_create_event_returns_201_with_id_and_slug(): void
    {
        $body = $this->createEventViaApi('Unique Create Test Event');
        $data = $body['data'] ?? [];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('slug', $data);
        $this->assertNotEmpty($data['id']);
        $this->assertNotEmpty($data['slug']);
    }

    public function test_update_event_changes_title(): void
    {
        $createBody = $this->createEventViaApi('Original Event Title');
        $id = $createBody['data']['id'];

        $updateResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id,
            $this->token,
            ['title' => 'Updated Event Title New'],
        );
        $this->assertSame(200, $updateResp->status());

        // GET the event list filtered by the id to confirm the title changed
        $listResp = $this->h->authedRequest('GET', '/api/v1/backstage/events', $this->token);
        $this->assertSame(200, $listResp->status());
        $listBody = json_decode($listResp->body(), true);
        $this->assertIsArray($listBody);
        $items = $listBody['items'] ?? [];
        $found = null;
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Event not found in list after update');
        $this->assertSame('Updated Event Title New', $found['title']);
    }

    public function test_publish_event_transitions_status(): void
    {
        $createBody = $this->createEventViaApi('Publish Status Test Event');
        $id = $createBody['data']['id'];

        $publishResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id . '/publish',
            $this->token,
        );
        $this->assertSame(200, $publishResp->status());
        $body = json_decode($publishResp->body(), true);
        $this->assertIsArray($body);
        $this->assertSame('published', ($body['data']['status'] ?? null));
    }

    public function test_archive_event_transitions_status(): void
    {
        $createBody = $this->createEventViaApi('Archive Status Test Event');
        $id = $createBody['data']['id'];

        $archiveResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id . '/archive',
            $this->token,
        );
        $this->assertSame(200, $archiveResp->status());
        $body = json_decode($archiveResp->body(), true);
        $this->assertIsArray($body);
        $this->assertSame('archived', ($body['data']['status'] ?? null));
    }

    public function test_list_filters_by_status(): void
    {
        // Create two events — one we archive, one stays draft
        $body1 = $this->createEventViaApi('Draft Event to Keep');
        $body2 = $this->createEventViaApi('Archived Event to Find');
        $archivedId = $body2['data']['id'];

        // Archive the second one
        $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $archivedId . '/archive',
            $this->token,
        );

        // GET filtered by archived — pass status as body key (Request::string() checks both body and query)
        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/events',
            $this->token,
            ['status' => 'archived'],
        );
        $this->assertSame(200, $listResp->status());
        $body = json_decode($listResp->body(), true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? [];

        $ids = array_column($items, 'id');
        $this->assertContains($archivedId, $ids, 'archived event should be in filtered list');
        foreach ($items as $item) {
            $this->assertSame('archived', $item['status'], 'filter should only return archived events');
        }
    }

    public function test_list_registrations_returns_items(): void
    {
        $createBody = $this->createEventViaApi('Registration List Test Event');
        $id = $createBody['data']['id'];

        // Seed a registration directly in the InMemory repo
        $userId = '01958000-0000-7000-9000-reg0000000aa';
        $this->h->events->seedAdminRegistration(
            $id, $userId, 'Reg User', 'reg@x.com', '2026-04-20 12:00:00',
        );

        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/events/' . $id . '/registrations',
            $this->token,
        );
        $this->assertSame(200, $listResp->status());
        $body = json_decode($listResp->body(), true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? [];
        $this->assertCount(1, $items);
        $this->assertSame($userId, $items[0]['user_id']);
        $this->assertSame('Reg User', $items[0]['name']);
        $this->assertSame('reg@x.com', $items[0]['email']);
    }

    public function test_remove_registration_returns_204(): void
    {
        $createBody = $this->createEventViaApi('Remove Registration Test Event');
        $id = $createBody['data']['id'];

        $userId = '01958000-0000-7000-9000-reg0000000bb';
        $this->h->events->seedAdminRegistration(
            $id, $userId, 'Remove User', 'remove@x.com', '2026-04-20 12:00:00',
        );

        // Remove the registration
        $removeResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id . '/registrations/' . $userId . '/remove',
            $this->token,
        );
        $this->assertSame(204, $removeResp->status());

        // Follow-up GET shows empty list
        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/events/' . $id . '/registrations',
            $this->token,
        );
        $body = json_decode($listResp->body(), true);
        $this->assertIsArray($body);
        $this->assertCount(0, $body['items'] ?? []);
    }
}
