<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardMemberRepository implements BoardMemberRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForBoard(BoardId $boardId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members WHERE board_id = ? ORDER BY role DESC, term_started_at ASC'
        );
        $stmt->execute([$boardId->value()]);
        return $this->fetchAll($stmt);
    }

    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members
              WHERE board_id = ?
                AND term_ended_at IS NULL
                AND term_started_at <= ?
                AND term_ends_at > ?
              ORDER BY role DESC, term_started_at ASC'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$boardId->value(), $ts, $ts]);
        return $this->fetchAll($stmt);
    }

    public function find(BoardMemberId $id): ?BoardMember
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members WHERE id = ?'
        );
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(BoardMember $m): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_members
                (id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason)
             VALUES (:id, :bid, :uid, :role, :ts, :te, :ted, :ter)
             ON DUPLICATE KEY UPDATE
                role              = VALUES(role),
                term_ended_at     = VALUES(term_ended_at),
                term_ended_reason = VALUES(term_ended_reason)'
        );
        $stmt->execute([
            ':id'   => $m->id->value(),
            ':bid'  => $m->boardId->value(),
            ':uid'  => $m->userId->value(),
            ':role' => $m->role->value,
            ':ts'   => $m->termStartedAt->format('Y-m-d H:i:s'),
            ':te'   => $m->termEndsAt->format('Y-m-d H:i:s'),
            ':ted'  => $m->termEndedAt?->format('Y-m-d H:i:s'),
            ':ter'  => $m->termEndedReason?->value,
        ]);
    }

    /**
     * @param \PDOStatement $stmt
     * @return list<BoardMember>
     */
    private function fetchAll(\PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): BoardMember
    {
        $id   = is_string($row['id']                ?? null) ? $row['id']                : throw new \DomainException('Corrupt board_members.id');
        $bid  = is_string($row['board_id']          ?? null) ? $row['board_id']          : throw new \DomainException('Corrupt board_members.board_id');
        $uid  = is_string($row['user_id']           ?? null) ? $row['user_id']           : throw new \DomainException('Corrupt board_members.user_id');
        $role = is_string($row['role']              ?? null) ? $row['role']              : throw new \DomainException('Corrupt board_members.role');
        $ts   = is_string($row['term_started_at']   ?? null) ? $row['term_started_at']   : throw new \DomainException('Corrupt board_members.term_started_at');
        $te   = is_string($row['term_ends_at']      ?? null) ? $row['term_ends_at']      : throw new \DomainException('Corrupt board_members.term_ends_at');
        $ted  = is_string($row['term_ended_at']     ?? null) ? $row['term_ended_at']     : null;
        $ter  = is_string($row['term_ended_reason'] ?? null) ? $row['term_ended_reason'] : null;

        return new BoardMember(
            id:              BoardMemberId::fromString($id),
            boardId:         BoardId::fromString($bid),
            userId:          UserId::fromString($uid),
            role:            BoardMemberRole::from($role),
            termStartedAt:   new \DateTimeImmutable($ts),
            termEndsAt:      new \DateTimeImmutable($te),
            termEndedAt:     $ted !== null ? new \DateTimeImmutable($ted) : null,
            termEndedReason: $ter !== null ? BoardMemberTermEndedReason::from($ter) : null,
        );
    }
}
