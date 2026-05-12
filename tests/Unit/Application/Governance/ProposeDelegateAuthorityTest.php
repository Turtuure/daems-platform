<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeDelegateAuthority;
use Daems\Application\Governance\Propose\ProposeDelegateAuthorityInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeDelegateAuthorityTest extends TestCase
{
    private function harness(): array
    {
        $boards = new InMemoryBoardRepository();
        $decisions = new InMemoryBoardDecisionRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $tenant = TenantId::generate();
        $proposer = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $proposer,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));
        $uc = new ProposeDelegateAuthority($boards, $decisions, $settings);
        return compact('uc', 'decisions', 'tenant', 'proposer');
    }

    public function test_rejects_non_delegatable_decision_type(): void
    {
        $h = $this->harness();
        $this->expectException(DelegationNotPermittedForType::class);
        $h['uc']->execute(new ProposeDelegateAuthorityInput(
            tenantId: $h['tenant'],
            decisionType: BoardDecisionType::Expel, // not delegatable
            delegatedToRole: UserTenantRole::Admin,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            meetingReference: 'Kokous 1',
            at: new \DateTimeImmutable('2026-05-20'),
        ));
    }

    public function test_happy_path_creates_unanimous_sync_decision(): void
    {
        $h = $this->harness();
        $id = $h['uc']->execute(new ProposeDelegateAuthorityInput(
            tenantId: $h['tenant'],
            decisionType: BoardDecisionType::ApproveBasic,
            delegatedToRole: UserTenantRole::Admin,
            proposedByUserId: $h['proposer'],
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            meetingReference: 'Kokous 2026-05-20',
            at: new \DateTimeImmutable('2026-05-20 10:00:00'),
        ));
        $d = $h['decisions']->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::DelegateAuthority, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Unanimous, $d->threshold);
        $this->assertSame(BoardDecisionMode::Sync, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertSame(BoardDecisionType::ApproveBasic, $d->payloadDelegationType);
        $this->assertSame('admin', $d->payloadDelegatedToRole);
    }
}
