<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsightStats;

use Daems\Domain\Insight\InsightRepositoryInterface;

final class ListInsightStats
{
    public function __construct(
        private readonly InsightRepositoryInterface $repo,
    ) {}

    public function execute(ListInsightStatsInput $input): ListInsightStatsOutput
    {
        return new ListInsightStatsOutput(
            stats: $this->repo->statsForTenant($input->tenantId),
        );
    }
}
