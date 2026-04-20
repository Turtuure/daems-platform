<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccountInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAuthTokenRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberStatusAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class AnonymiseAccountIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(42);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        // Seed tenant
        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'test-anon', 'AnonymiseTest']);

        // Seed admin user
        $this->adminId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->adminId, 'Admin', 'admin@test.local', 'hash', '1985-01-01']);
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->adminId, $this->tenantId, 'admin']);
    }

    private function makeUseCase(): AnonymiseAccount
    {
        return new AnonymiseAccount(
            new SqlUserRepository($this->conn),
            new SqlUserTenantRepository($this->conn->pdo()),
            new SqlAuthTokenRepository($this->conn),
            new SqlMemberStatusAuditRepository($this->conn),
            new PdoTransactionManager($this->conn->pdo()),
            new SystemClock(),
            new class implements \Daems\Domain\Shared\IdGeneratorInterface {
                public function generate(): string
                {
                    return Uuid7::generate()->value();
                }
            },
        );
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($this->adminId),
            email:              'admin@test.local',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function seedTargetUser(): string
    {
        $userId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, country,
                                address_street, address_zip, address_city, address_country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, 'Real Name', 'target@test.local', 'phash', '1990-05-15',
            'FI', 'Testikatu 1', '00100', 'Helsinki', 'FI',
        ]);
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$userId, $this->tenantId, 'member']);
        return $userId;
    }

    public function test_anonymise_wipes_pii_in_database(): void
    {
        $targetId = $this->seedTargetUser();
        $acting = $this->actingAdmin();

        $out = $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $acting));

        $this->assertTrue($out->success);

        $row = $this->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $row->execute([$targetId]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($data);
        $this->assertSame('Anonyymi', $data['name']);
        $this->assertSame('anon-' . $targetId . '@anon.local', $data['email']);
        $this->assertNull($data['date_of_birth']);
        $this->assertSame('', $data['country']);
        $this->assertSame('', $data['address_street']);
        $this->assertNull($data['password_hash']);
        $this->assertSame('terminated', $data['membership_status']);
        $this->assertNotNull($data['deleted_at']);
    }

    public function test_user_tenants_marked_left(): void
    {
        $targetId = $this->seedTargetUser();

        $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $this->actingAdmin()));

        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM user_tenants WHERE user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$targetId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_auth_tokens_deleted(): void
    {
        $targetId = $this->seedTargetUser();

        // Seed a token
        $tokenId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO auth_tokens (id, token_hash, user_id, issued_at, last_used_at, expires_at)
             VALUES (?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))'
        )->execute([$tokenId, hash('sha256', 'testtoken123'), $targetId]);

        $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $this->actingAdmin()));

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_audit_row_inserted(): void
    {
        $targetId = $this->seedTargetUser();

        $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $this->actingAdmin()));

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM member_status_audit WHERE user_id = ? AND reason = 'user_anonymised'"
        );
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('terminated', $row['new_status']);
        $this->assertSame($this->adminId, $row['performed_by_admin_id']);
    }

    public function test_not_found_throws_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $nonExistentId = Uuid7::generate()->value();
        $this->makeUseCase()->execute(new AnonymiseAccountInput($nonExistentId, $this->actingAdmin()));
    }

    public function test_already_anonymised_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $targetId = $this->seedTargetUser();

        // Pre-anonymise
        $this->pdo()->prepare(
            'UPDATE users SET deleted_at = NOW() WHERE id = ?'
        )->execute([$targetId]);

        $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $this->actingAdmin()));
    }

    public function test_non_admin_non_self_throws_forbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $targetId = $this->seedTargetUser();

        $randomUserId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?)'
        )->execute([$randomUserId, 'Random', 'random@test.local', 'hash', '1995-01-01']);

        $attacking = new ActingUser(
            id:                 UserId::fromString($randomUserId),
            email:              'random@test.local',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Member,
        );

        $this->makeUseCase()->execute(new AnonymiseAccountInput($targetId, $attacking));
    }
}
