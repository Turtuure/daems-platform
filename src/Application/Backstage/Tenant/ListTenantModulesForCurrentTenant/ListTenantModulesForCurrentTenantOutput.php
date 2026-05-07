<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Tenant\ListTenantModulesForCurrentTenant;

/**
 * @phpstan-type ModuleEntry array{slug: string, nameKey: ?string, descriptionKey: ?string, sinceAt: ?string}
 */
final class ListTenantModulesForCurrentTenantOutput
{
    /**
     * @param list<ModuleEntry> $enabled
     * @param list<ModuleEntry> $availableNotEnabled
     * @param list<ModuleEntry> $disabled
     */
    public function __construct(
        public readonly array $enabled,
        public readonly array $availableNotEnabled,
        public readonly array $disabled,
    ) {}
}
