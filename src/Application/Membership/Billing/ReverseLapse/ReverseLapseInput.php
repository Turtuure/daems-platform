<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReverseLapse;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ReverseLapseInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly UserId     $userId,
        public readonly string     $justification,
    ) {}
}
