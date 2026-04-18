<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

final class LogoutUserInput
{
    public function __construct(public readonly string $rawToken) {}
}
