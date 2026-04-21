<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Locale\LocaleNegotiator;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class LocaleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $accept = $request->header('Accept-Language') ?? '';
        $custom = $request->header('X-Daems-Locale') ?? '';
        $lang = $request->query('lang');

        $server = [
            'HTTP_ACCEPT_LANGUAGE' => $accept,
            'HTTP_X_DAEMS_LOCALE'  => $custom,
        ];
        $query = ['lang' => is_scalar($lang) ? (string) $lang : null];

        $locale = LocaleNegotiator::negotiate(
            server: $server,
            query: $query,
            default: SupportedLocale::contentFallback(),
        );

        $result = $next($request->withAttribute('locale', $locale));
        if (!$result instanceof Response) {
            throw new \RuntimeException('LocaleMiddleware next() did not return Response');
        }
        return $result;
    }
}
