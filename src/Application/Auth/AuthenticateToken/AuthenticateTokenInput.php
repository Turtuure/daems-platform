<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

final class AuthenticateTokenInput
{
    public function __construct(public readonly string $rawToken) {}
}
