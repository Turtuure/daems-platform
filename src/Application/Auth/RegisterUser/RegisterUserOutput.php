<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RegisterUser;

final class RegisterUserOutput
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $error = null,
    ) {}
}
