<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule;

use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Shared\Clock;

/**
 * Activates the proposed AnnualFeeSchedule rows tied to a board_decisions row id.
 *
 * Sequence:
 *   1. Load proposed rows by decisionId (reverse-FK lookup). If none → no-op.
 *   2. Derive (tenantId, year) from the first row (all share the same triple).
 *   3. For each {SUPPORTING, BASIC, FULL}: supersede the prior Active row for
 *      that (tenant, year, type) if one exists.
 *   4. Flip each loaded proposed row to Active with activatedAt/By set.
 */
final class ActivateAnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleRepositoryInterface $schedules,
        private readonly Clock                                $clock,
    ) {}

    public function handle(ActivateAnnualFeeScheduleInput $in): void
    {
        $proposed = $this->schedules->findProposedByDecision($in->decisionId);
        if ($proposed === []) {
            return;
        }

        $now      = $this->clock->now();
        $tenantId = $proposed[0]->tenantId();
        $year     = $proposed[0]->year();

        foreach ($proposed as $row) {
            $prev = $this->schedules->findActiveFor($tenantId, $year, $row->feeType());
            if ($prev !== null) {
                $prev->supersede($now);
                $this->schedules->save($prev);
            }
        }

        foreach ($proposed as $row) {
            $row->activate($in->activatedBy, $now);
            $this->schedules->save($row);
        }
    }
}
