<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use Exception;

final class ValidationException extends Exception
{
    /** @var array<string, string> */
    private readonly array $fields;

    /** @param array<string, string>|string $fieldsOrMessage */
    public function __construct(array|string $fieldsOrMessage)
    {
        if (is_string($fieldsOrMessage)) {
            $this->fields = [];
            parent::__construct($fieldsOrMessage);
        } else {
            $this->fields = $fieldsOrMessage;
            parent::__construct('validation_failed');
        }
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
