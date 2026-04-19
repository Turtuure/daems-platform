<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AdminController
{
    public function __construct(
        private readonly GetAdminStats $getAdminStats,
        private readonly AdminStatsRepositoryInterface $repo,
    ) {}

    public function stats(Request $request): Response
    {
        $output = $this->getAdminStats->execute();

        return Response::json([
            'data' => [
                'members' => [
                    'value'     => $output->members,
                    'change'    => $output->membersChange,
                    'sparkline' => $output->membersSparkline,
                ],
                'pending_applications' => [
                    'value'     => $output->pendingApplications,
                    'change'    => $output->applicationsChange,
                    'sparkline' => $output->applicationsSparkline,
                ],
                'upcoming_events' => [
                    'value'     => $output->upcomingEvents,
                    'change'    => $output->eventsChange,
                    'sparkline' => $output->eventsSparkline,
                ],
                'active_projects' => [
                    'value'     => $output->activeProjects,
                    'change'    => $output->projectsChange,
                    'sparkline' => $output->projectsSparkline,
                ],
                'member_growth' => $output->memberGrowth,
            ],
        ]);
    }

    public function memberGrowth(Request $request): Response
    {
        $allowed = ['30d', '90d', '1y', 'all'];
        $period  = in_array($request->query('period'), $allowed, true)
            ? $request->query('period')
            : '30d';

        return Response::json(['data' => $this->repo->getMemberGrowth($period)]);
    }
}
