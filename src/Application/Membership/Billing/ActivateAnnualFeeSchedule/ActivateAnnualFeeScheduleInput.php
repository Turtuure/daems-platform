<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule;

use Daems\Domain\User\UserId;

final class ActivateAnnualFeeScheduleInput
{
    public function __construct(
        public readonly string $decisionId,
        public readonly UserId $activatedBy,
    ) {}
}
