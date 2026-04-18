<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F006_ErrorSanitisationTest extends TestCase
{
    public function testDuplicateEmailOracleIsClosed(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $alice = $h->seedUser('alice@x.com');
        $bob = $h->seedUser('bob@x.com');
        $token = $h->tokenFor($bob);

        $resp = $h->authedRequest('POST', "/api/v1/users/{$bob->id()->value()}", $token, [
            'first_name' => 'Bob',
            'last_name'  => '',
            'email'      => 'alice@x.com',
            'dob'        => '1985-05-15',
            'country'    => 'US',
            'address_street'  => '',
            'address_zip'     => '',
            'address_city'    => '',
            'address_country' => '',
        ]);

        $body = $resp->body();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Duplicate entry', $body);
        $this->assertStringNotContainsString('alice@x.com', $body);
    }

    public function testKernelReturnsGenericInternalError(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'), debug: false);
        // Force a 500 by passing bad UUID to an endpoint that does UUID parsing
        $u = $h->seedUser('u@x.com');
        $token = $h->tokenFor($u);

        $resp = $h->authedRequest('POST', '/api/v1/users/not-a-uuid/delete', $token);
        $this->assertSame(500, $resp->status());
        $body = $resp->body();
        $this->assertStringContainsString('Internal server error', $body);
        $this->assertStringNotContainsString('Invalid UUID', $body);
    }

    public function testDebugModeRevealsDetails(): void
    {
        $h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'), debug: true);
        $u = $h->seedUser('u@x.com');
        $token = $h->tokenFor($u);

        $resp = $h->authedRequest('POST', '/api/v1/users/not-a-uuid/delete', $token);
        $this->assertSame(500, $resp->status());
        $this->assertStringContainsString('Invalid UUID', $resp->body());
    }
}
