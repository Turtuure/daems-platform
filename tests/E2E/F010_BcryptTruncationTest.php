<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F010_BcryptTruncationTest extends TestCase
{
    public function test73BytePasswordIsRejected(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $resp = $h->request('POST', '/api/v1/auth/register', [
            'name'          => 'T',
            'email'         => 'trunc@example.com',
            'password'      => str_repeat('a', 73),
            'date_of_birth' => '1990-01-01',
        ]);
        $this->assertSame(400, $resp->status());
        $this->assertStringContainsString('72 bytes', $resp->body());
    }

    public function test72BytePasswordIsAccepted(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $resp = $h->request('POST', '/api/v1/auth/register', [
            'name'          => 'T',
            'email'         => 'ok@example.com',
            'password'      => str_repeat('a', 72),
            'date_of_birth' => '1990-01-01',
        ]);
        $this->assertSame(201, $resp->status());
    }

    public function testLoginRejectsPasswordLongerThan72Bytes(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $h->seedUser('u@x.com', str_repeat('a', 72));

        // Registration with 73-byte password would previously succeed (truncated)
        // and then this 72-byte prefix would authenticate. Now both are rejected/capped.
        $resp = $h->request('POST', '/api/v1/auth/login', [
            'email'    => 'u@x.com',
            'password' => str_repeat('a', 73),
        ]);
        $this->assertSame(401, $resp->status());
    }
}
