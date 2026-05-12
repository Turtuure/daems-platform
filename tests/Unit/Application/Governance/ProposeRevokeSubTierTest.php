<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeRevokeSubTier;
use Daems\Application\Governance\Propose\ProposeRevokeSubTierInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Exception\NoActiveSubTierToRevoke;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberSubTierAwardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeRevokeSubTierTest extends TestCase
{
    /** @return array{uc:ProposeRevokeSubTier, decisions:InMemoryBoardDecisionRepository, awards:InMemoryMemberSubTierAwardRepository, tenant:TenantId, target:UserId, proposer:UserId} */
    private function harness(): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $awards = new InMemoryMemberSubTierAwardRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $target = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeRevokeSubTier($boards, $decisions, $settings, $awards);
        return compact('uc', 'decisions', 'awards', 'tenant', 'target', 'proposer');
    }

    public function test_rejects_when_no_active_subtier(): void
    {
        $h = $this->harness();
        $this->expectException(NoActiveSubTierToRevoke::class);
        $h['uc']->execute(new ProposeRevokeSubTierInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'reason',
            meetingReference: 'Kokous 2026-05-20',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_majority_sync_decision(): void
    {
        $h = $this->harness();
        $h['awards']->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $h['tenant'],
            userId: $h['target'],
            subTierSlug: 'gold',
            decisionId: BoardDecisionId::generate(),
            awardedAt: new \DateTimeImmutable('2026-04-01'),
            revokedAt: null,
            revokeDecisionId: null,
        ));

        $id = $h['uc']->execute(new ProposeRevokeSubTierInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'Inactive member',
            meetingReference: 'Kokous 2026-05-20 PK-2',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $d = $h['decisions']->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::RevokeSubTier, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Majority, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame('gold', $d->payloadSubTierSlug);
        $this->assertSame('Kokous 2026-05-20 PK-2', $d->meetingReference);
    }
}
