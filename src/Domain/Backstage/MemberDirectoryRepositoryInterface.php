<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface MemberDirectoryRepositoryInterface
{
    /**
     * @param array{status?:string, type?:string, q?:string} $filters
     * @return array{entries: list<MemberDirectoryEntry>, total: int}
     */
    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,      // 'member_number' | 'name' | 'joined_at' | 'status'
        string $dir,       // 'ASC' | 'DESC'
        int $page,
        int $perPage,
    ): array;

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void;

    /** @return list<MemberStatusAuditEntry> */
    public function getAuditEntriesForMember(
        UserId $userId,
        TenantId $tenantId,
        int $limit,
    ): array;
}
