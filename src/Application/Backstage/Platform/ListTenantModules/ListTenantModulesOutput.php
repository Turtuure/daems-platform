<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenantModules;

/**
 * @phpstan-type ModuleRow array{
 *   slug: string,
 *   nameKey: ?string,
 *   descriptionKey: ?string,
 *   category: ?string,
 *   isCore: bool,
 *   defaultAvailable: bool,
 *   dependsOn: list<string>,
 *   state: 'enabled'|'available'|'disabled'|'core',
 *   availableAt: ?string,
 *   enabledAt: ?string,
 *   disabledAt: ?string,
 * }
 */
final class ListTenantModulesOutput
{
    /** @param list<ModuleRow> $modules */
    public function __construct(
        public readonly array $modules,
    ) {}
}
