<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BackstageForumStatsTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-forumstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_returns_zero_stats_when_no_forum_data(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/forum/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertSame(0, $body['data']['open_reports']['value']);
        self::assertSame(0, $body['data']['topics']['value']);
        self::assertSame(0, $body['data']['mod_actions']['value']);
        self::assertCount(30, $body['data']['mod_actions']['sparkline']);
        self::assertCount(30, $body['data']['topics']['sparkline']);
        self::assertSame([], $body['data']['categories']['sparkline']);
    }

    public function test_403_for_non_admin(): void
    {
        $member      = $this->h->seedUser('member-forumstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/forum/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
