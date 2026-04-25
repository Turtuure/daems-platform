<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Notifications\ListNotificationsStats;

final class ListNotificationsStatsOutput
{
    /**
     * @param array{
     *   pending_you:       array{value: int, sparkline: list<array{date: string, value: int}>},
     *   pending_all:       array{value: int, sparkline: list<array{date: string, value: int}>},
     *   cleared_30d:       array{value: int, sparkline: list<array{date: string, value: int}>},
     *   oldest_pending_d:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
