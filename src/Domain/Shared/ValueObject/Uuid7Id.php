<?php

declare(strict_types=1);

namespace Daems\Domain\Shared\ValueObject;

/**
 * Abstract base for typed UUIDv7 identifiers.
 *
 * Subclasses inherit generate/fromString/value/equals with zero boilerplate.
 * Nominal typing is preserved: because `equals()` checks `static::class`,
 * a `UserId` can never report equal to a `ProjectId` even when the
 * underlying string is identical.
 */
abstract class Uuid7Id
{
    final protected function __construct(protected readonly string $value) {}

    public static function generate(): static
    {
        return new static(Uuid7::generate()->value());
    }

    public static function fromString(string $value): static
    {
        Uuid7::fromString($value); // validates
        return new static(strtolower($value));
    }

    final public function value(): string
    {
        return $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }
}
