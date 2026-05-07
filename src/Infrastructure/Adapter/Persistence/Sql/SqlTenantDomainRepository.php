<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantDomainRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use DateTimeImmutable;
use DomainException;
use PDO;

/**
 * MySQL implementation of TenantDomainRepositoryInterface against the
 * `tenant_domains` table (migration 019).
 *
 * The hostname (`domain` column) is the table primary key, so the
 * `string $domainId` parameter the interface uses to identify a row is
 * the hostname itself.
 *
 * `setPrimary()` runs in a transaction: any other primary in the same
 * tenant is demoted before the target is promoted. The repository does
 * NOT enforce the "at least one primary" invariant on `remove()` — that
 * is a use-case-layer concern (Wave E `RemoveTenantDomain`).
 */
final class SqlTenantDomainRepository implements TenantDomainRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByTenant(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain, tenant_id, is_primary, created_at
             FROM tenant_domains
             WHERE tenant_id = ?
             ORDER BY is_primary DESC, domain ASC'
        );
        $stmt->execute([$tenantId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    public function find(string $domainId): ?TenantDomain
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain, tenant_id, is_primary, created_at
             FROM tenant_domains
             WHERE domain = ?
             LIMIT 1'
        );
        $stmt->execute([$domainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function add(TenantDomain $domain): void
    {
        $tenantId = $domain->tenantId();
        if ($tenantId === null) {
            throw new \InvalidArgumentException('TenantDomain.tenantId required for add()');
        }
        $createdAt = $domain->createdAt() ?? new DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_domains (domain, tenant_id, is_primary, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $domain->id(),
            $tenantId->value(),
            $domain->isPrimary() ? 1 : 0,
            $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function update(TenantDomain $domain): void
    {
        $tenantId = $domain->tenantId();
        if ($tenantId === null) {
            throw new \InvalidArgumentException('TenantDomain.tenantId required for update()');
        }
        // domain (the PK / hostname) is NOT writable here — a hostname change
        // is a remove + add (different PK). update() only flips the primary
        // bit and re-asserts ownership.
        $stmt = $this->pdo->prepare(
            'UPDATE tenant_domains
             SET is_primary = ?, tenant_id = ?
             WHERE domain = ?'
        );
        $stmt->execute([
            $domain->isPrimary() ? 1 : 0,
            $tenantId->value(),
            $domain->id(),
        ]);
    }

    public function remove(string $domainId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tenant_domains WHERE domain = ?');
        $stmt->execute([$domainId]);
    }

    public function setPrimary(TenantId $tenantId, string $domainId): void
    {
        $alreadyInTx = $this->pdo->inTransaction();
        if (!$alreadyInTx) {
            $this->pdo->beginTransaction();
        }
        try {
            // Demote any current primary in this tenant other than the target.
            $demote = $this->pdo->prepare(
                'UPDATE tenant_domains
                 SET is_primary = 0
                 WHERE tenant_id = ? AND domain <> ?'
            );
            $demote->execute([$tenantId->value(), $domainId]);

            // Promote the target.
            $promote = $this->pdo->prepare(
                'UPDATE tenant_domains
                 SET is_primary = 1
                 WHERE tenant_id = ? AND domain = ?'
            );
            $promote->execute([$tenantId->value(), $domainId]);

            if (!$alreadyInTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$alreadyInTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function hydrate(array $row): TenantDomain
    {
        $hostname  = is_string($row['domain']    ?? null) ? $row['domain']    : throw new DomainException('Corrupt tenant_domains.domain');
        $tenantId  = is_string($row['tenant_id'] ?? null) ? $row['tenant_id'] : throw new DomainException('Corrupt tenant_domains.tenant_id');
        $createdAt = is_string($row['created_at'] ?? null) ? $row['created_at'] : throw new DomainException('Corrupt tenant_domains.created_at');
        $isPrimary = (bool) ($row['is_primary'] ?? false);

        return TenantDomain::create(
            hostname: $hostname,
            tenantId: TenantId::fromString($tenantId),
            isPrimary: $isPrimary,
            createdAt: new DateTimeImmutable($createdAt),
        );
    }
}
