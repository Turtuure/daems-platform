<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlAuthTokenRepository implements AuthTokenRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function store(AuthToken $token): void
    {
        $this->db->execute(
            'INSERT INTO auth_tokens
               (id, token_hash, user_id, issued_at, last_used_at, expires_at, revoked_at, user_agent, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $token->id()->value(),
                $token->tokenHash(),
                $token->userId()->value(),
                $token->issuedAt()->format('Y-m-d H:i:s'),
                $token->lastUsedAt()->format('Y-m-d H:i:s'),
                $token->expiresAt()->format('Y-m-d H:i:s'),
                $token->revokedAt()?->format('Y-m-d H:i:s'),
                $token->userAgent(),
                $token->ip(),
            ],
        );
    }

    public function findByHash(string $hash): ?AuthToken
    {
        $row = $this->db->queryOne('SELECT * FROM auth_tokens WHERE token_hash = ?', [$hash]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET last_used_at = ?, expires_at = ? WHERE id = ?',
            [
                $now->format('Y-m-d H:i:s'),
                $newExpiry->format('Y-m-d H:i:s'),
                $id->value(),
            ],
        );
    }

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$at->format('Y-m-d H:i:s'), $id->value()],
        );
    }

    public function revokeByHash(string $hash, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
            [$at->format('Y-m-d H:i:s'), $hash],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AuthToken
    {
        return new AuthToken(
            AuthTokenId::fromString((string) $row['id']),
            (string) $row['token_hash'],
            UserId::fromString((string) $row['user_id']),
            new DateTimeImmutable((string) $row['issued_at']),
            new DateTimeImmutable((string) $row['last_used_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            isset($row['revoked_at']) && $row['revoked_at'] !== null
                ? new DateTimeImmutable((string) $row['revoked_at'])
                : null,
            isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            isset($row['ip']) ? (string) $row['ip'] : null,
        );
    }
}
