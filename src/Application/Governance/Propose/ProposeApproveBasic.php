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
use Daems\Domain\Tenant\UserTenantRole;

final class ProposeApproveBasic
{
    /** @param callable(string):array{status:string} $applicationLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private $applicationLookup,
    ) {}

    public function execute(ProposeApproveBasicInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        $lookup = $this->applicationLookup;
        $app = $lookup($in->applicationId);
        if ($app['status'] !== 'pending') {
            throw new \DomainException("application={$in->applicationId} is not pending");
        }

        // Delegation check — short-circuits the board flow.
        $delegation = $this->delegations->findActive(
            $in->tenantId, BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $in->at
        );

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $status     = $delegation !== null ? BoardDecisionStatus::Passed  : BoardDecisionStatus::Pending;
        $resolvedAt = $delegation !== null ? $in->at                      : null;

        $decision = new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: $in->voteVisibility,
            status: $status,
            proposedByUserId: $in->proposedByUserId,
            proposedAt: $in->at,
            expiresAt:  $in->at->modify("+{$expiresInDays} days"),
            resolvedAt: $resolvedAt,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation: $delegation !== null,
            delegationId: $delegation?->id,
            payloadApplicationId: $in->applicationId,
        );
        $this->decisions->save($decision);
        return $id;
    }
}
