<?php

declare(strict_types=1);

namespace Daems\Application\Insight\GetInsight;

final class GetInsightOutput
{
    public function __construct(
        public readonly ?array $insight,
    ) {}
}
