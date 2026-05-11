<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Membership\Exception\InvalidSubTierAppliesTo;
use Daems\Domain\Tenant\TenantId;

final class TenantMembershipSubTier
{
    public function __construct(
        public readonly TenantMembershipSubTierId $id,
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly string $name,
        public readonly int $rankOrder,
        public readonly MembershipType $appliesTo,
    ) {
        if (!$appliesTo->allowsSubTier()) {
            throw new InvalidSubTierAppliesTo(
                'Sub-tier only allowed on SUPPORTING or BASIC, got: ' . $appliesTo->value
            );
        }
    }
}
