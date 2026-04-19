<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlUserTenantRepositoryTest extends MigrationTestCase
{
    private SqlUserTenantRepository $repo;
    private UserId $user;
    private TenantId $daems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(22);
        $this->repo = new SqlUserTenantRepository($this->pdo());

        // Seed a user — legacy `role` column may still exist from migration 006+008; include a valid value
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
             VALUES ('01958000-0000-7000-8000-000000000011', 'Sam', 'sam@t.fi', 'x', '1990-01-01', 'member')"
        );
        $this->user  = UserId::fromString('01958000-0000-7000-8000-000000000011');
        $this->daems = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    public function testAttachCreatesRowWithRole(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);
        $this->assertSame(UserTenantRole::Admin, $this->repo->findRole($this->user, $this->daems));
    }

    public function testFindRoleReturnsNullWhenNotMember(): void
    {
        $this->assertNull($this->repo->findRole($this->user, $this->daems));
    }

    public function testFindRoleReturnsNullAfterDetach(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->repo->detach($this->user, $this->daems);

        $this->assertNull($this->repo->findRole($this->user, $this->daems));
    }

    public function testDetachSetsLeftAt(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->repo->detach($this->user, $this->daems);

        $stmt = $this->pdo()->query(
            "SELECT left_at FROM user_tenants WHERE user_id = '01958000-0000-7000-8000-000000000011'"
        );
        $this->assertNotFalse($stmt);
        $leftAt = $stmt->fetchColumn();
        $this->assertNotNull($leftAt);
    }

    public function testRolesForUserReturnsOnlyActive(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->assertSame([UserTenantRole::Member], $this->repo->rolesForUser($this->user));

        $this->repo->detach($this->user, $this->daems);
        $this->assertSame([], $this->repo->rolesForUser($this->user));
    }

    public function testAttachIsIdempotentAndUpdatesRole(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);
        $this->assertSame(UserTenantRole::Admin, $this->repo->findRole($this->user, $this->daems));
    }
}
