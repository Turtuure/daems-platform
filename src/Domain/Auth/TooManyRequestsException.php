<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use RuntimeException;

final class TooManyRequestsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter, string $message = 'Too many requests.')
    {
        parent::__construct($message);
    }
}
