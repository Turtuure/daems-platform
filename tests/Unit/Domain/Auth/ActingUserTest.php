<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ActingUserTest extends TestCase
{
    private function make(
        bool $isPlatformAdmin = false,
        ?UserTenantRole $role = null,
    ): ActingUser {
        return new ActingUser(
            id:                 UserId::fromString('01958000-0000-7000-8000-000000000011'),
            email:              'sam@test.fi',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: $role,
        );
    }

    public function testPlatformAdminFlag(): void
    {
        $this->assertTrue($this->make(isPlatformAdmin: true)->isPlatformAdmin());
        $this->assertFalse($this->make()->isPlatformAdmin());
    }

    public function testRoleInReturnsRoleForActiveTenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->assertSame(UserTenantRole::Admin, $u->roleIn($active));
    }

    public function testRoleInReturnsNullForOtherTenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $other = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        $this->assertNull($u->roleIn($other));
    }

    public function testIsAdminInTrueForPlatformAdminEverywhere(): void
    {
        $u = $this->make(isPlatformAdmin: true);
        $anyTenant = TenantId::fromString('01958000-0000-7000-8000-999999999999');
        $this->assertTrue($u->isAdminIn($anyTenant));
    }

    public function testIsAdminInTrueForAdminInActiveTenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->assertTrue($u->isAdminIn($active));
    }

    public function testIsAdminInFalseForMember(): void
    {
        $u = $this->make(role: UserTenantRole::Member);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->assertFalse($u->isAdminIn($active));
    }

    public function testOwnsReturnsTrueForSameId(): void
    {
        $id = UserId::fromString('01958000-0000-7000-8000-000000000011');
        $u = $this->make();
        $this->assertTrue($u->owns($id));
    }

    public function testOwnsReturnsFalseForDifferentId(): void
    {
        $u = $this->make();
        $this->assertFalse($u->owns(UserId::generate()));
    }
}
