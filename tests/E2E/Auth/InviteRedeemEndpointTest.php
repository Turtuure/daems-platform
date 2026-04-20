<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Auth;

use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Application\Invite\IssueInvite\IssueInviteInput;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class InviteRedeemEndpointTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    private function seedUserWithoutPassword(): User
    {
        // Seed a user with no password (awaiting invite redemption)
        $u = new User(
            UserId::generate(),
            'Invite User',
            'invite-redeem@x.com',
            null,   // no password yet
            '1995-01-01',
        );
        $this->h->users->save($u);
        return $u;
    }

    private function issueInviteForUser(User $user): string
    {
        $issueInvite = $this->h->container->make(IssueInvite::class);
        $out = $issueInvite->execute(new IssueInviteInput(
            $user->id()->value(),
            $this->h->testTenantId->value(),
        ));
        return $out->rawToken;
    }

    public function test_valid_redeem_returns_user_payload(): void
    {
        $user  = $this->seedUserWithoutPassword();
        $token = $this->issueInviteForUser($user);

        $resp = $this->h->request('POST', '/api/v1/auth/invites/redeem', [
            'token'    => $token,
            'password' => 'validpass123',
        ]);

        $this->assertSame(200, $resp->status());

        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $data = $body['data'] ?? [];
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('token', $data);
        $this->assertSame($user->id()->value(), $data['user']['id'] ?? null);
    }

    public function test_second_redeem_with_same_token_returns_422_invite_used(): void
    {
        $user  = $this->seedUserWithoutPassword();
        $token = $this->issueInviteForUser($user);

        // First redeem
        $resp1 = $this->h->request('POST', '/api/v1/auth/invites/redeem', [
            'token'    => $token,
            'password' => 'validpass123',
        ]);
        $this->assertSame(200, $resp1->status());

        // Second redeem with same token
        $resp2 = $this->h->request('POST', '/api/v1/auth/invites/redeem', [
            'token'    => $token,
            'password' => 'anotherpass456',
        ]);
        $this->assertSame(422, $resp2->status());

        $body = json_decode($resp2->body(), true);
        $this->assertIsArray($body);
        $errors = $body['errors'] ?? [];
        $this->assertSame('invite_used', $errors['token'] ?? null);
    }
}
