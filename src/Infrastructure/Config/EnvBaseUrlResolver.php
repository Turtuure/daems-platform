<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Config;

use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Tenant\TenantSlugResolverInterface;

final class EnvBaseUrlResolver implements BaseUrlResolverInterface
{
    /** @param array<string,string> $map slug => base URL (no trailing slash) */
    public function __construct(
        private readonly array $map,
        private readonly string $fallback,
        private readonly TenantSlugResolverInterface $slugs,
    ) {}

    public function resolveFrontendBaseUrl(string $tenantId): string
    {
        $slug = $this->slugs->slugFor($tenantId);
        if ($slug === null) {
            return $this->fallback;
        }
        return $this->map[$slug] ?? $this->fallback;
    }
}
