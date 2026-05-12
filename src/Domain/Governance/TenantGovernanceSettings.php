<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

final class TenantGovernanceSettings
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly int $expulsionHearingDays,
        public readonly int $decisionExpirationDays,
    ) {
        if ($expulsionHearingDays < 1) {
            throw new \InvalidArgumentException('expulsionHearingDays must be >= 1');
        }
        if ($decisionExpirationDays < 1) {
            throw new \InvalidArgumentException('decisionExpirationDays must be >= 1');
        }
    }
}
