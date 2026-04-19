<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F009_LoginRateLimitTest extends TestCase
{
    public function testFiveFailuresThenSixthReturns429(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $h->seedUser('user@x.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $resp = $h->request('POST', '/api/v1/auth/login', [
                'email'    => 'user@x.com',
                'password' => 'wrong',
            ]);
            $this->assertSame(401, $resp->status(), "Attempt {$i} should fail with 401");
        }

        $resp = $h->request('POST', '/api/v1/auth/login', [
            'email'    => 'user@x.com',
            'password' => 'wrong',
        ]);
        $this->assertSame(429, $resp->status());
        $this->assertSame('900', $resp->header('Retry-After'));
    }

    public function testDifferentIpNotRateLimited(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $h->seedUser('user@x.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $h->request('POST', '/api/v1/auth/login', [
                'email'    => 'user@x.com',
                'password' => 'wrong',
            ], ip: '1.1.1.1');
        }

        $resp = $h->request('POST', '/api/v1/auth/login', [
            'email'    => 'user@x.com',
            'password' => 'correct-password',
        ], ip: '2.2.2.2');
        $this->assertSame(200, $resp->status());
    }

    public function testWindowExpires(): void
    {
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $h = new KernelHarness($clock);
        $h->seedUser('user@x.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $h->request('POST', '/api/v1/auth/login', ['email' => 'user@x.com', 'password' => 'wrong']);
        }

        $blocked = $h->request('POST', '/api/v1/auth/login', ['email' => 'user@x.com', 'password' => 'correct-password']);
        $this->assertSame(429, $blocked->status());

        $clock->advance('+16 minutes');

        $ok = $h->request('POST', '/api/v1/auth/login', ['email' => 'user@x.com', 'password' => 'correct-password']);
        $this->assertSame(200, $ok->status());
    }
}
