<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;

/**
 * Admin-side use case: list aggregated forum reports for the acting tenant.
 *
 * Behavior:
 *  - Authorization: acting user must be an admin in the active tenant or a platform admin.
 *  - Fetches aggregated rows (one row per reported target) via the report repository.
 *  - Applies a hard cap on the returned list via the `$limit` input.
 *
 * The `$forum` dependency is injected for symmetry with `GetForumReportDetail`
 * (and future list-side enrichments), keeping the controller's wiring uniform.
 */
final class ListForumReportsForAdmin
{
    public function __construct(
        private readonly ForumReportRepositoryInterface $reports,
        /**
         * Reserved for future list-side enrichment (e.g. target title/author lookups)
         * and kept here so the wiring is symmetric with GetForumReportDetail.
         *
         * @phpstan-ignore property.onlyWritten
         */
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListForumReportsForAdminInput $in): ListForumReportsForAdminOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $items = $this->reports->listAggregatedForTenant($tenantId, $in->filters);
        if (count($items) > $in->limit) {
            $items = array_slice($items, 0, $in->limit);
        }

        return new ListForumReportsForAdminOutput($items);
    }
}
