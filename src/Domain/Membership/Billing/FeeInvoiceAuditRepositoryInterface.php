<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

interface FeeInvoiceAuditRepositoryInterface
{
    public function append(FeeInvoiceAudit $row): void;

    /** @return list<FeeInvoiceAudit> */
    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array;
}
