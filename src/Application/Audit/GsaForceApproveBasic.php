<?php
declare(strict_types=1);

namespace Daems\Application\Audit;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;

final class GsaForceApproveBasic
{
    /** @param callable(string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId):void $approve */
    public function __construct(
        private readonly GsaOverrideRepositoryInterface $overrides,
        private $approve,
    ) {}

    public function execute(GsaForceApproveBasicInput $in): GsaOverrideId
    {
        // GsaOverride constructor enforces reason ≥ 10 chars via GsaOverrideRequiresReason.
        $id = GsaOverrideId::generate();
        $this->overrides->save(new GsaOverride(
            id:          $id,
            gsaUserId:   $in->gsaUserId,
            tenantId:    $in->tenantId,
            action:      GsaOverrideAction::ForceApproveBasic,
            targetId:    $in->applicationId,
            reason:      $in->reason,
            performedAt: $in->at,
        ));

        // Apply effect: same code path as a passed decision's ApproveBasicExecutor.
        $approve = $this->approve;
        $approve($in->applicationId, $in->at, null);

        return $id;
    }
}
