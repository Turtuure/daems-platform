<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Delegate;

use Daems\Application\Governance\Executor\AwardSubTierExecutor;
use Daems\Application\Governance\Propose\ProposeAwardSubTier;
use Daems\Application\Governance\Propose\ProposeAwardSubTierInput;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\UserTenantRole;

final class AwardSubTierAsDelegate
{
    public function __construct(
        private readonly ProposeAwardSubTier $propose,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly AwardSubTierExecutor $executor,
    ) {}

    public function execute(ProposeAwardSubTierInput $in): BoardDecisionId
    {
        $deleg = $this->delegations->findActive($in->tenantId, BoardDecisionType::AwardSubTier, UserTenantRole::Admin, $in->at);
        if ($deleg === null) {
            throw new DelegationNotPermittedForType('No active award_subtier delegation; use ProposeAwardSubTier instead');
        }
        $id = $this->propose->execute($in);
        $d  = $this->decisions->find($id) ?? throw new \RuntimeException('decision missing after propose');
        $this->executor->execute($d, $in->at);
        return $id;
    }
}
