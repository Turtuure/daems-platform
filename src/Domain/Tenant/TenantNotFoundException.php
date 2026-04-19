<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use RuntimeException;

final class TenantNotFoundException extends RuntimeException
{
    public static function bySlug(string $slug): self
    {
        return new self("Tenant not found for slug: {$slug}");
    }

    public static function byDomain(string $domain): self
    {
        return new self("Tenant not found for domain: {$domain}");
    }
}
