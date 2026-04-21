<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Infrastructure\Framework\Http\Middleware\LocaleMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

final class LocaleMiddlewareTest extends TestCase
{
    public function testAttachesAcceptLanguageLocale(): void
    {
        $mw = new LocaleMiddleware();
        $req = Request::forTesting('GET', '/', headers: ['Accept-Language' => 'en-GB,en;q=0.9']);

        $passed = null;
        $response = $mw->process($req, function (Request $r) use (&$passed): Response {
            $passed = $r;
            return Response::json([]);
        });

        $this->assertInstanceOf(Request::class, $passed);
        $locale = $passed->attribute('locale');
        $this->assertInstanceOf(SupportedLocale::class, $locale);
        $this->assertSame('en_GB', $locale->value());
    }

    public function testDefaultsToContentFallbackWhenMissing(): void
    {
        $mw = new LocaleMiddleware();
        $req = Request::forTesting('GET', '/');

        $passed = null;
        $mw->process($req, function (Request $r) use (&$passed): Response {
            $passed = $r;
            return Response::json([]);
        });

        $this->assertInstanceOf(Request::class, $passed);
        $locale = $passed->attribute('locale');
        $this->assertInstanceOf(SupportedLocale::class, $locale);
        $this->assertSame('en_GB', $locale->value());
    }

    public function testQueryParamWorksWhenAcceptAbsent(): void
    {
        $mw = new LocaleMiddleware();
        $req = Request::forTesting('GET', '/', query: ['lang' => 'sw_TZ']);

        $passed = null;
        $mw->process($req, function (Request $r) use (&$passed): Response {
            $passed = $r;
            return Response::json([]);
        });

        $this->assertInstanceOf(Request::class, $passed);
        $locale = $passed->attribute('locale');
        $this->assertInstanceOf(SupportedLocale::class, $locale);
        $this->assertSame('sw_TZ', $locale->value());
    }
}
