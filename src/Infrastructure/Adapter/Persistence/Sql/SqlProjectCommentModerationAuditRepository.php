<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectCommentModerationAuditRepository implements ProjectCommentModerationAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(ProjectCommentModerationAudit $a): void
    {
        $this->db->execute(
            'INSERT INTO project_comment_moderation_audit
                (id, tenant_id, project_id, comment_id, action, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $a->id,
                $a->tenantId->value(),
                $a->projectId,
                $a->commentId,
                $a->action,
                $a->reason,
                $a->performedBy,
                $a->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $rows = $this->db->query(
            'SELECT id, tenant_id, project_id, comment_id, action, reason, performed_by,
                    DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at
             FROM project_comment_moderation_audit
             WHERE tenant_id = ?
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit,
            [$tenantId->value()],
        );
        $out = [];
        foreach ($rows as $row) {
            $reason = $row['reason'] ?? null;
            $out[] = new ProjectCommentModerationAudit(
                self::str($row, 'id'),
                TenantId::fromString(self::str($row, 'tenant_id')),
                self::str($row, 'project_id'),
                self::str($row, 'comment_id'),
                self::str($row, 'action'),
                is_string($reason) ? $reason : null,
                self::str($row, 'performed_by'),
                new \DateTimeImmutable(self::str($row, 'created_at')),
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
