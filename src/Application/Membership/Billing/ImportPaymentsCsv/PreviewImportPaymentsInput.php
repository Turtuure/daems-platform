<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class PreviewImportPaymentsInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly string     $csvContent,
    ) {}
}
