<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class ProjectsStatsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-projectsstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/projects/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        // 4 KPI keys present.
        self::assertArrayHasKey('active',            $body['data']);
        self::assertArrayHasKey('drafts',            $body['data']);
        self::assertArrayHasKey('featured',          $body['data']);
        self::assertArrayHasKey('pending_proposals', $body['data']);

        // Each KPI has {value, sparkline} shape, value is int, sparkline is array.
        foreach (['active', 'drafts', 'featured', 'pending_proposals'] as $key) {
            self::assertArrayHasKey('value',     $body['data'][$key], "$key.value missing");
            self::assertArrayHasKey('sparkline', $body['data'][$key], "$key.sparkline missing");
            self::assertIsInt($body['data'][$key]['value'], "$key.value should be int");
            self::assertIsArray($body['data'][$key]['sparkline'], "$key.sparkline should be array");
        }

        // active / drafts / pending_proposals carry 30-entry BACKWARD sparklines.
        self::assertCount(30, $body['data']['active']['sparkline']);
        self::assertCount(30, $body['data']['drafts']['sparkline']);
        self::assertCount(30, $body['data']['pending_proposals']['sparkline']);

        // featured has NO temporal series — curation toggle, not a time-bucketed
        // metric. Pin the empty array to catch any future regression that
        // accidentally adds a sparkline here.
        self::assertSame([], $body['data']['featured']['sparkline']);

        // KernelHarness seeds no project rows in setUp, so all 4 values must be 0.
        // This pins value-threading from use case to controller envelope.
        self::assertSame(0, $body['data']['active']['value']);
        self::assertSame(0, $body['data']['drafts']['value']);
        self::assertSame(0, $body['data']['featured']['value']);
        self::assertSame(0, $body['data']['pending_proposals']['value']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member      = $this->h->seedUser('member-projectsstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/projects/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
