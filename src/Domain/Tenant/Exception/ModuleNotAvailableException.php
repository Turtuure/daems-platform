<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class ModuleNotAvailableException extends \DomainException
{
    public static function for(string $moduleSlug, string $tenantSlug): self
    {
        return new self("Module '{$moduleSlug}' is not available for tenant '{$tenantSlug}'");
    }
}
