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
}
