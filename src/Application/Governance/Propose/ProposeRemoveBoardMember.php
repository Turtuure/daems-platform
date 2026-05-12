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
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\LastBoardMemberCannotBeRemoved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;

final class ProposeRemoveBoardMember
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly BoardMemberRepositoryInterface $members,
    ) {}

    public function execute(ProposeRemoveBoardMemberInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync decision');
        }

        // Target must be a member of this board AND currently active
        $target = $this->members->find($in->targetBoardMemberId);
        if ($target === null || $target->boardId->value() !== $board->id->value()) {
            throw new NotABoardMember("member={$in->targetBoardMemberId->value()} not on tenant's board");
        }
        if (!$target->isActive($in->at)) {
            throw new NotABoardMember("member={$in->targetBoardMemberId->value()} not active");
        }

        // Last-active-member guard
        $active = $this->members->listActiveForBoard($board->id, $in->at);
        if (count($active) <= 1) {
            throw new LastBoardMemberCannotBeRemoved(
                "Cannot remove board_member={$in->targetBoardMemberId->value()} — only " . count($active) . ' active member(s) remain'
            );
        }

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::RemoveBoardMember,
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
            payloadBoardMemberId: $in->targetBoardMemberId,
            payloadReason: $in->reason,
        ));
        return $id;
    }
}
