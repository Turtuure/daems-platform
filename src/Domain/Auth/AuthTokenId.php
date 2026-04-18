<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class AuthTokenId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self(Uuid7::generate()->value());
    }

    public static function fromString(string $value): self
    {
        Uuid7::fromString($value);
        return new self(strtolower($value));
    }

    public function value(): string
    {
        return $this->value;
    }
}
