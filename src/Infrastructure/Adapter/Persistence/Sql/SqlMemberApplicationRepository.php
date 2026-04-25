<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MemberApplication $app): void
    {
        $this->db->execute(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app->id()->value(),
                $app->tenantId()->value(),
                $app->name(),
                $app->email(),
                $app->dateOfBirth(),
                $app->country(),
                $app->motivation(),
                $app->howHeard(),
                $app->status(),
            ],
        );
    }

    public function listPendingForTenant(TenantId $tenantId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM member_applications
             WHERE tenant_id = ? AND status = 'pending'
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId->value());
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $r): MemberApplication => $this->hydrate($r), $rows);
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
    {
        $row = $this->db->queryOne(
            'SELECT * FROM member_applications WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        UserId $decidedBy,
        ?string $note,
        \DateTimeImmutable $decidedAt,
    ): void {
        $this->db->execute(
            'UPDATE member_applications
                SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
              WHERE id = ? AND tenant_id = ?',
            [
                $decision,
                $decidedAt->format('Y-m-d H:i:s'),
                $decidedBy->value(),
                $note,
                $id,
                $tenantId->value(),
            ],
        );

        // Audit entry to member_register_audit (application-scoped)
        $this->db->execute(
            'INSERT INTO member_register_audit (id, tenant_id, application_id, action, performed_by, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
                $tenantId->value(),
                $id,
                $decision,
                $decidedBy->value(),
                $note,
            ],
        );
    }

    public function statsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: pending count + 30d approved/rejected counts + avg helpers.
        $totalsRow = $this->db->queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'approved' AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS approved_30d,
                SUM(CASE WHEN status = 'rejected' AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS rejected_30d,
                SUM(CASE WHEN decided_at IS NOT NULL AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS decided_count,
                COALESCE(SUM(CASE WHEN decided_at IS NOT NULL AND decided_at >= (NOW() - INTERVAL 30 DAY)
                                  THEN TIMESTAMPDIFF(HOUR, created_at, decided_at) ELSE 0 END), 0) AS decided_total_hours
             FROM member_applications
             WHERE tenant_id = ?",
            [$tid],
        ) ?? [];

        $pendingValue       = self::asInt($totalsRow, 'pending');
        $approvedValue      = self::asInt($totalsRow, 'approved_30d');
        $rejectedValue      = self::asInt($totalsRow, 'rejected_30d');
        $decidedCount       = self::asInt($totalsRow, 'decided_count');
        $decidedTotalHours  = self::asInt($totalsRow, 'decided_total_hours');

        // Pending sparkline (created_at, status = 'pending').
        $pendingSpark = $this->dailySeries(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'pending'
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)",
            [$tid],
        );

        // Approved sparkline (decided_at, status = 'approved').
        $approvedSpark = $this->dailySeries(
            "SELECT DATE(decided_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'approved'
                AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)",
            [$tid],
        );

        // Rejected sparkline (decided_at, status = 'rejected').
        $rejectedSpark = $this->dailySeries(
            "SELECT DATE(decided_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'rejected'
                AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)",
            [$tid],
        );

        return [
            'pending'             => ['value' => $pendingValue,  'sparkline' => $pendingSpark],
            'approved_30d'        => ['value' => $approvedValue, 'sparkline' => $approvedSpark],
            'rejected_30d'        => ['value' => $rejectedValue, 'sparkline' => $rejectedSpark],
            'decided_count'       => $decidedCount,
            'decided_total_hours' => $decidedTotalHours,
        ];
    }

    public function notificationStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: pending count + oldest pending age (days).
        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest
               FROM member_applications
              WHERE tenant_id = ? AND status = 'pending'",
            [$tid],
        ) ?? [];

        $pendingCount = self::asInt($countRow, 'n');
        $oldestAge    = self::asInt($countRow, 'oldest');

        // Sparkline: 30-day BACKWARD by created_at, ALL statuses (incoming volume).
        $sparkline = $this->dailySeries(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid],
        );

        return [
            'pending_count'           => $pendingCount,
            'created_at_daily_30d'    => $sparkline,
            'oldest_pending_age_days' => $oldestAge,
        ];
    }

    /**
     * Run a daily-count query and zero-fill into a 30-entry sparkline (today = last entry).
     *
     * @param list<scalar|null> $params
     * @return list<array{date: string, value: int}>
     */
    private function dailySeries(string $sql, array $params): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }

        foreach ($this->db->query($sql, $params) as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            if ($d === '' || !isset($days[$d])) {
                continue;
            }
            $n = $row['n'] ?? null;
            if (is_int($n)) {
                $days[$d] += $n;
            } elseif (is_string($n) && is_numeric($n)) {
                $days[$d] += (int) $n;
            }
        }

        $out = [];
        foreach ($days as $date => $value) {
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
    private function hydrate(array $row): MemberApplication
    {
        $country  = $row['country'] ?? null;
        $howHeard = $row['how_heard'] ?? null;

        $createdAt = $row['created_at'] ?? null;

        return new MemberApplication(
            MemberApplicationId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'name'),
            self::str($row, 'email'),
            self::str($row, 'date_of_birth'),
            is_string($country) ? $country : null,
            self::str($row, 'motivation'),
            is_string($howHeard) ? $howHeard : null,
            self::str($row, 'status'),
            is_string($createdAt) ? $createdAt : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }
}
