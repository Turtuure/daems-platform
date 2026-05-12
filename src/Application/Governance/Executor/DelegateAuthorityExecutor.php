<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

final class DelegateAuthorityExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(BoardId):TenantId $tenantOfBoard */
    public function __construct(
        private readonly BoardDelegationRepositoryInterface $delegations,
        private $tenantOfBoard,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::DelegateAuthority;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadDelegationType === null || $d->payloadDelegatedToRole === null) {
            throw new \DomainException('delegate_authority decision missing payload fields');
        }
        $tenantLookup = $this->tenantOfBoard;
        $tenantId = $tenantLookup($d->boardId);
        $role = UserTenantRole::from($d->payloadDelegatedToRole);

        // Auto-supersede: revoke any active delegation matching (tenant, type, role)
        $active = $this->delegations->findActive($tenantId, $d->payloadDelegationType, $role, $at);
        if ($active !== null) {
            $this->delegations->save(new BoardDelegation(
                id: $active->id,
                tenantId: $active->tenantId,
                decisionType: $active->decisionType,
                delegatedToRole: $active->delegatedToRole,
                sourceDecisionId: $active->sourceDecisionId,
                validFrom: $active->validFrom,
                revokedAt: $at,
            ));
        }

        // Create the new active delegation
        $this->delegations->save(new BoardDelegation(
            id: BoardDelegationId::generate(),
            tenantId: $tenantId,
            decisionType: $d->payloadDelegationType,
            delegatedToRole: $role,
            sourceDecisionId: $d->id,
            validFrom: $at,
            revokedAt: null,
        ));
    }
}
