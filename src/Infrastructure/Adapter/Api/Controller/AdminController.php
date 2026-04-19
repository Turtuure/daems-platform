<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AdminController
{
    public function __construct(
        private readonly GetAdminStats $getAdminStats,
    ) {}

    public function stats(Request $request): Response
    {
        $output = $this->getAdminStats->execute();

        return Response::json([
            'data' => [
                'members'              => $output->members,
                'pending_applications' => $output->pendingApplications,
                'upcoming_events'      => $output->upcomingEvents,
                'active_projects'      => $output->activeProjects,
            ],
        ]);
    }
}
