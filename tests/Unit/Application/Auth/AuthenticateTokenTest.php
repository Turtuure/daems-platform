<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\AuthenticateToken\AuthenticateTokenInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthenticateTokenTest extends TestCase
{
    private function seedUser(InMemoryUserRepository $users, string $role = 'registered'): User
    {
        $u = new User(
            UserId::generate(),
            'Jane',
            'j@x.com',
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
            $role,
        );
        $users->save($u);
        return $u;
    }

    public function testReturnsActingUserForValidToken(): void
    {
        $users = new InMemoryUserRepository();
        $u = $this->seedUser($users);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $u->id(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');

        $out = (new AuthenticateToken($tokens, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $this->assertNull($out->error);
        $this->assertNotNull($out->actingUser);
        $this->assertSame($u->id()->value(), $out->actingUser->id->value());
        $this->assertSame('registered', $out->actingUser->role);
    }

    public function testRejectsMissingToken(): void
    {
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken(new InMemoryAuthTokenRepository(), new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('unknown'));
        $this->assertNotNull($out->error);
        $this->assertNull($out->actingUser);
    }

    public function testRejectsExpiredToken(): void
    {
        $tokens = new InMemoryAuthTokenRepository();
        $userId = UserId::generate();
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $userId,
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-08T00:00:00Z'),
            null,
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken($tokens, new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('secret'));
        $this->assertNotNull($out->error);
    }

    public function testRejectsRevokedToken(): void
    {
        $tokens = new InMemoryAuthTokenRepository();
        $userId = UserId::generate();
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T12:00:00Z'),
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken($tokens, new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('secret'));
        $this->assertNotNull($out->error);
    }

    public function testSlidingExpiryAdvancesOnSuccess(): void
    {
        $tokens = new InMemoryAuthTokenRepository();
        $users = new InMemoryUserRepository();
        $u = $this->seedUser($users);

        $issued = new DateTimeImmutable('2026-04-19T00:00:00Z');
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $u->id(),
            $issued,
            $issued,
            $issued->modify('+7 days'),
            null,
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        (new AuthenticateToken($tokens, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $updated = $tokens->findByHash(hash('sha256', 'secret'));
        $this->assertNotNull($updated);
        $this->assertSame('2026-04-27T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }

    public function testHardCapAtIssuedPlus30Days(): void
    {
        $tokens = new InMemoryAuthTokenRepository();
        $users = new InMemoryUserRepository();
        $u = $this->seedUser($users);

        $issued = new DateTimeImmutable('2026-04-01T00:00:00Z');
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $u->id(),
            $issued,
            $issued,
            $issued->modify('+30 days'),
            null,
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-28T00:00:00Z');
        (new AuthenticateToken($tokens, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $updated = $tokens->findByHash(hash('sha256', 'secret'));
        $this->assertNotNull($updated);
        $this->assertSame('2026-05-01T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }

    public function testReturnsAdminRoleForAdminUser(): void
    {
        $users = new InMemoryUserRepository();
        $u = $this->seedUser($users, 'admin');

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $u->id(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');

        $out = (new AuthenticateToken($tokens, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $this->assertTrue($out->actingUser?->isAdmin());
    }
}
