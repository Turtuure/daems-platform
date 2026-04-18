<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RegisterUser;

final class RegisterUserInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly string $dateOfBirth,
    ) {}
}
