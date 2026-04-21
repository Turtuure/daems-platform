<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\LocaleNegotiator;
use Daems\Domain\Locale\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class LocaleNegotiatorTest extends TestCase
{
    public function testPrefersAcceptLanguageOverQuery(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9'],
            query: ['lang' => 'sw_TZ'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testQueryParamOverridesWhenAcceptLanguageAbsent(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: ['lang' => 'sw_TZ'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testCustomHeaderLastResortBeforeDefault(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_X_DAEMS_LOCALE' => 'fi_FI'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('fi_FI', $locale->value());
    }

    public function testDefaultWhenAllMissing(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testAcceptLanguageShortFormMaps(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'sw'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testAcceptLanguageSkipsUnsupportedAndTakesNextSupported(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,fr;q=0.9,sw;q=0.8'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testInvalidQueryParamIgnored(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: ['lang' => 'de_DE'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testInvalidCustomHeaderIgnored(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_X_DAEMS_LOCALE' => 'de_DE'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }
}
