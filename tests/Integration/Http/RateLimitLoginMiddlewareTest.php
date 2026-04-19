<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RateLimitLoginMiddlewareTest extends TestCase
{
    public function testAllowsFirstFourFailures(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $mw = new RateLimitLoginMiddleware($repo, $clock, maxFailures: 5, windowMinutes: 15, lockoutSeconds: 900);

        for ($i = 0; $i < 4; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );

        $this->assertSame(200, $resp->status());
    }

    public function testThrowsTooManyAfterFiveFailures(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $this->expectException(TooManyRequestsException::class);
        $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
    }

    public function testDifferentIpNotAffected(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '2.2.2.2']),
            static fn(): Response => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    public function testPassesThroughNonLoginRoutes(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 10; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('GET', '/api/v1/status', [], [], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    public function testWindowExpires(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $past = new DateTimeImmutable('2026-04-19T11:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $past);
        }
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    /**
     * Boundary: a failure recorded exactly 1 second outside the 15-minute
     * window must NOT count. Regression for off-by-one in the window
     * arithmetic (e.g., flipping "minutes" to "seconds").
     */
    public function testFailuresJustOutsideWindowDoNotCount(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $now = new DateTimeImmutable('2026-04-19T12:00:00Z');
        // Record 5 failures at now - 15min - 1s (outside the window).
        $justOutside = $now->modify('-15 minutes -1 second');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $justOutside);
        }
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    /**
     * Boundary: a failure recorded exactly 1 second INSIDE the 15-minute
     * window must count. Pairs with testFailuresJustOutsideWindowDoNotCount.
     */
    public function testFailuresJustInsideWindowDoCount(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $now = new DateTimeImmutable('2026-04-19T12:00:00Z');
        $justInside = $now->modify('-14 minutes -59 seconds');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $justInside);
        }
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $this->expectException(TooManyRequestsException::class);
        $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
    }

    public function testEmptyEmailPassesThrough(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 100; $i++) {
            $repo->record('1.1.1.1', '', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => ''], [], ['REMOTE_ADDR' => '1.1.1.1']),
            static fn(): Response => Response::json(['ok' => true]),
        );
        // Empty email bypasses the counter — the login use case itself
        // returns 400 for empty email so rate-limiting it is pointless
        // and only serves to let attackers poison the counter.
        $this->assertSame(200, $resp->status());
    }
}
