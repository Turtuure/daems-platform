<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Forum\ForumUserWarning;
use Daems\Domain\Forum\ForumUserWarningId;
use Daems\Domain\Forum\ForumUserWarningRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;
use DomainException;

final class SqlForumUserWarningRepository implements ForumUserWarningRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(ForumUserWarning $warning): void
    {
        $this->db->execute(
            'INSERT INTO forum_user_warnings
                (id, tenant_id, user_id, reason, related_report_id, issued_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $warning->id()->value(),
                $warning->tenantId()->value(),
                $warning->userId(),
                $warning->reason(),
                $warning->relatedReportId(),
                $warning->issuedBy(),
                $warning->createdAt(),
            ],
        );
    }

    public function listForUserForTenant(string $userId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_user_warnings
              WHERE user_id = ? AND tenant_id = ?
              ORDER BY created_at DESC',
            [$userId, $tenantId->value()],
        );

        return array_map(static fn (array $r): ForumUserWarning => new ForumUserWarning(
            ForumUserWarningId::fromString(self::str($r, 'id')),
            TenantId::fromString(self::str($r, 'tenant_id')),
            self::str($r, 'user_id'),
            self::str($r, 'reason'),
            self::strOrNull($r, 'related_report_id'),
            self::str($r, 'issued_by'),
            self::str($r, 'created_at'),
        ), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new DomainException("Missing or non-string column: {$key}");
    }

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }
}
