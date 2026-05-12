<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ProposeRevokeSubTierInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $targetUserId,
        public readonly UserId $proposedByUserId,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly string $reason,
        public readonly string $meetingReference,
        public readonly \DateTimeImmutable $at,
    ) {}
}
