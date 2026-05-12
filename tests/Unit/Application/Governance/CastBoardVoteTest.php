<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Application\Governance\BoardDecisionExecutorRegistry;
use Daems\Application\Governance\BoardDecisionResolutionService;
use Daems\Application\Governance\CastBoardVote;
use Daems\Application\Governance\ResolveBoardDecisionIfReady;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionVoteRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use PHPUnit\Framework\TestCase;

final class CastBoardVoteTest extends TestCase
{
    /** @return array{0:CastBoardVote, 1:InMemoryBoardDecisionRepository, 2:InMemoryBoardDecisionVoteRepository, 3:InMemoryBoardMemberRepository, 4:BoardDecisionExecutorRegistry} */
    private function harness(): array
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $votes     = new InMemoryBoardDecisionVoteRepository();
        $members   = new InMemoryBoardMemberRepository();
        $registry  = new BoardDecisionExecutorRegistry();
        $resolve   = new ResolveBoardDecisionIfReady(
            $decisions, $votes, $members,
            new BoardDecisionResolutionService(),
            $registry,
        );
        $useCase = new CastBoardVote($decisions, $votes, $members, $resolve);
        return [$useCase, $decisions, $votes, $members, $registry];
    }

    public function test_rejects_non_board_member(): void
    {
        [$uc, $decisions] = $this->harness();
        $boardId    = BoardId::generate();
        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
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
        $uc->execute($decisionId, UserId::generate(), BoardDecisionVoteValue::Yes, new \DateTimeImmutable('2026-05-13'));
    }

    public function test_rejects_when_decision_already_resolved(): void
    {
        [$uc, $decisions, , $members] = $this->harness();
        $boardId = BoardId::generate();
        $user    = UserId::generate();
        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardId, userId: $user,
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));
        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Passed,
            proposedByUserId: $user,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: new \DateTimeImmutable('2026-05-13'),
            meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $this->expectException(DecisionAlreadyResolved::class);
        $uc->execute($decisionId, $user, BoardDecisionVoteValue::Yes, new \DateTimeImmutable('2026-05-13'));
    }

    public function test_unanimous_decision_passes_when_single_chair_votes_yes(): void
    {
        [$uc, $decisions, , $members, $registry] = $this->harness();
        $boardId = BoardId::generate();
        $chair   = UserId::generate();
        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardId, userId: $chair,
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));
        // Register a no-op executor for ApproveBasic so the resolution path can run.
        $registry->register(new class implements BoardDecisionExecutorInterface {
            public function decisionType(): BoardDecisionType { return BoardDecisionType::ApproveBasic; }
            public function execute(\Daems\Domain\Governance\BoardDecision $d, \DateTimeImmutable $at): void {}
        });

        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: $chair,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $uc->execute($decisionId, $chair, BoardDecisionVoteValue::Yes, new \DateTimeImmutable('2026-05-13'));

        $found = $decisions->find($decisionId);
        $this->assertNotNull($found);
        $this->assertSame(BoardDecisionStatus::Passed, $found->status);
    }
}
