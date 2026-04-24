<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlPublicMemberRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class PublicMemberProfileIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(58);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, member_number_prefix, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'daems-pmp', 'Daems PMP', 'DAEMS']);

        $this->userId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, member_number, public_avatar_visible, membership_type, membership_started_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?, ?)'
        )->execute([
            $this->userId, 'Sam Hammersmith',
            'sam-pmp@test.com', password_hash('x', PASSWORD_BCRYPT), '1989-04-23',
            '000123', 'founding', '2024-06-11 12:00:00',
        ]);

        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->userId, $this->tenantId, 'member']);
    }

    public function test_finds_existing_member_with_tenant_prefix(): void
    {
        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $out = $uc->execute(new GetPublicMemberProfileInput('000123'));

        self::assertSame('Sam Hammersmith', $out->name);
        self::assertSame('DAEMS', $out->tenantMemberNumberPrefix);
        self::assertSame('founding', $out->memberType);
        self::assertSame('member', $out->role);
        self::assertSame('2024-06-11', $out->joinedAt);
        self::assertTrue($out->publicAvatarVisible);
        self::assertSame('SH', $out->avatarInitials);
    }

    public function test_throws_not_found_for_unknown_number(): void
    {
        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetPublicMemberProfileInput('999999'));
    }

    public function test_returns_null_avatar_url_when_visibility_off(): void
    {
        $this->pdo()->prepare('UPDATE users SET public_avatar_visible = 0 WHERE id = ?')
            ->execute([$this->userId]);

        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $out = $uc->execute(new GetPublicMemberProfileInput('000123'));

        self::assertFalse($out->publicAvatarVisible);
        self::assertNull($out->avatarUrl);
    }
}
