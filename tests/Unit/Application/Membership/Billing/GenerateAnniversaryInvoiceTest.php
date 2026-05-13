<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoiceInput;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GenerateAnniversaryInvoiceTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_creates_invoice_for_active_basic_member(): void
    {
        [$useCase, $invRepo] = $this->makeUseCase(year: 2026, scheduleAmountCents: 5000);

        $output = $useCase->handle(new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));

        $this->assertTrue($output->created);
        $this->assertSame(5000, $output->amountCents);
        $this->assertNull($output->overrideId);

        $invoice = $invRepo->findFor(TenantId::fromString(self::TENANT_ID), UserId::fromString(self::USER_ID), 2026);
        $this->assertNotNull($invoice);
        $this->assertSame(5000, $invoice->amountCents());
        $this->assertSame(MembershipType::Basic, $invoice->feeType());
        $this->assertEquals(new DateTimeImmutable('2026-09-13'), $invoice->dueDate());
    }

    public function test_idempotent_on_repeat(): void
    {
        [$useCase] = $this->makeUseCase(year: 2026, scheduleAmountCents: 5000);
        $input = new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        );

        $first = $useCase->handle($input);
        $second = $useCase->handle($input);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->invoiceId, $second->invoiceId);
    }

    public function test_throws_when_no_active_schedule(): void
    {
        [$useCase] = $this->makeUseCase(year: 2026, scheduleAmountCents: null);

        $this->expectException(NoActiveFeeScheduleException::class);
        $useCase->handle(new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));
    }

    /**
     * @return array{0:GenerateAnniversaryInvoice,1:InMemoryMemberFeeInvoiceRepository}
     */
    private function makeUseCase(int $year, ?int $scheduleAmountCents): array
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);

        $users = new InMemoryUserRepository();
        $users->save(new User(
            id:              UserId::fromString(self::USER_ID),
            name:            'Test',
            email:           'test@daems.fi',
            passwordHash:    null,
            dateOfBirth:     '1990-01-01',
            country:         'FI',
            membershipType:  'BASIC',
            membershipStatus: 'active',
        ));

        $schedules = new InMemoryAnnualFeeScheduleRepository();
        if ($scheduleAmountCents !== null) {
            $schedules->save(new AnnualFeeSchedule(
                id:           AnnualFeeScheduleId::generate(),
                tenantId:     $tenantId,
                year:         $year,
                feeType:      MembershipType::Basic,
                amountCents:  $scheduleAmountCents,
                currency:     'EUR',
                status:       AnnualFeeScheduleStatus::Active,
                decisionId:   null,
                activatedAt:  new DateTimeImmutable('2025-12-01'),
                activatedBy:  null,
                supersededAt: null,
                createdAt:    new DateTimeImmutable('2025-12-01'),
                createdBy:    null,
            ));
        }

        $overrides = new InMemoryUserFeeOverrideRepository();
        $invoices  = new InMemoryMemberFeeInvoiceRepository();

        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $settings->save(new TenantGovernanceSettings(
            tenantId:                          $tenantId,
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     false,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
        ));

        $clock = new FrozenClock(new DateTimeImmutable("{$year}-07-15T02:00:00"));

        $useCase = new GenerateAnniversaryInvoice($users, $schedules, $overrides, $invoices, $settings, $clock);
        return [$useCase, $invoices];
    }
}
