<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Auth\RedeemInvite\RedeemInvite;
use Daems\Application\Auth\RedeemInvite\RedeemInviteInput;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserInviteRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class InviteRedemptionIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $userId;
    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(40);

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
        )->execute([$this->tenantId, 'invite-test', 'Invite Test Tenant']);

        // Seed a user with no password (awaiting invite redemption)
        $this->userId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, membership_type, membership_status)
             VALUES (?, ?, ?, NULL, ?, ?, ?)'
        )->execute([$this->userId, 'New Member', 'invite-member@test.com', '1995-01-01', 'individual', 'active']);

        // Seed a user_invites row with a known token
        $this->rawToken = 'test-raw-token-abc123';
        $tokenObj  = InviteToken::fromRaw($this->rawToken);
        $inviteId  = Uuid7::generate()->value();
        $issuedAt  = new DateTimeImmutable();
        $expiresAt = $issuedAt->add(new \DateInterval('P7D'));

        $this->pdo()->prepare(
            'INSERT INTO user_invites (id, user_id, tenant_id, token_hash, issued_at, expires_at, used_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL)'
        )->execute([
            $inviteId,
            $this->userId,
            $this->tenantId,
            $tokenObj->hash,
            $issuedAt->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function buildRedeemInvite(): RedeemInvite
    {
        return new RedeemInvite(
            new SqlUserInviteRepository($this->conn->pdo()),
            new SqlUserRepository($this->conn),
            new SystemClock(),
        );
    }

    public function test_valid_token_sets_password_and_marks_invite_used(): void
    {
        $redeemer = $this->buildRedeemInvite();
        $out = $redeemer->execute(new RedeemInviteInput($this->rawToken, 'validpass123'));

        // User in output
        self::assertSame($this->userId, $out->user->id()->value());

        // password_hash is now a bcrypt verifiable against 'validpass123'
        $stmt = $this->pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);
        $hash = $stmt->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify('validpass123', $hash));

        // user_invites.used_at is now set
        $stmt2 = $this->pdo()->prepare(
            'SELECT used_at FROM user_invites WHERE user_id = ?'
        );
        $stmt2->execute([$this->userId]);
        $usedAt = $stmt2->fetchColumn();
        self::assertNotNull($usedAt);
        self::assertNotFalse($usedAt);
    }

    public function test_second_redemption_throws_invite_used(): void
    {
        $redeemer = $this->buildRedeemInvite();

        // First redemption
        $redeemer->execute(new RedeemInviteInput($this->rawToken, 'validpass123'));

        // Second redemption with same token
        $this->expectException(ValidationException::class);

        try {
            $redeemer->execute(new RedeemInviteInput($this->rawToken, 'anotherpass456'));
            self::fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            self::assertSame('invite_used', $e->fields()['token'] ?? null);
            throw $e;
        }
    }
}
