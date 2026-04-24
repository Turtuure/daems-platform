<?php

declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Domain\Insight\Insight;

final class UpdateInsightOutput
{
    public function __construct(public readonly Insight $insight) {}
}
