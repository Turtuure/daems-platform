<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\ReactivateTenant;

use Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant;
use Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReactivateTenantTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryUserRepository $users;
    private ReactivateTenant $uc;

    protected function setUp(): void
    {
        $this->tenants = new InMemoryTenantRepository();
        $this->users   = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->uc = new ReactivateTenant($this->tenants, $this->users);
    }

    public function test_reactivates_a_suspended_tenant(): void
    {
        $this->tenants->suspend(
            TenantId::fromString(self::TENANT_ID),
            'unpaid',
            new DateTimeImmutable('2026-05-01T10:00:00+00:00'),
        );
        // Sanity: precondition holds.
        self::assertTrue($this->tenants->findById(TenantId::fromString(self::TENANT_ID))?->suspended());

        $this->uc->execute(new ReactivateTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));

        $tenant = $this->tenants->findById(TenantId::fromString(self::TENANT_ID));
        self::assertNotNull($tenant);
        self::assertFalse($tenant->suspended());
        self::assertNull($tenant->suspendedReason());
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new ReactivateTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
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
