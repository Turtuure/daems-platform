<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardRepository implements BoardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findForTenant(TenantId $tenantId): ?Board
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, bootstrapped_by_user_id, bootstrapped_at, created_at
               FROM boards WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(Board $board): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO boards (id, tenant_id, bootstrapped_by_user_id, bootstrapped_at, created_at)
             VALUES (:id, :tid, :bby, :bat, :cat)
             ON DUPLICATE KEY UPDATE bootstrapped_by_user_id = VALUES(bootstrapped_by_user_id)'
        );
        $stmt->execute([
            ':id'  => $board->id->value(),
            ':tid' => $board->tenantId->value(),
            ':bby' => $board->bootstrappedByUserId->value(),
            ':bat' => $board->bootstrappedAt->format('Y-m-d H:i:s'),
            ':cat' => $board->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): Board
    {
        $id  = is_string($row['id']                      ?? null) ? $row['id']                      : throw new \DomainException('Corrupt boards.id');
        $tid = is_string($row['tenant_id']               ?? null) ? $row['tenant_id']               : throw new \DomainException('Corrupt boards.tenant_id');
        $bby = is_string($row['bootstrapped_by_user_id'] ?? null) ? $row['bootstrapped_by_user_id'] : throw new \DomainException('Corrupt boards.bootstrapped_by_user_id');
        $bat = is_string($row['bootstrapped_at']         ?? null) ? $row['bootstrapped_at']         : throw new \DomainException('Corrupt boards.bootstrapped_at');
        $cat = is_string($row['created_at']              ?? null) ? $row['created_at']              : throw new \DomainException('Corrupt boards.created_at');

        return new Board(
            id:                   BoardId::fromString($id),
            tenantId:             TenantId::fromString($tid),
            bootstrappedByUserId: UserId::fromString($bby),
            bootstrappedAt:       new \DateTimeImmutable($bat),
            createdAt:            new \DateTimeImmutable($cat),
        );
    }
}
