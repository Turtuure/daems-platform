<?php

declare(strict_types=1);

namespace Daems\Domain\Shared\ValueObject;

use InvalidArgumentException;

final class Uuid7
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        // 48-bit millisecond timestamp
        $ms = (int) (microtime(true) * 1000);
        $timeHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);

        // 10 bytes = 20 random hex chars
        $rand = bin2hex(random_bytes(10));

        // Group 3: version nibble '7' + 12 random bits (3 hex)
        $g3 = '7' . substr($rand, 0, 3);

        // Group 4: variant nibble (10xx) + 12 random bits (3 hex)
        $variantNibble = dechex(0x8 | (hexdec($rand[3]) & 0x3));
        $g4 = $variantNibble . substr($rand, 4, 3);

        // Group 5: 48 random bits (12 hex)
        $g5 = substr($rand, 8, 12);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            $g3,
            $g4,
            $g5,
        ));
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException("Invalid UUID7: {$value}");
        }
        return new self(strtolower($value));
    }

    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
