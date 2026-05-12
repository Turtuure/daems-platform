<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MemberFeeInvoiceTest extends TestCase
{
    public function test_issued_starts_pending(): void
    {
        $inv = $this->newInvoice();
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $inv->status());
        $this->assertNull($inv->paidAt());
    }

    public function test_mark_overdue_when_pending(): void
    {
        $inv = $this->newInvoice();
        $now = new DateTimeImmutable('2026-10-15T00:00:00');
        $inv->markOverdue($now);
        $this->assertSame(MemberFeeInvoiceStatus::Overdue, $inv->status());
    }

    public function test_mark_overdue_skips_when_already_paid(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment($this->payment());
        $inv->markOverdue(new DateTimeImmutable('2026-10-15T00:00:00'));
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $inv->status());
    }

    public function test_record_payment_flips_to_paid(): void
    {
        $inv = $this->newInvoice();
        $pmt = $this->payment();
        $inv->recordPayment($pmt);

        $this->assertSame(MemberFeeInvoiceStatus::Paid, $inv->status());
        $this->assertEquals($pmt->paidAt(), $inv->paidAt());
        $this->assertSame(5000, $inv->paidAmountCents());
        $this->assertSame('bank_transfer', $inv->paidMethod());
    }

    public function test_record_payment_rejects_already_paid(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment($this->payment());

        $this->expectException(InvoiceAlreadyPaidException::class);
        $inv->recordPayment($this->payment());
    }

    public function test_waive_flips_to_waived(): void
    {
        $inv = $this->newInvoice();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000aa');
        $now = new DateTimeImmutable('2026-10-01T00:00:00');
        $inv->waive($actor, 'Pitkäaikaissairaus', $now);

        $this->assertSame(MemberFeeInvoiceStatus::Waived, $inv->status());
        $this->assertEquals($now, $inv->waivedAt());
        $this->assertEquals($actor, $inv->waivedBy());
        $this->assertSame('Pitkäaikaissairaus', $inv->waiveReason());
    }

    public function test_reduce_sets_original_amount(): void
    {
        $inv = $this->newInvoice();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $now = new DateTimeImmutable('2026-09-20T00:00:00');
        $inv->reduce(2500, $actor, 'Sosiaalinen alennus', $now);

        $this->assertSame(MemberFeeInvoiceStatus::Reduced, $inv->status());
        $this->assertSame(2500, $inv->amountCents());
        $this->assertSame(5000, $inv->originalAmountCents());
    }

    public function test_reduce_rejects_zero_or_negative(): void
    {
        $inv = $this->newInvoice();
        $this->expectException(\InvalidArgumentException::class);
        $inv->reduce(0, UserId::fromString('01958000-0000-7000-8000-0000000000cc'), 'r', new DateTimeImmutable());
    }

    public function test_reduce_rejects_amount_above_original(): void
    {
        $inv = $this->newInvoice();
        $this->expectException(\InvalidArgumentException::class);
        $inv->reduce(6000, UserId::fromString('01958000-0000-7000-8000-0000000000cc'), 'r', new DateTimeImmutable());
    }

    private function newInvoice(): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
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
            createdAt:           new DateTimeImmutable('2026-07-15T02:00:00'),
        );
    }

    private function payment(): PaymentRecord
    {
        return new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-08-01T10:00:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      UserId::fromString('01958000-0000-7000-8000-0000000000ad'),
        );
    }
}
