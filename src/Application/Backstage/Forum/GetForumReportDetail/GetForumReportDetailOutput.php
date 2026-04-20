<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\GetForumReportDetail;

use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;

final class GetForumReportDetailOutput
{
    /**
     * @param list<ForumReport>      $rawReports
     * @param array<string, mixed>   $targetContent
     */
    public function __construct(
        public readonly AggregatedForumReport $aggregated,
        public readonly array $rawReports,
        public readonly array $targetContent,
    ) {}
}
