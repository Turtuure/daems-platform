<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveMemberFeeInvoice;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class WaiveMemberFeeInvoiceInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly string             $reason,
    ) {}
}
