<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * One yearly fee row per (tenant, year, fee_type).
 *
 * Invariants:
 *   - amount_cents >= 0 (0 = full waiver; allowed e.g. for FULL type per § 3)
 *   - status transitions: Draft → Proposed → Active → Superseded
 *     OR Draft → Active (direct admin-action, no formal decision)
 *   - Active row can be superseded, never re-activated
 *   - Superseded is terminal — kept for audit only
 *
 * HONORARY type has no AnnualFeeSchedule row (§ 3 kunniajäsen ei maksa).
 */
final class AnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleId $id,
        private readonly TenantId            $tenantId,
        private readonly int                 $year,
        private readonly MembershipType      $feeType,
        private readonly int                 $amountCents,
        private readonly string              $currency,
        private AnnualFeeScheduleStatus      $status,
        private readonly ?string             $decisionId,
        private ?DateTimeImmutable           $activatedAt,
        private ?UserId                      $activatedBy,
        private ?DateTimeImmutable           $supersededAt,
        private readonly DateTimeImmutable   $createdAt,
        private readonly ?UserId             $createdBy,
    ) {
        if ($amountCents < 0) {
            throw new InvalidArgumentException("amount_cents must be >= 0, got {$amountCents}");
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException("HONORARY type cannot have a fee schedule (§ 3)");
        }
    }

    public function id(): AnnualFeeScheduleId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function year(): int { return $this->year; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function amountCents(): int { return $this->amountCents; }
    public function currency(): string { return $this->currency; }
    public function status(): AnnualFeeScheduleStatus { return $this->status; }
    public function decisionId(): ?string { return $this->decisionId; }
    public function activatedAt(): ?DateTimeImmutable { return $this->activatedAt; }
    public function activatedBy(): ?UserId { return $this->activatedBy; }
    public function supersededAt(): ?DateTimeImmutable { return $this->supersededAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function createdBy(): ?UserId { return $this->createdBy; }

    public function activate(UserId $actor, DateTimeImmutable $now): void
    {
        if ($this->status === AnnualFeeScheduleStatus::Active) {
            throw new DomainException("Schedule already active");
        }
        if ($this->status === AnnualFeeScheduleStatus::Superseded) {
            throw new DomainException("Cannot activate a superseded schedule");
        }
        $this->status = AnnualFeeScheduleStatus::Active;
        $this->activatedAt = $now;
        $this->activatedBy = $actor;
    }

    public function supersede(DateTimeImmutable $now): void
    {
        if ($this->status !== AnnualFeeScheduleStatus::Active) {
            throw new DomainException("Only active schedules can be superseded; this is {$this->status->value}");
        }
        $this->status = AnnualFeeScheduleStatus::Superseded;
        $this->supersededAt = $now;
    }
}
