<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class EventsStatsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-eventsstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/events/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        // 4 KPI keys present.
        self::assertArrayHasKey('upcoming',          $body['data']);
        self::assertArrayHasKey('drafts',            $body['data']);
        self::assertArrayHasKey('registrations_30d', $body['data']);
        self::assertArrayHasKey('pending_proposals', $body['data']);

        // Each KPI has {value, sparkline} shape, value is int, sparkline is array.
        foreach (['upcoming', 'drafts', 'registrations_30d', 'pending_proposals'] as $key) {
            self::assertArrayHasKey('value',     $body['data'][$key], "$key.value missing");
            self::assertArrayHasKey('sparkline', $body['data'][$key], "$key.sparkline missing");
            self::assertIsInt($body['data'][$key]['value'], "$key.value should be int");
            self::assertIsArray($body['data'][$key]['sparkline'], "$key.sparkline should be array");
        }

        // All 4 KPIs carry 30-entry sparklines.
        // upcoming = forward 30d (today..today+29); drafts/registrations_30d/pending_proposals = backward 30d.
        self::assertCount(30, $body['data']['upcoming']['sparkline']);
        self::assertCount(30, $body['data']['drafts']['sparkline']);
        self::assertCount(30, $body['data']['registrations_30d']['sparkline']);
        self::assertCount(30, $body['data']['pending_proposals']['sparkline']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member      = $this->h->seedUser('member-eventsstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/events/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
