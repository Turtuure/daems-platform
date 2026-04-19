<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Admin\AdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlAdminRepository implements AdminStatsRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function getStats(): AdminStats
    {
        $members = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM users WHERE role = 'member'",
        )['n'] ?? 0);

        $pendingMember = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM member_applications WHERE status = 'pending'",
        )['n'] ?? 0);

        $pendingSupporter = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM supporter_applications WHERE status = 'pending'",
        )['n'] ?? 0);

        $upcomingEvents = (int) ($this->db->queryOne(
            'SELECT COUNT(*) AS n FROM events WHERE event_date >= CURDATE()',
        )['n'] ?? 0);

        $activeProjects = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM projects WHERE status != 'archived'",
        )['n'] ?? 0);

        return new AdminStats(
            members:             $members,
            pendingApplications: $pendingMember + $pendingSupporter,
            upcomingEvents:      $upcomingEvents,
            activeProjects:      $activeProjects,
        );
    }
}
