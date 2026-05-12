<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeScheduleInput;
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

final class ActivateAnnualFeeScheduleTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ACTOR_ID  = '01958000-0000-7000-8000-0000000000bb';
    private const DEC_ID    = '01958000-0000-7000-8000-0000000000cc';

    public function test_proposed_rows_become_active_and_supersede_prior(): void
    {
        $repo = new InMemoryAnnualFeeScheduleRepository();

        // Prior active row (from a previous decision earlier in 2026)
        $oldBasic = $this->row(MembershipType::Basic, 4000, AnnualFeeScheduleStatus::Active, decisionId: null);
        $repo->save($oldBasic);

        // New proposed rows tied to the decision we're activating
        $newSupp  = $this->row(MembershipType::Supporting, 1500, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $newBasic = $this->row(MembershipType::Basic,     5000, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $newFull  = $this->row(MembershipType::Full,         0, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $repo->save($newSupp);
        $repo->save($newBasic);
        $repo->save($newFull);

        $useCase = new ActivateAnnualFeeSchedule($repo, FrozenClock::at('2026-12-01T00:00:00'));
        $useCase->handle(new ActivateAnnualFeeScheduleInput(
            decisionId:  self::DEC_ID,
            activatedBy: UserId::fromString(self::ACTOR_ID),
        ));

        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $repo->findById($oldBasic->id())?->status());
        $this->assertSame(AnnualFeeScheduleStatus::Active,     $repo->findById($newSupp->id())?->status());
        $this->assertSame(AnnualFeeScheduleStatus::Active,     $repo->findById($newBasic->id())?->status());
        $this->assertSame(AnnualFeeScheduleStatus::Active,     $repo->findById($newFull->id())?->status());
    }

    public function test_noop_when_no_proposed_rows_match(): void
    {
        $repo = new InMemoryAnnualFeeScheduleRepository();
        $oldBasic = $this->row(MembershipType::Basic, 4000, AnnualFeeScheduleStatus::Active, decisionId: null);
        $repo->save($oldBasic);

        $useCase = new ActivateAnnualFeeSchedule($repo, FrozenClock::at('2026-12-01T00:00:00'));
        $useCase->handle(new ActivateAnnualFeeScheduleInput(
            decisionId:  '01958000-0000-7000-8000-000000000099', // unknown decision
            activatedBy: UserId::fromString(self::ACTOR_ID),
        ));

        // Prior active row untouched.
        $this->assertSame(AnnualFeeScheduleStatus::Active, $repo->findById($oldBasic->id())?->status());
    }

    private function row(MembershipType $type, int $cents, AnnualFeeScheduleStatus $status, ?string $decisionId): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     TenantId::fromString(self::TENANT_ID),
            year:         2027,
            feeType:      $type,
            amountCents:  $cents,
            currency:     'EUR',
            status:       $status,
            decisionId:   $decisionId,
            activatedAt:  $status === AnnualFeeScheduleStatus::Active ? new DateTimeImmutable('2026-06-01T00:00:00') : null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-05-01T00:00:00'),
            createdBy:    null,
        );
    }
}
