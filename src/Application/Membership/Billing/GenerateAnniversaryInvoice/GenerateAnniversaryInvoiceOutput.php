<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

final class GenerateAnniversaryInvoiceOutput
{
    public function __construct(
        public readonly bool    $created,
        public readonly string  $invoiceId,
        public readonly int     $amountCents,
        public readonly ?string $overrideId,
    ) {}
}
