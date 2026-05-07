<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class TenantSuspendedException extends \DomainException
{
    public static function for(string $tenantSlug, string $reason): self
    {
        return new self("Tenant '{$tenantSlug}' is suspended: {$reason}");
    }
}
