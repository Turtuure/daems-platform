<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Membership\MemberApplicationId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberDirectoryRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class BackstageTenantIsolationTest extends IsolationTestCase
{
    private SqlMemberApplicationRepository $appRepo;
    private SqlMemberDirectoryRepository $dirRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        $this->appRepo = new SqlMemberApplicationRepository($conn);
        $this->dirRepo = new SqlMemberDirectoryRepository($conn);
    }

    private function seedApp(string $tenantSlug, string $name): string
    {
        $id = MemberApplicationId::generate()->value();
        $stmt = $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, '1990-01-01', 'motive', 'pending')"
        );
        $stmt->execute([$id, $tenantSlug, $name, $name . '@x.com']);
        return $id;
    }

    private function seedMember(string $userId, string $tenantSlug): void
    {
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, membership_type, membership_status)
             VALUES ('{$userId}', '{$userId}', '{$userId}@x.com', 'x', '1990-01-01', 'individual', 'active')"
        );
        $this->pdo()->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('{$userId}', (SELECT id FROM tenants WHERE slug = '{$tenantSlug}'), 'member', NOW())"
        );
    }

    public function test_listPendingForTenant_isolates_by_tenant(): void
    {
        $daemsAppId = $this->seedApp('daems', 'Daems App');
        $saheAppId  = $this->seedApp('sahegroup', 'Sahe App');

        $daemsResults = $this->appRepo->listPendingForTenant($this->tenantId('daems'), 100);
        self::assertCount(1, $daemsResults);
        self::assertSame($daemsAppId, $daemsResults[0]->id()->value());
    }

    public function test_findByIdForTenant_rejects_cross_tenant_lookup(): void
    {
        $saheAppId = $this->seedApp('sahegroup', 'Sahe App');

        self::assertNull($this->appRepo->findByIdForTenant($saheAppId, $this->tenantId('daems')));
        self::assertNotNull($this->appRepo->findByIdForTenant($saheAppId, $this->tenantId('sahegroup')));
    }

    public function test_listMembersForTenant_isolates_by_tenant(): void
    {
        $this->seedMember('01958000-0000-7000-8000-daemsmbr0001', 'daems');
        $this->seedMember('01958000-0000-7000-8000-sahembrr0001', 'sahegroup');

        $daemsResult = $this->dirRepo->listMembersForTenant($this->tenantId('daems'), [], 'member_number', 'ASC', 1, 50);
        self::assertSame(1, $daemsResult['total']);
        self::assertSame('01958000-0000-7000-8000-daemsmbr0001', $daemsResult['entries'][0]->userId);
    }
}
