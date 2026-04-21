<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class InvalidLocaleException extends \DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Unsupported locale "%s"', $value));
    }
}
