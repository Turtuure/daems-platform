<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionVote;
use Daems\Domain\Governance\BoardDecisionVoteId;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberId;
use PDO;

final class SqlBoardDecisionVoteRepository implements BoardDecisionVoteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForDecision(BoardDecisionId $decisionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, decision_id, board_member_id, vote, cast_at
               FROM board_decision_votes WHERE decision_id = ? ORDER BY cast_at ASC'
        );
        $stmt->execute([$decisionId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function findByMember(BoardDecisionId $decisionId, BoardMemberId $memberId): ?BoardDecisionVote
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, decision_id, board_member_id, vote, cast_at
               FROM board_decision_votes WHERE decision_id = ? AND board_member_id = ?'
        );
        $stmt->execute([$decisionId->value(), $memberId->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function upsert(BoardDecisionVote $vote): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_decision_votes (id, decision_id, board_member_id, vote, cast_at)
             VALUES (:id, :did, :bm, :v, :ca)
             ON DUPLICATE KEY UPDATE
                vote    = VALUES(vote),
                cast_at = VALUES(cast_at)'
        );
        $stmt->execute([
            ':id'  => $vote->id->value(),
            ':did' => $vote->decisionId->value(),
            ':bm'  => $vote->boardMemberId->value(),
            ':v'   => $vote->vote->value,
            ':ca'  => $vote->castAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): BoardDecisionVote
    {
        $g = static function (string $k) use ($r): string {
            $v = $r[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt board_decision_votes.{$k}");
        };
        return new BoardDecisionVote(
            id:            BoardDecisionVoteId::fromString($g('id')),
            decisionId:    BoardDecisionId::fromString($g('decision_id')),
            boardMemberId: BoardMemberId::fromString($g('board_member_id')),
            vote:          BoardDecisionVoteValue::from($g('vote')),
            castAt:        new \DateTimeImmutable($g('cast_at')),
        );
    }
}
