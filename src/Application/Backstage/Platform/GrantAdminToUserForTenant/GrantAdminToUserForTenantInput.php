<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\GrantAdminToUserForTenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GrantAdminToUserForTenantInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly UserId $targetUserId,
        public readonly TenantId $tenantId,
    ) {}
}
