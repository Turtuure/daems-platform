<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Projects\ListProjectsStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListProjectsStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
