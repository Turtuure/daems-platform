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
}
