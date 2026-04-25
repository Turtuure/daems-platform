<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use DateTimeImmutable;
use Daems\Domain\Tenant\TenantId;

interface ForumReportRepositoryInterface
{
    public function upsert(ForumReport $report): void;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport;

    /**
     * @param array{status?:string,target_type?:string} $filters
     * @return list<AggregatedForumReport>
     */
    public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array;

    /** @return list<ForumReport> */
    public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array;

    public function resolveAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolutionAction,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void;

    public function dismissAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void;

    public function countOpenForTenant(TenantId $tenantId): int;

    /**
     * Count reports with status='open' for a tenant.
     */
    public function countOpenReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;

    /**
     * Daily count of newly-created reports for a tenant, last 30 days.
     * Returns exactly 30 entries: index 0 = 29 days ago, index 29 = today.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyNewReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;

    /**
     * Tenant-scoped slice for the unified Notifications inbox KPI strip.
     *
     * - `pending_count`           = current count of `status = 'open'` rows
     * - `created_at_daily_30d`    = 30-day daily count of newly-created rows
     *                               (across all statuses — incoming volume)
     * - `oldest_pending_age_days` = age in days of the oldest still-`open` row;
     *                               0 when no open rows
     *
     * @return array{
     *   pending_count: int,
     *   created_at_daily_30d: list<array{date: string, value: int}>,
     *   oldest_pending_age_days: int
     * }
     */
    public function notificationStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;
}
