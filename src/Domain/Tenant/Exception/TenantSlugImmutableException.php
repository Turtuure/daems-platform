<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class TenantSlugImmutableException extends \DomainException
{
    public static function for(string $oldSlug, string $newSlug): self
    {
        return new self("Tenant slug is immutable: cannot change from '{$oldSlug}' to '{$newSlug}'");
    }
}
