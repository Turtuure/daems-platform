<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReduceMemberFeeInvoice;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class ReduceMemberFeeInvoiceInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly int                $newAmountCents,
        public readonly string             $reason,
    ) {}
}
