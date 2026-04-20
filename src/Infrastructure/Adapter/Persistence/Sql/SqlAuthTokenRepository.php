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

    public function revokeAllForUser(string $userId): void
    {
        $this->db->execute(
            'DELETE FROM auth_tokens WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }

    /**
     * @param array<string, mixed> $row
     *
     * We require every schema column to be present in the row. `array_key_exists`
     * catches schema drift (column renamed or dropped) at the earliest possible
     * point rather than silently filling a null through `isset` fallbacks.
     */
    private function hydrate(array $row): AuthToken
    {
        foreach (['id', 'token_hash', 'user_id', 'issued_at', 'last_used_at', 'expires_at', 'revoked_at', 'user_agent', 'ip'] as $col) {
            if (!array_key_exists($col, $row)) {
                throw new \RuntimeException("auth_tokens row missing column: {$col}");
            }
        }

        return AuthToken::fromPersistence(
            AuthTokenId::fromString(self::str($row, 'id')),
            self::str($row, 'token_hash'),
            UserId::fromString(self::str($row, 'user_id')),
            new DateTimeImmutable(self::str($row, 'issued_at')),
            new DateTimeImmutable(self::str($row, 'last_used_at')),
            new DateTimeImmutable(self::str($row, 'expires_at')),
            $row['revoked_at'] !== null ? new DateTimeImmutable(self::str($row, 'revoked_at')) : null,
            $row['user_agent'] !== null ? self::str($row, 'user_agent') : null,
            $row['ip'] !== null ? self::str($row, 'ip') : null,
        );
    }
}
