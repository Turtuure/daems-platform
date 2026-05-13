<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RecordManualPayment;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use DateTimeImmutable;

final class RecordManualPaymentInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly int                $amountCents,
        public readonly DateTimeImmutable  $paidAt,
        public readonly string             $method,
        public readonly string             $reference,
    ) {}
}
