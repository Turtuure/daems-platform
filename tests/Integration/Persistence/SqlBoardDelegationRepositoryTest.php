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
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDelegationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlBoardDelegationRepositoryTest extends MigrationTestCase
{
    private string $tenantId   = '01958000-0000-7000-8000-aaaaaaaaaaaa';
    private string $userId     = '01958000-0000-7000-8000-bbbbbbbbbbbb';
    private string $boardId    = '01958000-0000-7000-8000-cccccccccccc';
    private string $decisionId = '01958000-0000-7000-8000-ddddddddddd1';

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
        // Source decision (FK target for board_delegations)
        (new SqlBoardDecisionRepository($this->pdo))->save(new BoardDecision(
            id:              BoardDecisionId::fromString($this->decisionId),
            boardId:         BoardId::fromString($this->boardId),
            decisionType:    BoardDecisionType::DelegateAuthority,
            threshold:       BoardDecisionThreshold::Unanimous,
            mode:            BoardDecisionMode::Sync,
            voteVisibility:  BoardDecisionVoteVisibility::Visible,
            status:          BoardDecisionStatus::Passed,
            proposedByUserId: UserId::fromString($this->userId),
            proposedAt:      new \DateTimeImmutable('2026-05-12'),
            expiresAt:       new \DateTimeImmutable('2026-07-12'),
            resolvedAt:      new \DateTimeImmutable('2026-05-12'),
            meetingReference: 'Kokous 1',
            withdrawalReason: null,
            viaDelegation:   false,
            delegationId:    null,
        ));
    }

    public function test_save_find_active_and_listActive(): void
    {
        $repo = new SqlBoardDelegationRepository($this->pdo);
        $deleg = new BoardDelegation(
            id:               BoardDelegationId::fromString('01958000-0000-7000-8000-ddddddddddd2'),
            tenantId:         TenantId::fromString($this->tenantId),
            decisionType:     BoardDecisionType::ApproveBasic,
            delegatedToRole:  UserTenantRole::Admin,
            sourceDecisionId: BoardDecisionId::fromString($this->decisionId),
            validFrom:        new \DateTimeImmutable('2026-05-12'),
            revokedAt:        null,
        );
        $repo->save($deleg);

        $now = new \DateTimeImmutable('2026-06-01');
        $found = $repo->findActive(
            TenantId::fromString($this->tenantId),
            BoardDecisionType::ApproveBasic,
            UserTenantRole::Admin,
            $now,
        );
        $this->assertNotNull($found);
        $this->assertCount(1, $repo->listActive(TenantId::fromString($this->tenantId), $now));

        // After revoke, listActive returns 0
        $revoked = new BoardDelegation(
            id:               $deleg->id,
            tenantId:         $deleg->tenantId,
            decisionType:     $deleg->decisionType,
            delegatedToRole:  $deleg->delegatedToRole,
            sourceDecisionId: $deleg->sourceDecisionId,
            validFrom:        $deleg->validFrom,
            revokedAt:        new \DateTimeImmutable('2026-05-20'),
        );
        $repo->save($revoked);
        $this->assertCount(0, $repo->listActive(TenantId::fromString($this->tenantId), $now));
    }
}
