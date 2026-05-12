<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

final class BoardDecisionVote
{
    public function __construct(
        public readonly BoardDecisionVoteId $id,
        public readonly BoardDecisionId $decisionId,
        public readonly BoardMemberId $boardMemberId,
        public readonly BoardDecisionVoteValue $vote,
        public readonly \DateTimeImmutable $castAt,
    ) {}
}
