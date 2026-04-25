<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Forum\ForumModerationAuditEntry;

final class ListForumStatsOutput
{
    /**
     * @param array{
     *   open_reports: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   topics:       array{value: int, sparkline: list<array{date: string, value: int}>},
     *   categories:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   mod_actions:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     * @param list<ForumModerationAuditEntry> $recentAudit
     */
    public function __construct(
        public readonly array $stats,
        public readonly array $recentAudit,
    ) {}
}
