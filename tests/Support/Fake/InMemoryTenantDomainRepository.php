<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * In-memory test fake for TenantDomainRepositoryInterface.
 *
 * Storage: keyed by hostname (the migration-019 PK). `add()` rejects duplicate
 * hostnames with `InvalidArgumentException`; `update()` rejects unknown ids
 * with `OutOfBoundsException`. `setPrimary()` is atomic in spirit — it demotes
 * any other primary in the same tenant before promoting the target.
 */
final class InMemoryTenantDomainRepository implements TenantDomainRepositoryInterface
{
    /** @var array<string, TenantDomain> keyed by hostname */
    private array $byId = [];

    public function findByTenant(TenantId $tenantId): array
    {
        $rows = [];
        foreach ($this->byId as $domain) {
            $owner = $domain->tenantId();
            if ($owner !== null && $owner->equals($tenantId)) {
                $rows[] = $domain;
            }
        }
        return $rows;
    }

    public function find(string $domainId): ?TenantDomain
    {
        return $this->byId[$domainId] ?? null;
    }

    public function add(TenantDomain $domain): void
    {
        $id = $domain->id();
        if (isset($this->byId[$id])) {
            throw new InvalidArgumentException("TenantDomain '{$id}' already exists");
        }
        $this->byId[$id] = $domain;
    }

    public function update(TenantDomain $domain): void
    {
        $id = $domain->id();
        if (!isset($this->byId[$id])) {
            throw new OutOfBoundsException("TenantDomain '{$id}' does not exist");
        }
        $this->byId[$id] = $domain;
    }

    public function remove(string $domainId): void
    {
        unset($this->byId[$domainId]);
    }

    public function setPrimary(TenantId $tenantId, string $domainId): void
    {
        // Step 1: demote any other primary in the same tenant.
        foreach ($this->byId as $id => $domain) {
            $owner = $domain->tenantId();
            if ($owner !== null && $owner->equals($tenantId) && $id !== $domainId && $domain->isPrimary()) {
                $this->byId[$id] = $domain->withPrimary(false);
            }
        }
        // Step 2: promote target.
        $target = $this->byId[$domainId] ?? null;
        if ($target === null) {
            throw new OutOfBoundsException("TenantDomain '{$domainId}' does not exist");
        }
        $this->byId[$domainId] = $target->withPrimary(true);
    }
}
