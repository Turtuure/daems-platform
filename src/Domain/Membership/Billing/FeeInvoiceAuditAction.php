<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

enum FeeInvoiceAuditAction: string
{
    case Created          = 'created';
    case Waived           = 'waived';
    case Reduced          = 'reduced';
    case Paid             = 'paid';
    case PaymentReversed  = 'payment_reversed';
    case OverdueFlagged   = 'overdue_flagged';
    case LapsedViaInvoice = 'lapsed_via_invoice';
}
