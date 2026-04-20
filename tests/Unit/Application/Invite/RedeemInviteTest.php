<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Invite;

use Daems\Application\Auth\RedeemInvite\RedeemInvite;
use Daems\Application\Auth\RedeemInvite\RedeemInviteInput;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserInviteRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RedeemInviteTest extends TestCase
{
    private const USER_ID    = '01958000-0000-7000-8000-000000000050';
    private const TENANT_ID  = '01958000-0000-7000-8000-000000000001';
    private const INVITE_ID  = '01958000-0000-7000-8000-000000000060';
    private const RAW_TOKEN  = 'my-raw-test-token-abc123';

    private function makeNow(string $datetime = '2026-04-20 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime);
    }

    private function makeClock(string $datetime = '2026-04-20 12:00:00'): Clock
    {
        return new class($datetime) implements Clock {
            public function __construct(private readonly string $dt) {}
            public function now(): DateTimeImmutable { return new DateTimeImmutable($this->dt); }
        };
    }

    private function seedUser(InMemoryUserRepository $users): User
    {
        $user = new User(
            UserId::fromString(self::USER_ID),
            'Invite User',
            'invite@example.com',
            null, // no password yet
            null,
        );
        $users->save($user);
        return $user;
    }

    private function seedInvite(
        InMemoryUserInviteRepository $invites,
        string $rawToken = self::RAW_TOKEN,
        ?DateTimeImmutable $usedAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $token = InviteToken::fromRaw($rawToken);
        $invite = new UserInvite(
            self::INVITE_ID,
            self::USER_ID,
            self::TENANT_ID,
            $token->hash,
            new DateTimeImmutable('2026-04-20 11:00:00'),
            $expiresAt ?? new DateTimeImmutable('2026-04-27 12:00:00'),
            $usedAt,
        );
        $invites->save($invite);
    }

    public function test_valid_token_sets_password_and_marks_used(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();

        $this->seedUser($users);
        $this->seedInvite($invites);

        $sut = new RedeemInvite($invites, $users, $this->makeClock());
        $out = $sut->execute(new RedeemInviteInput(self::RAW_TOKEN, 'password123'));

        self::assertSame(self::USER_ID, $out->user->id()->value());
        self::assertNotNull($out->user->passwordHash());
        self::assertTrue(password_verify('password123', (string) $out->user->passwordHash()));

        // Invite should be marked used
        $token  = InviteToken::fromRaw(self::RAW_TOKEN);
        $invite = $invites->findByTokenHash($token->hash);
        self::assertNotNull($invite);
        self::assertNotNull($invite->usedAt);
    }

    public function test_used_invite_throws_invite_used(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();

        $this->seedUser($users);
        $this->seedInvite($invites, usedAt: new DateTimeImmutable('2026-04-20 11:30:00'));

        $sut = new RedeemInvite($invites, $users, $this->makeClock());

        try {
            $sut->execute(new RedeemInviteInput(self::RAW_TOKEN, 'password123'));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['token' => 'invite_used'], $e->fields());
        }
    }

    public function test_expired_invite_throws_invite_expired(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();

        $this->seedUser($users);
        // Expires in the past
        $this->seedInvite($invites, expiresAt: new DateTimeImmutable('2026-04-19 12:00:00'));

        $sut = new RedeemInvite($invites, $users, $this->makeClock());

        try {
            $sut->execute(new RedeemInviteInput(self::RAW_TOKEN, 'password123'));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['token' => 'invite_expired'], $e->fields());
        }
    }

    public function test_unknown_token_throws_invite_invalid(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();
        // No invite seeded

        $sut = new RedeemInvite($invites, $users, $this->makeClock());

        try {
            $sut->execute(new RedeemInviteInput('nonexistent-token', 'password123'));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['token' => 'invite_invalid'], $e->fields());
        }
    }

    public function test_password_too_short_throws(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();

        $sut = new RedeemInvite($invites, $users, $this->makeClock());

        try {
            $sut->execute(new RedeemInviteInput(self::RAW_TOKEN, 'short'));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['password' => 'password_too_short'], $e->fields());
        }
    }

    public function test_password_too_long_throws(): void
    {
        $users   = new InMemoryUserRepository();
        $invites = new InMemoryUserInviteRepository();

        $sut = new RedeemInvite($invites, $users, $this->makeClock());

        try {
            $sut->execute(new RedeemInviteInput(self::RAW_TOKEN, str_repeat('a', 73)));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['password' => 'password_too_long'], $e->fields());
        }
    }
}
