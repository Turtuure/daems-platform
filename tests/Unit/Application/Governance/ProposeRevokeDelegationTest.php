<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeRevokeDelegation;
use Daems\Application\Governance\Propose\ProposeRevokeDelegationInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeRevokeDelegationTest extends TestCase
{
    public function test_rejects_when_delegation_not_active(): void
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $delegations = new InMemoryBoardDelegationRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeRevokeDelegation($boards, $decisions, $settings, $delegations);
        $this->expectException(\DomainException::class);
        $uc->execute(new ProposeRevokeDelegationInput(
            tenantId: $tenant,
            delegationId: BoardDelegationId::generate(),
            proposedByUserId: $proposer,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'remove this',
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_majority_sync_decision(): void
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $delegations = new InMemoryBoardDelegationRepository();

        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $delegId = BoardDelegationId::generate();
        $delegations->save(new BoardDelegation(
            id: $delegId,
            tenantId: $tenant,
            decisionType: BoardDecisionType::ApproveBasic,
            delegatedToRole: UserTenantRole::Admin,
            sourceDecisionId: BoardDecisionId::generate(),
            validFrom: new \DateTimeImmutable('2026-05-01'),
            revokedAt: null,
        ));

        $uc = new ProposeRevokeDelegation($boards, $decisions, $settings, $delegations);
        $id = $uc->execute(new ProposeRevokeDelegationInput(
            tenantId: $tenant,
            delegationId: $delegId,
            proposedByUserId: $proposer,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            reason: 'Revoke',
            meetingReference: 'Kokous 2026-05-20',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $d = $decisions->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::RevokeDelegation, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Majority, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame($delegId->value(), $d->payloadDelegationRevokeId?->value());
    }
}
