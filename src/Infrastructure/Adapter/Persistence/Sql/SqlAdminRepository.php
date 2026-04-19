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
            'SELECT COUNT(*) AS n FROM users',
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
            members:               $members,
            pendingApplications:   $pendingMember + $pendingSupporter,
            upcomingEvents:        $upcomingEvents,
            activeProjects:        $activeProjects,
            membersSparkline:      $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM users
                 WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)"
            ),
            applicationsSparkline: $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n
                 FROM (
                     SELECT created_at FROM member_applications WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 ) combined
                 GROUP BY DATE(created_at)"
            ),
            eventsSparkline:       $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM events
                 WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)"
            ),
            projectsSparkline:     $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM projects
                 WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)"
            ),
        );
    }

    /** Returns a 7-element array of daily counts (oldest→newest, missing days = 0). */
    private function dailyCounts(string $sql): array
    {
        $rows = $this->db->query($sql);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['d']] = (int) $row['n'];
        }

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = $byDate[$date] ?? 0;
        }

        return $result;
    }
}
