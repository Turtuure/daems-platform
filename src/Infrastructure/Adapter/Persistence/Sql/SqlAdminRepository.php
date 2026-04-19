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
            forumSparkline:        $this->dailyCountsSafe(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM forum_posts
                 WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)"
            ),
            insightsSparkline:     $this->dailyCountsSafe(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM insights
                 WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)"
            ),
            membersChange:         $this->weekOverWeek(
                'SELECT COUNT(*) AS n FROM users WHERE created_at >= CURDATE() - INTERVAL 6 DAY',
                'SELECT COUNT(*) AS n FROM users WHERE created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY'
            ),
            applicationsChange:    $this->weekOverWeek(
                "SELECT COUNT(*) AS n FROM (
                     SELECT created_at FROM member_applications WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                 ) t",
                "SELECT COUNT(*) AS n FROM (
                     SELECT created_at FROM member_applications WHERE created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY
                 ) t"
            ),
            eventsChange:          $this->weekOverWeek(
                'SELECT COUNT(*) AS n FROM events WHERE created_at >= CURDATE() - INTERVAL 6 DAY',
                'SELECT COUNT(*) AS n FROM events WHERE created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY'
            ),
            projectsChange:        $this->weekOverWeek(
                "SELECT COUNT(*) AS n FROM projects WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND status != 'archived'",
                "SELECT COUNT(*) AS n FROM projects WHERE created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY AND status != 'archived'"
            ),
            memberGrowth:          $this->getMemberGrowth('30d'),
        );
    }

    public function getMemberGrowth(string $period): array
    {
        return match ($period) {
            '90d'   => $this->growthDaily(90),
            '1y'    => $this->growthMonthly(12),
            'all'   => $this->growthAllTime(),
            default => $this->growthDaily(30),
        };
    }

    /** Daily cumulative growth for the last $days days. */
    private function growthDaily(int $days): array
    {
        $rows = $this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM users
             WHERE created_at >= CURDATE() - INTERVAL :days DAY
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            ['days' => $days - 1],
        );

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['d']] = (int) $row['n'];
        }

        $baseline = (int) ($this->db->queryOne(
            'SELECT COUNT(*) AS n FROM users WHERE created_at < CURDATE() - INTERVAL :days DAY',
            ['days' => $days - 1],
        )['n'] ?? 0);

        $labels  = [];
        $series  = [];
        $running = $baseline;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $running += $byDate[$date] ?? 0;
            $labels[] = date('j M', strtotime($date));
            $series[] = $running;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Monthly cumulative growth for the last $months months. */
    private function growthMonthly(int $months): array
    {
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n FROM users
             WHERE created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL :m MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY ym ASC",
            ['m' => $months - 1],
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['ym']] = (int) $row['n'];
        }

        $baseline = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM users
             WHERE created_at < DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL :m MONTH)",
            ['m' => $months - 1],
        )['n'] ?? 0);

        $labels  = [];
        $series  = [];
        $running = $baseline;

        for ($i = $months - 1; $i >= 0; $i--) {
            $ym       = date('Y-m', strtotime("first day of -$i months"));
            $running += $byMonth[$ym] ?? 0;
            $labels[] = date('M \'y', strtotime($ym . '-01'));
            $series[] = $running;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Monthly cumulative growth from the first ever registered user to today. */
    private function growthAllTime(): array
    {
        $first = $this->db->queryOne('SELECT MIN(created_at) AS d FROM users')['d'] ?? null;

        if ($first === null) {
            return ['labels' => [], 'series' => []];
        }

        $rows = $this->db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
             FROM users
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY ym ASC",
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['ym']] = (int) $row['n'];
        }

        $labels  = [];
        $series  = [];
        $running = 0;
        $cursor  = date('Y-m', strtotime($first));
        $today   = date('Y-m');

        while ($cursor <= $today) {
            $running += $byMonth[$cursor] ?? 0;
            $labels[] = date('M \'y', strtotime($cursor . '-01'));
            $series[] = $running;
            $cursor   = date('Y-m', strtotime($cursor . '-01 +1 month'));
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Returns a 7-element array of daily counts (oldest→newest, missing days = 0). */
    private function dailyCounts(string $sql): array
    {
        $rows   = $this->db->query($sql);
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['d']] = (int) $row['n'];
        }

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $result[] = $byDate[date('Y-m-d', strtotime("-{$i} days"))] ?? 0;
        }

        return $result;
    }

    /**
     * Defensive wrapper for optional datasets: if a table is not present yet,
     * keep dashboard stats available instead of failing the whole response.
     */
    private function dailyCountsSafe(string $sql): array
    {
        try {
            return $this->dailyCounts($sql);
        } catch (\Throwable) {
            return [0, 0, 0, 0, 0, 0, 0];
        }
    }

    /** Week-over-week % change: ((this_week - last_week) / last_week) * 100. */
    private function weekOverWeek(string $thisWeekSql, string $lastWeekSql): float
    {
        $this_week = (int) ($this->db->queryOne($thisWeekSql)['n'] ?? 0);
        $last_week = (int) ($this->db->queryOne($lastWeekSql)['n'] ?? 0);

        if ($last_week === 0) {
            return $this_week > 0 ? 100.0 : 0.0;
        }

        return round(($this_week - $last_week) / $last_week * 100, 1);
    }
}
