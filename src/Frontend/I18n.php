<?php

declare(strict_types=1);

namespace Daems\Frontend;

/**
 * Minimal static i18n for the backstage UI.
 *
 * Full-locale form (fi_FI, en_GB, sw_TZ) matches the platform API's
 * Daems\Domain\Locale\SupportedLocale. The platform-wide UI chrome default
 * is en_GB, aligned with the backend's content fallback. Tenants may
 * override via tenants.default_locale (e.g. Daem Society stays on fi_FI).
 *
 * Locale resolution (first match wins):
 *   1. $_GET['lang'] on the current request — also sets a cookie + session.
 *   2. $_SESSION['lang'] from a previous request.
 *   3. $_COOKIE['daems_lang'] across browser tabs.
 *   4. The first of the supported locales found in the
 *      Accept-Language header (supports fi, fi-FI, fi_FI forms).
 *   5. The current tenant's default locale (set via setTenantDefault()
 *      from sites-router.php or backstage's _module-guard.php).
 *   6. Platform default: 'en_GB'.
 *
 * Translation files live in /lang/<code>.php at the platform root and
 * return a flat associative array keyed by dotted strings.
 */
final class I18n
{
    public const SUPPORTED = ['fi_FI', 'en_GB', 'sw_TZ'];
    public const DEFAULT_LOCALE = 'en_GB';
    public const CONTENT_FALLBACK = 'en_GB';

    /** Maps short 2-letter codes to full-locale form. */
    private const LEGACY_MAP = [
        'fi' => 'fi_FI',
        'en' => 'en_GB',
        'sw' => 'sw_TZ',
    ];

    private static ?string $locale = null;

    /**
     * Locale to fall back to BEFORE the platform-wide default. Set by the
     * front-controllers (sites-router.php, backstage/_module-guard.php) once
     * the request's tenant has been resolved, so an anonymous visitor with
     * no Accept-Language header lands on the tenant's preferred language
     * rather than the platform default. NULL = no tenant default known.
     */
    private static ?string $tenantDefaultLocale = null;

    /** @var array<string, array<string, string>> */
    private static array $dict = [];

    /**
     * Pin the current request's tenant-default locale.
     *
     * Pass NULL or an unsupported value to clear (which falls back to the
     * platform-wide DEFAULT_LOCALE). Callers should constrain the input to
     * the tenant's supportedLocales() before passing it in — this method
     * itself only checks against the platform's SUPPORTED list as a final
     * defensive guard.
     */
    public static function setTenantDefault(?string $locale): void
    {
        if ($locale === null) {
            self::$tenantDefaultLocale = null;
            return;
        }
        $norm = self::normalize($locale);
        if ($norm === null) {
            self::$tenantDefaultLocale = null;
            return;
        }
        self::$tenantDefaultLocale = $norm;
    }

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        // 1. Explicit ?lang= override
        $get = $_GET['lang'] ?? null;
        if (is_string($get)) {
            $norm = self::normalize($get);
            if ($norm !== null) {
                self::$locale = $norm;
                $_SESSION['lang'] = $norm;
                setcookie('daems_lang', $norm, [
                    'expires'  => time() + 60 * 60 * 24 * 365,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);
                return self::$locale;
            }
        }

        // 2. Session (remap legacy 2-letter values on read)
        $sess = $_SESSION['lang'] ?? null;
        if (is_string($sess)) {
            $norm = self::normalize($sess);
            if ($norm !== null) {
                if ($norm !== $sess) {
                    $_SESSION['lang'] = $norm;
                }
                return self::$locale = $norm;
            }
        }

        // 3. Cookie (remap legacy 2-letter values on read)
        $cookie = $_COOKIE['daems_lang'] ?? null;
        if (is_string($cookie)) {
            $norm = self::normalize($cookie);
            if ($norm !== null) {
                if ($norm !== $cookie) {
                    setcookie('daems_lang', $norm, [
                        'expires'  => time() + 60 * 60 * 24 * 365,
                        'path'     => '/',
                        'samesite' => 'Lax',
                    ]);
                }
                return self::$locale = $norm;
            }
        }

        // 4. Accept-Language header — first supported tag wins.
        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($accept !== '') {
            foreach (explode(',', $accept) as $tag) {
                $code = trim(explode(';', $tag)[0]);
                if ($code === '' || $code === '*') {
                    continue;
                }
                $norm = self::normalize($code);
                if ($norm !== null) {
                    return self::$locale = $norm;
                }
            }
        }

        // 5. Tenant default — set by sites-router.php or backstage's
        // _module-guard.php once the request's tenant has been resolved.
        if (self::$tenantDefaultLocale !== null) {
            return self::$locale = self::$tenantDefaultLocale;
        }

        // 6. Platform default.
        return self::$locale = self::DEFAULT_LOCALE;
    }

    private static function normalize(string $input): ?string
    {
        $trim = trim($input);
        if ($trim === '') {
            return null;
        }
        $underscored = str_replace('-', '_', $trim);
        if (strpos($underscored, '_') !== false) {
            [$l, $r] = explode('_', $underscored, 2);
            $underscored = strtolower($l) . '_' . strtoupper($r);
        } else {
            $underscored = strtolower($underscored);
        }
        if (in_array($underscored, self::SUPPORTED, true)) {
            return $underscored;
        }
        $short = strtolower(substr($underscored, 0, 2));
        return self::LEGACY_MAP[$short] ?? null;
    }

    /**
     * Translate the given key. Falls back to default locale, then key itself.
     *
     * @param array<string, string|int> $params Replacements for {placeholders}.
     */
    public static function t(string $key, array $params = []): string
    {
        $loc = self::locale();
        $dict = self::load($loc);
        $value = $dict[$key] ?? null;
        if ($value === null && $loc !== self::DEFAULT_LOCALE) {
            $value = self::load(self::DEFAULT_LOCALE)[$key] ?? null;
        }
        if ($value === null) {
            return $key;
        }
        if ($params === []) {
            return $value;
        }
        $search  = array_map(static fn($k) => '{' . $k . '}', array_keys($params));
        $replace = array_map('strval', array_values($params));
        return str_replace($search, $replace, $value);
    }

    public static function e(string $key, array $params = []): string
    {
        return htmlspecialchars(self::t($key, $params), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Merge an extra dictionary into the named locale's in-memory cache.
     *
     * Used by site-local lang loaders (e.g. public/sites/_default/lang/<loc>.php)
     * that need to add page-specific keys without modifying the platform-wide
     * lang/<loc>.php files. Existing keys are preserved unless $overwrite is true.
     *
     * @param array<string, string> $extra
     */
    public static function merge(string $locale, array $extra, bool $overwrite = false): void
    {
        $existing = self::load($locale);
        self::$dict[$locale] = $overwrite
            ? array_merge($existing, $extra)
            : array_merge($extra, $existing);
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        if (isset(self::$dict[$locale])) {
            return self::$dict[$locale];
        }
        // I18n.php sits at daems-platform/src/Frontend/I18n.php; lang/ is at platform root.
        $path = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
        if (!file_exists($path)) {
            return self::$dict[$locale] = [];
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        return self::$dict[$locale] = is_array($loaded) ? $loaded : [];
    }
}
