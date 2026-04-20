<?php

declare(strict_types=1);

namespace Daems\Domain\Config;

interface BaseUrlResolverInterface
{
    /** Returns the public frontend base URL for the given tenant, no trailing slash. */
    public function resolveFrontendBaseUrl(string $tenantId): string;
}
