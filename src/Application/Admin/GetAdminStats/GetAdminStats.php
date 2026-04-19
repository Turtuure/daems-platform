<?php

declare(strict_types=1);

namespace Daems\Application\Admin\GetAdminStats;

use Daems\Domain\Admin\AdminStatsRepositoryInterface;

final class GetAdminStats
{
    public function __construct(
        private readonly AdminStatsRepositoryInterface $repo,
    ) {}

    public function execute(): GetAdminStatsOutput
    {
        $stats = $this->repo->getStats();

        return new GetAdminStatsOutput(
            members:               $stats->members,
            pendingApplications:   $stats->pendingApplications,
            upcomingEvents:        $stats->upcomingEvents,
            activeProjects:        $stats->activeProjects,
            membersSparkline:      $stats->membersSparkline,
            applicationsSparkline: $stats->applicationsSparkline,
            eventsSparkline:       $stats->eventsSparkline,
            projectsSparkline:     $stats->projectsSparkline,
            membersChange:         $stats->membersChange,
            applicationsChange:    $stats->applicationsChange,
            eventsChange:          $stats->eventsChange,
            projectsChange:        $stats->projectsChange,
        );
    }
}
