<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionVote;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardMemberId;

final class InMemoryBoardDecisionVoteRepository implements BoardDecisionVoteRepositoryInterface
{
    /** @var array<string, BoardDecisionVote> keyed by decision_id|board_member_id */
    private array $byKey = [];

    public function listForDecision(BoardDecisionId $decisionId): array
    {
        $out = [];
        foreach ($this->byKey as $v) {
            if ($v->decisionId->value() === $decisionId->value()) $out[] = $v;
        }
        usort($out, fn(BoardDecisionVote $a, BoardDecisionVote $b) => $a->castAt <=> $b->castAt);
        return $out;
    }

    public function findByMember(BoardDecisionId $decisionId, BoardMemberId $memberId): ?BoardDecisionVote
    {
        return $this->byKey[$decisionId->value() . '|' . $memberId->value()] ?? null;
    }

    public function upsert(BoardDecisionVote $vote): void
    {
        $this->byKey[$vote->decisionId->value() . '|' . $vote->boardMemberId->value()] = $vote;
    }
}
