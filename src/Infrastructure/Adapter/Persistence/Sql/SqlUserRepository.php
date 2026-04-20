<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;
use PDOException;

final class SqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->queryOne('SELECT * FROM users WHERE email = ?', [$email]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findById(string $id): ?User
    {
        $row = $this->db->queryOne('SELECT * FROM users WHERE id = ?', [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(User $user): void
    {
        try {
            $this->db->execute(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $user->id()->value(),
                    $user->name(),
                    $user->email(),
                    $user->passwordHash(),
                    $user->dateOfBirth(),
                ],
            );
        } catch (PDOException $e) {
            if (self::isDuplicateEmail($e)) {
                throw new ValidationException('Invalid email.');
            }
            throw $e;
        }
    }

    public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User
    {
        try {
            $this->db->execute(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, country,
                                   membership_type, membership_status, member_number, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $fields['name'],
                    $fields['email'],
                    $fields['date_of_birth'],
                    $fields['country'],
                    $fields['membership_type'],
                    $fields['membership_status'],
                    $fields['member_number'],
                    $now->format('Y-m-d H:i:s'),
                ],
            );
        } catch (PDOException $e) {
            if (self::isDuplicateEmail($e)) {
                throw new ValidationException('Invalid email.');
            }
            throw $e;
        }
        $user = $this->findById($userId);
        assert($user !== null);
        return $user;
    }

    public function updateProfile(string $id, array $fields): void
    {
        $allowed = ['name', 'email', 'date_of_birth', 'country',
                    'address_street', 'address_zip', 'address_city', 'address_country'];
        $set = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]    = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }
        if (empty($set)) {
            return;
        }
        $params[] = $id;
        try {
            $this->db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
        } catch (PDOException $e) {
            if (self::isDuplicateEmail($e)) {
                throw new ValidationException('Invalid email.');
            }
            throw $e;
        }
    }

    /**
     * True only when MySQL/MariaDB raised a UNIQUE-violation on users_email_unique.
     * Any other SQLSTATE 23000 (FK violation, CHECK violation, etc.) surfaces truthfully
     * rather than being mis-labelled as "Invalid email." — which would re-open the class
     * of bug SAST F-006 was trying to close, just at a lower layer.
     */
    private static function isDuplicateEmail(PDOException $e): bool
    {
        if ((string) $e->getCode() !== '23000') {
            return false;
        }
        $message = $e->getMessage();
        return str_contains($message, 'Duplicate entry')
            && str_contains($message, 'users_email_unique');
    }

    public function updatePassword(string $id, string $newHash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $id]);
    }

    public function deleteById(string $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function anonymise(string $userId, \DateTimeImmutable $now): void
    {
        $this->db->execute(
            "UPDATE users
             SET name             = 'Anonyymi',
                 email            = CONCAT('anon-', id, '@anon.local'),
                 date_of_birth    = NULL,
                 country          = '',
                 address_street   = '',
                 address_zip      = '',
                 address_city     = '',
                 address_country  = '',
                 password_hash    = NULL,
                 membership_status = 'terminated',
                 deleted_at       = ?
             WHERE id = ?",
            [$now->format('Y-m-d H:i:s'), $userId],
        );
    }

    private function hydrate(array $row): User
    {
        $deletedAt = null;
        if (isset($row['deleted_at']) && is_string($row['deleted_at']) && $row['deleted_at'] !== '') {
            $deletedAt = new \DateTimeImmutable($row['deleted_at']);
        }

        return new User(
            id:              UserId::fromString($row['id']),
            name:            $row['name'],
            email:           $row['email'],
            passwordHash:    $row['password_hash'],
            dateOfBirth:     $row['date_of_birth'],
            country:         $row['country'] ?? '',
            addressStreet:   $row['address_street'] ?? '',
            addressZip:      $row['address_zip'] ?? '',
            addressCity:     $row['address_city'] ?? '',
            addressCountry:  $row['address_country'] ?? '',
            membershipType:  $row['membership_type'] ?? 'individual',
            membershipStatus: $row['membership_status'] ?? 'active',
            memberNumber:    $row['member_number'] ?? null,
            createdAt:       $row['created_at'] ?? '',
            isPlatformAdmin: (bool) ($row['is_platform_admin'] ?? false),
            deletedAt:       $deletedAt,
        );
    }
}
