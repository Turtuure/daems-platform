<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F001_UnauthDeletionTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    public function testAnonymousDeletionReturns401(): void
    {
        $victim = $this->h->seedUser('victim@x.com');
        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}/anonymise");
        $this->assertSame(401, $resp->status());
    }

    public function testNonOwnerTokenReturns403(): void
    {
        $victim = $this->h->seedUser('victim2@x.com');
        $attacker = $this->h->seedUser('attacker@x.com');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$victim->id()->value()}/anonymise", $token);
        $this->assertSame(403, $resp->status());
        $stored = $this->h->users->findById($victim->id()->value());
        $this->assertNotNull($stored);
        $this->assertNull($stored?->deletedAt());
    }

    public function testSelfCanAnonymise(): void
    {
        $u = $this->h->seedUser('self@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$u->id()->value()}/anonymise", $token);
        $this->assertSame(204, $resp->status());
        $stored = $this->h->users->findById($u->id()->value());
        $this->assertNotNull($stored);
        $this->assertSame('Anonyymi', $stored?->name());
        $this->assertNotNull($stored?->deletedAt());
    }

    public function testAdminCanAnonymiseAnyone(): void
    {
        $victim = $this->h->seedUser('victim3@x.com');
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$victim->id()->value()}/anonymise", $token);
        $this->assertSame(204, $resp->status());
        $stored = $this->h->users->findById($victim->id()->value());
        $this->assertNotNull($stored);
        $this->assertSame('Anonyymi', $stored?->name());
    }
}
