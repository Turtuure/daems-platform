<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

final class PreviewImportPaymentsOutput
{
    /** @param list<PaymentMatchResult> $results */
    public function __construct(public readonly array $results) {}

    public function highConfidenceCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn(PaymentMatchResult $r): bool => $r->confidence === PaymentMatchResult::CONFIDENCE_HIGH,
        ));
    }
}
