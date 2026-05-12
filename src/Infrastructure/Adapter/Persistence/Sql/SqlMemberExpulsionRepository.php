<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlMemberExpulsionRepository implements MemberExpulsionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(MemberExpulsionId $id): ?MemberExpulsion
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_expulsions WHERE id = ?');
        $stmt->execute([$id->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array
    {
        $sql = 'SELECT * FROM member_expulsions WHERE tenant_id = ?';
        $params = [$tenantId->value()];
        if ($status !== null) { $sql .= ' AND status = ?'; $params[] = $status->value; }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(MemberExpulsion $e): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_expulsions
                (id, tenant_id, target_user_id, proposed_by_user_id, reason,
                 hearing_deadline_at, statement_text, statement_received_at,
                 decision_id, decided_at, expelled_at, appeal_filed_at, appeal_text,
                 status, created_at)
             VALUES (:id, :tid, :tu, :pby, :rsn,
                     :hd, :st, :sr,
                     :did, :da, :ea, :af, :at,
                     :status, :cat)
             ON DUPLICATE KEY UPDATE
                statement_text       = VALUES(statement_text),
                statement_received_at= VALUES(statement_received_at),
                decision_id          = VALUES(decision_id),
                decided_at           = VALUES(decided_at),
                expelled_at          = VALUES(expelled_at),
                appeal_filed_at      = VALUES(appeal_filed_at),
                appeal_text          = VALUES(appeal_text),
                status               = VALUES(status)'
        );
        $stmt->execute([
            ':id'     => $e->id->value(),
            ':tid'    => $e->tenantId->value(),
            ':tu'     => $e->targetUserId->value(),
            ':pby'    => $e->proposedByUserId->value(),
            ':rsn'    => $e->reason,
            ':hd'     => $e->hearingDeadlineAt->format('Y-m-d H:i:s'),
            ':st'     => $e->statementText,
            ':sr'     => $e->statementReceivedAt?->format('Y-m-d H:i:s'),
            ':did'    => $e->decisionId?->value(),
            ':da'     => $e->decidedAt?->format('Y-m-d H:i:s'),
            ':ea'     => $e->expelledAt?->format('Y-m-d H:i:s'),
            ':af'     => $e->appealFiledAt?->format('Y-m-d H:i:s'),
            ':at'     => $e->appealText,
            ':status' => $e->status->value,
            ':cat'    => $e->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): MemberExpulsion
    {
        $g = static function (string $k) use ($r): string {
            $v = $r[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt member_expulsions.{$k}");
        };
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;

        return new MemberExpulsion(
            id:                  MemberExpulsionId::fromString($g('id')),
            tenantId:            TenantId::fromString($g('tenant_id')),
            targetUserId:        UserId::fromString($g('target_user_id')),
            proposedByUserId:    UserId::fromString($g('proposed_by_user_id')),
            reason:              $g('reason'),
            hearingDeadlineAt:   new \DateTimeImmutable($g('hearing_deadline_at')),
            statementText:       $opt('statement_text'),
            statementReceivedAt: $opt('statement_received_at') !== null ? new \DateTimeImmutable((string) $opt('statement_received_at')) : null,
            decisionId:          $opt('decision_id') !== null ? BoardDecisionId::fromString((string) $opt('decision_id')) : null,
            decidedAt:           $opt('decided_at')  !== null ? new \DateTimeImmutable((string) $opt('decided_at')) : null,
            expelledAt:          $opt('expelled_at') !== null ? new \DateTimeImmutable((string) $opt('expelled_at')) : null,
            appealFiledAt:       $opt('appeal_filed_at') !== null ? new \DateTimeImmutable((string) $opt('appeal_filed_at')) : null,
            appealText:          $opt('appeal_text'),
            status:              MemberExpulsionStatus::from($g('status')),
            createdAt:           new \DateTimeImmutable($g('created_at')),
        );
    }
}
