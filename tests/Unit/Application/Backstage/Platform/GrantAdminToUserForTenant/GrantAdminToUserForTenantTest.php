<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\GrantAdminToUserForTenant;

use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenant;
use Daems\Application\Backstage\Platform\GrantAdminToUserForTenant\GrantAdminToUserForTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class GrantAdminToUserForTenantTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TARGET_ID = '01958000-0000-7000-8000-0000000000cc';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;
    private GrantAdminToUserForTenant $uc;

    protected function setUp(): void
    {
        $this->userTenants = new InMemoryUserTenantRepository();
        $this->users       = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->seedUser(self::TARGET_ID, false);
        $this->uc = new GrantAdminToUserForTenant($this->userTenants, $this->users);
    }

    public function test_grants_admin_role(): void
    {
        $this->uc->execute(new GrantAdminToUserForTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            targetUserId: UserId::fromString(self::TARGET_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
        $role = $this->userTenants->findRole(
            UserId::fromString(self::TARGET_ID),
            TenantId::fromString(self::TENANT_ID),
        );
        self::assertSame(UserTenantRole::Admin, $role);
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new GrantAdminToUserForTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            targetUserId: UserId::fromString(self::TARGET_ID),
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
