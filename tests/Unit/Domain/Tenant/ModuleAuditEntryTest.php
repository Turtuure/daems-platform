<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\ModuleAuditAction;
use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ModuleAuditEntryTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-0000000000aa';
    private const ROW_ID    = '01958000-0000-7000-8000-0000000000cc';

    public function testConstructsWithPlatformAdminRole(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $entry = new ModuleAuditEntry(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            action: ModuleAuditAction::MADE_AVAILABLE,
            actorUserId: UserId::fromString(self::USER_ID),
            actorRole: 'platform_admin',
            reason: 'On request',
            createdAt: $now,
        );

        $this->assertSame('forum', $entry->moduleSlug());
        $this->assertSame('platform_admin', $entry->actorRole());
        $this->assertSame(ModuleAuditAction::MADE_AVAILABLE, $entry->action());
        $this->assertSame('On request', $entry->reason());
        $this->assertSame($now, $entry->createdAt());
        $this->assertSame(self::ROW_ID, $entry->id());
    }

    public function testConstructsWithTenantAdminRole(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $entry = new ModuleAuditEntry(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'events',
            action: ModuleAuditAction::ENABLED,
            actorUserId: UserId::fromString(self::USER_ID),
            actorRole: 'tenant_admin',
            reason: null,
            createdAt: $now,
        );

        $this->assertSame('tenant_admin', $entry->actorRole());
        $this->assertNull($entry->reason());
    }

    public function testRejectsBogusActorRole(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("actorRole must be 'platform_admin' or 'tenant_admin', got 'moderator'");

        new ModuleAuditEntry(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            action: ModuleAuditAction::ENABLED,
            actorUserId: UserId::fromString(self::USER_ID),
            actorRole: 'moderator',
            reason: null,
            createdAt: $now,
        );
    }

    public function testRejectsEmptyModuleSlug(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('moduleSlug must be non-empty');

        new ModuleAuditEntry(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: '',
            action: ModuleAuditAction::ENABLED,
            actorUserId: UserId::fromString(self::USER_ID),
            actorRole: 'tenant_admin',
            reason: null,
            createdAt: $now,
        );
    }
}
