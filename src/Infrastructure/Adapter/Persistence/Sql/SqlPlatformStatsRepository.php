<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Platform\PlatformStats;
use Daems\Domain\Platform\PlatformStatsRepositoryInterface;
use PDO;
use PDOStatement;

final class SqlPlatformStatsRepository implements PlatformStatsRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(): PlatformStats
    {
        return new PlatformStats(
            tenantCount:         $this->tenantCount(),
            userCount:           $this->userCount(),
            dbSizeMb:            $this->dbSizeMb(),
            mysqlUptimeSeconds:  $this->mysqlUptimeSeconds(),
            usersSparkline:      $this->dailyCountsLast14('users', 'created_at'),
            tenantsSparkline:    $this->dailyCountsLast14('tenants', 'created_at'),
            activitySparkline:   $this->dailyCountsLast14('member_applications', 'created_at'),
            tenants:             $this->tenantList(),
            tenantActivity:      $this->tenantActivity(),
            recentActivity:      $this->recentActivity(),
        );
    }

    private function scalarInt(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    private function tenantCount(): int
    {
        return $this->scalarInt('SELECT COUNT(*) FROM tenants WHERE suspended_at IS NULL');
    }

    private function userCount(): int
    {
        return $this->scalarInt('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL');
    }

    private function dbSizeMb(): int
    {
        return $this->scalarInt(
            'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024)
               FROM information_schema.TABLES
              WHERE table_schema = DATABASE()'
        );
    }

    private function mysqlUptimeSeconds(): int
    {
        try {
            $stmt = $this->pdo->prepare("SHOW STATUS LIKE 'Uptime'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['Value']) && is_numeric($row['Value'])) {
                return (int) $row['Value'];
            }
        } catch (\Throwable) {
            // SHOW STATUS may be restricted on some MySQL setups — fall through.
        }
        return 0;
    }

    /**
     * @return list<int>
     */
    private function dailyCountsLast14(string $table, string $column): array
    {
        $sql = "SELECT DATE({$column}) AS d, COUNT(*) AS c
                  FROM {$table}
                 WHERE {$column} >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              GROUP BY DATE({$column})";
        $bucket = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (is_array($r) && isset($r['d'], $r['c']) && is_string($r['d']) && is_numeric($r['c'])) {
                    $bucket[$r['d']] = (int) $r['c'];
                }
            }
        } catch (\Throwable) {
            // Table missing or column missing — return all zeros.
            return array_fill(0, 14, 0);
        }
        $out = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d');
            $out[] = $bucket[$day] ?? 0;
        }
        return $out;
    }

    /**
     * @return list<array{slug:string, name:string, suspended:bool, members:int}>
     */
    private function tenantList(): array
    {
        $sql = 'SELECT t.slug, t.name, t.suspended_at,
                       COALESCE(c.members, 0) AS members
                  FROM tenants t
             LEFT JOIN (
                       SELECT tenant_id, COUNT(*) AS members
                         FROM user_tenants
                        GROUP BY tenant_id
                  ) c ON c.tenant_id = t.id
              ORDER BY t.name';
        $out = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $out[] = [
                    'slug'      => isset($r['slug']) && is_string($r['slug']) ? $r['slug'] : '',
                    'name'      => isset($r['name']) && is_string($r['name']) ? $r['name'] : '',
                    'suspended' => ($r['suspended_at'] ?? null) !== null,
                    'members'   => isset($r['members']) && is_numeric($r['members']) ? (int) $r['members'] : 0,
                ];
            }
        } catch (\Throwable) {
            // ignore
        }
        return $out;
    }

    /**
     * @return array{labels: list<string>, series: list<int>}
     */
    private function tenantActivity(): array
    {
        $series = $this->dailyCountsLast14('users', 'created_at');
        $labels = [];
        for ($i = 13; $i >= 0; $i--) {
            $labels[] = (new \DateTimeImmutable("-{$i} days"))->format('d.m');
        }
        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * @return list<array{type:string, message:string, when:string}>
     */
    private function recentActivity(): array
    {
        $out = [];
        $out = array_merge($out, $this->recentRows('application', "CONCAT(name, ' applied')", 'member_applications', 'created_at', 'created_at IS NOT NULL'));
        $out = array_merge($out, $this->recentRows('user', "CONCAT(name, ' joined')", 'users', 'created_at', 'deleted_at IS NULL'));

        usort($out, static fn(array $a, array $b): int => strcmp($b['when'], $a['when']));
        return array_slice($out, 0, 10);
    }

    /**
     * @return list<array{type:string, message:string, when:string}>
     */
    private function recentRows(string $type, string $messageExpr, string $table, string $tsColumn, string $whereExpr): array
    {
        $out = [];
        try {
            $sql = "SELECT {$messageExpr} AS message, {$tsColumn} AS at
                      FROM {$table}
                     WHERE {$whereExpr}
                  ORDER BY {$tsColumn} DESC
                     LIMIT 5";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $out[] = [
                    'type'    => $type,
                    'message' => isset($r['message']) && is_string($r['message']) ? $r['message'] : '',
                    'when'    => isset($r['at']) && is_string($r['at']) ? $r['at'] : '',
                ];
            }
        } catch (\Throwable) {
            // ignore — partial feed is fine
        }
        return $out;
    }
}
