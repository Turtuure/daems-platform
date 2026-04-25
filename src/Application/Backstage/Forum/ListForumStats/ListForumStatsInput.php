<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListForumStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
