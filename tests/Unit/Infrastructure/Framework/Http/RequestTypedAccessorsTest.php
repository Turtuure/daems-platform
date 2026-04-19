<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTypedAccessorsTest extends TestCase
{
    // --- string() ---

    public function testStringReturnsBodyValueAsString(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['email' => 'a@b']);
        $this->assertSame('a@b', $req->string('email'));
    }

    public function testStringCastsNonStringScalar(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['id' => 42]);
        $this->assertSame('42', $req->string('id'));
    }

    public function testStringReturnsDefaultWhenMissing(): void
    {
        $req = Request::forTesting('POST', '/t');
        $this->assertSame('fallback', $req->string('missing', 'fallback'));
        $this->assertNull($req->string('missing'));
    }

    public function testStringReturnsDefaultWhenArrayOrNull(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['nested' => ['x' => 1], 'nothing' => null]);
        $this->assertNull($req->string('nested'));
        $this->assertNull($req->string('nothing'));
    }

    public function testStringReadsFromQueryWhenBodyMissing(): void
    {
        $req = Request::forTesting('GET', '/t', query: ['q' => 'hello']);
        $this->assertSame('hello', $req->string('q'));
    }

    // --- int() ---

    public function testIntReturnsIntFromNumeric(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['age' => '18', 'score' => 100]);
        $this->assertSame(18, $req->int('age'));
        $this->assertSame(100, $req->int('score'));
    }

    public function testIntReturnsDefaultWhenNotNumeric(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['x' => 'abc']);
        $this->assertSame(0, $req->int('x', 0));
        $this->assertNull($req->int('missing'));
    }

    // --- bool() ---

    public function testBoolTrueValues(): void
    {
        foreach (['1', 'true', 'on', 'yes', true, 1] as $truthy) {
            $req = Request::forTesting('POST', '/t', body: ['v' => $truthy]);
            $this->assertTrue($req->bool('v'), 'expected true for ' . var_export($truthy, true));
        }
    }

    public function testBoolFalseValues(): void
    {
        foreach (['0', 'false', 'off', 'no', false, 0] as $falsy) {
            $req = Request::forTesting('POST', '/t', body: ['v' => $falsy]);
            $this->assertFalse($req->bool('v'), 'expected false for ' . var_export($falsy, true));
        }
    }

    public function testBoolReturnsDefaultWhenMissing(): void
    {
        $req = Request::forTesting('POST', '/t');
        $this->assertFalse($req->bool('missing', false));
        $this->assertTrue($req->bool('missing', true));
        $this->assertNull($req->bool('missing'));
    }

    // --- arrayValue() ---

    public function testArrayValueReturnsArray(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['list' => [1, 2, 3]]);
        $this->assertSame([1, 2, 3], $req->arrayValue('list'));
    }

    public function testArrayValueReturnsNullWhenNotArray(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['scalar' => 'x']);
        $this->assertNull($req->arrayValue('scalar'));
        $this->assertNull($req->arrayValue('missing'));
    }
}
