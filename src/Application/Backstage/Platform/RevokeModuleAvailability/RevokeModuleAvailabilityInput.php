<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\RevokeModuleAvailability;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use InvalidArgumentException;

final class RevokeModuleAvailabilityInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $moduleSlug,
        public readonly string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'RevokeModuleAvailabilityInput: reason is required for an audit revoke'
            );
        }
    }
}
