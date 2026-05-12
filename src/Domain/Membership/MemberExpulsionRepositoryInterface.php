<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

interface MemberExpulsionRepositoryInterface
{
    public function find(MemberExpulsionId $id): ?MemberExpulsion;

    /** @return list<MemberExpulsion> */
    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array;

    public function save(MemberExpulsion $expulsion): void;
}
