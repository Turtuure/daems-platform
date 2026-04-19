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
}
