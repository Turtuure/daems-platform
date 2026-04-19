<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support;

use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testReturnsFrozenTime(): void
    {
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $first = $clock->now();
        $this->assertSame($first->getTimestamp(), $clock->now()->getTimestamp());
    }

    public function testAdvance(): void
    {
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $clock->advance('+1 hour');
        $this->assertSame('2026-04-19T13:00:00+00:00', $clock->now()->format('c'));
    }
}
