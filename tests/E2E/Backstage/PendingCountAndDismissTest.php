<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class PendingCountAndDismissTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    private function seedPendingApp(string $name, string $email): MemberApplication
    {
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            $name, $email, '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);
        return $app;
    }

    public function test_pending_count_decreases_after_dismiss_and_dismiss_is_idempotent(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app1 = $this->seedPendingApp('Alice', 'alice@x.com');
        $app2 = $this->seedPendingApp('Bob', 'bob@x.com');
        $app3 = $this->seedPendingApp('Carol', 'carol@x.com');

        // GET pending-count → total:3
        $resp1 = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $this->assertSame(200, $resp1->status());

        $body1 = json_decode($resp1->body(), true);
        $this->assertIsArray($body1);
        $this->assertSame(3, $body1['data']['total'] ?? null);
        $this->assertCount(3, $body1['data']['items'] ?? []);

        // Dismiss one
        $dismissUrl = '/api/v1/backstage/applications/member/' . $app1->id()->value() . '/dismiss';
        $resp2 = $this->h->authedRequest('POST', $dismissUrl, $token);
        $this->assertSame(204, $resp2->status());

        // GET pending-count → total:2
        $resp3 = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $this->assertSame(200, $resp3->status());

        $body3 = json_decode($resp3->body(), true);
        $this->assertIsArray($body3);
        $this->assertSame(2, $body3['data']['total'] ?? null);
        $this->assertCount(2, $body3['data']['items'] ?? []);

        // Second dismiss is idempotent (204 again)
        $resp4 = $this->h->authedRequest('POST', $dismissUrl, $token);
        $this->assertSame(204, $resp4->status());

        // Count remains 2 (same dismissal = still hidden)
        $resp5 = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $body5 = json_decode($resp5->body(), true);
        $this->assertIsArray($body5);
        $this->assertSame(2, $body5['data']['total'] ?? null);
    }
}
