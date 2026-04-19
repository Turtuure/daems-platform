<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestAttributesTest extends TestCase
{
    public function testAttributeReturnsNullWhenNotSet(): void
    {
        $req = Request::forTesting('GET', '/test');
        $this->assertNull($req->attribute('missing'));
    }

    public function testWithAttributeReturnsNewInstance(): void
    {
        $req = Request::forTesting('GET', '/test');
        $req2 = $req->withAttribute('key', 'value');

        $this->assertNotSame($req, $req2);
        $this->assertNull($req->attribute('key'));
        $this->assertSame('value', $req2->attribute('key'));
    }

    public function testWithAttributePreservesOtherAttributes(): void
    {
        $req = Request::forTesting('GET', '/test')
            ->withAttribute('a', 1)
            ->withAttribute('b', 2);

        $this->assertSame(1, $req->attribute('a'));
        $this->assertSame(2, $req->attribute('b'));
    }

    public function testAttributeSupportsObjectValues(): void
    {
        $obj = new \stdClass();
        $req = Request::forTesting('GET', '/test')->withAttribute('obj', $obj);

        $this->assertSame($obj, $req->attribute('obj'));
    }

    public function testWithActingUserPreservesAttributes(): void
    {
        $actingUser = new ActingUser(UserId::generate(), 'registered');

        $req = Request::forTesting('GET', '/test')
            ->withAttribute('tenant', 'daems')
            ->withActingUser($actingUser);

        $this->assertSame('daems', $req->attribute('tenant'));
        $this->assertSame($actingUser, $req->actingUser());
    }
}
