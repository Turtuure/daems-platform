<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Events\ListEventsStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListEventsStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
