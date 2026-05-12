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
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberSubTierAwardRepository;

final class MemberSubTierAwardIsolationTest extends IsolationTestCase
{
    public function test_awards_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);
        $u1    = $this->seedUser('01958000-0000-7000-8000-ffffffffff01', 'u1@test');
        $u2    = $this->seedUser('01958000-0000-7000-8000-ffffffffff02', 'u2@test');

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
                id: $id, boardId: $bId,
                decisionType: BoardDecisionType::AwardSubTier,
                threshold: BoardDecisionThreshold::Majority,
                mode: BoardDecisionMode::Sync,
                voteVisibility: BoardDecisionVoteVisibility::Visible,
                status: BoardDecisionStatus::Passed,
                proposedByUserId: $gsa,
                proposedAt: new \DateTimeImmutable('2026-05-12'),
                expiresAt:  new \DateTimeImmutable('2026-07-12'),
                resolvedAt: new \DateTimeImmutable('2026-05-12'),
                meetingReference: 'K1', withdrawalReason: null,
                viaDelegation: false, delegationId: null,
            ));
        }

        $awards = new SqlMemberSubTierAwardRepository($this->pdo);
        $awards->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $daems, userId: $u1, subTierSlug: 'gold',
            decisionId: $decAId,
            awardedAt: new \DateTimeImmutable('2026-05-12'),
            revokedAt: null, revokeDecisionId: null,
        ));
        $awards->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $sahe, userId: $u2, subTierSlug: 'silver',
            decisionId: $decBId,
            awardedAt: new \DateTimeImmutable('2026-05-12'),
            revokedAt: null, revokeDecisionId: null,
        ));

        $now = new \DateTimeImmutable('2026-06-01');
        $this->assertNotNull($awards->findActive($daems, $u1, $now));
        $this->assertNull($awards->findActive($daems, $u2, $now));
        $this->assertNotNull($awards->findActive($sahe, $u2, $now));
        $this->assertNull($awards->findActive($sahe, $u1, $now));
    }
}
