<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlMemberFeeInvoiceRepository implements MemberFeeInvoiceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(MemberFeeInvoice $i): void
    {
        // Explicit id-upsert: state changes UPDATE by id; a different id with same
        // (tenant, user, year) hits the UNIQUE constraint and throws PDOException.
        // Plain `ON DUPLICATE KEY UPDATE` would silently overwrite the existing
        // row when the (tenant, user, year) unique matched a different id.
        $check = $this->pdo->prepare('SELECT id FROM member_fee_invoices WHERE id = ?');
        $check->execute([$i->id()->value()]);
        $exists = $check->fetchColumn() !== false;

        if ($exists) {
            $stmt = $this->pdo->prepare(
                'UPDATE member_fee_invoices SET
                    amount_cents          = :amt,
                    original_amount_cents = :oamt,
                    status                = :st,
                    paid_at               = :pat,
                    paid_amount_cents     = :pac,
                    paid_method           = :pm,
                    paid_reference        = :pref,
                    paid_by               = :pby,
                    waived_at             = :wat,
                    waived_by             = :wby,
                    waive_reason          = :wr
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'   => $i->id()->value(),
                'amt'  => $i->amountCents(),
                'oamt' => $i->originalAmountCents(),
                'st'   => $i->status()->value,
                'pat'  => $i->paidAt()?->format('Y-m-d H:i:s'),
                'pac'  => $i->paidAmountCents(),
                'pm'   => $i->paidMethod(),
                'pref' => $i->paidReference(),
                'pby'  => $i->paidBy()?->value(),
                'wat'  => $i->waivedAt()?->format('Y-m-d H:i:s'),
                'wby'  => $i->waivedBy()?->value(),
                'wr'   => $i->waiveReason(),
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_fee_invoices (
                id, tenant_id, user_id, year, fee_type, anniversary_date,
                amount_cents, original_amount_cents, currency, due_date, status,
                paid_at, paid_amount_cents, paid_method, paid_reference, paid_by,
                waived_at, waived_by, waive_reason, override_id, created_at
             ) VALUES (
                :id, :tid, :uid, :yr, :ft, :ann,
                :amt, :oamt, :cur, :dd, :st,
                :pat, :pac, :pm, :pref, :pby,
                :wat, :wby, :wr, :ovr, :cat
             )'
        );
        $stmt->execute([
            'id'   => $i->id()->value(),
            'tid'  => $i->tenantId()->value(),
            'uid'  => $i->userId()->value(),
            'yr'   => $i->year(),
            'ft'   => $i->feeType()->value,
            'ann'  => $i->anniversaryDate()->format('Y-m-d'),
            'amt'  => $i->amountCents(),
            'oamt' => $i->originalAmountCents(),
            'cur'  => $i->currency(),
            'dd'   => $i->dueDate()->format('Y-m-d'),
            'st'   => $i->status()->value,
            'pat'  => $i->paidAt()?->format('Y-m-d H:i:s'),
            'pac'  => $i->paidAmountCents(),
            'pm'   => $i->paidMethod(),
            'pref' => $i->paidReference(),
            'pby'  => $i->paidBy()?->value(),
            'wat'  => $i->waivedAt()?->format('Y-m-d H:i:s'),
            'wby'  => $i->waivedBy()?->value(),
            'wr'   => $i->waiveReason(),
            'ovr'  => $i->overrideId(),
            'cat'  => $i->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_fee_invoices WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoices WHERE tenant_id = ? AND user_id = ? AND year = ?'
        );
        $stmt->execute([$tenantId->value(), $userId->value(), $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findOverdueCandidates(TenantId $tenantId, DateTimeImmutable $asOf, int $graceDays): array
    {
        $cutoff = $asOf->modify("-{$graceDays} days")->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoices
             WHERE tenant_id = ? AND status = ? AND due_date < ?
             ORDER BY due_date ASC'
        );
        $stmt->execute([$tenantId->value(), MemberFeeInvoiceStatus::Pending->value, $cutoff]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (is_array($r)) {
                $out[] = $this->hydrate($r);
            }
        }
        return $out;
    }

    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.user_id AS user_id, a.year AS y1, b.year AS y2
             FROM member_fee_invoices a
             INNER JOIN member_fee_invoices b
                ON a.tenant_id = b.tenant_id
               AND a.user_id   = b.user_id
               AND b.year      = a.year + 1
             WHERE a.tenant_id = :tid
               AND a.status    = 'OVERDUE'
               AND b.status    = 'OVERDUE'
             ORDER BY a.user_id, a.year"
        );
        $stmt->execute(['tid' => $tenantId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userIdRaw = is_string($row['user_id'] ?? null) ? $row['user_id'] : throw new \DomainException('Corrupt member_fee_invoices.user_id');
            $y1 = (is_int($row['y1'] ?? null) || (is_string($row['y1'] ?? null) && ctype_digit((string) $row['y1'])))
                ? (int) $row['y1']
                : throw new \DomainException('Corrupt member_fee_invoices.year (y1)');
            $y2 = (is_int($row['y2'] ?? null) || (is_string($row['y2'] ?? null) && ctype_digit((string) $row['y2'])))
                ? (int) $row['y2']
                : throw new \DomainException('Corrupt member_fee_invoices.year (y2)');
            $out[] = [
                'user_id' => UserId::fromString($userIdRaw),
                'years'   => [$y1, $y2],
            ];
        }
        return $out;
    }

    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tenantId->value()];
        if (isset($filter['year'])) {
            $where[] = 'year = :yr';
            $params['yr'] = (int) $filter['year'];
        }
        if (isset($filter['status'])) {
            $where[] = 'status = :st';
            $params['st'] = (string) $filter['status'];
        }
        if (isset($filter['fee_type'])) {
            $where[] = 'fee_type = :ft';
            $params['ft'] = (string) $filter['fee_type'];
        }
        if (isset($filter['user_id'])) {
            $where[] = 'user_id = :uid';
            $params['uid'] = (string) $filter['user_id'];
        }

        $sql = 'SELECT * FROM member_fee_invoices WHERE ' . implode(' AND ', $where)
             . ' ORDER BY due_date DESC, created_at DESC'
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

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

    public function findByReference(TenantId $tenantId, string $reference): ?MemberFeeInvoice
    {
        // First try: <member_number>-<year> reference scheme.
        if (preg_match('/^(\d+)-(\d{4})$/', $reference, $m) === 1) {
            $stmt = $this->pdo->prepare(
                "SELECT mfi.* FROM member_fee_invoices mfi
                 INNER JOIN users u ON u.id = mfi.user_id
                 WHERE mfi.tenant_id = ? AND u.member_number = ? AND mfi.year = ?
                   AND mfi.status IN ('PENDING','OVERDUE','REDUCED')
                 LIMIT 2"
            );
            $stmt->execute([$tenantId->value(), $m[1], (int) $m[2]]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 1 && is_array($rows[0])) {
                return $this->hydrate($rows[0]);
            }
        }
        // Fallback: id-prefix match.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_fee_invoices
             WHERE tenant_id = ? AND id LIKE ? AND status IN ('PENDING','OVERDUE','REDUCED')
             LIMIT 2"
        );
        $stmt->execute([$tenantId->value(), $reference . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1 && is_array($rows[0])) {
            return $this->hydrate($rows[0]);
        }
        return null;
    }

    public function listOpenForUser(TenantId $tenantId, UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_fee_invoices
             WHERE tenant_id = ? AND user_id = ?
               AND status IN ('PENDING', 'OVERDUE', 'REDUCED')
             ORDER BY year DESC"
        );
        $stmt->execute([$tenantId->value(), $userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (is_array($r)) {
                $out[] = $this->hydrate($r);
            }
        }
        return $out;
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): MemberFeeInvoice
    {
        $id          = is_string($row['id']           ?? null) ? $row['id']           : throw new \DomainException('Corrupt member_fee_invoices.id');
        $tenantId    = is_string($row['tenant_id']    ?? null) ? $row['tenant_id']    : throw new \DomainException('Corrupt member_fee_invoices.tenant_id');
        $userId      = is_string($row['user_id']      ?? null) ? $row['user_id']      : throw new \DomainException('Corrupt member_fee_invoices.user_id');
        $year        = (is_int($row['year']            ?? null) || (is_string($row['year'] ?? null) && ctype_digit((string) $row['year'])))
            ? (int) $row['year']
            : throw new \DomainException('Corrupt member_fee_invoices.year');
        $feeType     = is_string($row['fee_type']     ?? null) ? $row['fee_type']     : throw new \DomainException('Corrupt member_fee_invoices.fee_type');
        $anniversary = is_string($row['anniversary_date'] ?? null) ? $row['anniversary_date'] : throw new \DomainException('Corrupt member_fee_invoices.anniversary_date');
        $amountCents = (is_int($row['amount_cents']   ?? null) || (is_string($row['amount_cents'] ?? null) && ctype_digit((string) $row['amount_cents'])))
            ? (int) $row['amount_cents']
            : throw new \DomainException('Corrupt member_fee_invoices.amount_cents');
        $origAmount  = null;
        if (isset($row['original_amount_cents'])) {
            $origAmount = (is_int($row['original_amount_cents']) || (is_string($row['original_amount_cents']) && ctype_digit((string) $row['original_amount_cents'])))
                ? (int) $row['original_amount_cents']
                : throw new \DomainException('Corrupt member_fee_invoices.original_amount_cents');
        }
        $currency    = is_string($row['currency']     ?? null) ? $row['currency']     : throw new \DomainException('Corrupt member_fee_invoices.currency');
        $dueDate     = is_string($row['due_date']     ?? null) ? $row['due_date']     : throw new \DomainException('Corrupt member_fee_invoices.due_date');
        $status      = is_string($row['status']       ?? null) ? $row['status']       : throw new \DomainException('Corrupt member_fee_invoices.status');
        $paidAt      = isset($row['paid_at'])      && is_string($row['paid_at'])      ? $row['paid_at']      : null;
        $paidAmount  = null;
        if (isset($row['paid_amount_cents'])) {
            $paidAmount = (is_int($row['paid_amount_cents']) || (is_string($row['paid_amount_cents']) && ctype_digit((string) $row['paid_amount_cents'])))
                ? (int) $row['paid_amount_cents']
                : throw new \DomainException('Corrupt member_fee_invoices.paid_amount_cents');
        }
        $paidMethod  = isset($row['paid_method'])    && is_string($row['paid_method'])    ? $row['paid_method']    : null;
        $paidRef     = isset($row['paid_reference']) && is_string($row['paid_reference']) ? $row['paid_reference'] : null;
        $paidBy      = isset($row['paid_by'])        && is_string($row['paid_by'])        ? $row['paid_by']        : null;
        $waivedAt    = isset($row['waived_at'])      && is_string($row['waived_at'])      ? $row['waived_at']      : null;
        $waivedBy    = isset($row['waived_by'])      && is_string($row['waived_by'])      ? $row['waived_by']      : null;
        $waiveReason = isset($row['waive_reason'])   && is_string($row['waive_reason'])   ? $row['waive_reason']   : null;
        $overrideId  = isset($row['override_id'])    && is_string($row['override_id'])    ? $row['override_id']    : null;
        $createdAt   = is_string($row['created_at']   ?? null) ? $row['created_at']   : throw new \DomainException('Corrupt member_fee_invoices.created_at');

        $payment = null;
        if ($paidAt !== null && $paidAmount !== null && $paidMethod !== null && $paidBy !== null) {
            $payment = new PaymentRecord(
                paidAt:      new DateTimeImmutable($paidAt),
                amountCents: $paidAmount,
                method:      $paidMethod,
                reference:   $paidRef ?? '',
                paidBy:      UserId::fromString($paidBy),
            );
        }

        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::fromString($id),
            tenantId:            TenantId::fromString($tenantId),
            userId:              UserId::fromString($userId),
            year:                $year,
            feeType:             MembershipType::from($feeType),
            anniversaryDate:     new DateTimeImmutable($anniversary),
            amountCents:         $amountCents,
            originalAmountCents: $origAmount,
            currency:            $currency,
            dueDate:             new DateTimeImmutable($dueDate),
            status:              MemberFeeInvoiceStatus::from($status),
            payment:             $payment,
            waivedAt:            $waivedAt !== null ? new DateTimeImmutable($waivedAt) : null,
            waivedBy:            $waivedBy !== null ? UserId::fromString($waivedBy) : null,
            waiveReason:         $waiveReason,
            overrideId:          $overrideId,
            createdAt:           new DateTimeImmutable($createdAt),
        );
    }
}
