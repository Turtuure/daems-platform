<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

final class LoginUserInput
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}
}
