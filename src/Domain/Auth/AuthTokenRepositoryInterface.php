<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use DateTimeImmutable;

interface AuthTokenRepositoryInterface
{
    public function store(AuthToken $token): void;

    public function findByHash(string $hash): ?AuthToken;

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void;

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void;

    public function revokeByHash(string $hash, DateTimeImmutable $at): void;

    public function revokeAllForUser(string $userId): void;
}
