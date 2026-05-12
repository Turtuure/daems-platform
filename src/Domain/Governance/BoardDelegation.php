<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

final class BoardDelegation
{
    public function __construct(
        public readonly BoardDelegationId $id,
        public readonly TenantId $tenantId,
        public readonly BoardDecisionType $decisionType,
        public readonly UserTenantRole $delegatedToRole,
        public readonly BoardDecisionId $sourceDecisionId,
        public readonly \DateTimeImmutable $validFrom,
        public readonly ?\DateTimeImmutable $revokedAt,
    ) {
        if (!$decisionType->isDelegatable()) {
            throw new DelegationNotPermittedForType(
                "Delegation not permitted for decision_type={$decisionType->value}"
            );
        }
        if ($delegatedToRole !== UserTenantRole::Admin) {
            throw new DelegationNotPermittedForType(
                "Only the admin role can be a delegatee; got {$delegatedToRole->value}"
            );
        }
    }

    public function isActive(\DateTimeImmutable $at): bool
    {
        if ($at < $this->validFrom)                  return false;
        if ($this->revokedAt !== null && $at >= $this->revokedAt) return false;
        return true;
    }
}
