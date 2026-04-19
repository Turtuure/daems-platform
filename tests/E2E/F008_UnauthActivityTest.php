<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F008_UnauthActivityTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    public function testAnonymousReturns401(): void
    {
        $u = $this->h->seedUser('u@x.com');
        $resp = $this->h->request('GET', "/api/v1/users/{$u->id()->value()}/activity");
        $this->assertSame(401, $resp->status());
    }

    public function testOtherUserReturns403(): void
    {
        $target = $this->h->seedUser('target@x.com');
        $attacker = $this->h->seedUser('attacker@x.com');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$target->id()->value()}/activity", $token);
        $this->assertSame(403, $resp->status());
    }

    public function testSelfGets200(): void
    {
        $u = $this->h->seedUser('u@x.com');
        $token = $this->h->tokenFor($u);
        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$u->id()->value()}/activity", $token);
        $this->assertSame(200, $resp->status());
    }

    public function testAdminGets200(): void
    {
        $target = $this->h->seedUser('target@x.com');
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$target->id()->value()}/activity", $token);
        $this->assertSame(200, $resp->status());
    }
}
