<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\SuspendTenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use InvalidArgumentException;

final class SuspendTenantInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('SuspendTenantInput: reason must be non-empty');
        }
    }
}
