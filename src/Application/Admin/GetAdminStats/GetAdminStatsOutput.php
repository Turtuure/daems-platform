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
    ) {}
}
