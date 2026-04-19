<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Tenant;

use Daems\Domain\Tenant\Tenant;

interface TenantResolverInterface
{
    public function resolve(string $host): ?Tenant;
}
