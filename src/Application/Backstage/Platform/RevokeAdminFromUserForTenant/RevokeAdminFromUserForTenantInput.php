<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class RevokeAdminFromUserForTenantInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly UserId $targetUserId,
        public readonly TenantId $tenantId,
    ) {}
}
