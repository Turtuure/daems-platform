<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

interface UserInviteRepositoryInterface
{
    public function save(UserInvite $invite): void;

    public function findByTokenHash(string $tokenHash): ?UserInvite;

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void;
}
