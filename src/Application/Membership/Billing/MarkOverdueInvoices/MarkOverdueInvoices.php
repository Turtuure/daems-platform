<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\MarkOverdueInvoices;

use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class MarkOverdueInvoices
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface         $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface          $audit,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly Clock                                       $clock,
    ) {}

    public function handle(MarkOverdueInvoicesInput $in): int
    {
        $settings = $this->settings->find($in->tenantId);
        $graceDays = $settings?->overdueGraceDays() ?? 30;
        $now = $this->clock->now();

        $candidates = $this->invoices->findOverdueCandidates($in->tenantId, $now, $graceDays);
        $flagged = 0;
        foreach ($candidates as $invoice) {
            $statusBefore = $invoice->status();
            $invoice->markOverdue($now);
            // markOverdue() is idempotent — only flips Pending → Overdue when now >= dueDate.
            if ($invoice->status() === $statusBefore) {
                continue;
            }
            $this->invoices->save($invoice);
            $this->audit->append(FeeInvoiceAudit::record(
                tenantId:  $invoice->tenantId(),
                invoiceId: $invoice->id(),
                action:    FeeInvoiceAuditAction::OverdueFlagged,
                actor:     null,
                now:       $now,
                payload:   [
                    'due_date'   => $invoice->dueDate()->format('Y-m-d'),
                    'grace_days' => $graceDays,
                ],
            ));
            $flagged++;
        }
        return $flagged;
    }
}
