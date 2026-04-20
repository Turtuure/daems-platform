<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RedeemInvite;

final class RedeemInviteInput
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $password,
    ) {}
}
