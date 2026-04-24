<?php
declare(strict_types=1);

namespace Daems\Domain\Member;

interface PublicMemberRepositoryInterface
{
    /**
     * Look up a member by raw member_number across ALL tenants.
     * Returns null when no user has that number or the user is soft-deleted.
     */
    public function findByMemberNumber(string $memberNumber): ?PublicMemberProfile;
}
