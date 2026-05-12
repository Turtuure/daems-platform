<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeAwardSubTier;
use Daems\Application\Governance\Propose\ProposeAwardSubTierInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Exception\AlreadyHasActiveSubTier;
use Daems\Domain\Membership\Exception\SubTierAppliesToMismatch;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberSubTierAwardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class ProposeAwardSubTierTest extends TestCase
{
    /**
     * @return array{
     *   uc: ProposeAwardSubTier,
     *   decisions: InMemoryBoardDecisionRepository,
     *   subtiers: InMemoryTenantMembershipSubTierRepository,
     *   awards: InMemoryMemberSubTierAwardRepository,
     *   tenant: TenantId,
     *   board: \Daems\Domain\Governance\Board,
     *   proposer: UserId,
     *   target: UserId,
     *   targetType: MembershipType,
     * }
     */
    private function harness(MembershipType $targetType = MembershipType::Basic): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $delegations = new InMemoryBoardDelegationRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $subtiers = new InMemoryTenantMembershipSubTierRepository();
        $awards = new InMemoryMemberSubTierAwardRepository();

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $target = UserId::generate();
        $board = new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $gsa,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        );
        $boards->save($board);
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        // Seed a 'gold' subtier applying to BASIC.
        $subtiers->save(new TenantMembershipSubTier(
            id: TenantMembershipSubTierId::generate(),
            tenantId: $tenant,
            slug: 'gold', name: 'Gold', rankOrder: 3,
            appliesTo: MembershipType::Basic,
        ));

        $userLookup = static fn(UserId $id) => [
            'membership_type'   => $targetType->value,
            'membership_status' => 'active',
        ];

        $uc = new ProposeAwardSubTier(
            $boards, $decisions, $delegations, $settings,
            $subtiers, $awards, $userLookup,
        );
        return compact('uc', 'decisions', 'subtiers', 'awards', 'tenant', 'board', 'gsa', 'target', 'targetType')
            + ['proposer' => $gsa];
    }

    public function test_rejects_when_applies_to_mismatches_member_type(): void
    {
        $h = $this->harness(MembershipType::Full); // FULL doesn't allow sub-tier
        $this->expectException(SubTierAppliesToMismatch::class);
        $h['uc']->execute(new ProposeAwardSubTierInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            subTierSlug: 'gold',
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'recognition',
            meetingReference: 'Kokous 2026-05-20 PK-1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_rejects_when_user_already_has_active_subtier(): void
    {
        $h = $this->harness();
        // Seed an active award already.
        $h['awards']->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $h['tenant'],
            userId: $h['target'],
            subTierSlug: 'silver',
            decisionId: BoardDecisionId::generate(),
            awardedAt: new \DateTimeImmutable('2026-04-01'),
            revokedAt: null,
            revokeDecisionId: null,
        ));
        $this->expectException(AlreadyHasActiveSubTier::class);
        $h['uc']->execute(new ProposeAwardSubTierInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            subTierSlug: 'gold',
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'reason',
            meetingReference: 'Kokous 2026-05-20',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_pending_majority_sync_decision(): void
    {
        $h = $this->harness();
        $id = $h['uc']->execute(new ProposeAwardSubTierInput(
            tenantId: $h['tenant'],
            targetUserId: $h['target'],
            subTierSlug: 'gold',
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Anonymous,
            reason: 'recognition',
            meetingReference: 'Kokous 2026-05-20 PK-1',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $d = $h['decisions']->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::AwardSubTier, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Majority, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame('gold', $d->payloadSubTierSlug);
        $this->assertSame('Kokous 2026-05-20 PK-1', $d->meetingReference);
    }
}
