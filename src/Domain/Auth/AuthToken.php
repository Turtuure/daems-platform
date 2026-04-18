<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class AuthToken
{
    public function __construct(
        private readonly AuthTokenId $id,
        private readonly string $tokenHash,
        private readonly UserId $userId,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $revokedAt,
        private readonly ?string $userAgent,
        private readonly ?string $ip,
    ) {}

    public function id(): AuthTokenId
    {
        return $this->id;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function issuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function lastUsedAt(): DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function isValidAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        return $now < $this->expiresAt;
    }
}
