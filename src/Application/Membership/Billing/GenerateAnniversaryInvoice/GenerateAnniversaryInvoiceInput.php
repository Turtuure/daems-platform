<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GenerateAnniversaryInvoiceInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId   $userId,
    ) {}
}
