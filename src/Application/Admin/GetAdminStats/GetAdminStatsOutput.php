<?php

declare(strict_types=1);

namespace Daems\Application\Admin\GetAdminStats;

final class GetAdminStatsOutput
{
    public function __construct(
        public readonly int $members,
        public readonly int $pendingApplications,
        public readonly int $upcomingEvents,
        public readonly int $activeProjects,
        /** @var int[] */
        public readonly array $membersSparkline = [],
        /** @var int[] */
        public readonly array $applicationsSparkline = [],
        /** @var int[] */
        public readonly array $eventsSparkline = [],
        /** @var int[] */
        public readonly array $projectsSparkline = [],
        /** @var int[] */
        public readonly array $forumSparkline = [],
        /** @var int[] */
        public readonly array $insightsSparkline = [],
        public readonly float $membersChange = 0.0,
        public readonly float $applicationsChange = 0.0,
        public readonly float $eventsChange = 0.0,
        public readonly float $projectsChange = 0.0,
        /** @var array{ labels: string[], series: int[] } */
        public readonly array $memberGrowth = ['labels' => [], 'series' => []],
    ) {}
}
