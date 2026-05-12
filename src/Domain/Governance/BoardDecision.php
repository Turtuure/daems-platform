<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Governance\Exception\AsyncRequiresUnanimous;
use Daems\Domain\User\UserId;

/**
 * Aggregate root for the board-decision lifecycle. Typed payload columns
 * are carried as nullable fields; readers/executors inspect them based on
 * decisionType. Constructor enforces the § 7 invariant: async ⇒ unanimous.
 */
final class BoardDecision
{
    public function __construct(
        public readonly BoardDecisionId $id,
        public readonly BoardId $boardId,
        public readonly BoardDecisionType $decisionType,
        public readonly BoardDecisionThreshold $threshold,
        public readonly BoardDecisionMode $mode,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly BoardDecisionStatus $status,
        public readonly UserId $proposedByUserId,
        public readonly \DateTimeImmutable $proposedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly ?string $meetingReference,
        public readonly ?string $withdrawalReason,
        public readonly bool $viaDelegation,
        public readonly ?BoardDelegationId $delegationId,
        // Typed payload — only some are populated per decisionType.
        public readonly ?UserId $payloadTargetUserId = null,
        public readonly ?string $payloadApplicationId = null,
        public readonly ?string $payloadSubTierSlug = null,
        public readonly ?string $payloadSubTierName = null,
        public readonly ?int $payloadSubTierRank = null,
        public readonly ?string $payloadSubTierAppliesTo = null,
        public readonly ?BoardDecisionSubTierCrudOperation $payloadSubTierOperation = null,
        public readonly ?BoardMemberId $payloadBoardMemberId = null,
        public readonly ?BoardDecisionType $payloadDelegationType = null,
        public readonly ?string $payloadDelegatedToRole = null,
        public readonly ?BoardDelegationId $payloadDelegationRevokeId = null,
        public readonly ?string $payloadReason = null,
    ) {
        if ($mode === BoardDecisionMode::Async && $threshold !== BoardDecisionThreshold::Unanimous) {
            throw new AsyncRequiresUnanimous(
                "§ 7: mode=async requires threshold=unanimous; got threshold={$threshold->value}"
            );
        }
    }

    public function withStatus(
        BoardDecisionStatus $status,
        ?\DateTimeImmutable $resolvedAt = null,
        ?string $withdrawalReason = null,
    ): self {
        return new self(
            id:                       $this->id,
            boardId:                  $this->boardId,
            decisionType:             $this->decisionType,
            threshold:                $this->threshold,
            mode:                     $this->mode,
            voteVisibility:           $this->voteVisibility,
            status:                   $status,
            proposedByUserId:         $this->proposedByUserId,
            proposedAt:               $this->proposedAt,
            expiresAt:                $this->expiresAt,
            resolvedAt:               $resolvedAt ?? $this->resolvedAt,
            meetingReference:         $this->meetingReference,
            withdrawalReason:         $withdrawalReason ?? $this->withdrawalReason,
            viaDelegation:            $this->viaDelegation,
            delegationId:             $this->delegationId,
            payloadTargetUserId:      $this->payloadTargetUserId,
            payloadApplicationId:     $this->payloadApplicationId,
            payloadSubTierSlug:       $this->payloadSubTierSlug,
            payloadSubTierName:       $this->payloadSubTierName,
            payloadSubTierRank:       $this->payloadSubTierRank,
            payloadSubTierAppliesTo:  $this->payloadSubTierAppliesTo,
            payloadSubTierOperation:  $this->payloadSubTierOperation,
            payloadBoardMemberId:     $this->payloadBoardMemberId,
            payloadDelegationType:    $this->payloadDelegationType,
            payloadDelegatedToRole:   $this->payloadDelegatedToRole,
            payloadDelegationRevokeId:$this->payloadDelegationRevokeId,
            payloadReason:            $this->payloadReason,
        );
    }
}
