<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantModuleTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-0000000000aa';
    private const ROW_ID    = '01958000-0000-7000-8000-0000000000bb';

    public function testConstructsWithOnlyAvailable(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $tm = new TenantModule(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            availableAt: $now,
            availableBy: UserId::fromString(self::USER_ID),
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->assertTrue($tm->isAvailable());
        $this->assertFalse($tm->isEnabled());
        $this->assertSame('forum', $tm->moduleSlug());
        $this->assertSame(self::ROW_ID, $tm->id());
    }

    public function testConstructsWithAvailableAndEnabled(): void
    {
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $tm = new TenantModule(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            availableAt: $av,
            availableBy: UserId::fromString(self::USER_ID),
            enabledAt: $en,
            enabledBy: UserId::fromString(self::USER_ID),
            disabledAt: null,
            createdAt: $av,
            updatedAt: $en,
        );

        $this->assertTrue($tm->isAvailable());
        $this->assertTrue($tm->isEnabled());
        $this->assertSame($en, $tm->enabledAt());
    }

    public function testRejectsEnabledWithoutAvailable(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("TenantModule 'forum': cannot be enabled without being available first");

        new TenantModule(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            availableAt: null,
            availableBy: null,
            enabledAt: $now,
            enabledBy: UserId::fromString(self::USER_ID),
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function testRejectsEmptyModuleSlug(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('moduleSlug must be non-empty');

        new TenantModule(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: '',
            availableAt: null,
            availableBy: null,
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function testIsEnabledFalseAfterRevokeWhenDisabledAtSetAndEnabledNull(): void
    {
        $created = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $disabled = new DateTimeImmutable('2026-05-07T12:00:00+00:00');

        $tm = new TenantModule(
            id: self::ROW_ID,
            tenantId: TenantId::fromString(self::TENANT_ID),
            moduleSlug: 'forum',
            availableAt: null,
            availableBy: null,
            enabledAt: null,
            enabledBy: null,
            disabledAt: $disabled,
            createdAt: $created,
            updatedAt: $disabled,
        );

        $this->assertFalse($tm->isAvailable());
        $this->assertFalse($tm->isEnabled());
        $this->assertSame($disabled, $tm->disabledAt());
    }
}
