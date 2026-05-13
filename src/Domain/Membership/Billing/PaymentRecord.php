<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Single payment event recorded against a MemberFeeInvoice.
 *
 * Immutable value object — once a payment is recorded the invoice flips to
 * PAID and the values are frozen on the invoice row. There is no separate
 * payments table in 0.7 (one-payment-per-invoice model; multi-payment
 * support deferred to 0.7.1 Stripe).
 *
 * Method is a free-string in 0.7 ('bank_transfer' | 'cash' | 'csv_import' | other).
 */
final class PaymentRecord
{
    public function __construct(
        private readonly DateTimeImmutable $paidAt,
        private readonly int               $amountCents,
        private readonly string            $method,
        private readonly string            $reference,
        private readonly UserId            $paidBy,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException("PaymentRecord.amount_cents must be > 0, got {$amountCents}");
        }
        if (trim($method) === '') {
            throw new InvalidArgumentException("PaymentRecord.method must not be empty");
        }
    }

    public function paidAt(): DateTimeImmutable { return $this->paidAt; }
    public function amountCents(): int { return $this->amountCents; }
    public function method(): string { return $this->method; }
    public function reference(): string { return $this->reference; }
    public function paidBy(): UserId { return $this->paidBy; }
}
