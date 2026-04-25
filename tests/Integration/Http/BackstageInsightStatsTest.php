<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BackstageInsightStatsTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-stats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_returns_zero_stats_when_no_insights(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/insights/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertSame(0, $body['data']['published']['value']);
        self::assertSame(0, $body['data']['scheduled']['value']);
        self::assertSame(0, $body['data']['featured']['value']);
        self::assertCount(30, $body['data']['published']['sparkline']);
    }

    public function test_counts_published_and_scheduled_correctly(): void
    {
        $today    = '2026-04-25';  // matches the FrozenClock date
        $tomorrow = '2026-04-26';

        $this->h->insights->save($this->makeInsight('pub-a', $today, false));
        $this->h->insights->save($this->makeInsight('sched-b', $tomorrow, false));

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/insights/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertSame(1, $body['data']['published']['value']);
        self::assertSame(1, $body['data']['scheduled']['value']);
        self::assertSame(0, $body['data']['featured']['value']);
    }

    public function test_403_for_non_admin(): void
    {
        $member      = $this->h->seedUser('member-stats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/insights/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }

    private function makeInsight(string $slug, string $date, bool $featured): Insight
    {
        return new Insight(
            id:            InsightId::generate(),
            tenantId:      $this->h->testTenantId,
            slug:          $slug,
            title:         'T-' . $slug,
            category:      'c',
            categoryLabel: 'C',
            featured:      $featured,
            date:          $date,
            author:        'a',
            readingTime:   1,
            excerpt:       'x',
            heroImage:     null,
            tags:          [],
            content:       '<p>y</p>',
        );
    }
}
