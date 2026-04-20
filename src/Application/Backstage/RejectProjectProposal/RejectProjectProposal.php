<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\RejectProjectProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;

final class RejectProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
    ) {}

    public function execute(RejectProjectProposalInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $proposal = $this->proposals->findByIdForTenant($input->proposalId, $tenantId)
            ?? throw new NotFoundException('proposal_not_found');

        if ($proposal->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        $now = $this->clock->now();

        $this->tx->run(function () use ($proposal, $tenantId, $now, $input): void {
            $this->proposals->recordDecision(
                $proposal->id()->value(),
                $tenantId,
                'rejected',
                $input->acting->id->value(),
                $input->note,
                $now,
            );
            $this->dismissals->deleteByAppId($proposal->id()->value());
        });
    }
}
