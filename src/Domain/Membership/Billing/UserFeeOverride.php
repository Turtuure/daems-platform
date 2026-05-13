<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Per-user override of the standard fee schedule.
 *
 * Created by board/admin when § 5 conditions apply ("vapauttaa jäsenen
 * jäsenmaksusta tai alentaa maksua määräajaksi perustellusta syystä").
 *
 * Override applies during the time window [valid_from, valid_until]. If
 * valid_until is NULL the override is indefinite. revoke() ends it early
 * (soft delete via revoked_at — historical audit preserved).
 *
 * Anniversary-cron checks isActiveAt(today) before applying.
 */
final class UserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideId  $id,
        private readonly TenantId           $tenantId,
        private readonly UserId             $userId,
        private readonly MembershipType     $feeType,
        private readonly int                $overrideAmountCents,
        private readonly DateTimeImmutable  $validFrom,
        private readonly ?DateTimeImmutable $validUntil,
        private readonly string             $reason,
        private readonly ?string            $decisionId,
        private readonly UserId             $createdBy,
        private readonly DateTimeImmutable  $createdAt,
        private ?DateTimeImmutable          $revokedAt,
        private ?UserId                     $revokedBy,
    ) {
        if ($overrideAmountCents < 0) {
            throw new InvalidArgumentException('override_amount_cents must be >= 0');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('reason must not be empty');
        }
        if ($validUntil !== null && $validUntil < $validFrom) {
            throw new InvalidArgumentException('valid_until must be >= valid_from');
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException('HONORARY type cannot have an override (no fees apply)');
        }
    }

    public function id(): UserFeeOverrideId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): UserId { return $this->userId; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function overrideAmountCents(): int { return $this->overrideAmountCents; }
    public function validFrom(): DateTimeImmutable { return $this->validFrom; }
    public function validUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function reason(): string { return $this->reason; }
    public function decisionId(): ?string { return $this->decisionId; }
    public function createdBy(): UserId { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function revokedBy(): ?UserId { return $this->revokedBy; }

    public function isActiveAt(DateTimeImmutable $when): bool
    {
        if ($this->revokedAt !== null && $when >= $this->revokedAt) {
            return false;
        }
        if ($when < $this->validFrom) {
            return false;
        }
        if ($this->validUntil !== null && $when > $this->validUntil) {
            return false;
        }
        return true;
    }

    public function revoke(UserId $actor, DateTimeImmutable $now): void
    {
        if ($this->revokedAt !== null) {
            return;
        }
        $this->revokedAt = $now;
        $this->revokedBy = $actor;
    }
}
