<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

/**
 * Immutable audit row. Created by every state-changing operation on a
 * MemberFeeInvoice (waive, reduce, payment, overdue, lapse). performedBy
 * is NULL when the change came from a cron command.
 */
final class FeeInvoiceAudit
{
    public function __construct(
        public readonly string                $id,
        public readonly TenantId              $tenantId,
        public readonly MemberFeeInvoiceId    $invoiceId,
        public readonly FeeInvoiceAuditAction $action,
        public readonly ?UserId               $performedBy,
        public readonly DateTimeImmutable     $performedAt,
        public readonly ?string               $payloadJson,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public static function record(
        TenantId              $tenantId,
        MemberFeeInvoiceId    $invoiceId,
        FeeInvoiceAuditAction $action,
        ?UserId               $actor,
        DateTimeImmutable     $now,
        ?array                $payload = null,
    ): self {
        return new self(
            id:          Uuid7::generate()->value(),
            tenantId:    $tenantId,
            invoiceId:   $invoiceId,
            action:      $action,
            performedBy: $actor,
            performedAt: $now,
            payloadJson: $payload !== null
                ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : null,
        );
    }
}
