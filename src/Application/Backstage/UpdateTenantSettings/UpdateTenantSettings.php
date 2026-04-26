<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantRepositoryInterface;

final class UpdateTenantSettings
{
    public function __construct(private readonly TenantRepositoryInterface $tenants) {}

    public function execute(UpdateTenantSettingsInput $input): UpdateTenantSettingsOutput
    {
        $tenantId = $input->actor->activeTenant;
        if (!$input->actor->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $prefix = $input->memberNumberPrefix;
        if ($prefix !== null) {
            $prefix = trim($prefix);
            if ($prefix === '') {
                $prefix = null;
            } elseif (strlen($prefix) > 20) {
                throw new ValidationException(['member_number_prefix' => 'max_20_chars']);
            } elseif (!preg_match('/^[A-Z0-9\-]+$/', $prefix)) {
                throw new ValidationException(['member_number_prefix' => 'invalid_chars']);
            }
        }
        $this->tenants->updatePrefix($tenantId, $prefix);

        // Optional: only persist time-format if the input opted in.
        if ($input->defaultTimeFormat !== null) {
            if ($input->defaultTimeFormat !== '12' && $input->defaultTimeFormat !== '24') {
                throw new ValidationException(['default_time_format' => 'must_be_12_or_24']);
            }
            $this->tenants->updateDefaultTimeFormat($tenantId, $input->defaultTimeFormat);
        }

        // Re-read to expose the now-current values to the caller.
        $tenant = $this->tenants->findById($tenantId);
        $effectiveTimeFormat = $tenant?->defaultTimeFormat ?? '24';

        return new UpdateTenantSettingsOutput($prefix, $effectiveTimeFormat);
    }
}
