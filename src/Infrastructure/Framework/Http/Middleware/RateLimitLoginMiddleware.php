<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class RateLimitLoginMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
        private readonly int $maxFailures = 5,
        private readonly int $windowMinutes = 15,
        private readonly int $lockoutSeconds = 900,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->method() !== 'POST' || $request->uri() !== '/api/v1/auth/login') {
            return $next($request);
        }

        $email = trim((string) $request->input('email'));
        if ($email === '') {
            return $next($request);
        }

        $since = $this->clock->now()->modify("-{$this->windowMinutes} minutes");
        $fails = $this->attempts->countFailuresSince($request->clientIp(), $email, $since);

        if ($fails >= $this->maxFailures) {
            throw new TooManyRequestsException($this->lockoutSeconds, 'Too many login attempts. Try again later.');
        }

        return $next($request);
    }
}
