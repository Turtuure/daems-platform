<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Tests\Support\Fake;

use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryTenantModulesRepositoryTest extends TestCase
{
    public function test_save_then_find_round_trips_the_entity(): void
    {
        $repo = new InMemoryTenantModulesRepository();
        $tenantId = TenantId::generate();
        $tm = $this->makeAvailableModule($tenantId, 'events');

        $repo->save($tm);

        $found = $repo->find($tenantId, 'events');
        self::assertNotNull($found);
        self::assertSame($tm->id(), $found->id());
        self::assertSame('events', $found->moduleSlug());
        self::assertTrue($found->isAvailable());
    }

    public function test_findByTenant_filters_by_tenantId_and_orders_by_slug_ASC(): void
    {
        $repo = new InMemoryTenantModulesRepository();
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();

        $repo->save($this->makeAvailableModule($tenantA, 'projects'));
        $repo->save($this->makeAvailableModule($tenantA, 'events'));
        $repo->save($this->makeAvailableModule($tenantA, 'forum'));
        $repo->save($this->makeAvailableModule($tenantB, 'aaaa'));

        $rows = $repo->findByTenant($tenantA);
        self::assertCount(3, $rows);
        self::assertSame(['events', 'forum', 'projects'], array_map(static fn ($r) => $r->moduleSlug(), $rows));
    }

    public function test_revokeAvailability_clears_state_and_appends_audit_entry(): void
    {
        $repo = new InMemoryTenantModulesRepository();
        $tenantId = TenantId::generate();
        $actor    = UserId::generate();
        $tm       = $this->makeAvailableModule($tenantId, 'events', $actor);

        $repo->save($tm);

        $now = new DateTimeImmutable('2026-05-07 12:00:00');
        $audit = new ModuleAuditEntry(
            id: '01958000-0000-7000-8000-000000000010',
            tenantId: $tenantId,
            moduleSlug: 'events',
            action: ModuleAuditAction::REVOKED_AVAILABILITY,
            actorUserId: $actor,
            actorRole: 'platform_admin',
            reason: 'compliance review',
            createdAt: $now,
        );

        $repo->revokeAvailability($tm, $now, [$audit]);

        $after = $repo->find($tenantId, 'events');
        self::assertNotNull($after);
        self::assertNull($after->availableAt());
        self::assertNull($after->availableBy());
        self::assertNull($after->enabledAt());
        self::assertNull($after->enabledBy());
        self::assertEquals($now, $after->disabledAt());
        self::assertEquals($now, $after->updatedAt());

        self::assertCount(1, $repo->audits);
        self::assertSame(ModuleAuditAction::REVOKED_AVAILABILITY, $repo->audits[0]->action());
        self::assertSame('events', $repo->audits[0]->moduleSlug());
    }

    public function test_findEnabledByTenant_filters_to_enabled_only(): void
    {
        $repo = new InMemoryTenantModulesRepository();
        $tenantId = TenantId::generate();
        $actor    = UserId::generate();

        $repo->save($this->makeEnabledModule($tenantId, 'forum', $actor));
        $repo->save($this->makeAvailableModule($tenantId, 'events', $actor));
        $repo->save($this->makeEnabledModule($tenantId, 'aaa', $actor));

        $rows = $repo->findEnabledByTenant($tenantId);
        self::assertCount(2, $rows);
        self::assertSame(['aaa', 'forum'], array_map(static fn ($r) => $r->moduleSlug(), $rows));
    }

    private function makeAvailableModule(
        TenantId $tenantId,
        string $slug,
        ?UserId $actor = null,
    ): TenantModule {
        $now = new DateTimeImmutable();
        return new TenantModule(
            id: \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
            tenantId: $tenantId,
            moduleSlug: $slug,
            availableAt: $now,
            availableBy: $actor,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeEnabledModule(
        TenantId $tenantId,
        string $slug,
        UserId $actor,
    ): TenantModule {
        $now = new DateTimeImmutable();
        return new TenantModule(
            id: \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
            tenantId: $tenantId,
            moduleSlug: $slug,
            availableAt: $now,
            availableBy: $actor,
            enabledAt: $now,
            enabledBy: $actor,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
