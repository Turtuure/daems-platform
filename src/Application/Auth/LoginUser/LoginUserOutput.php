<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

final class LoginUserOutput
{
    public function __construct(
        public readonly ?array $user,
        public readonly ?string $error = null,
    ) {}
}
