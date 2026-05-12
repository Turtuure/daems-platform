<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardDecisionVoteRepositoryInterface
{
    /** @return list<BoardDecisionVote> */
    public function listForDecision(BoardDecisionId $decisionId): array;

    public function findByMember(BoardDecisionId $decisionId, BoardMemberId $memberId): ?BoardDecisionVote;

    public function upsert(BoardDecisionVote $vote): void;
}
