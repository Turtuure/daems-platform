<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

final class UpdateTenantSettingsOutput
{
    public function __construct(
        public readonly ?string $memberNumberPrefix,
        public readonly string $defaultTimeFormat,
    ) {}
}
