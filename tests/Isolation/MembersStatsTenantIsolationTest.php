<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;

final class MembersStatsTenantIsolationTest extends IsolationTestCase
{
    private SqlUserTenantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlUserTenantRepository($this->pdo());
    }

    private function seedMember(string $tenantSlug, string $email): UserId
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO users
                (id, name, email, password_hash, date_of_birth,
                 country, address_street, address_zip, address_city, address_country,
                 membership_type, membership_status)
             VALUES (?, ?, ?, 'x', '1990-01-01',
                     '', '', '', '', '',
                     'individual', 'active')"
        )->execute([$id, 'M-' . $email, $email]);

        $userId = UserId::fromString($id);
        $this->repo->attach($userId, $this->tenantId($tenantSlug), UserTenantRole::Member);
        return $userId;
    }

    public function test_member_count_for_daems_excludes_sahegroup_members(): void
    {
        // 2 members in daems, 2 in sahegroup. Admin in daems queries.
        $this->seedMember('daems',     'd1@example.test');
        $this->seedMember('daems',     'd2@example.test');
        $this->seedMember('sahegroup', 's1@example.test');
        $this->seedMember('sahegroup', 's2@example.test');

        $daems = $this->repo->membershipStatsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->membershipStatsForTenant($this->tenantId('sahegroup'));

        self::assertSame(2, $daems['total_members']['value']);
        self::assertSame(2, $sahe['total_members']['value']);
    }
}
