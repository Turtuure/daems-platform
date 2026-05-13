<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReduceMemberFeeInvoice;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class ReduceMemberFeeInvoice
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface  $audit,
        private readonly Clock                               $clock,
    ) {}

    public function handle(ReduceMemberFeeInvoiceInput $in): void
    {
        $invoice = $this->invoices->findById($in->invoiceId);
        if ($invoice === null) {
            throw new \DomainException("Invoice not found: {$in->invoiceId->value()}");
        }
        if (!$in->actor->isAdminIn($invoice->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can reduce invoices');
        }

        $now = $this->clock->now();
        $amountBefore = $invoice->amountCents();
        $invoice->reduce($in->newAmountCents, $in->actor->id, $in->reason, $now);
        $this->invoices->save($invoice);

        $this->audit->append(FeeInvoiceAudit::record(
            tenantId:  $invoice->tenantId(),
            invoiceId: $invoice->id(),
            action:    FeeInvoiceAuditAction::Reduced,
            actor:     $in->actor->id,
            now:       $now,
            payload:   [
                'reason'        => $in->reason,
                'amount_before' => $amountBefore,
                'amount_after'  => $in->newAmountCents,
            ],
        ));
    }
}
