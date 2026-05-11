<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\InvalidWidgetSpan;
use Daems\Domain\Dashboard\WidgetSpan;
use PHPUnit\Framework\TestCase;

final class WidgetSpanTest extends TestCase
{
    public function test_accepts_1_to_4(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            self::assertSame($n, WidgetSpan::of($n)->value());
        }
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(InvalidWidgetSpan::class);
        WidgetSpan::of(0);
    }

    public function test_rejects_five(): void
    {
        $this->expectException(InvalidWidgetSpan::class);
        WidgetSpan::of(5);
    }
}
