<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlModuleAuditRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

/**
 * Integration tests for SqlModuleAuditRepository against the module_audit
 * table (migration 070).
 */
final class SqlModuleAuditRepositoryTest extends MigrationTestCase
{
    private SqlModuleAuditRepository $repo;
    private TenantId $tenantId;
    private UserId $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(72);

        $this->repo = new SqlModuleAuditRepository($this->pdo());

        $this->tenantId = TenantId::generate();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId->value(), 'audit-test', 'AuditTest']);

        $this->actorId = UserId::generate();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->actorId->value(), 'Actor', 'actor-' . $this->actorId->value() . '@audit.local', 'hash', '1985-01-01']);
    }

    public function test_append_writes_a_row_findable_via_listForTenant(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->append(new ModuleAuditEntry(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'events',
            action: ModuleAuditAction::MADE_AVAILABLE,
            actorUserId: $this->actorId,
            actorRole: 'platform_admin',
            reason: 'initial onboard',
            createdAt: $now,
        ));

        $rows = $this->repo->listForTenant($this->tenantId);
        self::assertCount(1, $rows);
        self::assertSame('events', $rows[0]->moduleSlug());
        self::assertSame(ModuleAuditAction::MADE_AVAILABLE, $rows[0]->action());
        self::assertSame('initial onboard', $rows[0]->reason());
    }

    public function test_listForTenant_orders_by_createdAt_DESC_and_respects_limit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->repo->append(new ModuleAuditEntry(
                id: Uuid7::generate()->value(),
                tenantId: $this->tenantId,
                moduleSlug: 'events',
                action: ModuleAuditAction::ENABLED,
                actorUserId: $this->actorId,
                actorRole: 'tenant_admin',
                reason: null,
                createdAt: new DateTimeImmutable(sprintf('2026-05-%02d 09:00:00', $i + 1)),
            ));
        }

        $rows = $this->repo->listForTenant($this->tenantId, 2);
        self::assertCount(2, $rows);
        self::assertSame('2026-05-04', $rows[0]->createdAt()->format('Y-m-d'));
        self::assertSame('2026-05-03', $rows[1]->createdAt()->format('Y-m-d'));
    }

    public function test_listForModule_filters_by_slug(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->append(new ModuleAuditEntry(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'events',
            action: ModuleAuditAction::MADE_AVAILABLE,
            actorUserId: $this->actorId,
            actorRole: 'platform_admin',
            reason: null,
            createdAt: $now,
        ));
        $this->repo->append(new ModuleAuditEntry(
            id: Uuid7::generate()->value(),
            tenantId: $this->tenantId,
            moduleSlug: 'forum',
            actorUserId: $this->actorId,
            action: ModuleAuditAction::ENABLED,
            actorRole: 'tenant_admin',
            reason: null,
            createdAt: $now,
        ));

        $rows = $this->repo->listForModule($this->tenantId, 'forum');
        self::assertCount(1, $rows);
        self::assertSame('forum', $rows[0]->moduleSlug());
    }
}
