<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ImportPaymentsCsv\ConfirmImportPayments;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\ConfirmImportPaymentsInput;
use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ConfirmImportPaymentsTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_applies_all_valid_matches(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $inv1 = $this->invoice(2024); $invoices->save($inv1);
        $inv2 = $this->invoice(2025); $invoices->save($inv2);
        $inv3 = $this->invoice(2026); $invoices->save($inv3);

        $markPaid = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable('2026-08-15')));
        $useCase = new ConfirmImportPayments($markPaid);

        $out = $useCase->handle(new ConfirmImportPaymentsInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            matches:  [
                ['invoice_id' => $inv1->id()->value(), 'amount_cents' => 5000, 'paid_at' => '2026-08-01T10:00:00', 'reference' => 'R1'],
                ['invoice_id' => $inv2->id()->value(), 'amount_cents' => 5000, 'paid_at' => '2026-08-02T10:00:00', 'reference' => 'R2'],
                ['invoice_id' => $inv3->id()->value(), 'amount_cents' => 5000, 'paid_at' => '2026-08-03T10:00:00', 'reference' => 'R3'],
            ],
        ));

        $this->assertCount(3, $out->applied);
        $this->assertSame([], $out->errors);
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $invoices->findById($inv1->id())?->status());
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $invoices->findById($inv2->id())?->status());
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $invoices->findById($inv3->id())?->status());
    }

    public function test_collects_per_row_errors_without_aborting(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $valid = $this->invoice(2024); $invoices->save($valid);

        $markPaid = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable()));
        $useCase = new ConfirmImportPayments($markPaid);

        // 2 entries: one valid, one with an invoice_id that doesn't exist.
        $missingId = MemberFeeInvoiceId::generate()->value();
        $out = $useCase->handle(new ConfirmImportPaymentsInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            matches:  [
                ['invoice_id' => $valid->id()->value(), 'amount_cents' => 5000, 'paid_at' => '2026-08-01T10:00:00', 'reference' => 'ok'],
                ['invoice_id' => $missingId,            'amount_cents' => 5000, 'paid_at' => '2026-08-01T10:00:00', 'reference' => 'missing'],
            ],
        ));

        $this->assertCount(1, $out->applied);
        $this->assertCount(1, $out->errors);
        $this->assertSame($missingId, $out->errors[0]['invoice_id']);
        $this->assertStringContainsString('not found', $out->errors[0]['error']);
    }

    public function test_non_admin_rejected(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $markPaid = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable()));
        $useCase = new ConfirmImportPayments($markPaid);

        $this->expectException(ForbiddenException::class);
        $useCase->handle(new ConfirmImportPaymentsInput(
            actor:    $this->actor(UserTenantRole::Member),
            tenantId: TenantId::fromString(self::TENANT_ID),
            matches:  [],
        ));
    }

    private function invoice(int $year): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            year:                $year,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable("{$year}-07-15"),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable("{$year}-09-13"),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable("{$year}-07-15"),
        );
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
