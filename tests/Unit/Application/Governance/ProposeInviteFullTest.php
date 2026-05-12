<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeInviteFull;
use Daems\Application\Governance\Propose\ProposeInviteFullInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\NotEligibleForFull;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\IsEligibleForFullMembership;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeInviteFullTest extends TestCase
{
    /** @return array{0:ProposeInviteFull, 1:InMemoryBoardRepository, 2:InMemoryBoardDecisionRepository, 3:TenantId, 4:UserId} */
    private function harness(callable $userLookup): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $delegations = new InMemoryBoardDelegationRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $gsa,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeInviteFull(
            $boards, $decisions, $delegations, $settings,
            new IsEligibleForFullMembership(),
            $userLookup,
        );
        return [$uc, $boards, $decisions, $tenant, $gsa];
    }

    public function test_rejects_when_user_not_yet_12_months(): void
    {
        $target = UserId::generate();
        $userLookup = static fn(UserId $id) => [
            'membership_type'      => 'BASIC',
            'membership_status'    => 'active',
            'membership_started_at'=> '2026-05-12', // 11 months before "now"
        ];
        [$uc, , , $tenant, $gsa] = $this->harness($userLookup);

        $this->expectException(NotEligibleForFull::class);
        $uc->execute(new ProposeInviteFullInput(
            tenantId: $tenant,
            targetUserId: $target,
            proposedByUserId: $gsa,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'Recognising contributions',
            at: new \DateTimeImmutable('2027-04-12 10:00:00'),
        ));
    }

    public function test_eligible_user_creates_pending_decision(): void
    {
        $target = UserId::generate();
        $userLookup = static fn(UserId $id) => [
            'membership_type'      => 'BASIC',
            'membership_status'    => 'active',
            'membership_started_at'=> '2026-05-12',
        ];
        [$uc, , $decisions, $tenant, $gsa] = $this->harness($userLookup);

        $id = $uc->execute(new ProposeInviteFullInput(
            tenantId: $tenant,
            targetUserId: $target,
            proposedByUserId: $gsa,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'Recognising 12-month contribution.',
            at: new \DateTimeImmutable('2027-06-12 10:00:00'),
        ));

        $d = $decisions->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::InviteFull, $d->decisionType);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame($target->value(), $d->payloadTargetUserId?->value());
        $this->assertSame('Recognising 12-month contribution.', $d->payloadReason);
    }
}
