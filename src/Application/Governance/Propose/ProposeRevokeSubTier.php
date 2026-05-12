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
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Exception\NoActiveSubTierToRevoke;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;

final class ProposeRevokeSubTier
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly MemberSubTierAwardRepositoryInterface $awards,
    ) {}

    public function execute(ProposeRevokeSubTierInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync majority decision');
        }

        // Precondition: user must have an active sub-tier
        $active = $this->awards->findActive($in->tenantId, $in->targetUserId, $in->at);
        if ($active === null) {
            throw new NoActiveSubTierToRevoke("user={$in->targetUserId->value()} has no active sub-tier to revoke");
        }

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::RevokeSubTier,
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
            payloadTargetUserId: $in->targetUserId,
            payloadSubTierSlug: $active->subTierSlug,
            payloadReason: $in->reason,
        ));
        return $id;
    }
}
