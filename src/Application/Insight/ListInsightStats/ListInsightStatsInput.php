<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsightStats;

use Daems\Domain\Tenant\TenantId;

final class ListInsightStatsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
    ) {}
}
