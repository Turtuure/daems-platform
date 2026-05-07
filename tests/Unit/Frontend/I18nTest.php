<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Frontend;

use Daems\Frontend\I18n;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locale-resolution unit tests for the static I18n facade.
 *
 * The class is static + process-global, so each test resets:
 *   - I18n::$locale (private static)
 *   - I18n::$tenantDefaultLocale (private static)
 *   - $_GET['lang'], $_SESSION['lang'], $_COOKIE['daems_lang'],
 *     $_SERVER['HTTP_ACCEPT_LANGUAGE']
 */
final class I18nTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetI18nStatics();
        unset(
            $_GET['lang'],
            $_SESSION['lang'],
            $_COOKIE['daems_lang'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
        );
    }

    protected function tearDown(): void
    {
        $this->resetI18nStatics();
        unset(
            $_GET['lang'],
            $_SESSION['lang'],
            $_COOKIE['daems_lang'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
        );
    }

    private function resetI18nStatics(): void
    {
        $ref = new ReflectionClass(I18n::class);
        $locale = $ref->getProperty('locale');
        $locale->setAccessible(true);
        $locale->setValue(null, null);
        $tenantDefault = $ref->getProperty('tenantDefaultLocale');
        $tenantDefault->setAccessible(true);
        $tenantDefault->setValue(null, null);
    }

    public function test_default_locale_when_no_signals_present(): void
    {
        // Truly empty environment falls through to platform DEFAULT_LOCALE.
        self::assertSame(I18n::DEFAULT_LOCALE, I18n::locale());
    }

    public function test_tenant_default_used_when_no_other_signals(): void
    {
        I18n::setTenantDefault('fi_FI');

        self::assertSame('fi_FI', I18n::locale());
    }

    public function test_tenant_default_wins_over_platform_default(): void
    {
        I18n::setTenantDefault('sw_TZ');

        // Platform default is en_GB; tenant default beats it.
        self::assertNotSame(I18n::DEFAULT_LOCALE, I18n::locale());
        self::assertSame('sw_TZ', I18n::locale());
    }

    public function test_get_lang_query_wins_over_tenant_default(): void
    {
        I18n::setTenantDefault('fi_FI');
        $_GET['lang'] = 'sw_TZ';

        self::assertSame('sw_TZ', I18n::locale());
    }

    public function test_session_wins_over_tenant_default(): void
    {
        I18n::setTenantDefault('fi_FI');
        $_SESSION['lang'] = 'sw_TZ';

        self::assertSame('sw_TZ', I18n::locale());
    }

    public function test_cookie_wins_over_tenant_default(): void
    {
        I18n::setTenantDefault('fi_FI');
        $_COOKIE['daems_lang'] = 'sw_TZ';

        self::assertSame('sw_TZ', I18n::locale());
    }

    public function test_accept_language_wins_over_tenant_default(): void
    {
        I18n::setTenantDefault('fi_FI');
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'sw-TZ,en;q=0.5';

        self::assertSame('sw_TZ', I18n::locale());
    }

    public function test_set_tenant_default_normalises_short_codes(): void
    {
        // 'fi' is a 2-letter legacy code that maps to 'fi_FI'.
        I18n::setTenantDefault('fi');

        self::assertSame('fi_FI', I18n::locale());
    }

    public function test_set_tenant_default_with_unsupported_value_clears_it(): void
    {
        // A bogus locale (not in SUPPORTED, not in LEGACY_MAP) is rejected
        // and treated as "no tenant default" — fallthrough to platform default.
        I18n::setTenantDefault('xx_YY');

        self::assertSame(I18n::DEFAULT_LOCALE, I18n::locale());
    }

    public function test_set_tenant_default_with_null_clears_it(): void
    {
        I18n::setTenantDefault('fi_FI');
        I18n::setTenantDefault(null);

        // Reset cached resolved locale so the 2nd call re-resolves.
        $ref = new ReflectionClass(I18n::class);
        $locale = $ref->getProperty('locale');
        $locale->setAccessible(true);
        $locale->setValue(null, null);

        self::assertSame(I18n::DEFAULT_LOCALE, I18n::locale());
    }
}
