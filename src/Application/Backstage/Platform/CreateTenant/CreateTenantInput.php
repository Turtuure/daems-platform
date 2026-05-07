<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\CreateTenant;

use Daems\Domain\User\UserId;
use InvalidArgumentException;

/**
 * Immutable input DTO for the GSA CreateTenant use case.
 *
 * Validates the slug shape eagerly so a malformed slug never reaches the
 * use case (or worse, the SQL UNIQUE constraint where the error becomes
 * harder to attribute). Locale lists and i18n maps are validated for shape
 * but not content — the use case + Tenant entity normalise.
 */
final class CreateTenantInput
{
    /**
     * @param array<string, string> $displayNamesI18n        ['fi_FI' => '…', 'en_GB' => '…']
     * @param array<string, string> $publicDescriptionsI18n  same shape; may be empty
     * @param list<string>          $supportedLocales        e.g. ['fi_FI','en_GB']
     */
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly string $slug,
        public readonly array $displayNamesI18n,
        public readonly array $publicDescriptionsI18n,
        public readonly array $supportedLocales,
        public readonly string $defaultLocale,
        public readonly string $memberNumberPrefix,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1) {
            throw new InvalidArgumentException(
                "CreateTenantInput: slug '{$slug}' must match /^[a-z][a-z0-9-]*$/"
            );
        }
        if ($defaultLocale === '') {
            throw new InvalidArgumentException('CreateTenantInput: defaultLocale must be non-empty');
        }
    }

    public function actingUserId(): UserId
    {
        return $this->actingUserId;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
