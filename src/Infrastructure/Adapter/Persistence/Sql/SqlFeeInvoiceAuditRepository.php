<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlFeeInvoiceAuditRepository implements FeeInvoiceAuditRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function append(FeeInvoiceAudit $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_fee_invoice_audit
                (id, tenant_id, invoice_id, action, performed_by, performed_at, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row->id,
            $row->tenantId->value(),
            $row->invoiceId->value(),
            $row->action->value,
            $row->performedBy?->value(),
            $row->performedAt->format('Y-m-d H:i:s'),
            $row->payloadJson,
        ]);
    }

    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoice_audit WHERE invoice_id = ? ORDER BY performed_at ASC'
        );
        $stmt->execute([$invoiceId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id          = is_string($r['id']           ?? null) ? $r['id']           : throw new \DomainException('Corrupt member_fee_invoice_audit.id');
            $tenantId    = is_string($r['tenant_id']    ?? null) ? $r['tenant_id']    : throw new \DomainException('Corrupt member_fee_invoice_audit.tenant_id');
            $invoiceIdRaw = is_string($r['invoice_id'] ?? null) ? $r['invoice_id'] : throw new \DomainException('Corrupt member_fee_invoice_audit.invoice_id');
            $action      = is_string($r['action']       ?? null) ? $r['action']       : throw new \DomainException('Corrupt member_fee_invoice_audit.action');
            $performedAt = is_string($r['performed_at'] ?? null) ? $r['performed_at'] : throw new \DomainException('Corrupt member_fee_invoice_audit.performed_at');
            $performedBy = isset($r['performed_by']) && is_string($r['performed_by']) ? $r['performed_by'] : null;
            $payload     = isset($r['payload_json'])  && is_string($r['payload_json'])  ? $r['payload_json']  : null;

            $out[] = new FeeInvoiceAudit(
                id:          $id,
                tenantId:    TenantId::fromString($tenantId),
                invoiceId:   MemberFeeInvoiceId::fromString($invoiceIdRaw),
                action:      FeeInvoiceAuditAction::from($action),
                performedBy: $performedBy !== null ? UserId::fromString($performedBy) : null,
                performedAt: new DateTimeImmutable($performedAt),
                payloadJson: $payload,
            );
        }
        return $out;
    }
}
