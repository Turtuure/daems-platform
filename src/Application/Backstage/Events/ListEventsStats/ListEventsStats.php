<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Events\ListEventsStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Event\EventRepositoryInterface;

/**
 * Assembles the 4-KPI strip for /backstage/events from 2 repositories:
 *   - upcoming + drafts come from EventRepository::statsForTenant
 *   - registrations_30d comes from EventRepository::dailyRegistrationsForTenant (Path B)
 *   - pending_proposals comes from EventProposalRepository::pendingStatsForTenant
 *
 * Admin-gated via ForbiddenException.
 */
final class ListEventsStats
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly EventProposalRepositoryInterface $proposals,
    ) {}

    public function execute(ListEventsStatsInput $input): ListEventsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        $eventStats = $this->events->statsForTenant($input->tenantId);
        $regs       = $this->events->dailyRegistrationsForTenant($input->tenantId);
        $props      = $this->proposals->pendingStatsForTenant($input->tenantId);

        return new ListEventsStatsOutput(stats: [
            'upcoming'          => $eventStats['upcoming'],
            'drafts'            => $eventStats['drafts'],
            'registrations_30d' => $regs,
            'pending_proposals' => $props,
        ]);
    }
}
