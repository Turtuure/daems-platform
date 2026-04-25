<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\Concerns\DailyStatsHelpers;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlEventProposalRepository implements EventProposalRepositoryInterface
{
    use DailyStatsHelpers;

    public function __construct(private readonly Connection $db)
    {
    }

    public function save(EventProposal $p): void
    {
        $this->db->execute(
            'INSERT INTO event_proposals
                (id, tenant_id, user_id, author_name, author_email, title,
                 event_date, event_time, location, is_online, description,
                 source_locale, status, created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status=VALUES(status), decided_at=VALUES(decided_at),
                decided_by=VALUES(decided_by), decision_note=VALUES(decision_note)',
            [
                $p->id()->value(),
                $p->tenantId()->value(),
                $p->userId(),
                $p->authorName(),
                $p->authorEmail(),
                $p->title(),
                $p->eventDate(),
                $p->eventTime(),
                $p->location(),
                $p->isOnline() ? 1 : 0,
                $p->description(),
                $p->sourceLocale(),
                $p->status(),
                $p->createdAt(),
                $p->decidedAt(),
                $p->decidedBy(),
                $p->decisionNote(),
            ],
        );
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?EventProposal
    {
        $row = $this->db->queryOne(
            'SELECT * FROM event_proposals WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM event_proposals WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC';
        $rows = $this->db->query($sql, $args);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->hydrate($r);
        }
        return $out;
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
            'UPDATE event_proposals
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
            'SELECT COUNT(*) AS n FROM event_proposals
              WHERE tenant_id = ? AND status = ?',
            [$tid, 'pending'],
        ) ?? [];
        $value = self::asStatsInt($countRow, 'n');

        // Sparkline: BACKWARD 30 days of created_at — INCOMING volume across
        // all statuses (a proposal counts in the day it was submitted, even if
        // later approved or rejected).
        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM event_proposals
              WHERE tenant_id = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid],
        );
        $sparkline = self::buildDailySeries30dBackward($rows);

        return ['value' => $value, 'sparkline' => $sparkline];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): EventProposal
    {
        return new EventProposal(
            EventProposalId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'user_id'),
            self::str($row, 'author_name'),
            self::str($row, 'author_email'),
            self::str($row, 'title'),
            self::str($row, 'event_date'),
            self::strOrNull($row, 'event_time'),
            self::strOrNull($row, 'location'),
            (bool) ($row['is_online'] ?? false),
            self::str($row, 'description'),
            self::str($row, 'source_locale'),
            self::str($row, 'status'),
            self::str($row, 'created_at'),
            self::strOrNull($row, 'decided_at'),
            self::strOrNull($row, 'decided_by'),
            self::strOrNull($row, 'decision_note'),
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
