<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;

final class InMemoryUserInviteRepository implements UserInviteRepositoryInterface
{
    /** @var array<string, UserInvite> */
    private array $byId = [];

    public function save(UserInvite $invite): void
    {
        $this->byId[$invite->id] = $invite;
    }

    public function findByTokenHash(string $tokenHash): ?UserInvite
    {
        foreach ($this->byId as $invite) {
            if ($invite->tokenHash === $tokenHash) {
                return $invite;
            }
        }
        return null;
    }

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void
    {
        if (!isset($this->byId[$inviteId])) {
            return;
        }
        $old = $this->byId[$inviteId];
        $this->byId[$inviteId] = new UserInvite(
            $old->id, $old->userId, $old->tenantId, $old->tokenHash,
            $old->issuedAt, $old->expiresAt, $usedAt
        );
    }
}
