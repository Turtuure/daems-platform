<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use DomainException;
use PDO;

/**
 * MySQL implementation of ModuleAuditRepositoryInterface against the
 * append-only `module_audit` table (migration 070).
 *
 * The list endpoints bind their LIMIT parameter as PARAM_INT — string-bound
 * integers in `LIMIT` cause MySQL to reject the query syntactically.
 */
final class SqlModuleAuditRepository implements ModuleAuditRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function append(ModuleAuditEntry $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO module_audit
                (id, tenant_id, module_slug, action, actor_user_id, actor_role, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entry->id(),
            $entry->tenantId()->value(),
            $entry->moduleSlug(),
            $entry->action()->value,
            $entry->actorUserId()->value(),
            $entry->actorRole(),
            $entry->reason(),
            $entry->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, module_slug, action, actor_user_id, actor_role, reason, created_at
             FROM module_audit
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId->value());
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    public function listForModule(TenantId $tenantId, string $moduleSlug, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, module_slug, action, actor_user_id, actor_role, reason, created_at
             FROM module_audit
             WHERE tenant_id = :tenant_id AND module_slug = :slug
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId->value());
        $stmt->bindValue('slug',      $moduleSlug);
        $stmt->bindValue('limit',     $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function hydrate(array $row): ModuleAuditEntry
    {
        $id        = is_string($row['id']            ?? null) ? $row['id']            : throw new DomainException('Corrupt module_audit.id');
        $tenantId  = is_string($row['tenant_id']     ?? null) ? $row['tenant_id']     : throw new DomainException('Corrupt module_audit.tenant_id');
        $slug      = is_string($row['module_slug']   ?? null) ? $row['module_slug']   : throw new DomainException('Corrupt module_audit.module_slug');
        $action    = is_string($row['action']        ?? null) ? $row['action']        : throw new DomainException('Corrupt module_audit.action');
        $actorId   = is_string($row['actor_user_id'] ?? null) ? $row['actor_user_id'] : throw new DomainException('Corrupt module_audit.actor_user_id');
        $actorRole = is_string($row['actor_role']    ?? null) ? $row['actor_role']    : throw new DomainException('Corrupt module_audit.actor_role');
        $createdAt = is_string($row['created_at']    ?? null) ? $row['created_at']    : throw new DomainException('Corrupt module_audit.created_at');
        $reason    = isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : null;

        return new ModuleAuditEntry(
            id: $id,
            tenantId: TenantId::fromString($tenantId),
            moduleSlug: $slug,
            action: ModuleAuditAction::from($action),
            actorUserId: UserId::fromString($actorId),
            actorRole: $actorRole,
            reason: $reason,
            createdAt: new DateTimeImmutable($createdAt),
        );
    }
}
