<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F003_UnauthPIIReadTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    public function testAnonymousReadReturns401(): void
    {
        $u = $this->h->seedUser('victim@x.com');
        $resp = $this->h->request('GET', "/api/v1/users/{$u->id()->value()}");
        $this->assertSame(401, $resp->status());
    }

    public function testOtherUserSeesReducedView(): void
    {
        $u = $this->h->seedUser('victim@x.com');
        $other = $this->h->seedUser('other@x.com');
        $token = $this->h->tokenFor($other);

        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$u->id()->value()}", $token);
        $this->assertSame(200, $resp->status());

        $body = $resp->body();
        $this->assertStringNotContainsString('victim@x.com', $body);
        $this->assertStringNotContainsString('address_street', $body);
        $this->assertStringNotContainsString('"dob"', $body);
    }

    public function testSelfSeesFullView(): void
    {
        $u = $this->h->seedUser('self@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$u->id()->value()}", $token);
        $body = $resp->body();
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('self@x.com', $body);
        $this->assertStringContainsString('"dob"', $body);
    }

    public function testAdminSeesFullView(): void
    {
        $u = $this->h->seedUser('victim@x.com');
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', "/api/v1/users/{$u->id()->value()}", $token);
        $this->assertStringContainsString('victim@x.com', $resp->body());
    }
}
