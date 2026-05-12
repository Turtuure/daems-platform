<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\MarkOverdueInvoices;

use Daems\Domain\Tenant\TenantId;

final class MarkOverdueInvoicesInput
{
    public function __construct(public readonly TenantId $tenantId) {}
}
