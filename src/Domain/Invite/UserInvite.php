<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

final class UserInvite
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly string $tokenHash,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $usedAt = null,
    ) {}

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $now < $this->expiresAt;
    }
}
