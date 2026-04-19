<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Admin\AdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlAdminRepository implements AdminStatsRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function getStatsForTenant(TenantId $tenantId): AdminStats
    {
        $tid = $tenantId->value();

        $members = $this->scalarInt($this->db->queryOne(
            'SELECT COUNT(DISTINCT user_id) AS n FROM user_tenants WHERE tenant_id = ? AND left_at IS NULL',
            [$tid],
        ), 'n');

        $pendingMember = $this->scalarInt($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM member_applications WHERE tenant_id = ? AND status = 'pending'",
            [$tid],
        ), 'n');

        $pendingSupporter = $this->scalarInt($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM supporter_applications WHERE tenant_id = ? AND status = 'pending'",
            [$tid],
        ), 'n');

        $upcomingEvents = $this->scalarInt($this->db->queryOne(
            'SELECT COUNT(*) AS n FROM events WHERE tenant_id = ? AND event_date >= CURDATE()',
            [$tid],
        ), 'n');

        $activeProjects = $this->scalarInt($this->db->queryOne(
            "SELECT COUNT(*) AS n FROM projects WHERE tenant_id = ? AND status != 'archived'",
            [$tid],
        ), 'n');

        return new AdminStats(
            members:               $members,
            pendingApplications:   $pendingMember + $pendingSupporter,
            upcomingEvents:        $upcomingEvents,
            activeProjects:        $activeProjects,
            membersSparkline:      $this->dailyCounts(
                "SELECT DATE(joined_at) AS d, COUNT(DISTINCT user_id) AS n FROM user_tenants
                 WHERE tenant_id = ? AND joined_at >= CURDATE() - INTERVAL 6 DAY AND left_at IS NULL
                 GROUP BY DATE(joined_at)",
                [$tid],
            ),
            applicationsSparkline: $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n
                 FROM (
                     SELECT created_at FROM member_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 ) combined
                 GROUP BY DATE(created_at)",
                [$tid, $tid],
            ),
            eventsSparkline:       $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM events
                 WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)",
                [$tid],
            ),
            projectsSparkline:     $this->dailyCounts(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM projects
                 WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)",
                [$tid],
            ),
            forumSparkline:        $this->dailyCountsSafe(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM forum_posts
                 WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)",
                [$tid],
            ),
            insightsSparkline:     $this->dailyCountsSafe(
                "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM insights
                 WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 GROUP BY DATE(created_at)",
                [$tid],
            ),
            membersChange:         $this->weekOverWeek(
                'SELECT COUNT(DISTINCT user_id) AS n FROM user_tenants WHERE tenant_id = ? AND joined_at >= CURDATE() - INTERVAL 6 DAY AND left_at IS NULL',
                'SELECT COUNT(DISTINCT user_id) AS n FROM user_tenants WHERE tenant_id = ? AND joined_at >= CURDATE() - INTERVAL 13 DAY AND joined_at < CURDATE() - INTERVAL 6 DAY AND left_at IS NULL',
                [$tid],
            ),
            applicationsChange:    $this->weekOverWeek(
                "SELECT COUNT(*) AS n FROM (
                     SELECT created_at FROM member_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY
                 ) t",
                "SELECT COUNT(*) AS n FROM (
                     SELECT created_at FROM member_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY
                     UNION ALL
                     SELECT created_at FROM supporter_applications WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY
                 ) t",
                [$tid, $tid],
            ),
            eventsChange:          $this->weekOverWeek(
                'SELECT COUNT(*) AS n FROM events WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY',
                'SELECT COUNT(*) AS n FROM events WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY',
                [$tid],
            ),
            projectsChange:        $this->weekOverWeek(
                "SELECT COUNT(*) AS n FROM projects WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 6 DAY AND status != 'archived'",
                "SELECT COUNT(*) AS n FROM projects WHERE tenant_id = ? AND created_at >= CURDATE() - INTERVAL 13 DAY AND created_at < CURDATE() - INTERVAL 6 DAY AND status != 'archived'",
                [$tid],
            ),
            memberGrowth:          $this->getMemberGrowthForTenant('30d', $tenantId),
        );
    }

    /** @return array{labels: array<string>, series: array<int>} */
    public function getMemberGrowthForTenant(string $period, TenantId $tenantId): array
    {
        return match ($period) {
            '90d'   => $this->growthDaily(90, $tenantId),
            '1y'    => $this->growthMonthly(12, $tenantId),
            'all'   => $this->growthAllTime($tenantId),
            default => $this->growthDaily(30, $tenantId),
        };
    }

    /**
     * Daily cumulative growth for the last $days days.
     *
     * @return array{labels: array<string>, series: array<int>}
     */
    private function growthDaily(int $days, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            "SELECT DATE(joined_at) AS d, COUNT(DISTINCT user_id) AS n FROM user_tenants
             WHERE tenant_id = :tid AND joined_at >= CURDATE() - INTERVAL :days DAY AND left_at IS NULL
             GROUP BY DATE(joined_at)
             ORDER BY d ASC",
            ['tid' => $tenantId->value(), 'days' => $days - 1],
        );

        $byDate = [];
        foreach ($rows as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            $byDate[$d] = $this->intVal($row['n'] ?? null);
        }

        $baseline = $this->scalarInt($this->db->queryOne(
            'SELECT COUNT(DISTINCT user_id) AS n FROM user_tenants WHERE tenant_id = :tid AND joined_at < CURDATE() - INTERVAL :days DAY AND left_at IS NULL',
            ['tid' => $tenantId->value(), 'days' => $days - 1],
        ), 'n');

        $labels  = [];
        $series  = [];
        $running = $baseline;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', (int) strtotime("-{$i} days"));
            $running += $byDate[$date] ?? 0;
            $labels[] = date('j M', (int) strtotime($date));
            $series[] = $running;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Monthly cumulative growth for the last $months months.
     *
     * @return array{labels: array<string>, series: array<int>}
     */
    private function growthMonthly(int $months, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            "SELECT DATE_FORMAT(joined_at, '%Y-%m') AS ym, COUNT(DISTINCT user_id) AS n FROM user_tenants
             WHERE tenant_id = :tid AND joined_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL :m MONTH) AND left_at IS NULL
             GROUP BY DATE_FORMAT(joined_at, '%Y-%m')
             ORDER BY ym ASC",
            ['tid' => $tenantId->value(), 'm' => $months - 1],
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $ym = is_string($row['ym'] ?? null) ? (string) $row['ym'] : '';
            $byMonth[$ym] = $this->intVal($row['n'] ?? null);
        }

        $baseline = $this->scalarInt($this->db->queryOne(
            "SELECT COUNT(DISTINCT user_id) AS n FROM user_tenants
             WHERE tenant_id = :tid AND joined_at < DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL :m MONTH) AND left_at IS NULL",
            ['tid' => $tenantId->value(), 'm' => $months - 1],
        ), 'n');

        $labels  = [];
        $series  = [];
        $running = $baseline;

        for ($i = $months - 1; $i >= 0; $i--) {
            $ym       = date('Y-m', (int) strtotime("first day of -$i months"));
            $running += $byMonth[$ym] ?? 0;
            $labels[] = date('M \'y', (int) strtotime($ym . '-01'));
            $series[] = $running;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Monthly cumulative growth from the first ever joined user to today, within tenant.
     *
     * @return array{labels: array<string>, series: array<int>}
     */
    private function growthAllTime(TenantId $tenantId): array
    {
        $firstRow = $this->db->queryOne('SELECT MIN(joined_at) AS d FROM user_tenants WHERE tenant_id = ? AND left_at IS NULL', [$tenantId->value()]);
        $firstRaw = $firstRow !== null ? ($firstRow['d'] ?? null) : null;
        $first    = is_string($firstRaw) ? $firstRaw : null;

        if ($first === null) {
            return ['labels' => [], 'series' => []];
        }

        $rows = $this->db->query(
            "SELECT DATE_FORMAT(joined_at, '%Y-%m') AS ym, COUNT(DISTINCT user_id) AS n
             FROM user_tenants
             WHERE tenant_id = ? AND left_at IS NULL
             GROUP BY DATE_FORMAT(joined_at, '%Y-%m')
             ORDER BY ym ASC",
            [$tenantId->value()],
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $ym = is_string($row['ym'] ?? null) ? (string) $row['ym'] : '';
            $byMonth[$ym] = $this->intVal($row['n'] ?? null);
        }

        $labels  = [];
        $series  = [];
        $running = 0;
        $cursor  = date('Y-m', (int) strtotime($first));
        $today   = date('Y-m');

        while ($cursor <= $today) {
            $running += $byMonth[$cursor] ?? 0;
            $labels[] = date('M \'y', (int) strtotime($cursor . '-01'));
            $series[] = $running;
            $cursor   = date('Y-m', (int) strtotime($cursor . '-01 +1 month'));
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Returns a 7-element array of daily counts (oldest→newest, missing days = 0).
     *
     * @param array<int, string|int> $params
     */
    private function dailyCounts(string $sql, array $params): array
    {
        $rows   = $this->db->query($sql, $params);
        $byDate = [];
        foreach ($rows as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            $byDate[$d] = $this->intVal($row['n'] ?? null);
        }

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $result[] = $byDate[date('Y-m-d', (int) strtotime("-{$i} days"))] ?? 0;
        }

        return $result;
    }

    /**
     * Defensive wrapper for optional datasets.
     *
     * @param array<int, string|int> $params
     */
    private function dailyCountsSafe(string $sql, array $params): array
    {
        try {
            return $this->dailyCounts($sql, $params);
        } catch (\Throwable) {
            return [0, 0, 0, 0, 0, 0, 0];
        }
    }

    /** @param array<string, mixed>|null $row */
    private function scalarInt(?array $row, string $key): int
    {
        return $this->intVal($row[$key] ?? null);
    }

    private function intVal(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }

    /**
     * Week-over-week % change.
     *
     * @param array<int, string|int> $params
     */
    private function weekOverWeek(string $thisWeekSql, string $lastWeekSql, array $params): float
    {
        $this_week = $this->scalarInt($this->db->queryOne($thisWeekSql, $params), 'n');
        $last_week = $this->scalarInt($this->db->queryOne($lastWeekSql, $params), 'n');

        if ($last_week === 0) {
            return $this_week > 0 ? 100.0 : 0.0;
        }

        return round(($this_week - $last_week) / $last_week * 100, 1);
    }
}
