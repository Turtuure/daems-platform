<?php

declare(strict_types=1);

namespace Daems\Domain\Admin;

interface AdminStatsRepositoryInterface
{
    public function getStats(): AdminStats;

    /**
     * Returns cumulative member growth series for the given period.
     * Period: '30d' | '90d' | '1y' | 'all'
     *
     * @return array{ labels: string[], series: int[] }
     */
    public function getMemberGrowth(string $period): array;
}
