<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(UserFeeOverride $o): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_fee_overrides
                (id, tenant_id, user_id, fee_type, override_amount_cents,
                 valid_from, valid_until, reason, decision_id,
                 created_by, created_at, revoked_at, revoked_by)
             VALUES (:id, :tid, :uid, :ft, :amt,
                     :vf, :vu, :rsn, :did,
                     :cby, :cat, :rat, :rby)
             ON DUPLICATE KEY UPDATE
                override_amount_cents = VALUES(override_amount_cents),
                valid_until           = VALUES(valid_until),
                reason                = VALUES(reason),
                revoked_at            = VALUES(revoked_at),
                revoked_by            = VALUES(revoked_by)'
        );
        $stmt->execute([
            'id'  => $o->id()->value(),
            'tid' => $o->tenantId()->value(),
            'uid' => $o->userId()->value(),
            'ft'  => $o->feeType()->value,
            'amt' => $o->overrideAmountCents(),
            'vf'  => $o->validFrom()->format('Y-m-d'),
            'vu'  => $o->validUntil()?->format('Y-m-d'),
            'rsn' => $o->reason(),
            'did' => $o->decisionId(),
            'cby' => $o->createdBy()->value(),
            'cat' => $o->createdAt()->format('Y-m-d H:i:s'),
            'rat' => $o->revokedAt()?->format('Y-m-d H:i:s'),
            'rby' => $o->revokedBy()?->value(),
        ]);
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_fee_overrides WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_fee_overrides
             WHERE tenant_id = ? AND user_id = ? AND fee_type = ?
               AND valid_from <= ?
               AND (valid_until IS NULL OR valid_until >= ?)
               AND (revoked_at IS NULL OR revoked_at > ?)
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $asOfDate = $asOf->format('Y-m-d');
        $asOfDT = $asOf->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $userId->value(), $feeType, $asOfDate, $asOfDate, $asOfDT]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        if ($activeOnly) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM user_fee_overrides
                 WHERE tenant_id = ?
                   AND valid_from <= CURDATE()
                   AND (valid_until IS NULL OR valid_until >= CURDATE())
                   AND revoked_at IS NULL
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$tenantId->value()]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM user_fee_overrides WHERE tenant_id = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$tenantId->value()]);
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (is_array($r)) {
                $out[] = $this->hydrate($r);
            }
        }
        return $out;
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): UserFeeOverride
    {
        $id          = is_string($row['id']           ?? null) ? $row['id']           : throw new \DomainException('Corrupt user_fee_overrides.id');
        $tenantId    = is_string($row['tenant_id']    ?? null) ? $row['tenant_id']    : throw new \DomainException('Corrupt user_fee_overrides.tenant_id');
        $userId      = is_string($row['user_id']      ?? null) ? $row['user_id']      : throw new \DomainException('Corrupt user_fee_overrides.user_id');
        $feeType     = is_string($row['fee_type']     ?? null) ? $row['fee_type']     : throw new \DomainException('Corrupt user_fee_overrides.fee_type');
        $amountCents = (is_int($row['override_amount_cents'] ?? null) || (is_string($row['override_amount_cents'] ?? null) && ctype_digit((string) $row['override_amount_cents'])))
            ? (int) $row['override_amount_cents']
            : throw new \DomainException('Corrupt user_fee_overrides.override_amount_cents');
        $validFrom   = is_string($row['valid_from']   ?? null) ? $row['valid_from']   : throw new \DomainException('Corrupt user_fee_overrides.valid_from');
        $validUntil  = isset($row['valid_until'])  && is_string($row['valid_until'])  ? $row['valid_until']  : null;
        $reason      = is_string($row['reason']       ?? null) ? $row['reason']       : throw new \DomainException('Corrupt user_fee_overrides.reason');
        $decisionId  = isset($row['decision_id'])  && is_string($row['decision_id'])  ? $row['decision_id']  : null;
        $createdBy   = is_string($row['created_by']   ?? null) ? $row['created_by']   : throw new \DomainException('Corrupt user_fee_overrides.created_by');
        $createdAt   = is_string($row['created_at']   ?? null) ? $row['created_at']   : throw new \DomainException('Corrupt user_fee_overrides.created_at');
        $revokedAt   = isset($row['revoked_at'])   && is_string($row['revoked_at'])   ? $row['revoked_at']   : null;
        $revokedBy   = isset($row['revoked_by'])   && is_string($row['revoked_by'])   ? $row['revoked_by']   : null;

        return new UserFeeOverride(
            id:                  UserFeeOverrideId::fromString($id),
            tenantId:            TenantId::fromString($tenantId),
            userId:              UserId::fromString($userId),
            feeType:             MembershipType::from($feeType),
            overrideAmountCents: $amountCents,
            validFrom:           new DateTimeImmutable($validFrom),
            validUntil:          $validUntil !== null ? new DateTimeImmutable($validUntil) : null,
            reason:              $reason,
            decisionId:          $decisionId,
            createdBy:           UserId::fromString($createdBy),
            createdAt:           new DateTimeImmutable($createdAt),
            revokedAt:           $revokedAt !== null ? new DateTimeImmutable($revokedAt) : null,
            revokedBy:           $revokedBy !== null ? UserId::fromString($revokedBy) : null,
        );
    }
}
