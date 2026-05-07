<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenantAdmins;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ListTenantAdminsInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
    ) {}
}
