<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberStatusAuditRepository implements MemberStatusAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MemberStatusAudit $audit): void
    {
        $this->db->execute(
            'INSERT INTO member_status_audit
                (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $audit->id,
                $audit->tenantId,
                $audit->userId,
                $audit->previousStatus,
                $audit->newStatus,
                $audit->reason,
                $audit->performedByAdminId,
                $audit->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }
}
