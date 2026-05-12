<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryTenantGovernanceSettingsRepository implements TenantGovernanceSettingsRepositoryInterface
{
    /** @var array<string, TenantGovernanceSettings> */
    private array $byTenant = [];

    public function find(TenantId $tenantId): ?TenantGovernanceSettings
    {
        return $this->byTenant[$tenantId->value()] ?? null;
    }

    public function save(TenantGovernanceSettings $settings): void
    {
        $this->byTenant[$settings->tenantId->value()] = $settings;
    }
}
