<?php

declare(strict_types=1);

namespace Daems\Domain\Admin;

use Daems\Domain\Tenant\TenantId;

interface AdminStatsRepositoryInterface
{
    public function getStatsForTenant(TenantId $tenantId): AdminStats;

    /**
     * Returns cumulative member growth series for the given period.
     * Period: '30d' | '90d' | '1y' | 'all'
     *
     * @return array{ labels: string[], series: int[] }
     */
    public function getMemberGrowthForTenant(string $period, TenantId $tenantId): array;
}
