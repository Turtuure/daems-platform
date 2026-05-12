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
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardDecisionRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberSubTierAwardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlMemberSubTierAwardRepositoryTest extends MigrationTestCase
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
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin) VALUES ('{$this->userId}', 'u', 'u@test', '1990-01-01', 0)");
        (new SqlBoardRepository($this->pdo))->save(new Board(
            id: BoardId::fromString($this->boardId),
            tenantId: TenantId::fromString($this->tenantId),
            bootstrappedByUserId: UserId::fromString($this->userId),
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        (new SqlBoardDecisionRepository($this->pdo))->save(new BoardDecision(
            id: BoardDecisionId::fromString($this->decisionId),
            boardId: BoardId::fromString($this->boardId),
            decisionType: BoardDecisionType::AwardSubTier,
            threshold: BoardDecisionThreshold::Majority,
            mode: BoardDecisionMode::Sync,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Passed,
            proposedByUserId: UserId::fromString($this->userId),
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: new \DateTimeImmutable('2026-05-12'),
            meetingReference: 'K1', withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));
    }

    public function test_save_findActive_listActiveForSubTierSlug(): void
    {
        $repo = new SqlMemberSubTierAwardRepository($this->pdo);
        $repo->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::fromString('01958000-0000-7000-8000-eeeeeeeeeee1'),
            tenantId: TenantId::fromString($this->tenantId),
            userId: UserId::fromString($this->userId),
            subTierSlug: 'gold',
            decisionId: BoardDecisionId::fromString($this->decisionId),
            awardedAt: new \DateTimeImmutable('2026-05-12'),
            revokedAt: null,
            revokeDecisionId: null,
        ));

        $now = new \DateTimeImmutable('2026-06-01');
        $active = $repo->findActive(TenantId::fromString($this->tenantId), UserId::fromString($this->userId), $now);
        $this->assertNotNull($active);
        $this->assertSame('gold', $active->subTierSlug);

        $this->assertCount(1, $repo->listForUser(TenantId::fromString($this->tenantId), UserId::fromString($this->userId)));
        $this->assertCount(1, $repo->listActiveForSubTierSlug(TenantId::fromString($this->tenantId), 'gold', $now));
    }
}
