<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeScheduleInput;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;

/**
 * Fires when a board_decisions row of decisionType=AnnualFeeSchedule reaches
 * Passed. Invokes ActivateAnnualFeeSchedule using the decision id (reverse-FK
 * link to the proposed AnnualFeeSchedule rows) and the proposer as the
 * audit actor.
 */
final class AnnualFeeScheduleExecutor implements BoardDecisionExecutorInterface
{
    public function __construct(
        private readonly ActivateAnnualFeeSchedule $activate,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::AnnualFeeSchedule;
    }

    public function execute(BoardDecision $decision, \DateTimeImmutable $at): void
    {
        $this->activate->handle(new ActivateAnnualFeeScheduleInput(
            decisionId:  $decision->id->value(),
            activatedBy: $decision->proposedByUserId,
        ));
    }
}
