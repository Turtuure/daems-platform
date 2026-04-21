<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\RejectEventProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class RejectEventProposal
{
    public function __construct(
        private readonly EventProposalRepositoryInterface $proposals,
        private readonly Clock $clock,
    ) {
    }

    public function execute(RejectEventProposalInput $input): RejectEventProposalOutput
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

        $this->proposals->recordDecision(
            $proposal->id()->value(),
            $tenantId,
            'rejected',
            $input->acting->id->value(),
            $input->note,
            $this->clock->now(),
        );

        return new RejectEventProposalOutput(true);
    }
}
