<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\UserTenantRole;
use PHPUnit\Framework\TestCase;

final class UserTenantRoleTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('admin',      UserTenantRole::Admin->value);
        $this->assertSame('registered', UserTenantRole::Registered->value);
    }

    public function testNoGlobalSystemAdministratorCase(): void
    {
        $this->assertNull(UserTenantRole::tryFrom('global_system_administrator'));
    }

    public function testFromStringOrRegisteredFallsBack(): void
    {
        $this->assertSame(UserTenantRole::Registered, UserTenantRole::fromStringOrRegistered('unknown-role'));
        $this->assertSame(UserTenantRole::Admin,      UserTenantRole::fromStringOrRegistered('admin'));
    }

    public function testLabels(): void
    {
        $this->assertSame('Administrator', UserTenantRole::Admin->label());
        $this->assertSame('Member',        UserTenantRole::Registered->label());
    }
}
