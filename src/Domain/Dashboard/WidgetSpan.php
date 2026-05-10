<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\InvalidWidgetSpan;

final class WidgetSpan
{
    private function __construct(private readonly int $value) {}

    public static function of(int $n): self
    {
        if ($n < 1 || $n > 4) {
            throw new InvalidWidgetSpan("Widget span must be 1-4, got {$n}");
        }
        return new self($n);
    }

    public function value(): int { return $this->value; }
}
