<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Yearly fee invoice for one (tenant, user, year).
 *
 * Lifecycle:
 *   PENDING --markOverdue--> OVERDUE
 *      |                       |
 *      | recordPayment         | recordPayment
 *      v                       v
 *     PAID  (terminal -- InvoiceAlreadyPaidException blocks re-pay)
 *
 *   PENDING/OVERDUE --waive--> WAIVED (terminal)
 *   PENDING/OVERDUE --reduce--> REDUCED (still open; can still recordPayment)
 *
 * Snapshot invariants: fee_type and amount_cents at issue time are immutable
 * by anniversary-cron re-runs. amount_cents is only mutated by reduce(),
 * which preserves the original in original_amount_cents for audit.
 */
final class MemberFeeInvoice
{
    public function __construct(
        private readonly MemberFeeInvoiceId  $id,
        private readonly TenantId            $tenantId,
        private readonly UserId              $userId,
        private readonly int                 $year,
        private readonly MembershipType      $feeType,
        private readonly DateTimeImmutable   $anniversaryDate,
        private int                          $amountCents,
        private ?int                         $originalAmountCents,
        private readonly string              $currency,
        private readonly DateTimeImmutable   $dueDate,
        private MemberFeeInvoiceStatus       $status,
        private ?PaymentRecord               $payment,
        private ?DateTimeImmutable           $waivedAt,
        private ?UserId                      $waivedBy,
        private ?string                      $waiveReason,
        private readonly ?string             $overrideId,
        private readonly DateTimeImmutable   $createdAt,
    ) {
        if ($amountCents < 0) {
            throw new InvalidArgumentException("amount_cents must be >= 0");
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException("HONORARY members do not receive invoices (§ 3)");
        }
    }

    public function id(): MemberFeeInvoiceId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): UserId { return $this->userId; }
    public function year(): int { return $this->year; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function anniversaryDate(): DateTimeImmutable { return $this->anniversaryDate; }
    public function amountCents(): int { return $this->amountCents; }
    public function originalAmountCents(): ?int { return $this->originalAmountCents; }
    public function currency(): string { return $this->currency; }
    public function dueDate(): DateTimeImmutable { return $this->dueDate; }
    public function status(): MemberFeeInvoiceStatus { return $this->status; }
    public function payment(): ?PaymentRecord { return $this->payment; }
    public function paidAt(): ?DateTimeImmutable { return $this->payment?->paidAt(); }
    public function paidAmountCents(): ?int { return $this->payment?->amountCents(); }
    public function paidMethod(): ?string { return $this->payment?->method(); }
    public function paidReference(): ?string { return $this->payment?->reference(); }
    public function paidBy(): ?UserId { return $this->payment?->paidBy(); }
    public function waivedAt(): ?DateTimeImmutable { return $this->waivedAt; }
    public function waivedBy(): ?UserId { return $this->waivedBy; }
    public function waiveReason(): ?string { return $this->waiveReason; }
    public function overrideId(): ?string { return $this->overrideId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    public function markOverdue(DateTimeImmutable $now): void
    {
        if ($this->status !== MemberFeeInvoiceStatus::Pending) {
            return;
        }
        if ($now < $this->dueDate) {
            return;
        }
        $this->status = MemberFeeInvoiceStatus::Overdue;
    }

    public function recordPayment(PaymentRecord $payment): void
    {
        if ($this->status === MemberFeeInvoiceStatus::Paid) {
            throw new InvoiceAlreadyPaidException("Invoice {$this->id->value()} is already paid");
        }
        if ($this->status === MemberFeeInvoiceStatus::Waived) {
            throw new InvoiceAlreadyPaidException("Invoice {$this->id->value()} is waived; cannot record payment");
        }
        $this->payment = $payment;
        $this->status = MemberFeeInvoiceStatus::Paid;
    }

    public function waive(UserId $actor, string $reason, DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new \DomainException("Cannot waive an invoice in final state {$this->status->value}");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Waive reason must not be empty");
        }
        $this->status = MemberFeeInvoiceStatus::Waived;
        $this->waivedAt = $now;
        $this->waivedBy = $actor;
        $this->waiveReason = $reason;
    }

    public function reduce(int $newAmountCents, UserId $actor, string $reason, DateTimeImmutable $now): void
    {
        if ($newAmountCents <= 0) {
            throw new InvalidArgumentException("Reduced amount must be > 0 (use waive() for zero)");
        }
        if ($newAmountCents >= $this->amountCents) {
            throw new InvalidArgumentException("Reduced amount must be less than current {$this->amountCents}");
        }
        if ($this->status->isFinal()) {
            throw new \DomainException("Cannot reduce an invoice in final state {$this->status->value}");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Reduce reason must not be empty");
        }
        if ($this->originalAmountCents === null) {
            $this->originalAmountCents = $this->amountCents;
        }
        $this->amountCents = $newAmountCents;
        $this->status = MemberFeeInvoiceStatus::Reduced;
        $this->waiveReason = $reason;
        $this->waivedBy = $actor;
        $this->waivedAt = $now;
    }
}
