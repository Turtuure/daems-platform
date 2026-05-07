<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\UpdateTenantDomain;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

/**
 * Updateable fields:
 *   - isPrimary (bool|null) — null = leave unchanged
 *
 * The hostname is the table PK (migration 019); changing it would be a
 * remove + add. We do not silently rename — callers must remove + add.
 * For backward-compat with the spec, $hostname is accepted and validated
 * to match the stored hostname; mismatch raises in the use case.
 */
final class UpdateTenantDomainInput
{
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $domainId,
        public readonly ?string $hostname,
        public readonly ?bool $isPrimary,
    ) {}
}
