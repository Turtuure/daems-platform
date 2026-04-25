<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Projects\ListProjectsStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;

/**
 * Assembles the 4-KPI strip for /backstage/projects from 2 repositories:
 *   - active + drafts + featured come from ProjectRepository::statsForTenant
 *   - pending_proposals comes from ProjectProposalRepository::pendingStatsForTenant
 *
 * Admin-gated via ForbiddenException.
 */
final class ListProjectsStats
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly ProjectProposalRepositoryInterface $proposals,
    ) {}

    public function execute(ListProjectsStatsInput $input): ListProjectsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        $proj  = $this->projects->statsForTenant($input->tenantId);
        $props = $this->proposals->pendingStatsForTenant($input->tenantId);

        return new ListProjectsStatsOutput(stats: [
            'active'            => $proj['active'],
            'drafts'            => $proj['drafts'],
            'featured'          => $proj['featured'],
            'pending_proposals' => $props,
        ]);
    }
}
