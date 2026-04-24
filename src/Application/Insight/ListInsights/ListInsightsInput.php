<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsights;

use Daems\Domain\Tenant\TenantId;

final class ListInsightsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $category = null,
        public readonly bool $includeUnpublished = false,
    ) {}
}
