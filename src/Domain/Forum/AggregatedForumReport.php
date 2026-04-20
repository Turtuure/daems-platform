<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

final class AggregatedForumReport
{
    /**
     * @param array<string,int> $reasonCounts reason_category => count
     * @param list<string>      $rawReportIds
     */
    public function __construct(
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly int $reportCount,
        public readonly array $reasonCounts,
        public readonly array $rawReportIds,
        public readonly string $earliestCreatedAt,
        public readonly string $latestCreatedAt,
        public readonly string $status,
    ) {}

    public function compoundKey(): string
    {
        return $this->targetType . ':' . $this->targetId;
    }
}
