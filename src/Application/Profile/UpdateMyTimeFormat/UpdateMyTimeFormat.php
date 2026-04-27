<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyTimeFormat;

use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class UpdateMyTimeFormat
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly TenantRepositoryInterface $tenants,
    ) {}

    public function execute(UpdateMyTimeFormatInput $input): UpdateMyTimeFormatOutput
    {
        $value = $input->timeFormat;
        if ($value !== null && $value !== '12' && $value !== '24') {
            throw new ValidationException(['time_format' => 'must_be_12_or_24_or_null']);
        }

        $this->users->updateTimeFormatOverride($input->actor->id->value(), $value);

        // Effective format: user override OR tenant default OR app default
        $tenant = $this->tenants->findById($input->actor->activeTenant);
        $effective = $value ?? $tenant->defaultTimeFormat ?? '24';

        return new UpdateMyTimeFormatOutput($value, $effective);
    }
}
