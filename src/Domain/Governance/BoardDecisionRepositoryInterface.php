<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardDecisionRepositoryInterface
{
    public function find(BoardDecisionId $id): ?BoardDecision;

    /** @return list<BoardDecision> */
    public function listForBoard(BoardId $boardId, ?BoardDecisionStatus $status = null, ?BoardDecisionType $type = null): array;

    /** @return list<BoardDecision> */
    public function listExpiredPending(\DateTimeImmutable $at, int $limit = 100): array;

    public function save(BoardDecision $decision): void;
}
