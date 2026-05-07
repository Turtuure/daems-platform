<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\GetTenantDetail;

/**
 * @phpstan-type DomainRow array{hostname: string, isPrimary: bool, createdAt: ?string}
 *
 * @phpstan-type Detail array{
 *   slug: string,
 *   name: string,
 *   displayNameI18n: array<string, string>|null,
 *   publicDescriptionI18n: array<string, string>|null,
 *   supportedLocales: list<string>,
 *   defaultLocale: string,
 *   memberNumberPrefix: ?string,
 *   defaultTimeFormat: string,
 *   status: 'active'|'suspended',
 *   suspendedAt: ?string,
 *   suspendedReason: ?string,
 *   createdAt: string,
 *   domains: list<DomainRow>,
 *   adminsCount: int,
 *   modulesEnabled: int,
 *   modulesAvailable: int,
 * }
 */
final class GetTenantDetailOutput
{
    /** @param Detail $detail */
    public function __construct(
        public readonly array $detail,
    ) {}
}
