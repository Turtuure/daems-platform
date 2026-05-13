<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlAnnualFeeScheduleRepository implements AnnualFeeScheduleRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(AnnualFeeSchedule $s): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO annual_fee_schedules
                (id, tenant_id, year, fee_type, amount_cents, currency, status,
                 decision_id, activated_at, activated_by, superseded_at, created_at, created_by)
             VALUES (:id, :tid, :yr, :ft, :amt, :cur, :st, :did, :aat, :aby, :sat, :cat, :cby)
             ON DUPLICATE KEY UPDATE
                amount_cents = VALUES(amount_cents),
                status       = VALUES(status),
                activated_at = VALUES(activated_at),
                activated_by = VALUES(activated_by),
                superseded_at= VALUES(superseded_at)'
        );
        $stmt->execute([
            'id'  => $s->id()->value(),
            'tid' => $s->tenantId()->value(),
            'yr'  => $s->year(),
            'ft'  => $s->feeType()->value,
            'amt' => $s->amountCents(),
            'cur' => $s->currency(),
            'st'  => $s->status()->value,
            'did' => $s->decisionId(),
            'aat' => $s->activatedAt()?->format('Y-m-d H:i:s'),
            'aby' => $s->activatedBy()?->value(),
            'sat' => $s->supersededAt()?->format('Y-m-d H:i:s'),
            'cat' => $s->createdAt()->format('Y-m-d H:i:s'),
            'cby' => $s->createdBy()?->value(),
        ]);
    }

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM annual_fee_schedules WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM annual_fee_schedules
             WHERE tenant_id = ? AND year = ? AND fee_type = ? AND status = ?
             ORDER BY activated_at DESC
             LIMIT 1'
        );
        $stmt->execute([$tenantId->value(), $year, $feeType->value, AnnualFeeScheduleStatus::Active->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array
    {
        $sql = 'SELECT * FROM annual_fee_schedules
                WHERE tenant_id = ? AND year = ? AND status = ?';
        $params = [$tenantId->value(), $year, AnnualFeeScheduleStatus::Proposed->value];
        if ($decisionId !== null) {
            $sql .= ' AND decision_id = ?';
            $params[] = $decisionId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (is_array($r)) {
                $out[] = $this->hydrate($r);
            }
        }
        return $out;
    }

    public function findProposedByDecision(string $decisionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM annual_fee_schedules
             WHERE decision_id = ? AND status = ?'
        );
        $stmt->execute([$decisionId, AnnualFeeScheduleStatus::Proposed->value]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function listForTenantYear(TenantId $tenantId, int $year): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM annual_fee_schedules
             WHERE tenant_id = ? AND year = ?
             ORDER BY fee_type ASC, created_at DESC'
        );
        $stmt->execute([$tenantId->value(), $year]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (is_array($r)) {
                $out[] = $this->hydrate($r);
            }
        }
        return $out;
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): AnnualFeeSchedule
    {
        $id          = is_string($row['id']           ?? null) ? $row['id']           : throw new \DomainException('Corrupt annual_fee_schedules.id');
        $tenantId    = is_string($row['tenant_id']    ?? null) ? $row['tenant_id']    : throw new \DomainException('Corrupt annual_fee_schedules.tenant_id');
        $year        = is_int($row['year']            ?? null) || (is_string($row['year'] ?? null) && ctype_digit((string) $row['year']))
            ? (int) $row['year']
            : throw new \DomainException('Corrupt annual_fee_schedules.year');
        $feeType     = is_string($row['fee_type']     ?? null) ? $row['fee_type']     : throw new \DomainException('Corrupt annual_fee_schedules.fee_type');
        $amountCents = is_int($row['amount_cents']    ?? null) || (is_string($row['amount_cents'] ?? null) && ctype_digit((string) $row['amount_cents']))
            ? (int) $row['amount_cents']
            : throw new \DomainException('Corrupt annual_fee_schedules.amount_cents');
        $currency    = is_string($row['currency']     ?? null) ? $row['currency']     : throw new \DomainException('Corrupt annual_fee_schedules.currency');
        $status      = is_string($row['status']       ?? null) ? $row['status']       : throw new \DomainException('Corrupt annual_fee_schedules.status');
        $decisionId  = isset($row['decision_id'])  && is_string($row['decision_id'])  ? $row['decision_id']  : null;
        $activatedAt = isset($row['activated_at'])  && is_string($row['activated_at'])  ? $row['activated_at']  : null;
        $activatedBy = isset($row['activated_by'])  && is_string($row['activated_by'])  ? $row['activated_by']  : null;
        $supersededAt= isset($row['superseded_at']) && is_string($row['superseded_at']) ? $row['superseded_at'] : null;
        $createdAt   = is_string($row['created_at']   ?? null) ? $row['created_at']   : throw new \DomainException('Corrupt annual_fee_schedules.created_at');
        $createdBy   = isset($row['created_by'])   && is_string($row['created_by'])   ? $row['created_by']   : null;

        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::fromString($id),
            tenantId:     TenantId::fromString($tenantId),
            year:         $year,
            feeType:      MembershipType::from($feeType),
            amountCents:  $amountCents,
            currency:     $currency,
            status:       AnnualFeeScheduleStatus::from($status),
            decisionId:   $decisionId,
            activatedAt:  $activatedAt !== null ? new DateTimeImmutable($activatedAt) : null,
            activatedBy:  $activatedBy !== null ? UserId::fromString($activatedBy) : null,
            supersededAt: $supersededAt !== null ? new DateTimeImmutable($supersededAt) : null,
            createdAt:    new DateTimeImmutable($createdAt),
            createdBy:    $createdBy !== null ? UserId::fromString($createdBy) : null,
        );
    }
}
