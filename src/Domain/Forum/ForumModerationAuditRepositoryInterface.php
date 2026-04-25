<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumModerationAuditRepositoryInterface
{
    public function record(ForumModerationAuditEntry $entry): void;

    /**
     * @param array{action?:string, performer?:string} $filters
     * @return list<ForumModerationAuditEntry>
     */
    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = [], int $offset = 0): array;

    /**
     * Count audit entries for a tenant where created_at >= NOW() - INTERVAL 30 DAY.
     */
    public function countActionsLast30dForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;

    /**
     * Daily count of audit entries for a tenant, last 30 days.
     * Returns exactly 30 entries: index 0 = 29 days ago, index 29 = today.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyActionCountForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;

    /**
     * Most-recent audit entries for a tenant.
     * @return list<ForumModerationAuditEntry>
     */
    public function recentForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit = 5): array;
}
