<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;

final class InMemoryBoardMemberRepository implements BoardMemberRepositoryInterface
{
    /** @var array<string, BoardMember> keyed by member id */
    private array $byId = [];

    public function listForBoard(BoardId $boardId): array
    {
        $out = [];
        foreach ($this->byId as $m) {
            if ($m->boardId->value() === $boardId->value()) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array
    {
        $out = [];
        foreach ($this->byId as $m) {
            if ($m->boardId->value() === $boardId->value() && $m->isActive($at)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public function find(BoardMemberId $id): ?BoardMember
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function save(BoardMember $member): void
    {
        $this->byId[$member->id->value()] = $member;
    }
}
