<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

interface BoardRepositoryInterface
{
    public function findForTenant(TenantId $tenantId): ?Board;

    public function save(Board $board): void;
}
