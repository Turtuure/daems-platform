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
    ) {}
}
