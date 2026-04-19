<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Domain\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $iso): self
    {
        return new self(new DateTimeImmutable($iso));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $next = $this->now->modify($modifier);
        if ($next === false) {
            throw new InvalidArgumentException("Invalid modifier: {$modifier}");
        }
        $this->now = $next;
    }
}
