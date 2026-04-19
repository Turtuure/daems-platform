<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;

final class InMemoryTenantRepository implements TenantRepositoryInterface
{
    /** @var array<string, Tenant> keyed by id */
    public array $byId = [];

    /** @var array<string, string> slug → id */
    private array $idBySlug = [];

    /** @var array<string, string> domain → id */
    private array $idByDomain = [];

    public function save(Tenant $tenant): void
    {
        $this->byId[$tenant->id->value()] = $tenant;
        $this->idBySlug[$tenant->slug->value()] = $tenant->id->value();
    }

    public function findById(TenantId $id): ?Tenant
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $id = $this->idBySlug[$slug] ?? null;
        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $id = $this->idByDomain[$domain] ?? null;
        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    /** @return list<Tenant> */
    public function findAll(): array
    {
        return array_values($this->byId);
    }
}
