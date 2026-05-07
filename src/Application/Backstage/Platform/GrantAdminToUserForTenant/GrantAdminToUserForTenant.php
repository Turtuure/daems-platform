<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\GrantAdminToUserForTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * GSA-only use case: promote a user to admin role in a specific tenant.
 *
 * Idempotent: re-granting admin to someone already admin is a no-op.
 * The InMemory + SQL repositories implement attach() as upsert semantics
 * so promoting a non-admin or first-time user works the same.
 */
final class GrantAdminToUserForTenant
{
    public function __construct(
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(GrantAdminToUserForTenantInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $this->userTenants->attach($input->targetUserId, $input->tenantId, UserTenantRole::Admin);
    }
}
