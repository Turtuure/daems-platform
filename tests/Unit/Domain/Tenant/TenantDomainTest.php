<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantDomain;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantDomainTest extends TestCase
{
    public function testAcceptsCommonDomains(): void
    {
        $this->assertSame('daems.fi',           TenantDomain::fromString('daems.fi')->value());
        $this->assertSame('www.daems.fi',       TenantDomain::fromString('www.daems.fi')->value());
        $this->assertSame('localhost',          TenantDomain::fromString('localhost')->value());
        $this->assertSame('daem-society.local', TenantDomain::fromString('daem-society.local')->value());
    }

    public function testLowercases(): void
    {
        $this->assertSame('daems.fi', TenantDomain::fromString('DAEMS.FI')->value());
    }

    public function testTrims(): void
    {
        $this->assertSame('daems.fi', TenantDomain::fromString('  daems.fi  ')->value());
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantDomain::fromString('');
    }

    public function testRejectsInvalidSyntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantDomain::fromString('not a domain');
    }
}
