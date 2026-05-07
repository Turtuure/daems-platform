<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\ListTenantAdmins;

use Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdmins;
use Daems\Application\Backstage\Platform\ListTenantAdmins\ListTenantAdminsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class ListTenantAdminsTest extends TestCase
{
    private const GSA_ID     = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID  = '01958000-0000-7000-8000-0000000000bb';
    private const ADMIN_A_ID = '01958000-0000-7000-8000-0000000000cc';
    private const ADMIN_B_ID = '01958000-0000-7000-8000-0000000000dd';
    private const TENANT_ID  = '01958000-0000-7000-8000-000000000001';

    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;
    private ListTenantAdmins $uc;

    protected function setUp(): void
    {
        $this->userTenants = new InMemoryUserTenantRepository();
        $this->users       = new InMemoryUserRepository();
        $this->userTenants->setUsers($this->users);
        $this->seedUser(self::GSA_ID,    'Gsa Admin',   'gsa@x.com',     true);
        $this->seedUser(self::NORMAL_ID, 'Normal User', 'normal@x.com',  false);
        $this->seedUser(self::ADMIN_A_ID,'Alice',       'alice@x.com',   false);
        $this->seedUser(self::ADMIN_B_ID,'Bob',         'bob@x.com',     false);
        $this->uc = new ListTenantAdmins($this->userTenants, $this->users);
    }

    public function test_returns_empty_when_no_admins_for_tenant(): void
    {
        $out = $this->uc->execute(new ListTenantAdminsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId:     TenantId::fromString(self::TENANT_ID),
        ));
        self::assertSame([], $out->admins);
        self::assertSame(0, $out->total);
    }

    public function test_returns_admins_with_metadata_sorted_by_name(): void
    {
        // Attach Bob first to verify sort order is by name (not insertion).
        $this->userTenants->attach(
            UserId::fromString(self::ADMIN_B_ID),
            TenantId::fromString(self::TENANT_ID),
            UserTenantRole::Admin,
        );
        $this->userTenants->attach(
            UserId::fromString(self::ADMIN_A_ID),
            TenantId::fromString(self::TENANT_ID),
            UserTenantRole::Admin,
        );

        $out = $this->uc->execute(new ListTenantAdminsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId:     TenantId::fromString(self::TENANT_ID),
        ));

        self::assertCount(2, $out->admins);
        self::assertSame(2, $out->total);
        self::assertSame('Alice', $out->admins[0]['name']);
        self::assertSame('alice@x.com', $out->admins[0]['email']);
        self::assertSame(self::ADMIN_A_ID, $out->admins[0]['userId']);
        // grantedAt must be ISO8601 string.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $out->admins[0]['grantedAt'],
        );
        self::assertSame('Bob', $out->admins[1]['name']);
    }

    public function test_excludes_non_admin_roles(): void
    {
        $this->userTenants->attach(
            UserId::fromString(self::ADMIN_A_ID),
            TenantId::fromString(self::TENANT_ID),
            UserTenantRole::Member,
        );
        $this->userTenants->attach(
            UserId::fromString(self::ADMIN_B_ID),
            TenantId::fromString(self::TENANT_ID),
            UserTenantRole::Admin,
        );

        $out = $this->uc->execute(new ListTenantAdminsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId:     TenantId::fromString(self::TENANT_ID),
        ));
        self::assertSame(1, $out->total);
        self::assertSame('Bob', $out->admins[0]['name']);
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new ListTenantAdminsInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId:     TenantId::fromString(self::TENANT_ID),
        ));
    }

    public function test_rejects_unknown_actor(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new ListTenantAdminsInput(
            actingUserId: UserId::fromString('01958000-0000-7000-8000-0000000000ff'),
            tenantId:     TenantId::fromString(self::TENANT_ID),
        ));
    }

    private function seedUser(string $id, string $name, string $email, bool $isPlatformAdmin): void
    {
        $user = new User(
            id: UserId::fromString($id),
            name: $name,
            email: $email,
            passwordHash: 'hash',
            dateOfBirth: null,
            country: 'FI',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $this->users->save($user);
    }
}
