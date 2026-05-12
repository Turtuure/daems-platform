<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class SubTierCrudExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(BoardId):TenantId $tenantOfBoard */
    public function __construct(
        private readonly TenantMembershipSubTierRepositoryInterface $subtiers,
        private $tenantOfBoard,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::SubTierCrud;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadSubTierOperation === null || $d->payloadSubTierSlug === null || $d->payloadSubTierAppliesTo === null) {
            throw new \DomainException('subtier_crud decision missing payload fields');
        }
        $tenantLookup = $this->tenantOfBoard;
        $tenantId = $tenantLookup($d->boardId);
        $appliesTo = MembershipType::from($d->payloadSubTierAppliesTo);

        switch ($d->payloadSubTierOperation) {
            case BoardDecisionSubTierCrudOperation::Create:
                $this->subtiers->save(new TenantMembershipSubTier(
                    id: TenantMembershipSubTierId::generate(),
                    tenantId: $tenantId,
                    slug: $d->payloadSubTierSlug,
                    name: $d->payloadSubTierName ?? $d->payloadSubTierSlug,
                    rankOrder: $d->payloadSubTierRank ?? 1,
                    appliesTo: $appliesTo,
                ));
                break;
            case BoardDecisionSubTierCrudOperation::Update:
                $existing = $this->subtiers->findBySlug($tenantId, $appliesTo, $d->payloadSubTierSlug);
                if ($existing !== null) {
                    $this->subtiers->save(new TenantMembershipSubTier(
                        id: $existing->id,
                        tenantId: $existing->tenantId,
                        slug: $existing->slug,
                        name: $d->payloadSubTierName ?? $existing->name,
                        rankOrder: $d->payloadSubTierRank ?? $existing->rankOrder,
                        appliesTo: $existing->appliesTo,
                    ));
                }
                break;
            case BoardDecisionSubTierCrudOperation::Delete:
                $existing = $this->subtiers->findBySlug($tenantId, $appliesTo, $d->payloadSubTierSlug);
                if ($existing !== null) {
                    $this->subtiers->delete($existing->id);
                }
                break;
        }
    }
}
