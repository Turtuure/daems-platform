<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class DraftAnnualFeeScheduleInput
{
    /**
     * @param array<string,int> $fees Map fee_type-value (SUPPORTING|BASIC|FULL) → amount_cents.
     *                                HONORARY MUST NOT appear (§ 3).
     */
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly int        $year,
        public readonly array      $fees,
    ) {}
}
