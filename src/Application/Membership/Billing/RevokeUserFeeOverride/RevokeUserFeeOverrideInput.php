<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RevokeUserFeeOverride;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;

final class RevokeUserFeeOverrideInput
{
    public function __construct(
        public readonly ActingUser        $actor,
        public readonly UserFeeOverrideId $overrideId,
    ) {}
}
