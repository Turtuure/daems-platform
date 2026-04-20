<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Config;

use Daems\Domain\Config\BaseUrlResolverInterface;

final class EnvBaseUrlResolver implements BaseUrlResolverInterface
{
    /** @param array<string,string> $map tenant_id => base URL (no trailing slash) */
    public function __construct(
        private readonly array $map,
        private readonly string $fallback,
    ) {}

    public function resolveFrontendBaseUrl(string $tenantId): string
    {
        return $this->map[$tenantId] ?? $this->fallback;
    }
}
