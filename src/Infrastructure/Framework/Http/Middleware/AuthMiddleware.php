<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\AuthenticateToken\AuthenticateTokenInput;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticateToken $authenticate) {}

    public function process(Request $request, callable $next): Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            throw new UnauthorizedException();
        }

        $out = $this->authenticate->execute(new AuthenticateTokenInput($raw));
        if ($out->actingUser === null) {
            throw new UnauthorizedException();
        }

        return $next($request->withActingUser($out->actingUser));
    }
}
