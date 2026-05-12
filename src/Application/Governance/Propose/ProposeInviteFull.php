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
use Daems\Domain\Governance\Exception\NotEligibleForFull;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\IsEligibleForFullMembership;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class ProposeInviteFull
{
    /** @param callable(UserId):array{membership_type:string, membership_status:string, membership_started_at:?string} $userLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly IsEligibleForFullMembership $eligibility,
        private $userLookup,
    ) {}

    public function execute(ProposeInviteFullInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        // 12-month + status precondition
        $lookup = $this->userLookup;
        $u = $lookup($in->targetUserId);
        $type   = MembershipType::tryFrom($u['membership_type']) ?? MembershipType::Basic;
        $status = $u['membership_status'];
        $startedAt = is_string($u['membership_started_at'] ?? null)
            ? new \DateTimeImmutable($u['membership_started_at'])
            : null;
        if (!$this->eligibility->check($type, $status, $startedAt, $in->at)) {
            throw new NotEligibleForFull("user={$in->targetUserId->value()} not eligible for FULL membership");
        }

        $delegation = $this->delegations->findActive(
            $in->tenantId, BoardDecisionType::InviteFull, UserTenantRole::Admin, $in->at
        );

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $resolvedAt = $delegation !== null ? $in->at : null;
        $statusEnum = $delegation !== null ? BoardDecisionStatus::Passed : BoardDecisionStatus::Pending;

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::InviteFull,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: $in->voteVisibility,
            status: $statusEnum,
            proposedByUserId: $in->proposedByUserId,
            proposedAt: $in->at,
            expiresAt:  $in->at->modify("+{$expiresInDays} days"),
            resolvedAt: $resolvedAt,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation: $delegation !== null,
            delegationId: $delegation?->id,
            payloadTargetUserId: $in->targetUserId,
            payloadReason: $in->reason,
        ));
        return $id;
    }
}
