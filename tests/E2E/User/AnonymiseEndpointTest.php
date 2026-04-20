<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\User;

use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class AnonymiseEndpointTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T10:00:00Z'));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $victim = $this->h->seedUser('victim@x.com');
        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}/anonymise");
        $this->assertSame(401, $resp->status());
    }

    public function test_non_owner_non_admin_returns_403(): void
    {
        $victim = $this->h->seedUser('victim2@x.com');
        $attacker = $this->h->seedUser('attacker@x.com', 'pass1234', 'member');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$victim->id()->value()}/anonymise", $token);
        $this->assertSame(403, $resp->status());
    }

    public function test_self_anonymise_returns_204(): void
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

    public function test_admin_can_anonymise_other_user(): void
    {
        $victim = $this->h->seedUser('victim3@x.com', 'pass1234', 'member');
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$victim->id()->value()}/anonymise", $token);
        $this->assertSame(204, $resp->status());

        $stored = $this->h->users->findById($victim->id()->value());
        $this->assertSame('Anonyymi', $stored?->name());
    }

    public function test_already_anonymised_returns_422(): void
    {
        $u = $this->h->seedUser('already-anon@x.com');
        // Manually mark as anonymised
        $this->h->users->anonymise($u->id()->value(), new \DateTimeImmutable('2026-04-01'));
        $token = $this->h->tokenFor($u);

        // After anonymise the old token won't be there any more — use a fresh user for the acting request
        // Actually the user's token was wiped, so we need an admin to attempt it
        $admin = $this->h->seedUser('admin2@x.com', 'adminpass', 'admin');
        $adminToken = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$u->id()->value()}/anonymise", $adminToken);
        $this->assertSame(422, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertSame('validation_failed', $body['error'] ?? null);
    }

    public function test_nonexistent_user_returns_404(): void
    {
        $admin = $this->h->seedUser('admin3@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);
        $fakeId = UserId::generate()->value();

        $resp = $this->h->authedRequest('POST', "/api/v1/users/{$fakeId}/anonymise", $token);
        $this->assertSame(404, $resp->status());
    }
}
