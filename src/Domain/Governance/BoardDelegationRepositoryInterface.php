<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

interface BoardDelegationRepositoryInterface
{
    public function findActive(
        TenantId $tenantId,
        BoardDecisionType $decisionType,
        UserTenantRole $delegatedToRole,
        \DateTimeImmutable $at,
    ): ?BoardDelegation;

    /** @return list<BoardDelegation> */
    public function listActive(TenantId $tenantId, \DateTimeImmutable $at): array;

    public function find(BoardDelegationId $id): ?BoardDelegation;

    public function save(BoardDelegation $delegation): void;
}
