<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RecordManualPayment;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Shared\Clock;

final class RecordManualPayment
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface  $audit,
        private readonly Clock                               $clock,
    ) {}

    public function handle(RecordManualPaymentInput $in): void
    {
        $invoice = $this->invoices->findById($in->invoiceId);
        if ($invoice === null) {
            throw new \DomainException("Invoice not found: {$in->invoiceId->value()}");
        }
        if (!$in->actor->isAdminIn($invoice->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can record payments');
        }

        $payment = new PaymentRecord(
            paidAt:      $in->paidAt,
            amountCents: $in->amountCents,
            method:      $in->method,
            reference:   $in->reference,
            paidBy:      $in->actor->id,
        );
        $invoice->recordPayment($payment);
        $this->invoices->save($invoice);

        $this->audit->append(FeeInvoiceAudit::record(
            tenantId:  $invoice->tenantId(),
            invoiceId: $invoice->id(),
            action:    FeeInvoiceAuditAction::Paid,
            actor:     $in->actor->id,
            now:       $this->clock->now(),
            payload:   [
                'amount_cents' => $in->amountCents,
                'method'       => $in->method,
                'reference'    => $in->reference,
                'paid_at'      => $in->paidAt->format(\DateTimeImmutable::ATOM),
            ],
        ));
    }
}
