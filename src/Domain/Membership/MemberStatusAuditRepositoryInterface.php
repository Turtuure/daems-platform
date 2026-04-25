<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

interface MemberStatusAuditRepositoryInterface
{
    public function save(MemberStatusAudit $audit): void;

    /**
     * Daily count of audit rows where new_status equals the given value, scoped to the tenant.
     * Returns exactly 30 backward zero-filled entries (29 days ago up to and including today).
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyTransitionsForTenant(TenantId $tenantId, string $newStatus): array;
}
