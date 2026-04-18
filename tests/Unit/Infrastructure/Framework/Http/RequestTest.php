<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private function make(array $headers = [], array $server = []): Request
    {
        return Request::forTesting('POST', '/x', [], [], $headers, $server);
    }

    public function testActingUserDefaultsToNull(): void
    {
        $this->assertNull($this->make()->actingUser());
    }

    public function testWithActingUserReturnsNewInstanceCarryingUser(): void
    {
        $req = $this->make();
        $acting = new ActingUser(UserId::generate(), 'registered');
        $req2 = $req->withActingUser($acting);

        $this->assertNull($req->actingUser());
        $this->assertSame($acting, $req2->actingUser());
    }

    public function testBearerTokenExtractedFromAuthorizationHeader(): void
    {
        $req = $this->make(['Authorization' => 'Bearer abc123']);
        $this->assertSame('abc123', $req->bearerToken());
    }

    public function testBearerTokenNullWhenHeaderMissing(): void
    {
        $this->assertNull($this->make()->bearerToken());
    }

    public function testBearerTokenNullWhenSchemeNotBearer(): void
    {
        $this->assertNull($this->make(['Authorization' => 'Basic xyz'])->bearerToken());
    }

    public function testBearerTokenNullWhenTokenEmpty(): void
    {
        $this->assertNull($this->make(['Authorization' => 'Bearer '])->bearerToken());
    }

    public function testClientIpFromRemoteAddr(): void
    {
        $req = $this->make([], ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', $req->clientIp());
    }

    public function testClientIpDefaultsWhenAbsent(): void
    {
        $this->assertSame('0.0.0.0', $this->make()->clientIp());
    }
}
