<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionVote;
use Daems\Domain\Governance\BoardDecisionVoteId;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardMemberTermExpired;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;

final class CastBoardVote
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly ResolveBoardDecisionIfReady $resolve,
    ) {}

    public function execute(
        BoardDecisionId $decisionId,
        UserId $actingUserId,
        BoardDecisionVoteValue $vote,
        \DateTimeImmutable $at,
    ): void {
        $decision = $this->decisions->find($decisionId)
            ?? throw new \DomainException('Decision not found');
        if ($decision->status !== BoardDecisionStatus::Pending) {
            throw new DecisionAlreadyResolved("decision={$decisionId->value()} status={$decision->status->value}");
        }

        // Map actingUserId to a board_member row on the decision's board.
        $member = null;
        foreach ($this->members->listForBoard($decision->boardId) as $m) {
            if ($m->userId->equals($actingUserId)) { $member = $m; break; }
        }
        if ($member === null) {
            throw new NotABoardMember("user={$actingUserId->value()} not on board={$decision->boardId->value()}");
        }
        if (!$member->isActive($at)) {
            throw new BoardMemberTermExpired("member={$member->id->value()} not active at {$at->format('c')}");
        }

        $this->votes->upsert(new BoardDecisionVote(
            id:            BoardDecisionVoteId::generate(),
            decisionId:    $decisionId,
            boardMemberId: $member->id,
            vote:          $vote,
            castAt:        $at,
        ));

        // Re-resolve immediately after every vote.
        $this->resolve->execute($decisionId, $at);
    }
}
