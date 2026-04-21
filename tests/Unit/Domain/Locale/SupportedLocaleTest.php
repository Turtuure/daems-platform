<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\InvalidLocaleException;
use PHPUnit\Framework\TestCase;

final class SupportedLocaleTest extends TestCase
{
    public function testFromStringAcceptsFiFi(): void
    {
        $locale = SupportedLocale::fromString('fi_FI');
        $this->assertSame('fi_FI', $locale->value());
    }

    public function testFromStringAcceptsEnGb(): void
    {
        $this->assertSame('en_GB', SupportedLocale::fromString('en_GB')->value());
    }

    public function testFromStringAcceptsSwTz(): void
    {
        $this->assertSame('sw_TZ', SupportedLocale::fromString('sw_TZ')->value());
    }

    public function testFromStringNormalizesHyphen(): void
    {
        $this->assertSame('fi_FI', SupportedLocale::fromString('fi-FI')->value());
    }

    public function testFromStringRejectsUnsupported(): void
    {
        $this->expectException(InvalidLocaleException::class);
        SupportedLocale::fromString('de_DE');
    }

    public function testFromShortMapsToDefaultRegion(): void
    {
        $this->assertSame('fi_FI', SupportedLocale::fromShort('fi')->value());
        $this->assertSame('en_GB', SupportedLocale::fromShort('en')->value());
        $this->assertSame('sw_TZ', SupportedLocale::fromShort('sw')->value());
    }

    public function testAllReturnsSupportedList(): void
    {
        $values = array_map(fn($l) => $l->value(), SupportedLocale::all());
        $this->assertSame(['fi_FI', 'en_GB', 'sw_TZ'], $values);
    }

    public function testEqualsComparesValue(): void
    {
        $a = SupportedLocale::fromString('fi_FI');
        $b = SupportedLocale::fromString('fi-FI');
        $this->assertTrue($a->equals($b));
    }
}
