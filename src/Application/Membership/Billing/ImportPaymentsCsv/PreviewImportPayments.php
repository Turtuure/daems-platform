<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;

/**
 * Two-step CSV import pattern: PREVIEW returns proposed matches without
 * touching state. ConfirmImportPayments (G7) applies the admin-approved set.
 *
 * Confidence labels:
 *  - 'high'             — invoice found AND amount equal within ±2¢
 *  - 'amount_mismatch'  — invoice found but amount diff > 2¢
 *  - 'no_match'         — reference empty OR no unambiguous invoice match
 */
final class PreviewImportPayments
{
    private const AMOUNT_TOLERANCE_CENTS = 2;

    public function __construct(
        private readonly NordeaPaymentCsvParser              $parser,
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
    ) {}

    public function handle(PreviewImportPaymentsInput $in): PreviewImportPaymentsOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can import payments');
        }

        $rows = $this->parser->parse($in->csvContent);
        $results = [];
        foreach ($rows as $row) {
            if ($row->reference === null) {
                $results[] = new PaymentMatchResult(
                    parsed:                $row,
                    matchedInvoiceId:      null,
                    matchedAmountCents:    null,
                    confidence:            PaymentMatchResult::CONFIDENCE_NO_MATCH,
                    reasonForLowConfidence: 'CSV row missing reference',
                );
                continue;
            }
            $invoice = $this->invoices->findByReference($in->tenantId, $row->reference);
            if ($invoice === null) {
                $results[] = new PaymentMatchResult(
                    parsed:                $row,
                    matchedInvoiceId:      null,
                    matchedAmountCents:    null,
                    confidence:            PaymentMatchResult::CONFIDENCE_NO_MATCH,
                    reasonForLowConfidence: "No open invoice for reference '{$row->reference}'",
                );
                continue;
            }
            $diff = abs($invoice->amountCents() - $row->amountCents);
            if ($diff <= self::AMOUNT_TOLERANCE_CENTS) {
                $results[] = new PaymentMatchResult(
                    parsed:                $row,
                    matchedInvoiceId:      $invoice->id(),
                    matchedAmountCents:    $invoice->amountCents(),
                    confidence:            PaymentMatchResult::CONFIDENCE_HIGH,
                    reasonForLowConfidence: null,
                );
            } else {
                $results[] = new PaymentMatchResult(
                    parsed:                $row,
                    matchedInvoiceId:      $invoice->id(),
                    matchedAmountCents:    $invoice->amountCents(),
                    confidence:            PaymentMatchResult::CONFIDENCE_AMOUNT_MISMATCH,
                    reasonForLowConfidence: "Expected {$invoice->amountCents()}¢, got {$row->amountCents}¢ (diff {$diff})",
                );
            }
        }
        return new PreviewImportPaymentsOutput($results);
    }
}
