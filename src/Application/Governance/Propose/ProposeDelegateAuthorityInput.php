<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class ProposeDelegateAuthorityInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly BoardDecisionType $decisionType,
        public readonly UserTenantRole $delegatedToRole,
        public readonly UserId $proposedByUserId,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly string $meetingReference,
        public readonly \DateTimeImmutable $at,
    ) {}
}
