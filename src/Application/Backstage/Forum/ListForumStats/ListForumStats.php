<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;

final class ListForumStats
{
    public function __construct(
        private readonly ForumRepositoryInterface $forumRepo,
        private readonly ForumReportRepositoryInterface $reportRepo,
        private readonly ForumModerationAuditRepositoryInterface $auditRepo,
    ) {}

    public function execute(ListForumStatsInput $input): ListForumStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        return new ListForumStatsOutput(
            stats: [
                'open_reports' => [
                    'value'     => $this->reportRepo->countOpenReportsForTenant($input->tenantId),
                    'sparkline' => $this->reportRepo->dailyNewReportsForTenant($input->tenantId),
                ],
                'topics' => [
                    'value'     => $this->forumRepo->countTopicsForTenant($input->tenantId),
                    'sparkline' => $this->forumRepo->dailyNewTopicsForTenant($input->tenantId),
                ],
                'categories' => [
                    'value'     => $this->forumRepo->countCategoriesForTenant($input->tenantId),
                    'sparkline' => [],
                ],
                'mod_actions' => [
                    'value'     => $this->auditRepo->countActionsLast30dForTenant($input->tenantId),
                    'sparkline' => $this->auditRepo->dailyActionCountForTenant($input->tenantId),
                ],
            ],
            recentAudit: $this->auditRepo->recentForTenant($input->tenantId, 5),
        );
    }
}
