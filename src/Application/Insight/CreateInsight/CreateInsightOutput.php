<?php

declare(strict_types=1);

namespace Daems\Application\Insight\CreateInsight;

use Daems\Domain\Insight\Insight;

final class CreateInsightOutput
{
    public function __construct(public readonly Insight $insight) {}
}
