<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\UpdateTenantBasics;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use InvalidArgumentException;

/**
 * Immutable input DTO for the GSA UpdateTenantBasics use case.
 *
 * The slug is included for round-trip safety — the use case rejects any
 * attempt to change it (TenantSlugImmutableException) so the controller
 * MUST send the unchanged slug back. Sending a different slug is a hard
 * error, never a silent accept.
 */
final class UpdateTenantBasicsInput
{
    /**
     * @param array<string, string> $displayNamesI18n
     * @param array<string, string> $publicDescriptionsI18n
     * @param list<string>          $supportedLocales
     */
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly array $displayNamesI18n,
        public readonly array $publicDescriptionsI18n,
        public readonly array $supportedLocales,
        public readonly string $defaultLocale,
        public readonly string $memberNumberPrefix,
    ) {
        if ($defaultLocale === '') {
            throw new InvalidArgumentException('UpdateTenantBasicsInput: defaultLocale must be non-empty');
        }
    }
}
