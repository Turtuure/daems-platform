<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ProposeApproveBasicInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $applicationId,
        public readonly UserId $proposedByUserId,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly \DateTimeImmutable $at,
    ) {}
}
