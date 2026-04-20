<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantSlugResolverInterface
{
    /** Returns the slug for the given tenant UUID, or null if not found. */
    public function slugFor(string $tenantId): ?string;
}
