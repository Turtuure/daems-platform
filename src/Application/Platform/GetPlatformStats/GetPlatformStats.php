<?php

declare(strict_types=1);

namespace Daems\Application\Platform\GetPlatformStats;

use Daems\Domain\Platform\PlatformStats;
use Daems\Domain\Platform\PlatformStatsRepositoryInterface;

final class GetPlatformStats
{
    public function __construct(
        private readonly PlatformStatsRepositoryInterface $repo,
    ) {}

    public function execute(): PlatformStats
    {
        return $this->repo->get();
    }
}
