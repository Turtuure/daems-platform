<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\RevokeAdminFromUserForTenant;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * GSA-only use case: demote a tenant admin back to plain member.
 *
 * Preserves the membership row — we set role=member rather than detach()
 * so the user keeps their tenant association and member-only data
 * (member_number, joined_at, etc.) survives.
 */
final class RevokeAdminFromUserForTenant
{
    public function __construct(
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(RevokeAdminFromUserForTenantInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $this->userTenants->attach($input->targetUserId, $input->tenantId, UserTenantRole::Member);
    }
}
