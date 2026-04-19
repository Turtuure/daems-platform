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
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, role)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $user->id()->value(),
                    $user->name(),
                    $user->email(),
                    $user->passwordHash(),
                    $user->dateOfBirth(),
                    $user->role(),
                ],
            );
        } catch (PDOException $e) {
            if (self::isDuplicateEmail($e)) {
                throw new ValidationException('Invalid email.');
            }
            throw $e;
        }
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

    private function hydrate(array $row): User
    {
        return new User(
            UserId::fromString($row['id']),
            $row['name'],
            $row['email'],
            $row['password_hash'],
            $row['date_of_birth'],
            $row['role'] ?? 'registered',
            $row['country'] ?? '',
            $row['address_street'] ?? '',
            $row['address_zip'] ?? '',
            $row['address_city'] ?? '',
            $row['address_country'] ?? '',
            $row['membership_type'] ?? 'individual',
            $row['membership_status'] ?? 'active',
            $row['member_number'] ?? null,
            $row['created_at'] ?? '',
        );
    }
}
