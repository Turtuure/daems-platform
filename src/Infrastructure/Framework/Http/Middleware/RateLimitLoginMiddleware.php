<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

/**
 * Fail-closed rate limiter for POST /api/v1/auth/login.
 *
 * Two budgets:
 *  - Primary: maxFailures per (ip, email) per windowMinutes — stops brute force
 *    against a specific account.
 *  - Secondary: maxFailuresPerIp per ip per windowMinutes — stops credential-stuffing
 *    from a single source across many emails. A 0 disables this budget.
 *
 * If the repository throws (DB down, etc.) the exception propagates up and the
 * request fails rather than silently bypassing the limiter — intentional
 * fail-closed behaviour, because "rate limiter is broken, let everyone in" is
 * exactly the wrong posture during a credential-stuffing incident.
 */
final class RateLimitLoginMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
        private readonly int $maxFailures = 5,
        private readonly int $windowMinutes = 15,
        private readonly int $lockoutSeconds = 900,
        private readonly int $maxFailuresPerIp = 20,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->method() !== 'POST' || $request->uri() !== '/api/v1/auth/login') {
            return $next($request);
        }

        $email = trim((string) $request->input('email'));
        if ($email === '') {
            // Empty email is rejected upstream as a 400 validation error.
            // Counting it here would let attackers poison the counter with
            // empty-email probes to lock out legitimate users.
            return $next($request);
        }

        $ip = $request->clientIp();
        $since = $this->clock->now()->modify("-{$this->windowMinutes} minutes");

        $failsForPair = $this->attempts->countFailuresSince($ip, $email, $since);
        if ($failsForPair >= $this->maxFailures) {
            throw new TooManyRequestsException($this->lockoutSeconds, 'Too many login attempts. Try again later.');
        }

        if ($this->maxFailuresPerIp > 0) {
            $failsForIp = $this->attempts->countFailuresByIpSince($ip, $since);
            if ($failsForIp >= $this->maxFailuresPerIp) {
                throw new TooManyRequestsException($this->lockoutSeconds, 'Too many login attempts. Try again later.');
            }
        }

        return $next($request);
    }
}
