<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\WithdrawBoardDecision;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use PHPUnit\Framework\TestCase;

final class WithdrawBoardDecisionTest extends TestCase
{
    public function test_proposer_can_withdraw(): void
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $members   = new InMemoryBoardMemberRepository();
        $uc = new WithdrawBoardDecision($decisions, $members);

        $proposer = UserId::generate();
        $id = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $id, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: $proposer,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $uc->execute($id, $proposer, 'changed my mind', new \DateTimeImmutable('2026-05-13'));
        $found = $decisions->find($id);
        $this->assertNotNull($found);
        $this->assertSame(BoardDecisionStatus::Withdrawn, $found->status);
        $this->assertSame('changed my mind', $found->withdrawalReason);
    }

    public function test_random_user_cannot_withdraw(): void
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $members   = new InMemoryBoardMemberRepository();
        $uc = new WithdrawBoardDecision($decisions, $members);

        $id = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $id, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $this->expectException(NotABoardMember::class);
        $uc->execute($id, UserId::generate(), 'no reason', new \DateTimeImmutable('2026-05-13'));
    }
}
