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
}
