<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectProposalRepository implements ProjectProposalRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(ProjectProposal $proposal): void
    {
        $this->db->execute(
            'INSERT INTO project_proposals
                (id, tenant_id, user_id, author_name, author_email, title, category, summary, description, source_locale, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $proposal->id()->value(),
                $proposal->tenantId()->value(),
                $proposal->userId(),
                $proposal->authorName(),
                $proposal->authorEmail(),
                $proposal->title(),
                $proposal->category(),
                $proposal->summary(),
                $proposal->description(),
                $proposal->sourceLocale(),
                $proposal->status(),
                $proposal->createdAt(),
            ],
        );
    }

    public function listPendingForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM project_proposals
             WHERE tenant_id = ? AND status = ?
             ORDER BY created_at DESC',
            [$tenantId->value(), 'pending'],
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal
    {
        $row = $this->db->queryOne(
            'SELECT * FROM project_proposals WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \DomainException('invalid_decision');
        }
        $this->db->execute(
            'UPDATE project_proposals
             SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
             WHERE id = ? AND tenant_id = ?',
            [$decision, $now->format('Y-m-d H:i:s'), $decidedBy, $note, $id, $tenantId->value()],
        );
    }

    public function pendingStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Value: all-time pending count (no time window).
        $countRow = $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM project_proposals
              WHERE tenant_id = ? AND status = ?',
            [$tid, 'pending'],
        ) ?? [];
        $value = self::statsAsInt($countRow, 'n');

        // Sparkline: BACKWARD 30 days of created_at — INCOMING volume across
        // all statuses (a proposal counts in the day it was submitted, even if
        // later approved or rejected).
        $sparkline = $this->dailySeriesBackward(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM project_proposals
              WHERE tenant_id = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid],
        );

        return ['value' => $value, 'sparkline' => $sparkline];
    }

    public function notificationStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: pending count + oldest pending age (days).
        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest
               FROM project_proposals
              WHERE tenant_id = ? AND status = 'pending'",
            [$tid],
        ) ?? [];

        $pendingCount = self::statsAsInt($countRow, 'n');
        $oldestAge    = self::statsAsInt($countRow, 'oldest');

        // Sparkline: 30-day BACKWARD by created_at, ALL statuses (incoming volume).
        $sparkline = $this->dailySeriesBackward(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM project_proposals
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
     * Run a daily-count query and zero-fill into a 30-entry BACKWARD sparkline
     * (today-29 first, today last).
     *
     * @param list<scalar|null> $params
     * @return list<array{date: string, value: int}>
     */
    private function dailySeriesBackward(string $sql, array $params): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        foreach ($this->db->query($sql, $params) as $row) {
            $d = isset($row['d']) && is_string($row['d']) ? $row['d'] : '';
            if ($d === '' || !isset($days[$d])) {
                continue;
            }
            $days[$d] += self::statsAsInt($row, 'n');
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function statsAsInt(array $row, string $key): int
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
    private function hydrate(array $row): ProjectProposal
    {
        return new ProjectProposal(
            ProjectProposalId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'user_id'),
            self::str($row, 'author_name'),
            self::str($row, 'author_email'),
            self::str($row, 'title'),
            self::str($row, 'category'),
            self::str($row, 'summary'),
            self::str($row, 'description'),
            self::str($row, 'status'),
            self::str($row, 'created_at'),
            self::strOrNull($row, 'decided_at'),
            self::strOrNull($row, 'decided_by'),
            self::strOrNull($row, 'decision_note'),
            self::strOrDefault($row, 'source_locale', 'fi_FI'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function strOrDefault(array $row, string $key, string $default): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : $default;
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
