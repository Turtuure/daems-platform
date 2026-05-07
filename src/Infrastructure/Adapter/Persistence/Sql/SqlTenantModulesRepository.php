<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use DomainException;
use PDO;

/**
 * MySQL implementation of TenantModulesRepositoryInterface against the
 * `tenant_modules` table (migration 069).
 *
 * `save()` performs a true upsert keyed on the (tenant_id, module_slug)
 * UNIQUE — a re-save with a fresh `id` value will NOT create a duplicate row.
 *
 * `revokeAvailability()` and the audit-row INSERTs that follow it run inside
 * a single transaction so the cascade is atomic from the DB's perspective.
 */
final class SqlTenantModulesRepository implements TenantModulesRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByTenant(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, module_slug, available_at, available_by,
                    enabled_at, enabled_by, disabled_at, created_at, updated_at
             FROM tenant_modules
             WHERE tenant_id = ?
             ORDER BY module_slug ASC'
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

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, module_slug, available_at, available_by,
                    enabled_at, enabled_by, disabled_at, created_at, updated_at
             FROM tenant_modules
             WHERE tenant_id = ? AND module_slug = ?
             LIMIT 1'
        );
        $stmt->execute([$tenantId->value(), $moduleSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(TenantModule $tm): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_modules
                (id, tenant_id, module_slug, available_at, available_by,
                 enabled_at, enabled_by, disabled_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                available_at = VALUES(available_at),
                available_by = VALUES(available_by),
                enabled_at   = VALUES(enabled_at),
                enabled_by   = VALUES(enabled_by),
                disabled_at  = VALUES(disabled_at),
                updated_at   = VALUES(updated_at)'
        );
        $stmt->execute([
            $tm->id(),
            $tm->tenantId()->value(),
            $tm->moduleSlug(),
            self::fmtDt($tm->availableAt()),
            self::fmtUserId($tm->availableBy()),
            self::fmtDt($tm->enabledAt()),
            self::fmtUserId($tm->enabledBy()),
            self::fmtDt($tm->disabledAt()),
            $tm->createdAt()->format('Y-m-d H:i:s'),
            $tm->updatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAvailability(
        TenantModule $tm,
        DateTimeImmutable $now,
        array $auditEntries,
    ): void {
        $alreadyInTx = $this->pdo->inTransaction();
        if (!$alreadyInTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $upd = $this->pdo->prepare(
                'UPDATE tenant_modules
                 SET available_at = NULL,
                     available_by = NULL,
                     enabled_at   = NULL,
                     enabled_by   = NULL,
                     disabled_at  = ?,
                     updated_at   = ?
                 WHERE id = ?'
            );
            $upd->execute([
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $tm->id(),
            ]);

            $ins = $this->pdo->prepare(
                'INSERT INTO module_audit
                    (id, tenant_id, module_slug, action, actor_user_id, actor_role, reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($auditEntries as $entry) {
                $ins->execute([
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

    public function findEnabledByTenant(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, module_slug, available_at, available_by,
                    enabled_at, enabled_by, disabled_at, created_at, updated_at
             FROM tenant_modules
             WHERE tenant_id = ? AND enabled_at IS NOT NULL
             ORDER BY module_slug ASC'
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

    /**
     * @param array<mixed, mixed> $row
     */
    private function hydrate(array $row): TenantModule
    {
        $id        = is_string($row['id']          ?? null) ? $row['id']         : throw new DomainException('Corrupt tenant_modules.id');
        $tenantId  = is_string($row['tenant_id']   ?? null) ? $row['tenant_id']  : throw new DomainException('Corrupt tenant_modules.tenant_id');
        $slug      = is_string($row['module_slug'] ?? null) ? $row['module_slug']: throw new DomainException('Corrupt tenant_modules.module_slug');
        $createdAt = is_string($row['created_at']  ?? null) ? $row['created_at'] : throw new DomainException('Corrupt tenant_modules.created_at');
        $updatedAt = is_string($row['updated_at']  ?? null) ? $row['updated_at'] : throw new DomainException('Corrupt tenant_modules.updated_at');

        return new TenantModule(
            id:          $id,
            tenantId:    TenantId::fromString($tenantId),
            moduleSlug:  $slug,
            availableAt: self::parseDt($row['available_at'] ?? null),
            availableBy: self::parseUserId($row['available_by'] ?? null),
            enabledAt:   self::parseDt($row['enabled_at'] ?? null),
            enabledBy:   self::parseUserId($row['enabled_by'] ?? null),
            disabledAt:  self::parseDt($row['disabled_at'] ?? null),
            createdAt:   new DateTimeImmutable($createdAt),
            updatedAt:   new DateTimeImmutable($updatedAt),
        );
    }

    private static function parseDt(mixed $v): ?DateTimeImmutable
    {
        if (is_string($v) && $v !== '') {
            return new DateTimeImmutable($v);
        }
        return null;
    }

    private static function parseUserId(mixed $v): ?UserId
    {
        return is_string($v) && $v !== '' ? UserId::fromString($v) : null;
    }

    private static function fmtDt(?DateTimeImmutable $v): ?string
    {
        return $v?->format('Y-m-d H:i:s');
    }

    private static function fmtUserId(?UserId $v): ?string
    {
        return $v?->value();
    }
}
