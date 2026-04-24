<?php
declare(strict_types=1);

namespace Daems\Application\Insight\DeleteInsight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

final class DeleteInsightInput
{
    public function __construct(
        public readonly InsightId $insightId,
        public readonly TenantId $tenantId,
    ) {}
}
