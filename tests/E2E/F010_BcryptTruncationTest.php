<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F010_BcryptTruncationTest extends TestCase
{
    public function test73BytePasswordIsRejectedAtRegistration(): void
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

    /**
     * Full SAST PoC replay: the original F-010 chain was
     *   1. register with 73-byte password  → 201 (server silently truncated to 72)
     *   2. login with 73-byte password     → 200
     *   3. login with the 72-byte prefix   → 200 (bcrypt treats both as identical)
     * With the cap in place:
     *   1. register with 73 bytes          → 400
     *   2. login with 73 bytes             → 401 (rejected before verify)
     *   3. login with the 72-byte prefix   → 401 (no account exists)
     * All three steps run against the real /auth/register + /auth/login routes.
     */
    public function testFullSastChainIsBlockedAtEveryStep(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $p73 = str_repeat('a', 73);
        $p72 = str_repeat('a', 72);

        // Step 1: registration must reject 73 bytes.
        $reg = $h->request('POST', '/api/v1/auth/register', [
            'name' => 'T', 'email' => 'trunc@example.com',
            'password' => $p73, 'date_of_birth' => '1990-01-01',
        ]);
        $this->assertSame(400, $reg->status(), 'register-73 must be 400');

        // Step 2: login with 73 bytes must reject (no account exists AND cap rejects).
        $login73 = $h->request('POST', '/api/v1/auth/login', [
            'email' => 'trunc@example.com', 'password' => $p73,
        ]);
        $this->assertSame(401, $login73->status(), 'login-73 must be 401');

        // Step 3: login with 72-byte prefix must reject (no account exists).
        $login72 = $h->request('POST', '/api/v1/auth/login', [
            'email' => 'trunc@example.com', 'password' => $p72,
        ]);
        $this->assertSame(401, $login72->status(), 'login-72 must be 401 (no account)');
    }
}
