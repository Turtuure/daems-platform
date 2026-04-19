<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use DateTimeImmutable;

final class InMemoryAuthTokenRepository implements AuthTokenRepositoryInterface
{
    /** @var array<string, AuthToken> keyed by hash */
    public array $byHash = [];

    public function store(AuthToken $token): void
    {
        $this->byHash[$token->tokenHash()] = $token;
    }

    public function findByHash(string $hash): ?AuthToken
    {
        return $this->byHash[$hash] ?? null;
    }

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void
    {
        foreach ($this->byHash as $hash => $t) {
            if ($t->id()->value() === $id->value()) {
                $this->byHash[$hash] = AuthToken::fromPersistence(
                    $t->id(),
                    $t->tokenHash(),
                    $t->userId(),
                    $t->issuedAt(),
                    $now,
                    $newExpiry,
                    $t->revokedAt(),
                    $t->userAgent(),
                    $t->ip(),
                );
                return;
            }
        }
    }

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void
    {
        foreach ($this->byHash as $hash => $t) {
            if ($t->id()->value() === $id->value()) {
                $this->replace($hash, $t, $at);
                return;
            }
        }
    }

    public function revokeByHash(string $hash, DateTimeImmutable $at): void
    {
        if (isset($this->byHash[$hash])) {
            $this->replace($hash, $this->byHash[$hash], $at);
        }
    }

    private function replace(string $hash, AuthToken $t, DateTimeImmutable $revokedAt): void
    {
        $this->byHash[$hash] = AuthToken::fromPersistence(
            $t->id(),
            $t->tokenHash(),
            $t->userId(),
            $t->issuedAt(),
            $t->lastUsedAt(),
            $t->expiresAt(),
            $revokedAt,
            $t->userAgent(),
            $t->ip(),
        );
    }
}
