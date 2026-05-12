<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;

final class InMemoryMemberExpulsionRepository implements MemberExpulsionRepositoryInterface
{
    /** @var array<string, MemberExpulsion> */
    private array $byId = [];

    public function find(MemberExpulsionId $id): ?MemberExpulsion
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array
    {
        $out = [];
        foreach ($this->byId as $e) {
            if ($e->tenantId->value() !== $tenantId->value()) continue;
            if ($status !== null && $e->status !== $status) continue;
            $out[] = $e;
        }
        usort($out, fn(MemberExpulsion $a, MemberExpulsion $b) => $b->createdAt <=> $a->createdAt);
        return $out;
    }

    public function save(MemberExpulsion $expulsion): void
    {
        $this->byId[$expulsion->id->value()] = $expulsion;
    }
}
