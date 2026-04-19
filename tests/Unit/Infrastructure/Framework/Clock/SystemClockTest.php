<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Clock;

use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testImplementsClockInterface(): void
    {
        $this->assertInstanceOf(Clock::class, new SystemClock());
    }

    public function testNowReturnsCurrentTime(): void
    {
        $before = new DateTimeImmutable('now');
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable('now');

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }
}
