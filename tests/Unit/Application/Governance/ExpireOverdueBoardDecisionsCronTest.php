<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\ExpireOverdueBoardDecisionsCron;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use PHPUnit\Framework\TestCase;

final class ExpireOverdueBoardDecisionsCronTest extends TestCase
{
    public function test_expires_only_overdue_pending(): void
    {
        $repo = new InMemoryBoardDecisionRepository();

        $overdueId = BoardDecisionId::generate();
        $repo->save(new BoardDecision(
            id: $overdueId, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-01-01'),
            expiresAt:  new \DateTimeImmutable('2026-03-01'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $futureId = BoardDecisionId::generate();
        $repo->save(new BoardDecision(
            id: $futureId, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-05-01'),
            expiresAt:  new \DateTimeImmutable('2026-07-01'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $cron = new ExpireOverdueBoardDecisionsCron($repo);
        $count = $cron->run(new \DateTimeImmutable('2026-05-12'));

        $this->assertSame(1, $count);
        $this->assertSame(BoardDecisionStatus::Expired, $repo->find($overdueId)?->status);
        $this->assertSame(BoardDecisionStatus::Pending, $repo->find($futureId)?->status);
    }
}
