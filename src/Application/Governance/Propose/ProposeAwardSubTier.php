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
use Daems\Domain\Membership\Exception\AlreadyHasActiveSubTier;
use Daems\Domain\Membership\Exception\SubTierAppliesToMismatch;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class ProposeAwardSubTier
{
    /** @param callable(UserId):array{membership_type:string, membership_status:string} $userLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly TenantMembershipSubTierRepositoryInterface $subtiers,
        private readonly MemberSubTierAwardRepositoryInterface $awards,
        private $userLookup,
    ) {}

    public function execute(ProposeAwardSubTierInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync majority decision');
        }

        // Resolve user's membership_type to determine applies_to.
        $lookup = $this->userLookup;
        $u = $lookup($in->targetUserId);
        $userType = MembershipType::tryFrom($u['membership_type']) ?? MembershipType::Basic;

        // applies_to check
        $subtier = $this->subtiers->findBySlug($in->tenantId, $userType, $in->subTierSlug);
        if ($subtier === null) {
            throw new SubTierAppliesToMismatch(
                "sub-tier {$in->subTierSlug} not available for applies_to={$userType->value} in tenant={$in->tenantId->value()}"
            );
        }

        // No active award already
        $active = $this->awards->findActive($in->tenantId, $in->targetUserId, $in->at);
        if ($active !== null) {
            throw new AlreadyHasActiveSubTier(
                "user={$in->targetUserId->value()} already has active sub-tier={$active->subTierSlug}"
            );
        }

        $delegation = $this->delegations->findActive(
            $in->tenantId, BoardDecisionType::AwardSubTier, UserTenantRole::Admin, $in->at
        );

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $resolvedAt = $delegation !== null ? $in->at : null;
        $statusEnum = $delegation !== null ? BoardDecisionStatus::Passed : BoardDecisionStatus::Pending;

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::AwardSubTier,
            threshold: BoardDecisionThreshold::Majority,
            mode: BoardDecisionMode::Sync,
            voteVisibility: $in->voteVisibility,
            status: $statusEnum,
            proposedByUserId: $in->proposedByUserId,
            proposedAt: $in->at,
            expiresAt:  $in->at->modify("+{$expiresInDays} days"),
            resolvedAt: $resolvedAt,
            meetingReference: $in->meetingReference,
            withdrawalReason: null,
            viaDelegation: $delegation !== null,
            delegationId: $delegation?->id,
            payloadTargetUserId: $in->targetUserId,
            payloadSubTierSlug: $in->subTierSlug,
            payloadSubTierAppliesTo: $userType->value,
            payloadReason: $in->reason,
        ));
        return $id;
    }
}
