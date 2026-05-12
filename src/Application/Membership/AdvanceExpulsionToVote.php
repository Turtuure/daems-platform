<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Exception\ExpulsionAlreadyAdvanced;
use Daems\Domain\Membership\Exception\ExpulsionHearingNotElapsed;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class AdvanceExpulsionToVote
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        \DateTimeImmutable $at,
        ?string $meetingReference,
    ): BoardDecisionId {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");
        if ($e->status !== MemberExpulsionStatus::Hearing) {
            throw new ExpulsionAlreadyAdvanced("expulsion={$expulsionId->value()} status={$e->status->value}");
        }
        if ($at < $e->hearingDeadlineAt && $e->statementReceivedAt === null) {
            throw new ExpulsionHearingNotElapsed("hearing_deadline_at={$e->hearingDeadlineAt->format('c')}");
        }

        $board = $this->boards->findForTenant($e->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$e->tenantId->value()}");

        // Permission: chair only.
        $chair = null;
        foreach ($this->members->listActiveForBoard($board->id, $at) as $m) {
            if ($m->role === BoardMemberRole::Chair) { $chair = $m; break; }
        }
        if ($chair === null || !$chair->userId->equals($actingUserId)) {
            throw new NotABoardMember('only the chair may advance an expulsion');
        }

        $tg            = $this->settings->find($e->tenantId);
        $expiresInDays = $tg !== null ? $tg->decisionExpirationDays : 60;

        // Create the Expel decision.
        $decisionId = BoardDecisionId::generate();
        $this->decisions->save(new BoardDecision(
            id:               $decisionId,
            boardId:          $board->id,
            decisionType:     BoardDecisionType::Expel,
            threshold:        BoardDecisionThreshold::Unanimous,
            mode:             BoardDecisionMode::Sync,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: $e->proposedByUserId,
            proposedAt:       $at,
            expiresAt:        $at->modify("+{$expiresInDays} days"),
            resolvedAt:       null,
            meetingReference: $meetingReference,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
            payloadTargetUserId: $e->targetUserId,
            payloadReason:       $e->reason,
        ));

        // Move the expulsion to AwaitingVote and link the decision.
        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       $e->statementText,
            statementReceivedAt: $e->statementReceivedAt,
            decisionId:          $decisionId,
            decidedAt:           null,
            expelledAt:          null,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::AwaitingVote,
            createdAt:           $e->createdAt,
        ));

        return $decisionId;
    }
}
