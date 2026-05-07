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

    public function testFindAdminsForTenantReturnsEmptyWhenNoAdmins(): void
    {
        // No memberships at all — empty list.
        $this->assertSame([], $this->repo->findAdminsForTenant($this->daems));

        // Member-only membership — also empty.
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->assertSame([], $this->repo->findAdminsForTenant($this->daems));
    }

    public function testFindAdminsForTenantReturnsAdminWithUserMetadata(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);

        $rows = $this->repo->findAdminsForTenant($this->daems);
        $this->assertCount(1, $rows);
        $this->assertSame('01958000-0000-7000-8000-000000000011', $rows[0]['user_id']);
        $this->assertSame('Sam', $rows[0]['name']);
        $this->assertSame('sam@t.fi', $rows[0]['email']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $rows[0]['granted_at']);
    }

    public function testFindAdminsForTenantFiltersByTenant(): void
    {
        // Use the second tenant seeded by migration 019 (sahegroup) — no
        // need to insert it (and the tenants schema at mig 22 has no `status`
        // column — that arrives at mig 71).
        $other = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        // Seed a second user attached as admin to the OTHER tenant only.
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
             VALUES ('01958000-0000-7000-8000-000000000022', 'Other', 'other@t.fi', 'x', '1990-01-01', 'member')"
        );
        $otherUser = UserId::fromString('01958000-0000-7000-8000-000000000022');
        $this->repo->attach($otherUser, $other, UserTenantRole::Admin);
        // And one admin in our tenant.
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);

        $rows = $this->repo->findAdminsForTenant($this->daems);
        $this->assertCount(1, $rows);
        $this->assertSame('Sam', $rows[0]['name']);

        $otherRows = $this->repo->findAdminsForTenant($other);
        $this->assertCount(1, $otherRows);
        $this->assertSame('Other', $otherRows[0]['name']);
    }

    public function testFindAdminsForTenantOrdersByName(): void
    {
        // Two admins — Charlie (alphabetically last) and Alice.
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
             VALUES ('01958000-0000-7000-8000-000000000033', 'Charlie', 'c@t.fi', 'x', '1990-01-01', 'member'),
                    ('01958000-0000-7000-8000-000000000044', 'Alice',   'a@t.fi', 'x', '1990-01-01', 'member')"
        );
        $charlie = UserId::fromString('01958000-0000-7000-8000-000000000033');
        $alice   = UserId::fromString('01958000-0000-7000-8000-000000000044');
        $this->repo->attach($charlie, $this->daems, UserTenantRole::Admin);
        $this->repo->attach($alice,   $this->daems, UserTenantRole::Admin);

        $rows = $this->repo->findAdminsForTenant($this->daems);
        $this->assertCount(2, $rows);
        $this->assertSame('Alice',   $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testFindAdminsForTenantExcludesLeftMemberships(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);
        $this->repo->detach($this->user, $this->daems);

        $this->assertSame([], $this->repo->findAdminsForTenant($this->daems));
    }
}
