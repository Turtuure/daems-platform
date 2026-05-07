<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class TenantPrimaryDomainRequiredException extends \DomainException
{
    public static function for(string $tenantSlug): self
    {
        return new self("Tenant '{$tenantSlug}' must have at least one primary domain");
    }
}
