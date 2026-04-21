<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class LocaleNegotiator
{
    /**
     * @param array<string, mixed> $server $_SERVER superglobal (or equivalent)
     * @param array<string, mixed> $query  $_GET superglobal (or equivalent)
     */
    public static function negotiate(
        array $server,
        array $query,
        SupportedLocale $default,
    ): SupportedLocale {
        // 1. Accept-Language
        $accept = isset($server['HTTP_ACCEPT_LANGUAGE']) && is_string($server['HTTP_ACCEPT_LANGUAGE'])
            ? $server['HTTP_ACCEPT_LANGUAGE']
            : '';
        if ($accept !== '') {
            foreach (explode(',', $accept) as $tag) {
                $parts = explode(';', $tag);
                $code = trim($parts[0]);
                if ($code === '' || $code === '*') {
                    continue;
                }
                $full = str_replace('-', '_', $code);
                if (SupportedLocale::isSupported($full)) {
                    return SupportedLocale::fromString($full);
                }
                // try short form
                $short = strtolower(substr($code, 0, 2));
                try {
                    return SupportedLocale::fromShort($short);
                } catch (InvalidLocaleException) {
                    continue;
                }
            }
        }

        // 2. Query param
        $lang = $query['lang'] ?? null;
        if (is_string($lang) && SupportedLocale::isSupported(str_replace('-', '_', $lang))) {
            return SupportedLocale::fromString($lang);
        }

        // 3. Custom header
        $custom = isset($server['HTTP_X_DAEMS_LOCALE']) && is_string($server['HTTP_X_DAEMS_LOCALE'])
            ? $server['HTTP_X_DAEMS_LOCALE']
            : '';
        if ($custom !== '' && SupportedLocale::isSupported(str_replace('-', '_', $custom))) {
            return SupportedLocale::fromString($custom);
        }

        // 4. Default
        return $default;
    }
}
