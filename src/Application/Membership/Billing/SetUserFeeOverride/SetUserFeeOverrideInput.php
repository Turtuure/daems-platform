<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\SetUserFeeOverride;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class SetUserFeeOverrideInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly TenantId           $tenantId,
        public readonly UserId             $userId,
        public readonly string             $feeType,
        public readonly int                $overrideAmountCents,
        public readonly DateTimeImmutable  $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly string             $reason,
        public readonly ?string            $decisionId = null,
    ) {}
}
