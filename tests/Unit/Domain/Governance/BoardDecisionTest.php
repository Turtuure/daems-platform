<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\AsyncRequiresUnanimous;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class BoardDecisionTest extends TestCase
{
    public function test_async_with_majority_is_rejected(): void
    {
        $this->expectException(AsyncRequiresUnanimous::class);
        new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::AwardSubTier,
            threshold:        BoardDecisionThreshold::Majority,
            mode:             BoardDecisionMode::Async,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
    }

    public function test_async_unanimous_is_allowed(): void
    {
        $d = new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::ApproveBasic,
            threshold:        BoardDecisionThreshold::Unanimous,
            mode:             BoardDecisionMode::Async,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
    }

    public function test_sync_majority_is_allowed_with_meeting_reference(): void
    {
        $d = new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::AwardSubTier,
            threshold:        BoardDecisionThreshold::Majority,
            mode:             BoardDecisionMode::Sync,
            voteVisibility:   BoardDecisionVoteVisibility::Anonymous,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: 'Hallituksen kokous 2026-05-20 — PK-12',
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
        $this->assertSame('Hallituksen kokous 2026-05-20 — PK-12', $d->meetingReference);
    }

    public function test_with_status_returns_new_instance_with_updated_status_and_resolved_at(): void
    {
        $d = new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::ApproveBasic,
            threshold:        BoardDecisionThreshold::Unanimous,
            mode:             BoardDecisionMode::Async,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
        $resolved = $d->withStatus(BoardDecisionStatus::Passed, new \DateTimeImmutable('2026-05-13'));
        $this->assertSame(BoardDecisionStatus::Passed, $resolved->status);
        $this->assertSame('2026-05-13', $resolved->resolvedAt?->format('Y-m-d'));
        $this->assertSame(BoardDecisionStatus::Pending, $d->status, 'original immutable');
    }
}
