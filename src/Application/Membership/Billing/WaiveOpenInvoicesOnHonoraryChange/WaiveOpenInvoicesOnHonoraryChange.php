<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange;

use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice;
use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoiceInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;

/**
 * When a member's membership_type is set to HONORARY, all of their currently
 * open (PENDING/OVERDUE/REDUCED) invoices are waived per § 3.
 *
 * Returns the number of invoices waived. Existing PAID/WAIVED rows are
 * skipped by `listOpenForUser`'s filter (status->isOpen()).
 */
final class WaiveOpenInvoicesOnHonoraryChange
{
    private const REASON = 'Honorary member, fees waived per § 3';

    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly WaiveMemberFeeInvoice               $waive,
    ) {}

    public function handle(WaiveOpenInvoicesOnHonoraryChangeInput $in): int
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can waive invoices');
        }

        $openInvoices = $this->invoices->listOpenForUser($in->tenantId, $in->userId);
        $waived = 0;
        foreach ($openInvoices as $invoice) {
            $this->waive->handle(new WaiveMemberFeeInvoiceInput(
                actor:     $in->actor,
                invoiceId: $invoice->id(),
                reason:    self::REASON,
            ));
            $waived++;
        }
        return $waived;
    }
}
