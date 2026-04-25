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

    public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication;

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
}
