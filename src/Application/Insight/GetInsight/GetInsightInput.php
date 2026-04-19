<?php

declare(strict_types=1);

namespace Daems\Application\Insight\GetInsight;

use Daems\Domain\Tenant\TenantId;

final class GetInsightInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $slug,
    ) {}
}
