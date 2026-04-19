<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

final class AuthToken
{
    private function __construct(
        private readonly AuthTokenId $id,
        private readonly string $tokenHash,
        private readonly UserId $userId,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $revokedAt,
        private readonly ?string $userAgent,
        private readonly ?string $ip,
    ) {
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('AuthToken expiresAt must be after issuedAt');
        }
        if ($lastUsedAt < $issuedAt) {
            throw new InvalidArgumentException('AuthToken lastUsedAt cannot precede issuedAt');
        }
        if ($revokedAt !== null && $revokedAt < $issuedAt) {
            throw new InvalidArgumentException('AuthToken revokedAt cannot precede issuedAt');
        }
    }

    /**
     * Create a brand-new token (login path).
     * lastUsedAt = issuedAt; not yet revoked.
     */
    public static function issue(
        AuthTokenId $id,
        string $tokenHash,
        UserId $userId,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        ?string $userAgent,
        ?string $ip,
    ): self {
        return new self($id, $tokenHash, $userId, $issuedAt, $issuedAt, $expiresAt, null, $userAgent, $ip);
    }

    /**
     * Rehydrate a token from persisted storage — no invariants beyond the ctor's.
     */
    public static function fromPersistence(
        AuthTokenId $id,
        string $tokenHash,
        UserId $userId,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
        ?string $userAgent,
        ?string $ip,
    ): self {
        return new self($id, $tokenHash, $userId, $issuedAt, $lastUsedAt, $expiresAt, $revokedAt, $userAgent, $ip);
    }

    public function id(): AuthTokenId { return $this->id; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function userId(): UserId { return $this->userId; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function lastUsedAt(): DateTimeImmutable { return $this->lastUsedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function userAgent(): ?string { return $this->userAgent; }
    public function ip(): ?string { return $this->ip; }

    public function isValidAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        return $now < $this->expiresAt;
    }
}
