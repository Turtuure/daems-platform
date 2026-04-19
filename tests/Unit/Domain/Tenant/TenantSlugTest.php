<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantSlug;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantSlugTest extends TestCase
{
    public function testAcceptsValidSlugs(): void
    {
        $this->assertSame('daems',     TenantSlug::fromString('daems')->value());
        $this->assertSame('sahegroup', TenantSlug::fromString('sahegroup')->value());
        $this->assertSame('foo-bar',   TenantSlug::fromString('foo-bar')->value());
        $this->assertSame('abc123',    TenantSlug::fromString('abc123')->value());
    }

    public function testRejectsUppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('Daems');
    }

    public function testRejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('ab');
    }

    public function testRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString(str_repeat('a', 65));
    }

    public function testRejectsSpecialChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('foo_bar');
    }

    public function testEquals(): void
    {
        $this->assertTrue(TenantSlug::fromString('daems')->equals(TenantSlug::fromString('daems')));
        $this->assertFalse(TenantSlug::fromString('daems')->equals(TenantSlug::fromString('sahegroup')));
    }
}
