<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleTest extends TestCase
{
    public function test_draft_constructs_with_zero_amount_allowed(): void
    {
        $schedule = $this->schedule(amountCents: 0, status: AnnualFeeScheduleStatus::Draft);
        $this->assertSame(0, $schedule->amountCents());
        $this->assertSame(AnnualFeeScheduleStatus::Draft, $schedule->status());
    }

    public function test_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->schedule(amountCents: -1);
    }

    public function test_supersedes_marks_status_and_sets_timestamp(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Active);
        $now = new DateTimeImmutable('2027-01-15T10:00:00');
        $schedule->supersede($now);

        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $schedule->status());
        $this->assertEquals($now, $schedule->supersededAt());
    }

    public function test_activate_from_proposed(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Proposed);
        $now = new DateTimeImmutable('2026-12-01T00:00:00');
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $schedule->activate($actor, $now);

        $this->assertSame(AnnualFeeScheduleStatus::Active, $schedule->status());
        $this->assertEquals($now, $schedule->activatedAt());
        $this->assertEquals($actor, $schedule->activatedBy());
    }

    public function test_activate_rejects_already_active(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Active);
        $this->expectException(\DomainException::class);
        $schedule->activate(UserId::fromString('01958000-0000-7000-8000-0000000000cc'), new DateTimeImmutable());
    }

    public function test_activate_rejects_superseded(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Superseded);
        $this->expectException(\DomainException::class);
        $schedule->activate(UserId::fromString('01958000-0000-7000-8000-0000000000dd'), new DateTimeImmutable());
    }

    private function schedule(
        int $amountCents = 5000,
        AnnualFeeScheduleStatus $status = AnnualFeeScheduleStatus::Draft,
    ): AnnualFeeSchedule {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::fromString('01958000-0000-7000-8000-0000000000aa'),
            tenantId:     TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            year:         2027,
            feeType:      MembershipType::Basic,
            amountCents:  $amountCents,
            currency:     'EUR',
            status:       $status,
            decisionId:   null,
            activatedAt:  null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-11-01T00:00:00'),
            createdBy:    null,
        );
    }
}
