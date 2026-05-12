<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlMemberSubTierAwardRepository implements MemberSubTierAwardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActive(TenantId $tenantId, UserId $userId, \DateTimeImmutable $at): ?MemberSubTierAward
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND user_id = ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY awarded_at DESC LIMIT 1'
        );
        $stmt->execute([$tenantId->value(), $userId->value(), $at->format('Y-m-d H:i:s')]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listForUser(TenantId $tenantId, UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND user_id = ?
              ORDER BY awarded_at DESC'
        );
        $stmt->execute([$tenantId->value(), $userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function listActiveForSubTierSlug(TenantId $tenantId, string $subTierSlug, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND sub_tier_slug = ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY awarded_at DESC'
        );
        $stmt->execute([$tenantId->value(), $subTierSlug, $at->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(MemberSubTierAward $a): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_sub_tier_awards
                (id, tenant_id, user_id, sub_tier_slug, decision_id, awarded_at, revoked_at, revoke_decision_id)
             VALUES (:id, :tid, :uid, :slug, :did, :aa, :ra, :rdi)
             ON DUPLICATE KEY UPDATE
                revoked_at         = VALUES(revoked_at),
                revoke_decision_id = VALUES(revoke_decision_id)'
        );
        $stmt->execute([
            ':id'   => $a->id->value(),
            ':tid'  => $a->tenantId->value(),
            ':uid'  => $a->userId->value(),
            ':slug' => $a->subTierSlug,
            ':did'  => $a->decisionId->value(),
            ':aa'   => $a->awardedAt->format('Y-m-d H:i:s'),
            ':ra'   => $a->revokedAt?->format('Y-m-d H:i:s'),
            ':rdi'  => $a->revokeDecisionId?->value(),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): MemberSubTierAward
    {
        $g = static function (string $k) use ($r): string {
            $v = $r[$k] ?? null;
            return is_string($v) ? $v : throw new \DomainException("Corrupt member_sub_tier_awards.{$k}");
        };
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;
        return new MemberSubTierAward(
            id:               MemberSubTierAwardId::fromString($g('id')),
            tenantId:         TenantId::fromString($g('tenant_id')),
            userId:           UserId::fromString($g('user_id')),
            subTierSlug:      $g('sub_tier_slug'),
            decisionId:       BoardDecisionId::fromString($g('decision_id')),
            awardedAt:        new \DateTimeImmutable($g('awarded_at')),
            revokedAt:        $opt('revoked_at') !== null ? new \DateTimeImmutable((string) $opt('revoked_at')) : null,
            revokeDecisionId: $opt('revoke_decision_id') !== null ? BoardDecisionId::fromString((string) $opt('revoke_decision_id')) : null,
        );
    }
}
