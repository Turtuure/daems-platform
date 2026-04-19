<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    public function testConstruction(): void
    {
        $t = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2026-04-19T10:00:00+00:00'),
        );

        $this->assertSame('daems',         $t->slug->value());
        $this->assertSame('Daems Society', $t->name);
    }
}
