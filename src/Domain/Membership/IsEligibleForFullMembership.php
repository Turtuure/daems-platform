<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

final class IsEligibleForFullMembership
{
    public function check(
        MembershipType $type,
        string $membershipStatus,
        ?\DateTimeImmutable $membershipStartedAt,
        \DateTimeImmutable $now,
    ): bool {
        if ($type !== MembershipType::Basic)        return false;
        if ($membershipStatus !== 'active')         return false;
        if ($membershipStartedAt === null)          return false;
        $twelveAgo = $now->modify('-12 months');
        return $membershipStartedAt <= $twelveAgo;
    }
}
