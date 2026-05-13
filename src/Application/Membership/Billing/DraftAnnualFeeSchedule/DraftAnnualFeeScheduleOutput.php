<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

final class DraftAnnualFeeScheduleOutput
{
    /**
     * @param list<string> $scheduleIds  ID of each saved schedule row (1 per fee_type).
     * @param ?string      $decisionId   board_decisions row id when formal flow used; null for direct activation.
     */
    public function __construct(
        public readonly array   $scheduleIds,
        public readonly ?string $decisionId,
    ) {}
}
