<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;

final class InMemoryBoardDecisionRepository implements BoardDecisionRepositoryInterface
{
    /** @var array<string, BoardDecision> */
    private array $byId = [];

    public function find(BoardDecisionId $id): ?BoardDecision
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function listForBoard(BoardId $boardId, ?BoardDecisionStatus $status = null, ?BoardDecisionType $type = null): array
    {
        $out = [];
        foreach ($this->byId as $d) {
            if ($d->boardId->value() !== $boardId->value()) continue;
            if ($status !== null && $d->status !== $status) continue;
            if ($type   !== null && $d->decisionType !== $type) continue;
            $out[] = $d;
        }
        usort($out, fn(BoardDecision $a, BoardDecision $b) => $b->proposedAt <=> $a->proposedAt);
        return $out;
    }

    public function listExpiredPending(\DateTimeImmutable $at, int $limit = 100): array
    {
        $out = [];
        foreach ($this->byId as $d) {
            if ($d->status === BoardDecisionStatus::Pending && $d->expiresAt < $at) $out[] = $d;
        }
        usort($out, fn(BoardDecision $a, BoardDecision $b) => $a->expiresAt <=> $b->expiresAt);
        return array_slice($out, 0, $limit);
    }

    public function save(BoardDecision $decision): void
    {
        $this->byId[$decision->id->value()] = $decision;
    }
}
