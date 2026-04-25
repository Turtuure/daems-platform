<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * Shape-only E2E for /api/v1/backstage/notifications/stats.
 *
 * KernelHarness wires the use case against the 4 InMemory* fakes for the
 * source repos (member apps, supporter apps, project proposals, forum
 * reports). Per the Phase 7 review, those fakes return zero-stub
 * implementations of `notificationStatsForTenant` + `clearedDailyForTenant`,
 * so every KPI value here is 0. We pin the SHAPE only — value-level
 * assertions live in the isolation test against real SQL.
 */
final class NotificationsStatsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-notifstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/notifications/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        // 4 KPI keys present.
        self::assertArrayHasKey('pending_you',      $body['data']);
        self::assertArrayHasKey('pending_all',      $body['data']);
        self::assertArrayHasKey('cleared_30d',      $body['data']);
        self::assertArrayHasKey('oldest_pending_d', $body['data']);

        // Each KPI has {value, sparkline} shape, value is int, sparkline is array.
        foreach (['pending_you', 'pending_all', 'cleared_30d', 'oldest_pending_d'] as $key) {
            self::assertArrayHasKey('value',     $body['data'][$key], "$key.value missing");
            self::assertArrayHasKey('sparkline', $body['data'][$key], "$key.sparkline missing");
            self::assertIsInt($body['data'][$key]['value'], "$key.value should be int");
            self::assertIsArray($body['data'][$key]['sparkline'], "$key.sparkline should be array");
        }

        // pending_you / pending_all / cleared_30d carry 30-entry BACKWARD daily series.
        self::assertCount(30, $body['data']['pending_you']['sparkline']);
        self::assertCount(30, $body['data']['pending_all']['sparkline']);
        self::assertCount(30, $body['data']['cleared_30d']['sparkline']);

        // oldest_pending_d has NO temporal series — it is a max-age scalar, not a
        // time-bucketed metric. Pin the empty array to catch any future regression
        // that accidentally adds a sparkline here.
        self::assertSame([], $body['data']['oldest_pending_d']['sparkline']);

        // KernelHarness InMemory fakes return zero-stubs for notificationStatsForTenant
        // and clearedDailyForTenant — all 4 values must be 0. This pins value-threading
        // from use case to controller envelope.
        self::assertSame(0, $body['data']['pending_you']['value']);
        self::assertSame(0, $body['data']['pending_all']['value']);
        self::assertSame(0, $body['data']['cleared_30d']['value']);
        self::assertSame(0, $body['data']['oldest_pending_d']['value']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member      = $this->h->seedUser('member-notifstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/notifications/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
