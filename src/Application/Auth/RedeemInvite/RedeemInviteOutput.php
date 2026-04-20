<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RedeemInvite;

use Daems\Domain\User\User;

final class RedeemInviteOutput
{
    public function __construct(public readonly User $user) {}
}
