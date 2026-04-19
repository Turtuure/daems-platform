<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface MemberApplicationRepositoryInterface
{
    public function save(MemberApplication $application): void;

    /** @return list<MemberApplication> */
    public function listPendingForTenant(TenantId $tenantId, int $limit): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,            // 'approved' | 'rejected'
        UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void;
}
