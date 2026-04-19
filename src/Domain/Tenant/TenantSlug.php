<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use InvalidArgumentException;

final class TenantSlug
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'TenantSlug must be 3-64 chars, lowercase a-z0-9- only, no leading/trailing hyphen.'
            );
        }
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
