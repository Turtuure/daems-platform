<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantModulesRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;
use PDO;

/**
 * Integration tests for SqlTenantModulesRepository.
 *
 * Bootstraps the schema up through migration 072 (tenant_modules + module_audit
 * + tenant fields + seeded rows) so the existing daems/sahegroup tenants
 * + their default-available modules are present when each test starts.
 */
final class SqlTenantModulesRepositoryTest extends MigrationTestCase
{
    private SqlTenantModulesRepository $repo;
    private TenantId $tenantId;
    private UserId $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(72);

        $this->repo = new SqlTenantModulesRepository($this->pdo());

        // Seed an isolated tenant for this test class so we don't tangle with
        // the daems/sahegroup rows already created by 019/072.
        $this->tenantId = TenantId::generate();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId->value(), 'tm-test', 'TenantModulesTest']);

        $this->actorId = UserId::generate();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->actorId->value(), 'Actor', 'actor-' . $this->actorId->value() . '@test.local', 'hash', '1985-01-01']);
    }

    public function test_save_and_find_round_trip(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $tm = new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'events',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repo->save($tm);

        $found = $this->repo->find($this->tenantId, 'events');
        self::assertNotNull($found);
        self::assertSame('events', $found->moduleSlug());
        self::assertTrue($found->isAvailable());
        self::assertFalse($found->isEnabled());
        self::assertNotNull($found->availableBy());
        self::assertSame($this->actorId->value(), $found->availableBy()?->value());
    }

    public function test_save_is_idempotent_on_unique_key(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $first = new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'forum',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repo->save($first);

        // Second save with a fresh id but same (tenant_id, module_slug) → upsert
        $later = new DateTimeImmutable('2026-05-07 11:00:00');
        $second = new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'forum',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: $later,
            enabledBy: $this->actorId,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $later,
        );
        $this->repo->save($second);

        $rows = $this->repo->findByTenant($this->tenantId);
        $forumRows = array_values(array_filter(
            $rows,
            static fn ($r) => $r->moduleSlug() === 'forum'
        ));
        self::assertCount(1, $forumRows);
        self::assertTrue($forumRows[0]->isEnabled());
    }

    public function test_findByTenant_orders_by_module_slug_ASC(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        foreach (['projects', 'forum', 'events'] as $slug) {
            $this->repo->save(new TenantModule(
                id: Uuid7::generate()->value(),
                tenantId: $this->tenantId,
                moduleSlug: $slug,
                availableAt: $now,
                availableBy: $this->actorId,
                enabledAt: null,
                enabledBy: null,
                disabledAt: null,
                createdAt: $now,
                updatedAt: $now,
            ));
        }
        $rows = $this->repo->findByTenant($this->tenantId);
        self::assertSame(['events', 'forum', 'projects'], array_map(static fn ($r) => $r->moduleSlug(), $rows));
    }

    public function test_revokeAvailability_clears_state_and_writes_audit_row(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $tm = new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'insights',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: $now,
            enabledBy: $this->actorId,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repo->save($tm);

        $revokeAt = new DateTimeImmutable('2026-05-08 12:00:00');
        $audit = new ModuleAuditEntry(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'insights',
            action: ModuleAuditAction::REVOKED_AVAILABILITY,
            actorUserId: $this->actorId,
            actorRole: 'platform_admin',
            reason: 'compliance review',
            createdAt: $revokeAt,
        );
        $this->repo->revokeAvailability($tm, $revokeAt, [$audit]);

        $after = $this->repo->find($this->tenantId, 'insights');
        self::assertNotNull($after);
        self::assertNull($after->availableAt());
        self::assertNull($after->availableBy());
        self::assertNull($after->enabledAt());
        self::assertNull($after->enabledBy());
        self::assertNotNull($after->disabledAt());

        // module_audit row written
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM module_audit WHERE tenant_id = ? AND module_slug = ? AND action = ?'
        );
        $stmt->execute([$this->tenantId->value(), 'insights', 'revoked_availability']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_findEnabledByTenant_filters_correctly(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        // available, not enabled
        $this->repo->save(new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'avail-only',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        ));
        // enabled
        $this->repo->save(new TenantModule(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'enabled',
            availableAt: $now,
            availableBy: $this->actorId,
            enabledAt: $now,
            enabledBy: $this->actorId,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        ));

        $rows = $this->repo->findEnabledByTenant($this->tenantId);
        self::assertCount(1, $rows);
        self::assertSame('enabled', $rows[0]->moduleSlug());
    }
}
