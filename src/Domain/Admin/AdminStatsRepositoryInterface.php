<?php

declare(strict_types=1);

namespace Daems\Domain\Admin;

interface AdminStatsRepositoryInterface
{
    public function getStats(): AdminStats;
}
