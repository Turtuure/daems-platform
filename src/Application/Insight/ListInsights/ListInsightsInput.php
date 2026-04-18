<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsights;

final class ListInsightsInput
{
    public function __construct(
        public readonly ?string $category = null,
    ) {}
}
