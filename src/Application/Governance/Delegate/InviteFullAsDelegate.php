<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Delegate;

use Daems\Application\Governance\Executor\InviteFullExecutor;
use Daems\Application\Governance\Propose\ProposeInviteFull;
use Daems\Application\Governance\Propose\ProposeInviteFullInput;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\UserTenantRole;

final class InviteFullAsDelegate
{
    public function __construct(
        private readonly ProposeInviteFull $propose,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly InviteFullExecutor $executor,
    ) {}

    public function execute(ProposeInviteFullInput $in): BoardDecisionId
    {
        $deleg = $this->delegations->findActive($in->tenantId, BoardDecisionType::InviteFull, UserTenantRole::Admin, $in->at);
        if ($deleg === null) {
            throw new DelegationNotPermittedForType('No active invite_full delegation; use ProposeInviteFull instead');
        }
        $id = $this->propose->execute($in);
        $d  = $this->decisions->find($id) ?? throw new \RuntimeException('decision missing after propose');
        $this->executor->execute($d, $in->at);
        return $id;
    }
}
