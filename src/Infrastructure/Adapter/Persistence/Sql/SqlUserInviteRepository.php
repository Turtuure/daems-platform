<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use PDO;

final class SqlUserInviteRepository implements UserInviteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(UserInvite $invite): void
    {
        $sql = 'INSERT INTO user_invites
                  (id, user_id, tenant_id, token_hash, issued_at, expires_at, used_at)
                VALUES (?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute([
            $invite->id,
            $invite->userId,
            $invite->tenantId,
            $invite->tokenHash,
            $invite->issuedAt->format('Y-m-d H:i:s'),
            $invite->expiresAt->format('Y-m-d H:i:s'),
            $invite->usedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?UserInvite
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, tenant_id, token_hash, issued_at, expires_at, used_at
             FROM user_invites WHERE token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $usedAt = $row['used_at'];
        return new UserInvite(
            self::str($row, 'id'),
            self::str($row, 'user_id'),
            self::str($row, 'tenant_id'),
            self::str($row, 'token_hash'),
            new \DateTimeImmutable(self::str($row, 'issued_at')),
            new \DateTimeImmutable(self::str($row, 'expires_at')),
            $usedAt !== null ? new \DateTimeImmutable(self::str($row, 'used_at')) : null,
        );
    }

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void
    {
        $this->pdo->prepare('UPDATE user_invites SET used_at = ? WHERE id = ?')
            ->execute([$usedAt->format('Y-m-d H:i:s'), $inviteId]);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }
}
