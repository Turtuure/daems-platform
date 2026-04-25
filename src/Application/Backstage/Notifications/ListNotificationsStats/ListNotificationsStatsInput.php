<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Notifications\ListNotificationsStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListNotificationsStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
