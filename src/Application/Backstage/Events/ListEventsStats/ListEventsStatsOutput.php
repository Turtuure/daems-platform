<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Events\ListEventsStats;

final class ListEventsStatsOutput
{
    /**
     * @param array{
     *   upcoming:           array{value: int, sparkline: list<array{date: string, value: int}>},
     *   drafts:             array{value: int, sparkline: list<array{date: string, value: int}>},
     *   registrations_30d:  array{value: int, sparkline: list<array{date: string, value: int}>},
     *   pending_proposals:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
