<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

interface EventProposalRepositoryInterface
{
    public function save(EventProposal $proposal): void;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?EventProposal;

    /** @return list<EventProposal> */
    public function listForTenant(TenantId $tenantId, ?string $status = null): array;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void;

    /**
     * KPI slice for event proposals.
     *
     * value:     COUNT(*) WHERE tenant_id = ? AND status = 'pending' (all-time)
     * sparkline: backward 30 days of created_at daily count for ALL proposals
     *            regardless of status — this is incoming submission volume.
     *
     * @return array{value: int, sparkline: list<array{date: string, value: int}>}
     */
    public function pendingStatsForTenant(TenantId $tenantId): array;
}
