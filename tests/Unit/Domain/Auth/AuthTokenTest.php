<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthTokenTest extends TestCase
{
    private function make(?DateTimeImmutable $expires = null, ?DateTimeImmutable $revoked = null): AuthToken
    {
        return AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', 'raw'),
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            $expires ?? new DateTimeImmutable('2026-04-26T00:00:00Z'),
            $revoked,
            null,
            null,
        );
    }

    public function testIsValidAtBeforeExpiry(): void
    {
        $t = $this->make(new DateTimeImmutable('2026-04-26T00:00:00Z'));
        $this->assertTrue($t->isValidAt(new DateTimeImmutable('2026-04-25T00:00:00Z')));
    }

    public function testIsNotValidAfterExpiry(): void
    {
        $t = $this->make(new DateTimeImmutable('2026-04-26T00:00:00Z'));
        $this->assertFalse($t->isValidAt(new DateTimeImmutable('2026-04-27T00:00:00Z')));
    }

    public function testIsNotValidAtExactExpiryInstant(): void
    {
        $expires = new DateTimeImmutable('2026-04-26T00:00:00Z');
        $t = $this->make($expires);
        $this->assertFalse($t->isValidAt($expires), 'at-instant-of-expiry should be invalid');
    }

    public function testIsNotValidWhenRevoked(): void
    {
        $t = $this->make(null, new DateTimeImmutable('2026-04-20T00:00:00Z'));
        $this->assertFalse($t->isValidAt(new DateTimeImmutable('2026-04-21T00:00:00Z')));
    }

    public function testAccessorsReturnConstructorValues(): void
    {
        $id = AuthTokenId::generate();
        $userId = UserId::generate();
        $issued = new DateTimeImmutable('2026-04-19T00:00:00Z');
        $t = AuthToken::fromPersistence(
            $id, 'hash', $userId, $issued, $issued, $issued->modify('+7 days'), null, 'ua', '1.2.3.4',
        );

        $this->assertSame($id, $t->id());
        $this->assertSame('hash', $t->tokenHash());
        $this->assertSame($userId, $t->userId());
        $this->assertSame('ua', $t->userAgent());
        $this->assertSame('1.2.3.4', $t->ip());
    }

    public function testIssueFactoryCreatesTokenWithLastUsedAtEqualIssuedAt(): void
    {
        $issued = new DateTimeImmutable('2026-04-19T00:00:00Z');
        $t = AuthToken::issue(
            AuthTokenId::generate(), 'h', UserId::generate(),
            $issued, $issued->modify('+7 days'), null, null,
        );
        $this->assertSame($issued, $t->lastUsedAt());
        $this->assertNull($t->revokedAt());
    }

    public function testCtorRejectsExpiresBeforeIssued(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthToken::fromPersistence(
            AuthTokenId::generate(),
            'h',
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-18T00:00:00Z'),
            null,
            null,
            null,
        );
    }

    public function testCtorRejectsLastUsedBeforeIssued(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthToken::fromPersistence(
            AuthTokenId::generate(),
            'h',
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-18T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        );
    }

    public function testCtorRejectsRevokedBeforeIssued(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthToken::fromPersistence(
            AuthTokenId::generate(),
            'h',
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            new DateTimeImmutable('2026-04-18T00:00:00Z'),
            null,
            null,
        );
    }
}
