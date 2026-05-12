<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeApproveBasicTest extends TestCase
{
    public function test_creates_pending_unanimous_async_decision(): void
    {
        $boards     = new InMemoryBoardRepository();
        $decisions  = new InMemoryBoardDecisionRepository();
        $delegations= new InMemoryBoardDelegationRepository();
        $settings   = new InMemoryTenantGovernanceSettingsRepository();

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $gsa,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeApproveBasic(
            $boards,
            $decisions,
            $delegations,
            $settings,
            /* applicationLookup */ static fn(string $id) => ['status' => 'pending'],
        );
        $proposer = UserId::generate();

        $id = $uc->execute(new ProposeApproveBasicInput(
            tenantId: $tenant,
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            proposedByUserId: $proposer,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            at: new \DateTimeImmutable('2026-05-12 10:00:00'),
        ));

        $d = $decisions->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::ApproveBasic, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Unanimous, $d->threshold);
        $this->assertSame(BoardDecisionMode::Async, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertFalse($d->viaDelegation);
        // expires_at = proposed_at + 60 days
        $this->assertSame('2026-07-11 10:00:00', $d->expiresAt->format('Y-m-d H:i:s'));
    }
}
