<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Tests\Support\Fake;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryModuleAuditRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryModuleAuditRepositoryTest extends TestCase
{
    public function test_append_persists_in_insertion_order(): void
    {
        $repo = new InMemoryModuleAuditRepository();
        $tenantId = TenantId::generate();
        $actor    = UserId::generate();

        $repo->append($this->makeEntry($tenantId, $actor, 'events', ModuleAuditAction::MADE_AVAILABLE, '2026-05-01 10:00:00'));
        $repo->append($this->makeEntry($tenantId, $actor, 'forum',  ModuleAuditAction::ENABLED,        '2026-05-02 10:00:00'));

        self::assertCount(2, $repo->entries);
        self::assertSame('events', $repo->entries[0]->moduleSlug());
        self::assertSame('forum',  $repo->entries[1]->moduleSlug());
    }

    public function test_listForTenant_filters_by_tenant_and_orders_by_createdAt_DESC(): void
    {
        $repo = new InMemoryModuleAuditRepository();
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();
        $actor   = UserId::generate();

        $repo->append($this->makeEntry($tenantA, $actor, 'events', ModuleAuditAction::MADE_AVAILABLE, '2026-05-01 10:00:00'));
        $repo->append($this->makeEntry($tenantA, $actor, 'forum',  ModuleAuditAction::ENABLED,        '2026-05-03 10:00:00'));
        $repo->append($this->makeEntry($tenantA, $actor, 'projects', ModuleAuditAction::DISABLED,     '2026-05-02 10:00:00'));
        $repo->append($this->makeEntry($tenantB, $actor, 'events', ModuleAuditAction::MADE_AVAILABLE, '2026-05-04 10:00:00'));

        $rows = $repo->listForTenant($tenantA);
        self::assertCount(3, $rows);
        self::assertSame('forum',    $rows[0]->moduleSlug());
        self::assertSame('projects', $rows[1]->moduleSlug());
        self::assertSame('events',   $rows[2]->moduleSlug());
    }

    public function test_listForTenant_respects_limit(): void
    {
        $repo = new InMemoryModuleAuditRepository();
        $tenantId = TenantId::generate();
        $actor    = UserId::generate();

        for ($i = 0; $i < 5; $i++) {
            $repo->append($this->makeEntry(
                $tenantId,
                $actor,
                'events',
                ModuleAuditAction::MADE_AVAILABLE,
                sprintf('2026-05-%02d 10:00:00', $i + 1),
            ));
        }

        $rows = $repo->listForTenant($tenantId, 2);
        self::assertCount(2, $rows);
    }

    public function test_listForModule_additionally_filters_by_slug(): void
    {
        $repo = new InMemoryModuleAuditRepository();
        $tenantId = TenantId::generate();
        $actor    = UserId::generate();

        $repo->append($this->makeEntry($tenantId, $actor, 'events', ModuleAuditAction::MADE_AVAILABLE, '2026-05-01 10:00:00'));
        $repo->append($this->makeEntry($tenantId, $actor, 'forum',  ModuleAuditAction::ENABLED,        '2026-05-02 10:00:00'));
        $repo->append($this->makeEntry($tenantId, $actor, 'events', ModuleAuditAction::ENABLED,        '2026-05-03 10:00:00'));

        $rows = $repo->listForModule($tenantId, 'events');
        self::assertCount(2, $rows);
        self::assertSame(ModuleAuditAction::ENABLED, $rows[0]->action());
        self::assertSame(ModuleAuditAction::MADE_AVAILABLE, $rows[1]->action());
    }

    private function makeEntry(
        TenantId $tenantId,
        UserId $actor,
        string $slug,
        ModuleAuditAction $action,
        string $createdAt,
    ): ModuleAuditEntry {
        return new ModuleAuditEntry(
            id: Uuid7::generate()->value(),
            tenantId: $tenantId,
            moduleSlug: $slug,
            action: $action,
            actorUserId: $actor,
            actorRole: 'platform_admin',
            reason: null,
            createdAt: new DateTimeImmutable($createdAt),
        );
    }
}
