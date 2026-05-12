<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;

final class RevokeDelegationExecutor implements BoardDecisionExecutorInterface
{
    public function __construct(
        private readonly BoardDelegationRepositoryInterface $delegations,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::RevokeDelegation;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadDelegationRevokeId === null) {
            throw new \DomainException('revoke_delegation decision missing payload_delegation_revoke_id');
        }
        $existing = $this->delegations->find($d->payloadDelegationRevokeId);
        if ($existing === null) return;
        $this->delegations->save(new BoardDelegation(
            id: $existing->id,
            tenantId: $existing->tenantId,
            decisionType: $existing->decisionType,
            delegatedToRole: $existing->delegatedToRole,
            sourceDecisionId: $existing->sourceDecisionId,
            validFrom: $existing->validFrom,
            revokedAt: $at,
        ));
    }
}
