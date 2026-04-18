<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\AuthorizationException;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testUnauthorizedIsAuthorizationException(): void
    {
        $this->assertInstanceOf(AuthorizationException::class, new UnauthorizedException());
    }

    public function testForbiddenIsAuthorizationException(): void
    {
        $this->assertInstanceOf(AuthorizationException::class, new ForbiddenException());
    }

    public function testTooManyRequestsCarriesRetryAfter(): void
    {
        $e = new TooManyRequestsException(900);
        $this->assertSame(900, $e->retryAfter);
    }

    public function testUnauthorizedDefaultMessage(): void
    {
        $this->assertSame('Authentication required.', (new UnauthorizedException())->getMessage());
    }

    public function testForbiddenDefaultMessage(): void
    {
        $this->assertSame('Forbidden.', (new ForbiddenException())->getMessage());
    }
}
