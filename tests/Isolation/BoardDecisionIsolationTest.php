<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;

final class BoardDecisionIsolationTest extends IsolationTestCase
{
    public function test_decisions_listed_only_for_their_board(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);

        $boards = new SqlBoardRepository($this->pdo);
        $boardA = BoardId::fromString('01958000-0000-7000-8000-ccccccccccaa');
        $boardB = BoardId::fromString('01958000-0000-7000-8000-ccccccccccbb');
        $boards->save(new Board($boardA, $daems, $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));
        $boards->save(new Board($boardB, $sahe,  $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));

        $decisions = new SqlBoardDecisionRepository($this->pdo);
        $decisions->save(new BoardDecision(
            id:              BoardDecisionId::fromString('01958000-0000-7000-8000-ddddddddddaa'),
            boardId:         $boardA,
            decisionType:    BoardDecisionType::ApproveBasic,
            threshold:       BoardDecisionThreshold::Unanimous,
            mode:            BoardDecisionMode::Async,
            voteVisibility:  BoardDecisionVoteVisibility::Visible,
            status:          BoardDecisionStatus::Pending,
            proposedByUserId: $gsa,
            proposedAt:      new \DateTimeImmutable('2026-05-12'),
            expiresAt:       new \DateTimeImmutable('2026-07-12'),
            resolvedAt:      null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:   false,
            delegationId:    null,
        ));
        $decisions->save(new BoardDecision(
            id:              BoardDecisionId::fromString('01958000-0000-7000-8000-ddddddddddbb'),
            boardId:         $boardB,
            decisionType:    BoardDecisionType::ApproveBasic,
            threshold:       BoardDecisionThreshold::Unanimous,
            mode:            BoardDecisionMode::Async,
            voteVisibility:  BoardDecisionVoteVisibility::Visible,
            status:          BoardDecisionStatus::Pending,
            proposedByUserId: $gsa,
            proposedAt:      new \DateTimeImmutable('2026-05-12'),
            expiresAt:       new \DateTimeImmutable('2026-07-12'),
            resolvedAt:      null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:   false,
            delegationId:    null,
        ));

        $this->assertCount(1, $decisions->listForBoard($boardA));
        $this->assertCount(1, $decisions->listForBoard($boardB));
        $this->assertSame($boardA->value(), $decisions->listForBoard($boardA)[0]->boardId->value());
    }
}
