<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use DomainException;
use PDO;

final class SqlTenantRepository implements TenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(TenantId $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, name, created_at, member_number_prefix FROM tenants WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, name, created_at, member_number_prefix FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.slug, t.name, t.created_at, t.member_number_prefix
             FROM tenants t
             JOIN tenant_domains td ON td.tenant_id = t.id
             WHERE td.domain = ? LIMIT 1'
        );
        $stmt->execute([strtolower(trim($domain))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Tenant> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, slug, name, created_at, member_number_prefix FROM tenants ORDER BY slug');
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Tenant
    {
        $id      = is_string($row['id']         ?? null) ? $row['id']         : throw new DomainException('Corrupt tenants.id');
        $slug    = is_string($row['slug']        ?? null) ? $row['slug']        : throw new DomainException('Corrupt tenants.slug');
        $name    = is_string($row['name']        ?? null) ? $row['name']        : throw new DomainException('Corrupt tenants.name');
        $created = is_string($row['created_at']  ?? null) ? $row['created_at']  : throw new DomainException('Corrupt tenants.created_at');

        $prefix  = isset($row['member_number_prefix']) && is_string($row['member_number_prefix'])
            ? $row['member_number_prefix']
            : null;

        return new Tenant(
            TenantId::fromString($id),
            TenantSlug::fromString($slug),
            $name,
            new DateTimeImmutable($created),
            $prefix,
        );
    }

    public function updatePrefix(TenantId $tenantId, ?string $prefix): void
    {
        $stmt = $this->pdo->prepare('UPDATE tenants SET member_number_prefix = ? WHERE id = ?');
        $stmt->execute([$prefix, $tenantId->value()]);
    }
}
