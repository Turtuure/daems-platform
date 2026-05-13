<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class WaiveOpenInvoicesOnHonoraryChangeInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly UserId     $userId,
    ) {}
}
