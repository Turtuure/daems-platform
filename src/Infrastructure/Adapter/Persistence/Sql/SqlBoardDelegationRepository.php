<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use PDO;

final class SqlBoardDelegationRepository implements BoardDelegationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActive(TenantId $tenantId, BoardDecisionType $decisionType, UserTenantRole $delegatedToRole, \DateTimeImmutable $at): ?BoardDelegation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_delegations
              WHERE tenant_id = ? AND decision_type = ? AND delegated_to_role = ?
                AND valid_from <= ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $decisionType->value, $delegatedToRole->value, $ts, $ts]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listActive(TenantId $tenantId, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_delegations
              WHERE tenant_id = ?
                AND valid_from <= ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY valid_from DESC'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $ts, $ts]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function find(BoardDelegationId $id): ?BoardDelegation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM board_delegations WHERE id = ?');
        $stmt->execute([$id->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function save(BoardDelegation $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_delegations
                (id, tenant_id, decision_type, delegated_to_role, source_decision_id, valid_from, revoked_at)
             VALUES (:id, :tid, :dt, :role, :src, :vf, :rev)
             ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at)'
        );
        $stmt->execute([
            ':id'   => $d->id->value(),
            ':tid'  => $d->tenantId->value(),
            ':dt'   => $d->decisionType->value,
            ':role' => $d->delegatedToRole->value,
            ':src'  => $d->sourceDecisionId->value(),
            ':vf'   => $d->validFrom->format('Y-m-d H:i:s'),
            ':rev'  => $d->revokedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): BoardDelegation
    {
        $g = static function (string $k) use ($r): string {
            $v = $r[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt board_delegations.{$k}");
        };
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;
        return new BoardDelegation(
            id:               BoardDelegationId::fromString($g('id')),
            tenantId:         TenantId::fromString($g('tenant_id')),
            decisionType:     BoardDecisionType::from($g('decision_type')),
            delegatedToRole:  UserTenantRole::from($g('delegated_to_role')),
            sourceDecisionId: BoardDecisionId::fromString($g('source_decision_id')),
            validFrom:        new \DateTimeImmutable($g('valid_from')),
            revokedAt:        $opt('revoked_at') !== null ? new \DateTimeImmutable((string) $opt('revoked_at')) : null,
        );
    }
}
