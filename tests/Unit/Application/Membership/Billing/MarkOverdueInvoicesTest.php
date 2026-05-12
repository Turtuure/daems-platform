<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices;
use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoicesInput;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MarkOverdueInvoicesTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_flags_invoices_past_due_plus_grace(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $settings = $this->settings(30);

        // Past grace: due 2026-01-01, now=2026-10-15, cutoff=09-15 → 01-01 < 09-15 ✓
        $past = $this->invoice(year: 2025, due: new DateTimeImmutable('2026-01-01'));
        $invoices->save($past);

        // Within grace: due 2026-10-10, cutoff 09-15 → 10-10 NOT < 09-15
        $recent = $this->invoice(year: 2026, due: new DateTimeImmutable('2026-10-10'));
        $invoices->save($recent);

        $useCase = new MarkOverdueInvoices($invoices, $audit, $settings, new FrozenClock(new DateTimeImmutable('2026-10-15T03:00:00')));
        $flagged = $useCase->handle(new MarkOverdueInvoicesInput(TenantId::fromString(self::TENANT_ID)));

        $this->assertSame(1, $flagged);
        $this->assertSame(MemberFeeInvoiceStatus::Overdue, $invoices->findById($past->id())?->status());
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $invoices->findById($recent->id())?->status());
        $this->assertCount(1, $audit->rows);
    }

    public function test_idempotent_second_run_flags_zero(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $settings = $this->settings(30);

        $past = $this->invoice(year: 2025, due: new DateTimeImmutable('2026-01-01'));
        $invoices->save($past);

        $useCase = new MarkOverdueInvoices($invoices, $audit, $settings, new FrozenClock(new DateTimeImmutable('2026-10-15T03:00:00')));

        $first = $useCase->handle(new MarkOverdueInvoicesInput(TenantId::fromString(self::TENANT_ID)));
        $this->assertSame(1, $first);

        $second = $useCase->handle(new MarkOverdueInvoicesInput(TenantId::fromString(self::TENANT_ID)));
        $this->assertSame(0, $second);
    }

    public function test_no_settings_uses_default_grace_30(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $settings = new InMemoryTenantGovernanceSettingsRepository(); // no rows saved → null

        $past = $this->invoice(year: 2025, due: new DateTimeImmutable('2026-01-01'));
        $invoices->save($past);

        $useCase = new MarkOverdueInvoices($invoices, $audit, $settings, new FrozenClock(new DateTimeImmutable('2026-10-15T03:00:00')));
        $flagged = $useCase->handle(new MarkOverdueInvoicesInput(TenantId::fromString(self::TENANT_ID)));

        $this->assertSame(1, $flagged);
    }

    private function settings(int $graceDays): InMemoryTenantGovernanceSettingsRepository
    {
        $repo = new InMemoryTenantGovernanceSettingsRepository();
        $repo->save(new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString(self::TENANT_ID),
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     false,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  $graceDays,
            lapseCheckEnabled:                 true,
        ));
        return $repo;
    }

    private function invoice(int $year, DateTimeImmutable $due): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            year:                $year,
            feeType:             MembershipType::Basic,
            anniversaryDate:     $due->modify('-60 days'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             $due,
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           $due->modify('-60 days'),
        );
    }
}
