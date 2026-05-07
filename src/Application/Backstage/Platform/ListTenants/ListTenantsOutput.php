<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenants;

/**
 * Read-model row shape:
 *   - slug:               string
 *   - displayNameI18n:    array<string, string>|null  (raw i18n map; null = use slug)
 *   - status:             'active'|'suspended'
 *   - domainsCount:       int
 *   - adminsCount:        int
 *   - modulesEnabled:     int   number of currently-enabled tenant_modules rows
 *   - modulesAvailable:   int   number of available_at-set rows (incl. enabled)
 *   - suspendedAt:        string|null  ISO8601, populated only when suspended
 *
 * @phpstan-type TenantRow array{
 *   slug: string,
 *   displayNameI18n: array<string, string>|null,
 *   status: 'active'|'suspended',
 *   domainsCount: int,
 *   adminsCount: int,
 *   modulesEnabled: int,
 *   modulesAvailable: int,
 *   suspendedAt: string|null,
 * }
 */
final class ListTenantsOutput
{
    /** @param list<TenantRow> $tenants */
    public function __construct(
        public readonly array $tenants,
    ) {}
}
