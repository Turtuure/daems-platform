<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardMemberRepositoryInterface
{
    /** @return list<BoardMember> */
    public function listForBoard(BoardId $boardId): array;

    /** @return list<BoardMember> */
    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array;

    public function find(BoardMemberId $id): ?BoardMember;

    public function save(BoardMember $member): void;
}
