<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Session;

use Daems\Infrastructure\Framework\Session\ArraySession;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function testStringReturnsStoredString(): void
    {
        $s = new ArraySession(['name' => 'Sam']);
        $this->assertSame('Sam', $s->string('name'));
    }

    public function testStringReturnsDefaultWhenMissing(): void
    {
        $s = new ArraySession();
        $this->assertSame('x', $s->string('missing', 'x'));
        $this->assertNull($s->string('missing'));
    }

    public function testStringReturnsDefaultWhenNotString(): void
    {
        $s = new ArraySession(['n' => 42, 'a' => ['x']]);
        $this->assertSame('fallback', $s->string('n', 'fallback'));
        $this->assertNull($s->string('a'));
    }

    public function testArrayReturnsStoredArray(): void
    {
        $s = new ArraySession(['user' => ['id' => '1', 'name' => 'Sam']]);
        $this->assertSame(['id' => '1', 'name' => 'Sam'], $s->array('user'));
    }

    public function testArrayReturnsNullWhenNotArray(): void
    {
        $s = new ArraySession(['x' => 'scalar']);
        $this->assertNull($s->array('x'));
        $this->assertNull($s->array('missing'));
    }

    public function testSetStoresValue(): void
    {
        $s = new ArraySession();
        $s->set('k', 'v');
        $this->assertSame('v', $s->string('k'));
    }

    public function testUnsetRemovesKey(): void
    {
        $s = new ArraySession(['k' => 'v']);
        $s->unset('k');
        $this->assertNull($s->string('k'));
    }

    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $s = new ArraySession(['k' => null]);
        $this->assertTrue($s->has('k'));
        $this->assertFalse($s->has('missing'));
    }

    public function testIntReturnsIntFromNumericString(): void
    {
        $s = new ArraySession(['n' => '42']);
        $this->assertSame(42, $s->int('n'));
    }

    public function testBoolReturnsStoredBool(): void
    {
        $s = new ArraySession(['flag' => true]);
        $this->assertTrue($s->bool('flag'));
        $this->assertFalse($s->bool('missing', false));
    }
}
