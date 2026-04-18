<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support\Fake;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryAuthTokenRepositoryTest extends TestCase
{
    private function make(string $hash = 'h'): AuthToken
    {
        return new AuthToken(
            AuthTokenId::generate(),
            $hash,
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        );
    }

    public function testStoreAndFind(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make('abc');
        $r->store($t);
        $this->assertSame($t, $r->findByHash('abc'));
        $this->assertNull($r->findByHash('missing'));
    }

    public function testTouchLastUsedAdvancesExpiry(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make();
        $r->store($t);
        $r->touchLastUsed(
            $t->id(),
            new DateTimeImmutable('2026-04-20T00:00:00Z'),
            new DateTimeImmutable('2026-04-27T00:00:00Z'),
        );
        $updated = $r->findByHash($t->tokenHash());
        $this->assertNotNull($updated);
        $this->assertSame('2026-04-27T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }

    public function testRevoke(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make();
        $r->store($t);
        $r->revoke($t->id(), new DateTimeImmutable('2026-04-20T00:00:00Z'));
        $this->assertNotNull($r->findByHash($t->tokenHash())->revokedAt());
    }

    public function testRevokeByHash(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make('abc');
        $r->store($t);
        $r->revokeByHash('abc', new DateTimeImmutable('2026-04-20T00:00:00Z'));
        $this->assertNotNull($r->findByHash('abc')->revokedAt());
    }
}
