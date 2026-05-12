<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class MemberFeeInvoiceId
{
    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid7::generate()->value());
    }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $raw) !== 1) {
            throw new \InvalidArgumentException("Not a valid UUIDv7: {$raw}");
        }
        return new self($raw);
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
