<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class BoardMemberTest extends TestCase
{
    public function test_active_when_within_term_and_no_end_marker(): void
    {
        $now = new \DateTimeImmutable('2026-05-12 10:00:00');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertTrue($m->isActive($now));
    }

    public function test_inactive_when_term_ended_at_set(): void
    {
        $now = new \DateTimeImmutable('2026-05-12');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       new \DateTimeImmutable('2026-04-01'),
            termEndedReason:   BoardMemberTermEndedReason::Resigned,
        );
        $this->assertFalse($m->isActive($now));
    }

    public function test_inactive_when_term_not_yet_started(): void
    {
        $now = new \DateTimeImmutable('2025-12-31');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Chair,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertFalse($m->isActive($now));
    }

    public function test_inactive_when_term_already_expired(): void
    {
        $now = new \DateTimeImmutable('2028-02-01');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertFalse($m->isActive($now));
    }
}
