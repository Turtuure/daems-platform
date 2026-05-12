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
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;

final class ProposeDelegateAuthority
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
    ) {}

    public function execute(ProposeDelegateAuthorityInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        if (!$in->decisionType->isDelegatable()) {
            throw new DelegationNotPermittedForType(
                "decision_type={$in->decisionType->value} is not delegatable"
            );
        }
        if ($in->delegatedToRole !== UserTenantRole::Admin) {
            throw new DelegationNotPermittedForType(
                "Only admin role can be delegatee; got {$in->delegatedToRole->value}"
            );
        }
        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync decision');
        }

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::DelegateAuthority,
            threshold: BoardDecisionThreshold::Unanimous,
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
            payloadDelegationType: $in->decisionType,
            payloadDelegatedToRole: $in->delegatedToRole->value,
        ));
        return $id;
    }
}
