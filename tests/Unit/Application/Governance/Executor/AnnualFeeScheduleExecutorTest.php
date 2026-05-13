<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance\Executor;

use Daems\Application\Governance\Executor\AnnualFeeScheduleExecutor;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleExecutorTest extends TestCase
{
    public function test_decision_type_is_annual_fee_schedule(): void
    {
        $repo = new InMemoryAnnualFeeScheduleRepository();
        $executor = new AnnualFeeScheduleExecutor(
            new ActivateAnnualFeeSchedule($repo, FrozenClock::at('2026-12-01T00:00:00')),
        );
        $this->assertSame(BoardDecisionType::AnnualFeeSchedule, $executor->decisionType());
    }

    public function test_execute_activates_proposed_rows(): void
    {
        $repo = new InMemoryAnnualFeeScheduleRepository();
        $tenantId  = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $proposer  = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $decisionId = BoardDecisionId::fromString('01958000-0000-7000-8000-0000000000cc');

        $proposed = new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $tenantId,
            year:         2027,
            feeType:      MembershipType::Basic,
            amountCents:  5000,
            currency:     'EUR',
            status:       AnnualFeeScheduleStatus::Proposed,
            decisionId:   $decisionId->value(),
            activatedAt:  null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-11-01T00:00:00'),
            createdBy:    $proposer,
        );
        $repo->save($proposed);

        $executor = new AnnualFeeScheduleExecutor(
            new ActivateAnnualFeeSchedule($repo, FrozenClock::at('2026-12-01T00:00:00')),
        );

        $decision = new BoardDecision(
            id:                $decisionId,
            boardId:           BoardId::fromString('01958000-0000-7000-8000-0000000000b0'),
            decisionType:      BoardDecisionType::AnnualFeeSchedule,
            threshold:         BoardDecisionThreshold::Majority,
            mode:              BoardDecisionMode::Sync,
            voteVisibility:    BoardDecisionVoteVisibility::Visible,
            status:            BoardDecisionStatus::Passed,
            proposedByUserId:  $proposer,
            proposedAt:        new DateTimeImmutable('2026-11-01T00:00:00'),
            expiresAt:         new DateTimeImmutable('2026-12-31T00:00:00'),
            resolvedAt:        new DateTimeImmutable('2026-12-01T00:00:00'),
            meetingReference:  null,
            withdrawalReason:  null,
            viaDelegation:     false,
            delegationId:      null,
        );

        $executor->execute($decision, new DateTimeImmutable('2026-12-01T00:00:00'));

        $reloaded = $repo->findById($proposed->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(AnnualFeeScheduleStatus::Active, $reloaded->status());
        $this->assertEquals($proposer, $reloaded->activatedBy());
    }
}
