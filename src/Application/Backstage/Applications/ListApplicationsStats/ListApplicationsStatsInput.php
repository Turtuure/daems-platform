<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Applications\ListApplicationsStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListApplicationsStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
