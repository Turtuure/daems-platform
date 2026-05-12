<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;

final class ProposeRevokeDelegation
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly BoardDelegationRepositoryInterface $delegations,
    ) {}

    public function execute(ProposeRevokeDelegationInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");
        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync decision');
        }

        $target = $this->delegations->find($in->delegationId);
        if ($target === null || !$target->isActive($in->at)) {
            throw new \DomainException("delegation={$in->delegationId->value()} not active");
        }

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::RevokeDelegation,
            threshold: BoardDecisionThreshold::Majority,
            mode: BoardDecisionMode::Sync,
            voteVisibility: $in->voteVisibility,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: $in->proposedByUserId,
            proposedAt: $in->at,
            expiresAt:  $in->at->modify("+{$expiresInDays} days"),
            resolvedAt: null,
            meetingReference: $in->meetingReference,
            withdrawalReason: null,
            viaDelegation: false,
            delegationId: null,
            payloadDelegationRevokeId: $in->delegationId,
            payloadReason: $in->reason,
        ));
        return $id;
    }
}
