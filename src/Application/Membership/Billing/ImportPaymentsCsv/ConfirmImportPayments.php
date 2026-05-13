<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment;
use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPaymentInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use DateTimeImmutable;

/**
 * Applies admin-confirmed CSV matches by dispatching RecordManualPayment per
 * row with method='csv_import'. Per-row failures are collected as `errors`
 * so a single bad row doesn't abort the whole import.
 */
final class ConfirmImportPayments
{
    public function __construct(
        private readonly RecordManualPayment $markPaid,
    ) {}

    public function handle(ConfirmImportPaymentsInput $in): ConfirmImportPaymentsOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can confirm payment imports');
        }

        $applied = [];
        $errors = [];
        foreach ($in->matches as $m) {
            $invoiceIdRaw = $m['invoice_id'];
            try {
                $this->markPaid->handle(new RecordManualPaymentInput(
                    actor:       $in->actor,
                    invoiceId:   MemberFeeInvoiceId::fromString($invoiceIdRaw),
                    amountCents: $m['amount_cents'],
                    paidAt:      new DateTimeImmutable($m['paid_at']),
                    method:      'csv_import',
                    reference:   $m['reference'],
                ));
                $applied[] = $invoiceIdRaw;
            } catch (\Throwable $e) {
                $errors[] = ['invoice_id' => $invoiceIdRaw, 'error' => $e->getMessage()];
            }
        }
        return new ConfirmImportPaymentsOutput($applied, $errors);
    }
}
