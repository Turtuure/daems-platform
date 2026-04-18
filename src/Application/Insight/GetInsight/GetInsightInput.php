<?php

declare(strict_types=1);

namespace Daems\Application\Insight\GetInsight;

final class GetInsightInput
{
    public function __construct(
        public readonly string $slug,
    ) {}
}
