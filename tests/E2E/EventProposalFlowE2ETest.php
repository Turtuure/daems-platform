<?php
declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Tenant\UserTenantRole;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class EventProposalFlowE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-21T12:00:00Z'));
    }

    public function test_member_submit_and_admin_approve(): void
    {
        $member = $this->h->seedUser('member@x.com');
        $admin = $this->h->seedUser('admin@x.com');
        $this->h->userTenants->attach($admin->id(), $this->h->testTenantId, UserTenantRole::Admin);

        $memberToken = $this->h->tokenFor($member);
        $adminToken = $this->h->tokenFor($admin);

        // Member submits
        $resp = $this->h->authedRequest('POST', '/api/v1/event-proposals', $memberToken, [
            'title'       => 'Community Meetup',
            'event_date'  => '2026-09-01',
            'event_time'  => '18:00',
            'location'    => 'Helsinki',
            'is_online'   => false,
            'description' => 'A big meetup for our community to celebrate.',
            'source_locale' => 'sw_TZ',
        ]);
        $this->assertSame(201, $resp->status());
        $submitData = json_decode($resp->body(), true);
        $this->assertIsArray($submitData);
        $proposalId = $submitData['data']['id'];
        $this->assertIsString($proposalId);

        // Admin list
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/event-proposals', $adminToken);
        $this->assertSame(200, $resp->status());
        $listData = json_decode($resp->body(), true);
        $this->assertCount(1, $listData['data']);
        $this->assertSame('pending', $listData['data'][0]['status']);
        $this->assertSame('sw_TZ', $listData['data'][0]['source_locale']);

        // Admin approve
        $resp = $this->h->authedRequest('POST', "/api/v1/backstage/event-proposals/{$proposalId}/approve", $adminToken, [
            'note' => 'Great event!',
        ]);
        $this->assertSame(201, $resp->status());
        $approveData = json_decode($resp->body(), true);
        $this->assertIsArray($approveData);
        $this->assertArrayHasKey('event_id', $approveData['data']);
        $slug = $approveData['data']['slug'];

        // Member lists events with Swahili — sees the event
        $resp = $this->h->request('GET', "/api/v1/events/{$slug}", [], ['Accept-Language' => 'sw-TZ']);
        $this->assertSame(200, $resp->status());
        $eventData = json_decode($resp->body(), true);
        $this->assertSame('Community Meetup', $eventData['data']['title']);
        $this->assertFalse($eventData['data']['title_fallback']);
    }

    public function test_member_reject_flow(): void
    {
        $member = $this->h->seedUser('member2@x.com');
        $admin = $this->h->seedUser('admin2@x.com');
        $this->h->userTenants->attach($admin->id(), $this->h->testTenantId, UserTenantRole::Admin);

        $memberToken = $this->h->tokenFor($member);
        $adminToken = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/event-proposals', $memberToken, [
            'title'       => 'Rejected Proposal',
            'event_date'  => '2026-10-01',
            'description' => 'Not suitable for our community.',
            'is_online'   => true,
        ]);
        $this->assertSame(201, $resp->status());
        $proposalId = json_decode($resp->body(), true)['data']['id'];

        $resp = $this->h->authedRequest('POST', "/api/v1/backstage/event-proposals/{$proposalId}/reject", $adminToken, [
            'note' => 'Not a fit.',
        ]);
        $this->assertSame(200, $resp->status());
    }
}
