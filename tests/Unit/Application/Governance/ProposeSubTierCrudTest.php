<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeSubTierCrud;
use Daems\Application\Governance\Propose\ProposeSubTierCrudInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Exception\DuplicateSubTierSlug;
use Daems\Domain\Membership\Exception\SubTierInUse;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryMemberSubTierAwardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class ProposeSubTierCrudTest extends TestCase
{
    private function harness(): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $subtiers = new InMemoryTenantMembershipSubTierRepository();
        $awards = new InMemoryMemberSubTierAwardRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeSubTierCrud($boards, $decisions, $settings, $subtiers, $awards);
        return compact('uc', 'decisions', 'subtiers', 'awards', 'tenant', 'proposer');
    }

    public function test_create_rejects_when_slug_already_exists(): void
    {
        $h = $this->harness();
        $h['subtiers']->save(new TenantMembershipSubTier(
            id: TenantMembershipSubTierId::generate(),
            tenantId: $h['tenant'],
            slug: 'gold', name: 'Gold', rankOrder: 3,
            appliesTo: MembershipType::Basic,
        ));
        $this->expectException(DuplicateSubTierSlug::class);
        $h['uc']->execute(new ProposeSubTierCrudInput(
            tenantId: $h['tenant'],
            operation: BoardDecisionSubTierCrudOperation::Create,
            subTierSlug: 'gold',
            name: 'Gold',
            rankOrder: 3,
            appliesTo: MembershipType::Basic,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_create_happy_path(): void
    {
        $h = $this->harness();
        $id = $h['uc']->execute(new ProposeSubTierCrudInput(
            tenantId: $h['tenant'],
            operation: BoardDecisionSubTierCrudOperation::Create,
            subTierSlug: 'platinum',
            name: 'Platinum',
            rankOrder: 4,
            appliesTo: MembershipType::Basic,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
        $d = $h['decisions']->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::SubTierCrud, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Majority, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionSubTierCrudOperation::Create, $d->payloadSubTierOperation);
        $this->assertSame('platinum', $d->payloadSubTierSlug);
        $this->assertSame('Platinum', $d->payloadSubTierName);
        $this->assertSame(4, $d->payloadSubTierRank);
        $this->assertSame('BASIC', $d->payloadSubTierAppliesTo);
    }

    public function test_delete_rejects_when_subtier_in_use(): void
    {
        $h = $this->harness();
        $h['subtiers']->save(new TenantMembershipSubTier(
            id: TenantMembershipSubTierId::generate(),
            tenantId: $h['tenant'],
            slug: 'gold', name: 'Gold', rankOrder: 3,
            appliesTo: MembershipType::Basic,
        ));
        $h['awards']->save(new MemberSubTierAward(
            id: MemberSubTierAwardId::generate(),
            tenantId: $h['tenant'],
            userId: UserId::generate(),
            subTierSlug: 'gold',
            decisionId: BoardDecisionId::generate(),
            awardedAt: new \DateTimeImmutable('2026-04-01'),
            revokedAt: null,
            revokeDecisionId: null,
        ));
        $this->expectException(SubTierInUse::class);
        $h['uc']->execute(new ProposeSubTierCrudInput(
            tenantId: $h['tenant'],
            operation: BoardDecisionSubTierCrudOperation::Delete,
            subTierSlug: 'gold',
            name: null, rankOrder: null,
            appliesTo: MembershipType::Basic,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }
}
