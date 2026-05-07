<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Tests\Support\Fake;

use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class InMemoryTenantDomainRepositoryTest extends TestCase
{
    public function test_add_then_find_round_trips(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantId = TenantId::generate();

        $domain = TenantDomain::create('newco.fi', $tenantId, true, new DateTimeImmutable());
        $repo->add($domain);

        $found = $repo->find('newco.fi');
        self::assertNotNull($found);
        self::assertSame('newco.fi', $found->id());
        self::assertTrue($found->isPrimary());
    }

    public function test_add_rejects_duplicate_hostname(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantId = TenantId::generate();
        $now = new DateTimeImmutable();

        $repo->add(TenantDomain::create('newco.fi', $tenantId, true, $now));
        $this->expectException(InvalidArgumentException::class);
        $repo->add(TenantDomain::create('newco.fi', $tenantId, false, $now));
    }

    public function test_findByTenant_filters_to_owner(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();
        $now = new DateTimeImmutable();

        $repo->add(TenantDomain::create('a-1.fi', $tenantA, true,  $now));
        $repo->add(TenantDomain::create('a-2.fi', $tenantA, false, $now));
        $repo->add(TenantDomain::create('b-1.fi', $tenantB, true,  $now));

        self::assertCount(2, $repo->findByTenant($tenantA));
        self::assertCount(1, $repo->findByTenant($tenantB));
    }

    public function test_update_rejects_unknown_id(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantId = TenantId::generate();
        $domain = TenantDomain::create('ghost.fi', $tenantId, false, new DateTimeImmutable());

        $this->expectException(OutOfBoundsException::class);
        $repo->update($domain);
    }

    public function test_remove_deletes_by_id(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantId = TenantId::generate();
        $repo->add(TenantDomain::create('gone.fi', $tenantId, false, new DateTimeImmutable()));

        $repo->remove('gone.fi');
        self::assertNull($repo->find('gone.fi'));
    }

    public function test_setPrimary_atomically_swaps_the_primary(): void
    {
        $repo = new InMemoryTenantDomainRepository();
        $tenantId = TenantId::generate();
        $now = new DateTimeImmutable();

        $repo->add(TenantDomain::create('one.fi', $tenantId, true,  $now));
        $repo->add(TenantDomain::create('two.fi', $tenantId, false, $now));
        $repo->add(TenantDomain::create('three.fi', $tenantId, false, $now));

        $repo->setPrimary($tenantId, 'two.fi');

        $one = $repo->find('one.fi');
        $two = $repo->find('two.fi');
        $three = $repo->find('three.fi');
        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotNull($three);
        self::assertFalse($one->isPrimary());
        self::assertTrue($two->isPrimary());
        self::assertFalse($three->isPrimary());
    }
}
