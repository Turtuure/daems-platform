<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface SupporterApplicationRepositoryInterface
{
    public function save(SupporterApplication $application): void;

    /** @return list<SupporterApplication> */
    public function listPendingForTenant(TenantId $tenantId, int $limit): array;

    /**
     * Lists decided supporter applications (approved or rejected) for the tenant, scoped to the
     * last $days days by `decided_at`. Returns array shape rather than entities because the
     * payload includes `decided_at` and `decision_note` which are not part of the
     * SupporterApplication value object.
     *
     * @param 'approved'|'rejected' $decision
     * @return list<array{
     *   id: string,
     *   org_name: string,
     *   contact_person: string,
     *   email: string,
     *   country: ?string,
     *   motivation: string,
     *   decided_at: string,
     *   decision_note: ?string
     * }>
     */
    public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication;

    /**
     * Single-row detail fetch for the admin detail page. Returns array shape
     * including all submitted fields PLUS decision metadata (decided_at,
     * decision_note) for already-decided applications. Returns null when not
     * found in the tenant scope.
     *
     * @return array{
     *   id: string,
     *   org_name: string,
     *   contact_person: string,
     *   reg_no: ?string,
     *   email: string,
     *   country: ?string,
     *   motivation: string,
     *   how_heard: ?string,
     *   status: string,
     *   created_at: ?string,
     *   decided_at: ?string,
     *   decision_note: ?string
     * }|null
     */
    public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void;

    /**
     * Tenant-scoped KPI stats slice for the backstage dashboard.
     *
     * - `pending.value`         = current count of `status = 'pending'` rows
     * - `pending.sparkline`     = 30-day daily count of pending rows by `created_at`
     * - `approved_30d.value`    = rows with `status = 'approved' AND decided_at >= NOW() - 30d`
     * - `approved_30d.sparkline`= 30-day daily count of approved rows by `decided_at`
     * - `rejected_30d.*`        = analogous for `status = 'rejected'`
     * - `decided_count`         = total rows decided (any decision) in the last 30d
     * - `decided_total_hours`   = sum of HOUR diffs (created_at -> decided_at) over those rows
     *
     * The `decided_*` helpers feed `avg_response_hours` computation in the use case
     * (combined across MemberApplication + SupporterApplication slices).
     *
     * @return array{
     *   pending:             array{value: int, sparkline: list<array{date: string, value: int}>},
     *   approved_30d:        array{value: int, sparkline: list<array{date: string, value: int}>},
     *   rejected_30d:        array{value: int, sparkline: list<array{date: string, value: int}>},
     *   decided_count:       int,
     *   decided_total_hours: int
     * }
     */
    public function statsForTenant(TenantId $tenantId): array;

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
