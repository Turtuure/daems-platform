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
}
