<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Tenant\EnableModuleForTenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class EnableModuleForTenantInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $moduleSlug,
    ) {}
}
