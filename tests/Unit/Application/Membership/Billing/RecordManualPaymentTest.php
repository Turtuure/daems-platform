<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment;
use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPaymentInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RecordManualPaymentTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';

    public function test_admin_records_payment(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $invoice = $this->seedInvoice();
        $invoices->save($invoice);

        $useCase = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable('2026-08-01T10:00:00')));
        $useCase->handle(new RecordManualPaymentInput(
            actor:       $this->actor(),
            invoiceId:   $invoice->id(),
            amountCents: 5000,
            paidAt:      new DateTimeImmutable('2026-08-01T10:00:00'),
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
        ));

        $loaded = $invoices->findById($invoice->id());
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $loaded?->status());
        $this->assertSame(5000, $loaded?->paidAmountCents());
        $this->assertSame('bank_transfer', $loaded?->paidMethod());
        $this->assertCount(1, $audit->listForInvoice($invoice->id()));
    }

    public function test_non_admin_rejected(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $invoice = $this->seedInvoice();
        $invoices->save($invoice);

        $useCase = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable()));
        $this->expectException(ForbiddenException::class);
        $useCase->handle(new RecordManualPaymentInput(
            actor:       $this->actor(UserTenantRole::Member),
            invoiceId:   $invoice->id(),
            amountCents: 5000,
            paidAt:      new DateTimeImmutable(),
            method:      'cash',
            reference:   '',
        ));
    }

    public function test_already_paid_throws(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $invoice = $this->seedInvoice();
        $invoice->recordPayment(new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-07-20'),
            amountCents: 5000,
            method:      'cash',
            reference:   'prepaid',
            paidBy:      UserId::fromString(self::ADMIN_ID),
        ));
        $invoices->save($invoice);

        $useCase = new RecordManualPayment($invoices, $audit, new FrozenClock(new DateTimeImmutable()));
        $this->expectException(InvoiceAlreadyPaidException::class);
        $useCase->handle(new RecordManualPaymentInput(
            actor:       $this->actor(),
            invoiceId:   $invoice->id(),
            amountCents: 5000,
            paidAt:      new DateTimeImmutable(),
            method:      'bank_transfer',
            reference:   'dup',
        ));
    }

    private function seedInvoice(): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-09-13'),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15'),
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
