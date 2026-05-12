<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlGsaOverrideRepository implements GsaOverrideRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gsa_overrides WHERE tenant_id = ? ORDER BY performed_at DESC LIMIT {$limit}"
        );
        $stmt->execute([$tenantId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(GsaOverride $o): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gsa_overrides (id, gsa_user_id, tenant_id, action, target_id, reason, performed_at)
             VALUES (:id, :gsa, :tid, :act, :tgt, :rsn, :pa)'
        );
        $stmt->execute([
            ':id'  => $o->id->value(),
            ':gsa' => $o->gsaUserId->value(),
            ':tid' => $o->tenantId->value(),
            ':act' => $o->action->value,
            ':tgt' => $o->targetId,
            ':rsn' => $o->reason,
            ':pa'  => $o->performedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): GsaOverride
    {
        $g = static function (string $k) use ($r): string {
            $v = $r[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt gsa_overrides.{$k}");
        };
        return new GsaOverride(
            id:          GsaOverrideId::fromString($g('id')),
            gsaUserId:   UserId::fromString($g('gsa_user_id')),
            tenantId:    TenantId::fromString($g('tenant_id')),
            action:      GsaOverrideAction::from($g('action')),
            targetId:    $g('target_id'),
            reason:      $g('reason'),
            performedAt: new \DateTimeImmutable($g('performed_at')),
        );
    }
}
