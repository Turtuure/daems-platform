<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

final class UnauthorizedException extends AuthorizationException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message);
    }
}
