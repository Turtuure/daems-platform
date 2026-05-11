<?php

declare(strict_types=1);

namespace Daems\Domain\Platform;

interface PlatformStatsRepositoryInterface
{
    public function get(): PlatformStats;
}
