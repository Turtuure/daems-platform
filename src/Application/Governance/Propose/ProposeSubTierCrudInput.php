<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ProposeSubTierCrudInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly BoardDecisionSubTierCrudOperation $operation,
        public readonly string $subTierSlug,
        public readonly ?string $name,
        public readonly ?int $rankOrder,
        public readonly MembershipType $appliesTo,
        public readonly UserId $proposedByUserId,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly string $meetingReference,
        public readonly \DateTimeImmutable $at,
    ) {}
}
