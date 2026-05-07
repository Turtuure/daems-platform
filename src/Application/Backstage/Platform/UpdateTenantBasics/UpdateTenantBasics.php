<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\UpdateTenantBasics;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\TenantSlugImmutableException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use DomainException;

/**
 * GSA-only use case: update the editable fields of an existing tenant.
 *
 * Slug is immutable post-creation — sending a different slug raises
 * TenantSlugImmutableException rather than being silently ignored.
 * Suspension state is not touched here; that's owned by SuspendTenant
 * and ReactivateTenant.
 */
final class UpdateTenantBasics
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(UpdateTenantBasicsInput $input): void
    {
        $actor = $this->users->findById($input->actingUserId->value());
        if ($actor === null || !$actor->isPlatformAdmin()) {
            throw new ForbiddenException('not_platform_admin');
        }

        $existing = $this->tenants->findById($input->tenantId);
        if ($existing === null) {
            throw new DomainException('Tenant not found');
        }

        if ($input->slug !== $existing->slug->value()) {
            throw TenantSlugImmutableException::for($existing->slug->value(), $input->slug);
        }

        $updated = new Tenant(
            id: $existing->id,
            slug: $existing->slug,
            name: $existing->name,
            createdAt: $existing->createdAt,
            memberNumberPrefix: $input->memberNumberPrefix,
            defaultTimeFormat: $existing->defaultTimeFormat,
            displayNameI18n: $input->displayNamesI18n === [] ? null : $input->displayNamesI18n,
            publicDescriptionI18n: $input->publicDescriptionsI18n === [] ? null : $input->publicDescriptionsI18n,
            supportedLocales: $input->supportedLocales,
            defaultLocale: $input->defaultLocale,
            suspendedAt: $existing->suspendedAt(),
            suspendedReason: $existing->suspendedReason(),
        );

        $this->tenants->update($updated);
    }
}
