<?php

declare(strict_types=1);

namespace Daems\Domain\Admin;

final class AdminStats
{
    public function __construct(
        public readonly int $members,
        public readonly int $pendingApplications,
        public readonly int $upcomingEvents,
        public readonly int $activeProjects,
    ) {}
}
