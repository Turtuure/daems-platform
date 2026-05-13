<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ConfirmImportPaymentsInput
{
    /**
     * @param list<array{invoice_id:string, amount_cents:int, paid_at:string, reference:string}> $matches
     */
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly array      $matches,
    ) {}
}
