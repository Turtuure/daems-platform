<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\User\UserId;

final class BoardMember
{
    public function __construct(
        public readonly BoardMemberId $id,
        public readonly BoardId $boardId,
        public readonly UserId $userId,
        public readonly BoardMemberRole $role,
        public readonly \DateTimeImmutable $termStartedAt,
        public readonly \DateTimeImmutable $termEndsAt,
        public readonly ?\DateTimeImmutable $termEndedAt,
        public readonly ?BoardMemberTermEndedReason $termEndedReason,
    ) {}

    public function isActive(\DateTimeImmutable $at): bool
    {
        if ($this->termEndedAt !== null)             return false;
        if ($at < $this->termStartedAt)              return false;
        if ($at >= $this->termEndsAt)                return false;
        return true;
    }
}
