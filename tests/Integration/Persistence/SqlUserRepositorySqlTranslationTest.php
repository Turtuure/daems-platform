<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves SqlUserRepository's PDOException translation is scoped precisely:
 * - SQLSTATE 23000 with "Duplicate entry … users_email_unique" → ValidationException
 * - Any other SQLSTATE (including other 23xxx errors like FK / CHECK / NOT NULL,
 *   or non-duplicate UNIQUE violations on PK) passes through unchanged
 *
 * We stub Connection to throw PDOException instances shaped like the real
 * driver would. This avoids needing a working SQL engine in CI and tests
 * the translation logic in isolation.
 */
final class SqlUserRepositorySqlTranslationTest extends TestCase
{
    public function testSaveTranslatesDuplicateEmailOnUsersEmailUniqueToValidationException(): void
    {
        $repo = $this->repoThatThrows(self::duplicateEmailPdoException());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid email.');
        $repo->save($this->makeUser('alice@x.com'));
    }

    public function testSavePassesThroughDuplicateEntryOnDifferentIndex(): void
    {
        // SQLSTATE 23000 but NOT users_email_unique — must NOT be mislabelled.
        $repo = $this->repoThatThrows(new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'xyz' for key 'PRIMARY'",
            23000,
        ));

        try {
            $repo->save($this->makeUser('alice@x.com'));
            $this->fail('expected PDOException to propagate');
        } catch (PDOException $e) {
            $this->assertStringContainsString('PRIMARY', $e->getMessage());
        }
    }

    public function testSavePassesThroughForeignKeyViolation(): void
    {
        $repo = $this->repoThatThrows(new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails",
            23000,
        ));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');
        $repo->save($this->makeUser('alice@x.com'));
    }

    public function testSavePassesThroughNotNullViolation(): void
    {
        $repo = $this->repoThatThrows(new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'email' cannot be null",
            23000,
        ));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/cannot be null/i');
        $repo->save($this->makeUser('alice@x.com'));
    }

    public function testSavePassesThroughNonIntegrityViolation(): void
    {
        $repo = $this->repoThatThrows(new PDOException(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away",
            0,
        ));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/gone away/i');
        $repo->save($this->makeUser('alice@x.com'));
    }

    public function testUpdateProfileTranslatesDuplicateEmailToValidationException(): void
    {
        $repo = $this->repoThatThrows(self::duplicateEmailPdoException());

        $this->expectException(ValidationException::class);
        $repo->updateProfile('id', ['email' => 'alice@x.com']);
    }

    public function testUpdateProfilePassesThroughOtherIntegrityViolations(): void
    {
        $repo = $this->repoThatThrows(new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row",
            23000,
        ));

        $this->expectException(PDOException::class);
        $repo->updateProfile('id', ['email' => 'alice@x.com']);
    }

    private static function duplicateEmailPdoException(): PDOException
    {
        return new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice@x.com' for key 'users_email_unique'",
            23000,
        );
    }

    private function makeUser(string $email): User
    {
        return new User(
            UserId::generate(),
            'Test',
            $email,
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    /**
     * Build a SqlUserRepository whose Connection will throw $e on any execute().
     */
    private function repoThatThrows(PDOException $e): SqlUserRepository
    {
        $connection = new class ($e) extends Connection {
            public function __construct(private readonly PDOException $toThrow) {
                // intentionally skip parent constructor (no real DB)
            }
            public function execute(string $sql, array $params = []): int
            {
                throw $this->toThrow;
            }
            public function query(string $sql, array $params = []): array { return []; }
            public function queryOne(string $sql, array $params = []): ?array { return null; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function pdo(): \PDO { throw new \LogicException('not supported in test'); }
        };

        return new SqlUserRepository($connection);
    }
}
