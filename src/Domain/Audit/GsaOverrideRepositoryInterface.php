<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

use Daems\Domain\Tenant\TenantId;

interface GsaOverrideRepositoryInterface
{
    /** @return list<GsaOverride> */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;

    public function save(GsaOverride $override): void;
}
