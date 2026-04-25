<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Projects\ListProjectsStats;

final class ListProjectsStatsOutput
{
    /**
     * @param array{
     *   active:             array{value: int, sparkline: list<array{date: string, value: int}>},
     *   drafts:             array{value: int, sparkline: list<array{date: string, value: int}>},
     *   featured:           array{value: int, sparkline: list<array{date: string, value: int}>},
     *   pending_proposals:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
