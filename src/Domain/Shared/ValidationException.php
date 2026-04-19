<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use Exception;

final class ValidationException extends Exception
{
    /** @param array<string, string> $fields */
    public function __construct(private readonly array $fields)
    {
        parent::__construct('validation_failed');
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
