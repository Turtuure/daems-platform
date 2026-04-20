<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Forum\AggregatedForumReport;

final class ListForumReportsForAdminOutput
{
    /**
     * @param list<AggregatedForumReport> $items
     */
    public function __construct(public readonly array $items) {}
}
