<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Shared;

use Daems\Domain\Shared\ValueObject\Uuid7;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Uuid7Test extends TestCase
{
    public function testGenerateReturnsValidUuid7(): void
    {
        $uuid = Uuid7::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid->value(),
        );
    }

    public function testGenerateReturnsDifferentValuesEachCall(): void
    {
        $a = Uuid7::generate();
        $b = Uuid7::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid7(): void
    {
        $raw = '019681ab-cdef-7abc-89ab-0123456789ab';
        $uuid = Uuid7::fromString($raw);

        $this->assertSame($raw, $uuid->value());
    }

    public function testFromStringNormalisesToLowercase(): void
    {
        $uuid = Uuid7::fromString('019681AB-CDEF-7ABC-89AB-0123456789AB');

        $this->assertSame('019681ab-cdef-7abc-89ab-0123456789ab', $uuid->value());
    }

    public function testFromStringThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uuid7::fromString('not-a-uuid');
    }

    public function testFromStringThrowsOnUuid4(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Version nibble is 4, not 7
        Uuid7::fromString('019681ab-cdef-4abc-89ab-0123456789ab');
    }

    public function testToStringMatchesValue(): void
    {
        $uuid = Uuid7::generate();

        $this->assertSame($uuid->value(), (string) $uuid);
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $raw  = '019681ab-cdef-7abc-89ab-0123456789ab';
        $uuid = Uuid7::fromString($raw);

        $this->assertTrue($uuid->equals(Uuid7::fromString($raw)));
    }
}
