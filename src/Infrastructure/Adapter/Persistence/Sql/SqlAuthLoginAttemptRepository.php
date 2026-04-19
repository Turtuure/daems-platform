<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use DateTimeImmutable;
use Throwable;

final class SqlAuthLoginAttemptRepository implements AuthLoginAttemptRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'INSERT INTO auth_login_attempts (ip, email, attempted_at, success) VALUES (?, ?, ?, ?)',
            [$ip, $email, $at->format('Y-m-d H:i:s'), $success ? 1 : 0],
        );

        // Opportunistic cleanup runs 1-in-100 inserts. It is isolated from the
        // caller so a cleanup failure (lock contention, etc.) cannot mask the
        // successful INSERT above — the caller would otherwise see a
        // recording-failed exception after the row was already persisted.
        if (random_int(0, 99) === 0) {
            try {
                $this->db->execute(
                    'DELETE FROM auth_login_attempts WHERE attempted_at < ?',
                    [$at->modify('-24 hours')->format('Y-m-d H:i:s')],
                );
            } catch (Throwable $e) {
                $this->logger->error('auth_login_attempts opportunistic cleanup failed', ['exception' => $e]);
            }
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

    public function countFailuresByIpSince(string $ip, DateTimeImmutable $since): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM auth_login_attempts
             WHERE ip = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $since->format('Y-m-d H:i:s')],
        );
        return (int) ($row['n'] ?? 0);
    }
}
