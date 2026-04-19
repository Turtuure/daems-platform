<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantIdTest extends TestCase
{
    public function testAcceptsValidUuidV7(): void
    {
        $id = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->assertSame('01958000-0000-7000-8000-000000000001', $id->value());
    }

    public function testRejectsInvalidUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('not-a-uuid');
    }

    public function testEquals(): void
    {
        $a = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $b = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $c = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testGenerate(): void
    {
        $id = TenantId::generate();
        $this->assertSame(36, strlen($id->value()));
    }
}
