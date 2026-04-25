<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;

final class UserTenantStatsTest extends MigrationTestCase
{
    private SqlUserTenantRepository $repo;
    private TenantId $daems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);
        $this->repo  = new SqlUserTenantRepository($this->pdo());
        $this->daems = $this->tenantId('daems');
    }

    public function test_membership_stats_for_tenant_returns_expected_shape(): void
    {
        $u1 = $this->seedUser('alice@example.test', 'individual', 'active');
        $u2 = $this->seedUser('bob@example.test',   'supporter',  'active');
        $u3 = $this->seedUser('carol@example.test', 'individual', 'inactive');

        $this->repo->attach($u1, $this->daems, UserTenantRole::Member);
        $this->repo->attach($u2, $this->daems, UserTenantRole::Supporter);
        $this->repo->attach($u3, $this->daems, UserTenantRole::Member);

        $stats = $this->repo->membershipStatsForTenant($this->daems);

        self::assertSame(3, $stats['total_members']['value']);
        self::assertSame(3, $stats['new_members']['value']);
        self::assertSame(1, $stats['supporters']['value']);
        self::assertSame(1, $stats['inactive']['value']);

        self::assertCount(30, $stats['total_members']['sparkline']);
        self::assertCount(30, $stats['new_members']['sparkline']);
        self::assertCount(30, $stats['supporters']['sparkline']);
        self::assertSame([], $stats['inactive']['sparkline']);

        self::assertSame(['date', 'value'], array_keys($stats['total_members']['sparkline'][0]));

        // Sparkline window: first entry = 29 days ago, last entry = today.
        $expectedFirst = date('Y-m-d', strtotime('-29 days'));
        $expectedLast  = date('Y-m-d');
        self::assertSame($expectedFirst, $stats['total_members']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['total_members']['sparkline'][29]['date']);
    }

    public function test_membership_stats_isolated_per_tenant(): void
    {
        $u1 = $this->seedUser('eve@example.test',   'individual', 'active');
        $this->repo->attach($u1, $this->daems, UserTenantRole::Member);

        $sahe  = $this->tenantId('sahegroup');
        $stats = $this->repo->membershipStatsForTenant($sahe);

        self::assertSame(0, $stats['total_members']['value']);
        self::assertSame(0, $stats['new_members']['value']);
        self::assertSame(0, $stats['supporters']['value']);
        self::assertSame(0, $stats['inactive']['value']);
    }

    public function test_today_join_lands_on_last_sparkline_entry(): void
    {
        $u1 = $this->seedUser('frank@example.test', 'individual', 'active');
        $this->repo->attach($u1, $this->daems, UserTenantRole::Member);

        $stats = $this->repo->membershipStatsForTenant($this->daems);

        $today = date('Y-m-d');
        self::assertSame($today, $stats['total_members']['sparkline'][29]['date']);
        self::assertSame(1,      $stats['total_members']['sparkline'][29]['value']);
        self::assertSame(1,      $stats['new_members']['sparkline'][29]['value']);
    }

    private function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return TenantId::fromString((string) $row['id']);
    }

    private function seedUser(string $email, string $membershipType, string $membershipStatus): UserId
    {
        $id = $this->generateUuidV7();
        $this->pdo()->prepare(
            "INSERT INTO users
                (id, name, email, password_hash, date_of_birth,
                 country, address_street, address_zip, address_city, address_country,
                 membership_type, membership_status, member_number)
             VALUES (?, ?, ?, 'x', '1990-01-01',
                     '', '', '', '', '',
                     ?, ?, NULL)"
        )->execute([$id, $email, $email, $membershipType, $membershipStatus]);
        return UserId::fromString($id);
    }

    private function generateUuidV7(): string
    {
        // Use the project's Uuid7 generator if available, else a simple v4-ish fallback.
        if (class_exists(\Daems\Domain\Shared\ValueObject\Uuid7::class)) {
            return \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value();
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
