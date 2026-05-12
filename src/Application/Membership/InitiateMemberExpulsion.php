<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;

final class InitiateMemberExpulsion
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
    ) {}

    public function execute(InitiateMemberExpulsionInput $in): MemberExpulsionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        // Proposer must be an active board member.
        $isBoardMember = false;
        foreach ($this->members->listActiveForBoard($board->id, $in->at) as $m) {
            if ($m->userId->equals($in->proposedByUserId)) { $isBoardMember = true; break; }
        }
        if (!$isBoardMember) {
            throw new NotABoardMember("user={$in->proposedByUserId->value()} cannot initiate expulsion");
        }

        if (trim($in->reason) === '') {
            throw new \InvalidArgumentException('reason required');
        }

        $settings    = $this->settings->find($in->tenantId);
        $hearingDays = $settings !== null ? $settings->expulsionHearingDays : 14;

        $id = MemberExpulsionId::generate();
        $this->expulsions->save(new MemberExpulsion(
            id:                  $id,
            tenantId:            $in->tenantId,
            targetUserId:        $in->targetUserId,
            proposedByUserId:    $in->proposedByUserId,
            reason:              trim($in->reason),
            hearingDeadlineAt:   $in->at->modify("+{$hearingDays} days"),
            statementText:       null,
            statementReceivedAt: null,
            decisionId:          null,
            decidedAt:           null,
            expelledAt:          null,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::Hearing,
            createdAt:           $in->at,
        ));
        return $id;
    }
}
