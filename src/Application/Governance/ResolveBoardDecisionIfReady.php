<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;

final class ResolveBoardDecisionIfReady
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly BoardDecisionResolutionService $service,
        private readonly BoardDecisionExecutorRegistry $executors,
    ) {}

    public function execute(BoardDecisionId $decisionId, \DateTimeImmutable $at): void
    {
        $decision = $this->decisions->find($decisionId);
        if ($decision === null || $decision->status !== BoardDecisionStatus::Pending) return;

        $active = $this->members->listActiveForBoard($decision->boardId, $at);
        $activeIds = [];
        $chairId = null;
        foreach ($active as $m) {
            $activeIds[$m->id->value()] = true;
            if ($m->role === BoardMemberRole::Chair) $chairId = $m->id->value();
        }

        $yes = 0; $no = 0; $abstain = 0; $chairVoted = false;
        foreach ($this->votes->listForDecision($decisionId) as $v) {
            // Ignore votes from non-active members (defensive — should not happen).
            if (!isset($activeIds[$v->boardMemberId->value()])) continue;
            match ($v->vote) {
                BoardDecisionVoteValue::Yes     => $yes++,
                BoardDecisionVoteValue::No      => $no++,
                BoardDecisionVoteValue::Abstain => $abstain++,
            };
            if ($v->boardMemberId->value() === $chairId) $chairVoted = true;
        }

        $next = $this->service->resolve(
            threshold:   $decision->threshold,
            activeCount: count($active),
            yes:         $yes,
            no:          $no,
            abstain:     $abstain,
            chairVoted:  $chairVoted,
        );
        if ($next === BoardDecisionStatus::Pending) return;

        $resolved = $decision->withStatus($next, resolvedAt: $at);
        $this->decisions->save($resolved);

        if ($next === BoardDecisionStatus::Passed) {
            $this->executors->for($resolved->decisionType)->execute($resolved, $at);
        }
    }
}
