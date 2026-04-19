<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

final class LogoutUserOutput
{
    public function __construct(public readonly bool $ok = true) {}
}
