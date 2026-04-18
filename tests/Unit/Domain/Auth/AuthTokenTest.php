<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthTokenTest extends TestCase
{
    private function make(?DateTimeImmutable $expires = null, ?DateTimeImmutable $revoked = null): AuthToken
    {
        return new AuthToken(
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
        $t = new AuthToken($id, 'hash', $userId, $issued, $issued, $issued->modify('+7 days'), null, 'ua', '1.2.3.4');

        $this->assertSame($id, $t->id());
        $this->assertSame('hash', $t->tokenHash());
        $this->assertSame($userId, $t->userId());
        $this->assertSame('ua', $t->userAgent());
        $this->assertSame('1.2.3.4', $t->ip());
    }
}
