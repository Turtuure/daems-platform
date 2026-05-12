<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

interface TenantGovernanceSettingsRepositoryInterface
{
    public function find(TenantId $tenantId): ?TenantGovernanceSettings;

    public function save(TenantGovernanceSettings $settings): void;
}
