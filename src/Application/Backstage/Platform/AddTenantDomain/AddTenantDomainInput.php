<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\AddTenantDomain;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use InvalidArgumentException;

/**
 * Hostname is validated by TenantDomain entity construction; we add an
 * extra-tight pattern guard at the Input boundary so a malformed hostname
 * fails before the use case authorises (cheaper rejection path).
 */
final class AddTenantDomainInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $hostname,
        public readonly bool $isPrimary,
    ) {
        if (preg_match('/^[a-z0-9.\\-]+$/', $hostname) !== 1) {
            throw new InvalidArgumentException(
                "AddTenantDomainInput: hostname '{$hostname}' must match /^[a-z0-9.-]+\$/"
            );
        }
    }
}
