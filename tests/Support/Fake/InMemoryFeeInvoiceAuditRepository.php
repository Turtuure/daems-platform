<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class InMemoryFeeInvoiceAuditRepository implements FeeInvoiceAuditRepositoryInterface
{
    /** @var list<FeeInvoiceAudit> */
    public array $rows = [];

    public function append(FeeInvoiceAudit $row): void
    {
        $this->rows[] = $row;
    }

    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(FeeInvoiceAudit $r): bool => $r->invoiceId->equals($invoiceId),
        ));
    }
}
