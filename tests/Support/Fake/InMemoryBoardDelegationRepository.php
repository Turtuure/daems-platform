<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

final class InMemoryBoardDelegationRepository implements BoardDelegationRepositoryInterface
{
    /** @var array<string, BoardDelegation> */
    private array $byId = [];

    public function findActive(TenantId $tenantId, BoardDecisionType $decisionType, UserTenantRole $delegatedToRole, \DateTimeImmutable $at): ?BoardDelegation
    {
        $candidates = [];
        foreach ($this->byId as $d) {
            if ($d->tenantId->value() === $tenantId->value()
                && $d->decisionType === $decisionType
                && $d->delegatedToRole === $delegatedToRole
                && $d->isActive($at)) {
                $candidates[] = $d;
            }
        }
        if (empty($candidates)) return null;
        usort($candidates, fn(BoardDelegation $a, BoardDelegation $b) => $b->validFrom <=> $a->validFrom);
        return $candidates[0];
    }

    public function listActive(TenantId $tenantId, \DateTimeImmutable $at): array
    {
        $out = [];
        foreach ($this->byId as $d) {
            if ($d->tenantId->value() === $tenantId->value() && $d->isActive($at)) $out[] = $d;
        }
        return $out;
    }

    public function find(BoardDelegationId $id): ?BoardDelegation
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function save(BoardDelegation $delegation): void
    {
        $this->byId[$delegation->id->value()] = $delegation;
    }
}
