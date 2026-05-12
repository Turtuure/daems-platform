<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Delegate;

use Daems\Application\Governance\Executor\ApproveBasicExecutor;
use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\UserTenantRole;

/**
 * Direct admin-side hook: when an admin clicks "Hyväksy" on an Application,
 * and a standing delegation for approve_basic→admin is active, this use case
 * runs the executor immediately and saves the decision in Passed status.
 *
 * Implementation: route through ProposeApproveBasic (which detects the active
 * delegation), then call the executor once to apply the side-effect. The
 * propose-flow already saves status=Passed when delegation is active.
 */
final class ApproveBasicAsDelegate
{
    public function __construct(
        private readonly ProposeApproveBasic $propose,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly ApproveBasicExecutor $executor,
    ) {}

    public function execute(ProposeApproveBasicInput $in): BoardDecisionId
    {
        // Guard: delegation MUST be active or this short-circuit is invalid.
        $deleg = $this->delegations->findActive($in->tenantId, BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $in->at);
        if ($deleg === null) {
            throw new DelegationNotPermittedForType('No active approve_basic delegation; use ProposeApproveBasic instead');
        }
        $id = $this->propose->execute($in);
        $d  = $this->decisions->find($id) ?? throw new \RuntimeException('decision missing after propose');
        $this->executor->execute($d, $in->at);
        return $id;
    }
}
