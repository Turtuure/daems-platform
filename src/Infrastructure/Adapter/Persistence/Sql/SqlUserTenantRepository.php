<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PDO;

final class SqlUserTenantRepository implements UserTenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }
        $role = $row['role'] ?? null;
        return is_string($role) ? UserTenantRole::tryFrom($role) : null;
    }

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at, left_at)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value(), $role->value]);
    }

    public function detach(UserId $userId, TenantId $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_tenants SET left_at = NOW() WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
    }

    public function markAllLeftForUser(string $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_tenants SET left_at = ? WHERE user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $userId]);
    }

    /** @return list<UserTenantRole> */
    public function rolesForUser(UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_string($value)) {
                $role = UserTenantRole::tryFrom($value);
                if ($role !== null) {
                    $out[] = $role;
                }
            }
        }
        return $out;
    }

    /**
     * @return array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function membershipStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Totals (full history, scoped to active memberships).
        $totalRow = $this->fetchOneAssoc(
            'SELECT
                COUNT(*) AS total_members,
                SUM(CASE WHEN ut.joined_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_members,
                SUM(CASE WHEN u.membership_type   = ? THEN 1 ELSE 0 END) AS supporters,
                SUM(CASE WHEN u.membership_status = ? THEN 1 ELSE 0 END) AS inactive
             FROM user_tenants ut
             INNER JOIN users u ON u.id = ut.user_id
             WHERE ut.tenant_id = ? AND ut.left_at IS NULL',
            ['supporter', 'inactive', $tid],
        );

        // Sparkline templates: 30 entries each, today = last entry.
        $base = new \DateTimeImmutable('today');
        $totalDays      = [];
        $supporterDays  = [];
        for ($i = 29; $i >= 0; $i--) {
            $key = $base->modify("-{$i} days")->format('Y-m-d');
            $totalDays[$key]     = 0;
            $supporterDays[$key] = 0;
        }

        // Daily joined_at counts split by supporter flag, scoped to last 30 days.
        $rows = $this->fetchAllAssoc(
            'SELECT
                DATE(ut.joined_at) AS d,
                CASE WHEN u.membership_type = ? THEN 1 ELSE 0 END AS is_supporter,
                COUNT(*) AS c
             FROM user_tenants ut
             INNER JOIN users u ON u.id = ut.user_id
             WHERE ut.tenant_id = ?
               AND ut.left_at IS NULL
               AND ut.joined_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(ut.joined_at), is_supporter',
            ['supporter', $tid],
        );

        foreach ($rows as $row) {
            $d           = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            $isSupporter = self::asInt($row, 'is_supporter') === 1;
            $cnt         = self::asInt($row, 'c');
            if (isset($totalDays[$d])) {
                $totalDays[$d] += $cnt;
                if ($isSupporter) {
                    $supporterDays[$d] += $cnt;
                }
            }
        }

        $totalSeries     = self::seriesFromMap($totalDays);
        $supporterSeries = self::seriesFromMap($supporterDays);

        return [
            'total_members' => [
                'value'     => self::asNullableInt($totalRow, 'total_members'),
                'sparkline' => $totalSeries,
            ],
            'new_members' => [
                'value'     => self::asNullableInt($totalRow, 'new_members'),
                'sparkline' => $totalSeries,
            ],
            'supporters' => [
                'value'     => self::asNullableInt($totalRow, 'supporters'),
                'sparkline' => $supporterSeries,
            ],
            'inactive' => [
                'value'     => self::asNullableInt($totalRow, 'inactive'),
                'sparkline' => [], // filled by ListMembersStats use case from MemberStatusAuditRepository
            ],
        ];
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>
     */
    private function fetchOneAssoc(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAllAssoc(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, int> $map
     * @return list<array{date: string, value: int}>
     */
    private static function seriesFromMap(array $map): array
    {
        $out = [];
        foreach ($map as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function asInt(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }

    /** @param array<string, mixed> $row */
    private static function asNullableInt(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if ($v === null) {
            return 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }
}
