<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use DateTimeImmutable;

final class ParsedPaymentRow
{
    public function __construct(
        public readonly int               $rowNumber,
        public readonly ?string           $reference,
        public readonly int               $amountCents,
        public readonly DateTimeImmutable $valueDate,
        public readonly string            $payerName,
        public readonly string            $rawLine,
    ) {}
}
