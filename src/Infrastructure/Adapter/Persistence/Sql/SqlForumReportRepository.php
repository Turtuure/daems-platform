<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use DateTimeImmutable;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\Concerns\DailyStatsHelpers;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumReportRepository implements ForumReportRepositoryInterface
{
    use DailyStatsHelpers;

    public function __construct(private readonly Connection $db) {}

    public function upsert(ForumReport $report): void
    {
        $this->db->execute(
            'INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id,
                 reason_category, reason_detail, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                reason_category = VALUES(reason_category),
                reason_detail   = VALUES(reason_detail),
                status          = VALUES(status),
                created_at      = VALUES(created_at),
                resolved_at     = NULL,
                resolved_by     = NULL,
                resolution_note = NULL,
                resolution_action = NULL',
            [
                $report->id()->value(),
                $report->tenantId()->value(),
                $report->targetType(),
                $report->targetId(),
                $report->reporterUserId(),
                $report->reasonCategory(),
                $report->reasonDetail(),
                $report->status(),
                $report->createdAt(),
            ],
        );
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_reports WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array
    {
        $status = $filters['status'] ?? ForumReport::STATUS_OPEN;
        $args = [$tenantId->value(), $status];
        $sql = 'SELECT target_type, target_id, status,
                       COUNT(*) AS report_count,
                       MIN(created_at) AS earliest,
                       MAX(created_at) AS latest,
                       GROUP_CONCAT(id) AS ids,
                       GROUP_CONCAT(reason_category) AS cats
                  FROM forum_reports
                 WHERE tenant_id = ? AND status = ?';
        $filterTargetType = $filters['target_type'] ?? null;
        if ($filterTargetType !== null && $filterTargetType !== '') {
            $sql .= ' AND target_type = ?';
            $args[] = $filterTargetType;
        }
        $sql .= ' GROUP BY target_type, target_id, status ORDER BY latest DESC';

        $rows = $this->db->query($sql, $args);
        $out = [];
        foreach ($rows as $r) {
            $catsRaw = $r['cats'] ?? '';
            $catsStr = is_string($catsRaw) ? $catsRaw : '';
            $cats = array_filter(explode(',', $catsStr));
            $counts = [];
            foreach ($cats as $c) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
            $idsRaw = $r['ids'] ?? '';
            $idsStr = is_string($idsRaw) ? $idsRaw : '';
            $ids = array_values(array_filter(explode(',', $idsStr)));
            $out[] = new AggregatedForumReport(
                self::str($r, 'target_type'),
                self::str($r, 'target_id'),
                self::asStatsInt($r, 'report_count'),
                $counts,
                $ids,
                self::str($r, 'earliest'),
                self::str($r, 'latest'),
                self::str($r, 'status'),
            );
        }
        return $out;
    }

    public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_reports
              WHERE target_type = ? AND target_id = ? AND tenant_id = ?
              ORDER BY created_at DESC',
            [$targetType, $targetId, $tenantId->value()],
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function resolveAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolutionAction,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->db->execute(
            'UPDATE forum_reports
                SET status = ?, resolved_at = ?, resolved_by = ?,
                    resolution_note = ?, resolution_action = ?
              WHERE target_type = ? AND target_id = ? AND tenant_id = ? AND status = ?',
            [
                ForumReport::STATUS_RESOLVED,
                $now->format('Y-m-d H:i:s'),
                $resolvedBy,
                $note,
                $resolutionAction,
                $targetType,
                $targetId,
                $tenantId->value(),
                ForumReport::STATUS_OPEN,
            ],
        );
    }

    public function dismissAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->db->execute(
            'UPDATE forum_reports
                SET status = ?, resolved_at = ?, resolved_by = ?,
                    resolution_note = ?, resolution_action = ?
              WHERE target_type = ? AND target_id = ? AND tenant_id = ? AND status = ?',
            [
                ForumReport::STATUS_DISMISSED,
                $now->format('Y-m-d H:i:s'),
                $resolvedBy,
                $note,
                'dismissed',
                $targetType,
                $targetId,
                $tenantId->value(),
                ForumReport::STATUS_OPEN,
            ],
        );
    }

    public function countOpenForTenant(TenantId $tenantId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(DISTINCT CONCAT(target_type,":",target_id)) AS c
               FROM forum_reports WHERE tenant_id = ? AND status = ?',
            [$tenantId->value(), ForumReport::STATUS_OPEN],
        );
        if ($row === null) {
            return 0;
        }
        $c = $row['c'] ?? 0;
        if (is_int($c)) {
            return $c;
        }
        return is_string($c) && is_numeric($c) ? (int) $c : 0;
    }

    public function countOpenReportsForTenant(TenantId $tenantId): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM forum_reports WHERE tenant_id = ? AND status = 'open'",
            [$tenantId->value()],
        );
        return self::asStatsInt($row ?? [], 'c');
    }

    /** @return list<array{date: string, value: int}> */
    public function dailyNewReportsForTenant(TenantId $tenantId): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM forum_reports
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value()],
        );
        foreach ($rows as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            if (isset($days[$d])) {
                $days[$d] = self::asStatsInt($row, 'c');
            }
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    public function notificationStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: open count + oldest open age (days).
        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest
               FROM forum_reports
              WHERE tenant_id = ? AND status = 'open'",
            [$tid],
        ) ?? [];

        $pendingCount = self::asStatsInt($countRow, 'n');
        $oldestAge    = self::asStatsInt($countRow, 'oldest');

        // Sparkline: 30-day BACKWARD by created_at, ALL statuses (incoming volume).
        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM forum_reports
              WHERE tenant_id = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid],
        );
        $sparkline = self::buildDailySeries30dBackward($rows);

        return [
            'pending_count'           => $pendingCount,
            'created_at_daily_30d'    => $sparkline,
            'oldest_pending_age_days' => $oldestAge,
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ForumReport
    {
        return new ForumReport(
            ForumReportId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'target_type'),
            self::str($row, 'target_id'),
            self::str($row, 'reporter_user_id'),
            self::str($row, 'reason_category'),
            self::strOrNull($row, 'reason_detail'),
            self::str($row, 'status'),
            self::strOrNull($row, 'resolved_at'),
            self::strOrNull($row, 'resolved_by'),
            self::strOrNull($row, 'resolution_note'),
            self::strOrNull($row, 'resolution_action'),
            self::str($row, 'created_at'),
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

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

}
