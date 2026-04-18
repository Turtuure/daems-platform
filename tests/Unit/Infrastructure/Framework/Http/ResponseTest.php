<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testUnauthorizedReturns401Json(): void
    {
        $r = Response::unauthorized('Authentication required.');
        $this->assertSame(401, $r->status());
        $this->assertStringContainsString('Authentication required.', $r->body());
    }

    public function testForbiddenReturns403Json(): void
    {
        $r = Response::forbidden();
        $this->assertSame(403, $r->status());
        $this->assertStringContainsString('Forbidden.', $r->body());
    }

    public function testTooManyRequestsSetsRetryAfterHeader(): void
    {
        $r = Response::tooManyRequests('Slow down.', 900);
        $this->assertSame(429, $r->status());
        $this->assertSame('900', $r->header('Retry-After'));
    }

    public function testJsonNullGivesEmptyBody(): void
    {
        $r = Response::json(null, 204);
        $this->assertSame(204, $r->status());
        $this->assertSame('', $r->body());
    }
}
