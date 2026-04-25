<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumModerationAuditRepository implements ForumModerationAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(ForumModerationAuditEntry $entry): void
    {
        $this->db->execute(
            'INSERT INTO forum_moderation_audit
                (id, tenant_id, target_type, target_id, action,
                 original_payload, new_payload, reason, performed_by,
                 related_report_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $entry->id()->value(),
                $entry->tenantId()->value(),
                $entry->targetType(),
                $entry->targetId(),
                $entry->action(),
                $entry->originalPayload() !== null ? json_encode($entry->originalPayload(), JSON_THROW_ON_ERROR) : null,
                $entry->newPayload() !== null ? json_encode($entry->newPayload(), JSON_THROW_ON_ERROR) : null,
                $entry->reason(),
                $entry->performedBy(),
                $entry->relatedReportId(),
                $entry->createdAt(),
            ],
        );
    }

    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = [], int $offset = 0): array
    {
        $sql  = 'SELECT * FROM forum_moderation_audit WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if (isset($filters['action']) && $filters['action'] !== '') {
            $sql   .= ' AND action = ?';
            $args[] = $filters['action'];
        }
        if (isset($filters['performer']) && $filters['performer'] !== '') {
            $sql   .= ' AND performed_by = ?';
            $args[] = $filters['performer'];
        }
        $sql   .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $args[] = $limit;
        $args[] = max(0, $offset);

        $rows = $this->db->query($sql, $args);
        $out  = [];
        foreach ($rows as $r) {
            $orig = null;
            $origRaw = $r['original_payload'] ?? null;
            if (is_string($origRaw) && $origRaw !== '') {
                $d = json_decode($origRaw, true);
                if (is_array($d)) {
                    /** @var array<string,mixed> $d */
                    $orig = $d;
                }
            }
            $new    = null;
            $newRaw = $r['new_payload'] ?? null;
            if (is_string($newRaw) && $newRaw !== '') {
                $d = json_decode($newRaw, true);
                if (is_array($d)) {
                    /** @var array<string,mixed> $d */
                    $new = $d;
                }
            }
            $reason          = $r['reason'] ?? null;
            $relatedReportId = $r['related_report_id'] ?? null;
            $out[] = new ForumModerationAuditEntry(
                ForumModerationAuditId::fromString(self::str($r, 'id')),
                TenantId::fromString(self::str($r, 'tenant_id')),
                self::str($r, 'target_type'),
                self::str($r, 'target_id'),
                self::str($r, 'action'),
                $orig,
                $new,
                is_string($reason) ? $reason : null,
                self::str($r, 'performed_by'),
                is_string($relatedReportId) ? $relatedReportId : null,
                self::str($r, 'created_at'),
            );
        }
        return $out;
    }

    public function countActionsLast30dForTenant(TenantId $tenantId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM forum_moderation_audit
             WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            [$tenantId->value()],
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

    /** @return list<array{date: string, value: int}> */
    public function dailyActionCountForTenant(TenantId $tenantId): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM forum_moderation_audit
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value()],
        );
        foreach ($rows as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            if (isset($days[$d])) {
                $c = $row['c'] ?? 0;
                $days[$d] = is_int($c) ? $c : (is_string($c) && is_numeric($c) ? (int) $c : 0);
            }
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    /** @return list<ForumModerationAuditEntry> */
    public function recentForTenant(TenantId $tenantId, int $limit = 5): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_moderation_audit
             WHERE tenant_id = ?
             ORDER BY created_at DESC
             LIMIT ' . max(1, min(100, $limit)),
            [$tenantId->value()],
        );
        $out = [];
        foreach ($rows as $r) {
            $orig = null;
            $origRaw = $r['original_payload'] ?? null;
            if (is_string($origRaw) && $origRaw !== '') {
                $d = json_decode($origRaw, true);
                if (is_array($d)) {
                    /** @var array<string,mixed> $d */
                    $orig = $d;
                }
            }
            $new    = null;
            $newRaw = $r['new_payload'] ?? null;
            if (is_string($newRaw) && $newRaw !== '') {
                $d = json_decode($newRaw, true);
                if (is_array($d)) {
                    /** @var array<string,mixed> $d */
                    $new = $d;
                }
            }
            $reason          = $r['reason'] ?? null;
            $relatedReportId = $r['related_report_id'] ?? null;
            $out[] = new ForumModerationAuditEntry(
                ForumModerationAuditId::fromString(self::str($r, 'id')),
                TenantId::fromString(self::str($r, 'tenant_id')),
                self::str($r, 'target_type'),
                self::str($r, 'target_id'),
                self::str($r, 'action'),
                $orig,
                $new,
                is_string($reason) ? $reason : null,
                self::str($r, 'performed_by'),
                is_string($relatedReportId) ? $relatedReportId : null,
                self::str($r, 'created_at'),
            );
        }
        return $out;
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
