<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;

final class UserTenantIsolationTest extends IsolationTestCase
{
    private SqlUserTenantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlUserTenantRepository($this->pdo());
    }

    public function test_role_scoped_to_tenant_membership(): void
    {
        $aliceId = '01958000-0000-7000-8000-000000000aaa';
        $this->seedUser($aliceId, 'alice@test');

        // Alice is admin in daems, not a member in sahegroup
        $this->attachUserToTenant(UserId::fromString($aliceId), 'daems', UserTenantRole::Admin);

        self::assertSame(UserTenantRole::Admin, $this->repo->findRole(UserId::fromString($aliceId), $this->tenantId('daems')));
        self::assertNull($this->repo->findRole(UserId::fromString($aliceId), $this->tenantId('sahegroup')));
    }

    public function test_roles_for_user_only_returns_active_memberships(): void
    {
        $bobId = '01958000-0000-7000-8000-000000000bbb';
        $this->seedUser($bobId, 'bob@test');

        $this->attachUserToTenant(UserId::fromString($bobId), 'daems', UserTenantRole::Member);
        $this->attachUserToTenant(UserId::fromString($bobId), 'sahegroup', UserTenantRole::Registered);

        $roles = $this->repo->rolesForUser(UserId::fromString($bobId));

        self::assertCount(2, $roles);
        self::assertContains(UserTenantRole::Member, $roles);
        self::assertContains(UserTenantRole::Registered, $roles);
    }

    public function test_detach_isolates_by_tenant(): void
    {
        $carolId = '01958000-0000-7000-8000-000000000ccc';
        $this->seedUser($carolId, 'carol@test');

        $this->attachUserToTenant(UserId::fromString($carolId), 'daems', UserTenantRole::Admin);
        $this->attachUserToTenant(UserId::fromString($carolId), 'sahegroup', UserTenantRole::Member);

        // Leaving daems should not affect sahegroup membership
        $this->repo->detach(UserId::fromString($carolId), $this->tenantId('daems'));

        self::assertNull($this->repo->findRole(UserId::fromString($carolId), $this->tenantId('daems')));
        self::assertSame(UserTenantRole::Member, $this->repo->findRole(UserId::fromString($carolId), $this->tenantId('sahegroup')));
    }
}
