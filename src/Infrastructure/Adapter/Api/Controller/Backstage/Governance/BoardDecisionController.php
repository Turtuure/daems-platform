<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Governance\CastBoardVote;
use Daems\Application\Governance\Delegate\ApproveBasicAsDelegate;
use Daems\Application\Governance\Delegate\AwardSubTierAsDelegate;
use Daems\Application\Governance\Delegate\InviteFullAsDelegate;
use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Application\Governance\Propose\ProposeAwardSubTier;
use Daems\Application\Governance\Propose\ProposeAwardSubTierInput;
use Daems\Application\Governance\Propose\ProposeDelegateAuthority;
use Daems\Application\Governance\Propose\ProposeDelegateAuthorityInput;
use Daems\Application\Governance\Propose\ProposeInviteFull;
use Daems\Application\Governance\Propose\ProposeInviteFullInput;
use Daems\Application\Governance\Propose\ProposeRemoveBoardMember;
use Daems\Application\Governance\Propose\ProposeRemoveBoardMemberInput;
use Daems\Application\Governance\Propose\ProposeRevokeDelegation;
use Daems\Application\Governance\Propose\ProposeRevokeDelegationInput;
use Daems\Application\Governance\Propose\ProposeRevokeSubTier;
use Daems\Application\Governance\Propose\ProposeRevokeSubTierInput;
use Daems\Application\Governance\Propose\ProposeSubTierCrud;
use Daems\Application\Governance\Propose\ProposeSubTierCrudInput;
use Daems\Application\Governance\WithdrawBoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BoardDecisionController
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly ProposeApproveBasic $proposeApproveBasic,
        private readonly ApproveBasicAsDelegate $approveBasicDelegate,
        private readonly ProposeInviteFull $proposeInviteFull,
        private readonly InviteFullAsDelegate $inviteFullDelegate,
        private readonly ProposeAwardSubTier $proposeAwardSubTier,
        private readonly AwardSubTierAsDelegate $awardSubTierDelegate,
        private readonly ProposeRevokeSubTier $proposeRevokeSubTier,
        private readonly ProposeSubTierCrud $proposeSubTierCrud,
        private readonly ProposeRemoveBoardMember $proposeRemoveBoardMember,
        private readonly ProposeDelegateAuthority $proposeDelegateAuthority,
        private readonly ProposeRevokeDelegation $proposeRevokeDelegation,
        private readonly CastBoardVote $castVote,
        private readonly WithdrawBoardDecision $withdraw,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $board = $this->boards->findForTenant($actor->activeTenant);
        if ($board === null) return Response::json(['data' => []]);

        $status = $req->query('status');
        $type   = $req->query('type');
        $statusE = is_string($status) && $status !== '' ? BoardDecisionStatus::tryFrom($status) : null;
        $typeE   = is_string($type)   && $type   !== '' ? BoardDecisionType::tryFrom($type)     : null;

        $rows = [];
        foreach ($this->decisions->listForBoard($board->id, $statusE, $typeE) as $d) {
            $tally = ['yes' => 0, 'no' => 0, 'abstain' => 0];
            foreach ($this->votes->listForDecision($d->id) as $v) {
                $tally[$v->vote->value]++;
            }
            $rows[] = [
                'id'                => $d->id->value(),
                'decision_type'     => $d->decisionType->value,
                'threshold'         => $d->threshold->value,
                'mode'              => $d->mode->value,
                'vote_visibility'   => $d->voteVisibility->value,
                'status'            => $d->status->value,
                'proposed_by'       => $d->proposedByUserId->value(),
                'proposed_at'       => $d->proposedAt->format(\DateTimeInterface::ATOM),
                'expires_at'        => $d->expiresAt->format(\DateTimeInterface::ATOM),
                'resolved_at'       => $d->resolvedAt?->format(\DateTimeInterface::ATOM),
                'via_delegation'    => $d->viaDelegation,
                'meeting_reference' => $d->meetingReference,
                'tally'             => $tally,
            ];
        }
        return Response::json(['data' => $rows]);
    }

    public function show(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $d     = $this->decisions->find(BoardDecisionId::fromString($id))
            ?? throw new \DomainException('decision not found');

        $board = $this->boards->findForTenant($actor->activeTenant);
        $viewerMemberId = null;
        if ($board !== null) {
            foreach ($this->members->listForBoard($board->id) as $m) {
                if ($m->userId->equals($actor->id)) { $viewerMemberId = $m->id->value(); break; }
            }
        }
        $viewerVoted = false;
        $votes = $this->votes->listForDecision($d->id);
        foreach ($votes as $v) {
            if ($viewerMemberId !== null && $v->boardMemberId->value() === $viewerMemberId) { $viewerVoted = true; break; }
        }

        $voteRows = [];
        $showIndividual = $d->voteVisibility === BoardDecisionVoteVisibility::Visible
            || $actor->isPlatformAdmin()
            || $viewerVoted;
        if ($showIndividual) {
            foreach ($votes as $v) {
                $voteRows[] = [
                    'board_member_id' => $v->boardMemberId->value(),
                    'vote'            => $v->vote->value,
                    'cast_at'         => $v->castAt->format(\DateTimeInterface::ATOM),
                ];
            }
        }
        $tally = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        foreach ($votes as $v) $tally[$v->vote->value]++;

        return Response::json([
            'decision' => [
                'id'                => $d->id->value(),
                'decision_type'     => $d->decisionType->value,
                'threshold'         => $d->threshold->value,
                'mode'              => $d->mode->value,
                'vote_visibility'   => $d->voteVisibility->value,
                'status'            => $d->status->value,
                'proposed_by'       => $d->proposedByUserId->value(),
                'proposed_at'       => $d->proposedAt->format(\DateTimeInterface::ATOM),
                'expires_at'        => $d->expiresAt->format(\DateTimeInterface::ATOM),
                'resolved_at'       => $d->resolvedAt?->format(\DateTimeInterface::ATOM),
                'via_delegation'    => $d->viaDelegation,
                'meeting_reference' => $d->meetingReference,
                'payload'           => array_filter([
                    'target_user_id'      => $d->payloadTargetUserId?->value(),
                    'application_id'      => $d->payloadApplicationId,
                    'sub_tier_slug'       => $d->payloadSubTierSlug,
                    'sub_tier_name'       => $d->payloadSubTierName,
                    'sub_tier_rank'       => $d->payloadSubTierRank,
                    'sub_tier_applies_to' => $d->payloadSubTierAppliesTo,
                    'sub_tier_operation'  => $d->payloadSubTierOperation?->value,
                    'board_member_id'     => $d->payloadBoardMemberId?->value(),
                    'delegation_type'     => $d->payloadDelegationType?->value,
                    'delegated_to_role'   => $d->payloadDelegatedToRole,
                    'reason'              => $d->payloadReason,
                ], static fn($v) => $v !== null),
            ],
            'tally' => $tally,
            'votes' => $voteRows,
        ]);
    }

    public function proposeApproveBasic(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $appId = is_string($body['application_id'] ?? null) ? $body['application_id'] : throw new \DomainException('application_id required');

        $input = new ProposeApproveBasicInput(
            tenantId: $actor->activeTenant,
            applicationId: $appId,
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            at: new \DateTimeImmutable(),
        );
        try {
            $id = $this->approveBasicDelegate->execute($input);
        } catch (DelegationNotPermittedForType) {
            $id = $this->proposeApproveBasic->execute($input);
        }
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeInviteFull(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $userId = is_string($body['user_id'] ?? null) ? $body['user_id'] : throw new \DomainException('user_id required');
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        $input = new ProposeInviteFullInput(
            tenantId: $actor->activeTenant,
            targetUserId: UserId::fromString($userId),
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            reason: $reason,
            at: new \DateTimeImmutable(),
        );
        try {
            $id = $this->inviteFullDelegate->execute($input);
        } catch (DelegationNotPermittedForType) {
            $id = $this->proposeInviteFull->execute($input);
        }
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeAwardSubTier(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $userId = is_string($body['user_id'] ?? null) ? $body['user_id'] : throw new \DomainException('user_id required');
        $slug   = is_string($body['sub_tier_slug'] ?? null) ? $body['sub_tier_slug'] : throw new \DomainException('sub_tier_slug required');
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $mref   = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';

        $input = new ProposeAwardSubTierInput(
            tenantId: $actor->activeTenant,
            targetUserId: UserId::fromString($userId),
            subTierSlug: $slug,
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            reason: $reason,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        );
        try {
            $id = $this->awardSubTierDelegate->execute($input);
        } catch (DelegationNotPermittedForType) {
            $id = $this->proposeAwardSubTier->execute($input);
        }
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeRevokeSubTier(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $userId = is_string($body['user_id'] ?? null) ? $body['user_id'] : throw new \DomainException('user_id required');
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $mref   = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';
        $vis    = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');

        $id = $this->proposeRevokeSubTier->execute(new ProposeRevokeSubTierInput(
            tenantId: $actor->activeTenant,
            targetUserId: UserId::fromString($userId),
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            reason: $reason,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeSubTierCrud(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $op    = BoardDecisionSubTierCrudOperation::from(is_string($body['operation'] ?? null) ? $body['operation'] : throw new \DomainException('operation required'));
        $slug  = is_string($body['sub_tier_slug'] ?? null) ? $body['sub_tier_slug'] : throw new \DomainException('sub_tier_slug required');
        $name  = is_string($body['name'] ?? null) ? $body['name'] : null;
        $rank  = is_int($body['rank_order'] ?? null) ? $body['rank_order'] : null;
        $apply = MembershipType::from(is_string($body['applies_to'] ?? null) ? $body['applies_to'] : throw new \DomainException('applies_to required'));
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $mref  = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';

        $id = $this->proposeSubTierCrud->execute(new ProposeSubTierCrudInput(
            tenantId: $actor->activeTenant,
            operation: $op,
            subTierSlug: $slug,
            name: $name,
            rankOrder: $rank,
            appliesTo: $apply,
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeRemoveBoardMember(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $bmId  = is_string($body['board_member_id'] ?? null) ? $body['board_member_id'] : throw new \DomainException('board_member_id required');
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $mref   = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';
        $vis    = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');

        $id = $this->proposeRemoveBoardMember->execute(new ProposeRemoveBoardMemberInput(
            tenantId: $actor->activeTenant,
            targetBoardMemberId: BoardMemberId::fromString($bmId),
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            reason: $reason,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeDelegateAuthority(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $dt    = BoardDecisionType::from(is_string($body['decision_type'] ?? null) ? $body['decision_type'] : throw new \DomainException('decision_type required'));
        $role  = UserTenantRole::from(is_string($body['delegated_to_role'] ?? null) ? $body['delegated_to_role'] : 'admin');
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $mref  = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';

        $id = $this->proposeDelegateAuthority->execute(new ProposeDelegateAuthorityInput(
            tenantId: $actor->activeTenant,
            decisionType: $dt,
            delegatedToRole: $role,
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function proposeRevokeDelegation(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $delegId = is_string($body['delegation_id'] ?? null) ? $body['delegation_id'] : throw new \DomainException('delegation_id required');
        $reason  = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $mref    = is_string($body['meeting_reference'] ?? null) ? $body['meeting_reference'] : '';
        $vis     = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');

        $id = $this->proposeRevokeDelegation->execute(new ProposeRevokeDelegationInput(
            tenantId: $actor->activeTenant,
            delegationId: BoardDelegationId::fromString($delegId),
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            reason: $reason,
            meetingReference: $mref,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['decision_id' => $id->value()], 201);
    }

    public function vote(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->all();
        $vote  = BoardDecisionVoteValue::from(is_string($body['vote'] ?? null) ? $body['vote'] : 'abstain');
        $this->castVote->execute(
            decisionId:   BoardDecisionId::fromString($id),
            actingUserId: $actor->id,
            vote:         $vote,
            at:           new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }

    public function withdraw(Request $req, string $id): Response
    {
        $actor  = $req->requireActingUser();
        $body   = $req->all();
        $reason = is_string($body['withdrawal_reason'] ?? null) ? $body['withdrawal_reason'] : '';
        $this->withdraw->execute(
            decisionId:       BoardDecisionId::fromString($id),
            actingUserId:     $actor->id,
            withdrawalReason: $reason,
            at:               new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }
}
