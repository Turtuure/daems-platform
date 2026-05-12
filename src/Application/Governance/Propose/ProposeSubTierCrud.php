<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Exception\DuplicateSubTierSlug;
use Daems\Domain\Membership\Exception\SubTierInUse;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;

final class ProposeSubTierCrud
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly TenantMembershipSubTierRepositoryInterface $subtiers,
        private readonly MemberSubTierAwardRepositoryInterface $awards,
    ) {}

    public function execute(ProposeSubTierCrudInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        if (trim($in->meetingReference) === '') {
            throw new \InvalidArgumentException('meeting_reference required for sync majority decision');
        }

        $existing = $this->subtiers->findBySlug($in->tenantId, $in->appliesTo, $in->subTierSlug);

        switch ($in->operation) {
            case BoardDecisionSubTierCrudOperation::Create:
                if ($in->name === null || $in->rankOrder === null) {
                    throw new \InvalidArgumentException('Create operation requires name + rank_order');
                }
                if ($existing !== null) {
                    throw new DuplicateSubTierSlug(
                        "sub-tier slug={$in->subTierSlug} already exists for applies_to={$in->appliesTo->value} in tenant={$in->tenantId->value()}"
                    );
                }
                break;
            case BoardDecisionSubTierCrudOperation::Update:
                if ($existing === null) {
                    throw new \DomainException(
                        "sub-tier slug={$in->subTierSlug} not found for applies_to={$in->appliesTo->value} in tenant={$in->tenantId->value()}"
                    );
                }
                if ($in->name === null && $in->rankOrder === null) {
                    throw new \InvalidArgumentException('Update operation requires at least one of name or rank_order');
                }
                break;
            case BoardDecisionSubTierCrudOperation::Delete:
                if ($existing === null) {
                    throw new \DomainException(
                        "sub-tier slug={$in->subTierSlug} not found for applies_to={$in->appliesTo->value} in tenant={$in->tenantId->value()}"
                    );
                }
                $activeAwards = $this->awards->listActiveForSubTierSlug($in->tenantId, $in->subTierSlug, $in->at);
                if (count($activeAwards) > 0) {
                    throw new SubTierInUse(
                        "sub-tier slug={$in->subTierSlug} has " . count($activeAwards) . ' active award(s); revoke them first'
                    );
                }
                break;
        }

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $id = BoardDecisionId::generate();

        $this->decisions->save(new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::SubTierCrud,
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
            payloadSubTierSlug: $in->subTierSlug,
            payloadSubTierName: $in->name,
            payloadSubTierRank: $in->rankOrder,
            payloadSubTierAppliesTo: $in->appliesTo->value,
            payloadSubTierOperation: $in->operation,
        ));
        return $id;
    }
}
