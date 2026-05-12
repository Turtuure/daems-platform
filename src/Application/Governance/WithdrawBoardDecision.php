<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;

final class WithdrawBoardDecision
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardMemberRepositoryInterface $members,
    ) {}

    public function execute(
        BoardDecisionId $decisionId,
        UserId $actingUserId,
        string $withdrawalReason,
        \DateTimeImmutable $at,
    ): void {
        $decision = $this->decisions->find($decisionId)
            ?? throw new \DomainException('Decision not found');
        if ($decision->status !== BoardDecisionStatus::Pending) {
            throw new DecisionAlreadyResolved("decision={$decisionId->value()} status={$decision->status->value}");
        }
        if (trim($withdrawalReason) === '') {
            throw new \InvalidArgumentException('withdrawal_reason required');
        }

        // Permission: proposer OR active chair.
        $allowed = $decision->proposedByUserId->equals($actingUserId);
        if (!$allowed) {
            foreach ($this->members->listActiveForBoard($decision->boardId, $at) as $m) {
                if ($m->role === BoardMemberRole::Chair && $m->userId->equals($actingUserId)) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot withdraw this decision");
        }

        $this->decisions->save($decision->withStatus(
            BoardDecisionStatus::Withdrawn,
            resolvedAt: $at,
            withdrawalReason: trim($withdrawalReason),
        ));
    }
}
