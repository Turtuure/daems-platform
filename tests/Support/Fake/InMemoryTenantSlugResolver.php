<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\TenantSlugResolverInterface;

final class InMemoryTenantSlugResolver implements TenantSlugResolverInterface
{
    /** @param array<string, string> $map tenantId => slug */
    public function __construct(private readonly array $map = []) {}

    public function slugFor(string $tenantId): ?string
    {
        return $this->map[$tenantId] ?? null;
    }
}
