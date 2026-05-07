<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\UpdateTenantDomain;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: mutate an existing tenant_domains row.
 *
 * Currently supports toggling isPrimary. Hostname renames are not allowed
 * (it's the table PK); a hostname change request raises DomainException so
 * a UI mistake is loud, not silent.
 */
final class UpdateTenantDomain
{
    public function __construct(
        private readonly TenantDomainRepositoryInterface $domains,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(UpdateTenantDomainInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $existing = $this->domains->find($input->domainId);
        if ($existing === null) {
            throw new DomainException("TenantDomain '{$input->domainId}' not found");
        }
        $owner = $existing->tenantId();
        if ($owner === null || !$owner->equals($input->tenantId)) {
            throw new DomainException(
                "TenantDomain '{$input->domainId}' does not belong to tenant '{$input->tenantId->value()}'"
            );
        }

        if ($input->hostname !== null && $input->hostname !== $existing->hostname()) {
            throw new DomainException(
                "TenantDomain hostname is immutable; remove and re-add to change"
            );
        }

        if ($input->isPrimary === true && !$existing->isPrimary()) {
            $this->domains->setPrimary($input->tenantId, $input->domainId);
            return;
        }

        if ($input->isPrimary === false && $existing->isPrimary()) {
            // Demote-only — caller is responsible for promoting another. The
            // primary-required invariant is checked downstream on remove().
            $this->domains->update($existing->withPrimary(false));
        }
    }
}
