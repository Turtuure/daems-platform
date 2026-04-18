<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F002_UnauthUpdateTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    private function updatePayload(string $email = 'new@x.com'): array
    {
        return [
            'first_name'      => 'Bob',
            'last_name'       => 'Hijacked',
            'email'           => $email,
            'dob'             => '1985-05-15',
            'country'         => 'US',
            'address_street'  => '',
            'address_zip'     => '',
            'address_city'    => '',
            'address_country' => '',
        ];
    }

    public function testAnonymousUpdateReturns401(): void
    {
        $victim = $this->h->seedUser('victim@x.com');
        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}", $this->updatePayload());
        $this->assertSame(401, $resp->status());
    }

    public function testNonOwnerTokenReturns403(): void
    {
        $victim = $this->h->seedUser('victim@x.com');
        $attacker = $this->h->seedUser('attacker@x.com');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$victim->id()->value()}", $token, $this->updatePayload());
        $this->assertSame(403, $resp->status());
    }

    public function testSelfCanUpdate(): void
    {
        $u = $this->h->seedUser('self@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$u->id()->value()}", $token, $this->updatePayload('newself@x.com'));
        $this->assertSame(200, $resp->status());
    }

    public function testDuplicateEmailReturnsGenericError(): void
    {
        $alice = $this->h->seedUser('alice@x.com');
        $bob = $this->h->seedUser('bob@x.com');
        $bobToken = $this->h->tokenFor($bob);

        // Bob tries to take Alice's email
        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$bob->id()->value()}", $bobToken, $this->updatePayload('alice@x.com'));

        $body = $resp->body();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Duplicate', $body);
        $this->assertStringNotContainsString('alice@x.com', $body);
        $this->assertStringContainsString('Invalid email', $body);
    }
}
