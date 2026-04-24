<?php
declare(strict_types=1);

namespace Daems\Domain\Member;

interface PublicMemberRepositoryInterface
{
    /**
     * Look up a member by user.id (UUIDv7) across ALL tenants.
     * Returns null when no user has that id or the user is soft-deleted.
     */
    public function findByUserId(string $userId): ?PublicMemberProfile;
}
