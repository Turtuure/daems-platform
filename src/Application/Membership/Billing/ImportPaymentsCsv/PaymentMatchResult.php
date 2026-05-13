<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class PaymentMatchResult
{
    public const CONFIDENCE_HIGH            = 'high';
    public const CONFIDENCE_AMOUNT_MISMATCH = 'amount_mismatch';
    public const CONFIDENCE_NO_MATCH        = 'no_match';

    public function __construct(
        public readonly ParsedPaymentRow    $parsed,
        public readonly ?MemberFeeInvoiceId $matchedInvoiceId,
        public readonly ?int                $matchedAmountCents,
        public readonly string              $confidence,
        public readonly ?string             $reasonForLowConfidence,
    ) {}
}
