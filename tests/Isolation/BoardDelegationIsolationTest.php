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
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDelegationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;

final class BoardDelegationIsolationTest extends IsolationTestCase
{
    public function test_delegations_listed_only_for_their_tenant(): void
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
        $decAId = BoardDecisionId::fromString('01958000-0000-7000-8000-ddddddddddaa');
        $decBId = BoardDecisionId::fromString('01958000-0000-7000-8000-ddddddddddbb');
        foreach ([[$decAId, $boardA], [$decBId, $boardB]] as [$id, $bId]) {
            $decisions->save(new BoardDecision(
                id:              $id,
                boardId:         $bId,
                decisionType:    BoardDecisionType::DelegateAuthority,
                threshold:       BoardDecisionThreshold::Unanimous,
                mode:            BoardDecisionMode::Sync,
                voteVisibility:  BoardDecisionVoteVisibility::Visible,
                status:          BoardDecisionStatus::Passed,
                proposedByUserId: $gsa,
                proposedAt:      new \DateTimeImmutable('2026-05-12'),
                expiresAt:       new \DateTimeImmutable('2026-07-12'),
                resolvedAt:      new \DateTimeImmutable('2026-05-12'),
                meetingReference: 'K1',
                withdrawalReason: null,
                viaDelegation:   false,
                delegationId:    null,
            ));
        }

        $delegations = new SqlBoardDelegationRepository($this->pdo);
        $delegations->save(new BoardDelegation(
            id:               BoardDelegationId::fromString('01958000-0000-7000-8000-ffffffffffaa'),
            tenantId:         $daems,
            decisionType:     BoardDecisionType::ApproveBasic,
            delegatedToRole:  UserTenantRole::Admin,
            sourceDecisionId: $decAId,
            validFrom:        new \DateTimeImmutable('2026-05-12'),
            revokedAt:        null,
        ));
        $delegations->save(new BoardDelegation(
            id:               BoardDelegationId::fromString('01958000-0000-7000-8000-ffffffffffbb'),
            tenantId:         $sahe,
            decisionType:     BoardDecisionType::ApproveBasic,
            delegatedToRole:  UserTenantRole::Admin,
            sourceDecisionId: $decBId,
            validFrom:        new \DateTimeImmutable('2026-05-12'),
            revokedAt:        null,
        ));

        $now = new \DateTimeImmutable('2026-06-01');
        $this->assertCount(1, $delegations->listActive($daems, $now));
        $this->assertCount(1, $delegations->listActive($sahe, $now));
        $this->assertNotNull($delegations->findActive($daems, BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $now));
        $this->assertNotNull($delegations->findActive($sahe,  BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $now));
    }
}
