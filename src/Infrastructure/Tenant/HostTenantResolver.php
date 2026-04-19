<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;

final class HostTenantResolver
{
    /**
     * @param array<string, string> $fallbackMap host => slug
     */
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly array $fallbackMap,
    ) {}

    public function resolve(string $host): ?Tenant
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $byDomain = $this->tenants->findByDomain($host);
        if ($byDomain !== null) {
            return $byDomain;
        }

        if (isset($this->fallbackMap[$host])) {
            return $this->tenants->findBySlug($this->fallbackMap[$host]);
        }

        return null;
    }
}
