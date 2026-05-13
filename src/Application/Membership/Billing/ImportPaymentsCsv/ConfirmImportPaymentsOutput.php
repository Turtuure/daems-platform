<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

final class ConfirmImportPaymentsOutput
{
    /**
     * @param list<string> $applied  invoice IDs that were marked PAID
     * @param list<array{invoice_id:string, error:string}> $errors
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $errors,
    ) {}
}
