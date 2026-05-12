<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

enum MemberFeeInvoiceStatus: string
{
    case Pending  = 'PENDING';
    case Paid     = 'PAID';
    case Overdue  = 'OVERDUE';
    case Waived   = 'WAIVED';
    case Reduced  = 'REDUCED';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Overdue || $this === self::Reduced;
    }

    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Waived;
    }
}
