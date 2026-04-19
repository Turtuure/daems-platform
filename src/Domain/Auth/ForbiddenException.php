<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

final class ForbiddenException extends AuthorizationException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct($message);
    }
}
