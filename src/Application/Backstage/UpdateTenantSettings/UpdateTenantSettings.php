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
        return new UpdateTenantSettingsOutput($prefix);
    }
}
