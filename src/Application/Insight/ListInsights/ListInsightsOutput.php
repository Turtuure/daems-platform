<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsights;

final class ListInsightsOutput
{
    public function __construct(
        public readonly array $insights,
    ) {}
}
