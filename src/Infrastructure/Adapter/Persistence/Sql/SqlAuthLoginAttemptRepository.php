<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlAuthLoginAttemptRepository implements AuthLoginAttemptRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'INSERT INTO auth_login_attempts (ip, email, attempted_at, success) VALUES (?, ?, ?, ?)',
            [$ip, $email, $at->format('Y-m-d H:i:s'), $success ? 1 : 0],
        );

        if (random_int(0, 99) === 0) {
            $this->db->execute(
                'DELETE FROM auth_login_attempts WHERE attempted_at < ?',
                [$at->modify('-24 hours')->format('Y-m-d H:i:s')],
            );
        }
    }

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM auth_login_attempts
             WHERE ip = ? AND email = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $email, $since->format('Y-m-d H:i:s')],
        );
        return (int) ($row['n'] ?? 0);
    }
}
