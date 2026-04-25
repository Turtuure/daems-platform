<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectProposalRepositoryInterface
{
    public function save(ProjectProposal $proposal): void;

    /** @return ProjectProposal[] — pending only, newest first */
    public function listPendingForTenant(TenantId $tenantId): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void;

    /**
     * @return array{value: int, sparkline: list<array{date: string, value: int}>}
     */
    public function pendingStatsForTenant(TenantId $tenantId): array;

    /**
     * Tenant-scoped slice for the unified Notifications inbox KPI strip.
     *
     * - `pending_count`           = current count of `status = 'pending'` rows
     * - `created_at_daily_30d`    = 30-day daily count of newly-created rows
     *                               (across all statuses — incoming volume)
     * - `oldest_pending_age_days` = age in days of the oldest still-`pending` row;
     *                               0 when no pending rows
     *
     * @return array{
     *   pending_count: int,
     *   created_at_daily_30d: list<array{date: string, value: int}>,
     *   oldest_pending_age_days: int
     * }
     */
    public function notificationStatsForTenant(TenantId $tenantId): array;

    /**
     * Daily count of cleared events (decisions/closures) for the tenant in last 30 days,
     * zero-filled to 30 backward entries (today-29 .. today). Used by ListNotificationsStats
     * use case to compute the cleared_30d KPI.
     *
     * @return list<array{date: string, value: int}>
     */
    public function clearedDailyForTenant(TenantId $tenantId): array;
}
