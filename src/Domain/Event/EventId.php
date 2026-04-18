<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class EventId
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

    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
