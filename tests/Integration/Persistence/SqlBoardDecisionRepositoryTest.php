<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlBoardDecisionRepositoryTest extends MigrationTestCase
{
    private string $tenantId  = '01958000-0000-7000-8000-aaaaaaaaaaaa';
    private string $userId    = '01958000-0000-7000-8000-bbbbbbbbbbbb';
    private string $boardId   = '01958000-0000-7000-8000-cccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('{$this->tenantId}', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin)
                          VALUES ('{$this->userId}', 'gsa', 'gsa@test', '1990-01-01', 1)");
        (new SqlBoardRepository($this->pdo))->save(new Board(
            id:                   BoardId::fromString($this->boardId),
            tenantId:             TenantId::fromString($this->tenantId),
            bootstrappedByUserId: UserId::fromString($this->userId),
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12'),
            createdAt:            new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_save_find_list_filters_and_expired(): void
    {
        $repo = new SqlBoardDecisionRepository($this->pdo);
        $pendingId = BoardDecisionId::fromString('01958000-0000-7000-8000-ddddddddddd1');
        $repo->save(new BoardDecision(
            id:              $pendingId,
            boardId:         BoardId::fromString($this->boardId),
            decisionType:    BoardDecisionType::ApproveBasic,
            threshold:       BoardDecisionThreshold::Unanimous,
            mode:            BoardDecisionMode::Async,
            voteVisibility:  BoardDecisionVoteVisibility::Visible,
            status:          BoardDecisionStatus::Pending,
            proposedByUserId: UserId::fromString($this->userId),
            proposedAt:      new \DateTimeImmutable('2026-05-12'),
            expiresAt:       new \DateTimeImmutable('2025-01-01'),  // expired in the past
            resolvedAt:      null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:   false,
            delegationId:    null,
            payloadApplicationId: '01958000-0000-7000-8000-eeeeeeeeeeee',
        ));

        $this->assertNotNull($repo->find($pendingId));
        $this->assertCount(1, $repo->listForBoard(BoardId::fromString($this->boardId)));
        $this->assertCount(1, $repo->listForBoard(BoardId::fromString($this->boardId), BoardDecisionStatus::Pending));
        $this->assertCount(0, $repo->listForBoard(BoardId::fromString($this->boardId), BoardDecisionStatus::Passed));
        $this->assertCount(1, $repo->listExpiredPending(new \DateTimeImmutable('2026-05-12')));
    }
}
