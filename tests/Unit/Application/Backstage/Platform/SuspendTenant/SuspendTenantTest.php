<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\SuspendTenant;

use Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant;
use Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SuspendTenantTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryUserRepository $users;
    private FrozenClock $clock;
    private SuspendTenant $uc;

    protected function setUp(): void
    {
        $this->tenants = new InMemoryTenantRepository();
        $this->users   = new InMemoryUserRepository();
        $this->clock   = FrozenClock::at('2026-05-07T10:00:00+00:00');
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->uc = new SuspendTenant($this->tenants, $this->users, $this->clock);
    }

    public function test_suspends_tenant(): void
    {
        $this->uc->execute(new SuspendTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            reason: 'unpaid_invoice',
        ));
        $tenant = $this->tenants->findById(TenantId::fromString(self::TENANT_ID));
        self::assertNotNull($tenant);
        self::assertTrue($tenant->suspended());
        self::assertSame('unpaid_invoice', $tenant->suspendedReason());
        self::assertSame('2026-05-07', $tenant->suspendedAt()?->format('Y-m-d'));
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new SuspendTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            reason: 'reason',
        ));
    }

    public function test_rejects_empty_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SuspendTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            reason: '   ',
        );
    }

    private function seedUser(string $id, bool $isPlatformAdmin): void
    {
        $user = new User(
            id: UserId::fromString($id),
            name: 'Test',
            email: 'u-' . substr($id, -4) . '@test.local',
            passwordHash: 'hash',
            dateOfBirth: null,
            country: 'FI',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $this->users->save($user);
    }
}
