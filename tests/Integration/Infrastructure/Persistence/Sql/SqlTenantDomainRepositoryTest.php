<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantDomainRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

/**
 * Integration tests for SqlTenantDomainRepository against tenant_domains
 * (migration 019). Runs migrations through 072 to keep alignment with the
 * post-Wave-D schema.
 */
final class SqlTenantDomainRepositoryTest extends MigrationTestCase
{
    private SqlTenantDomainRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(72);

        $this->repo = new SqlTenantDomainRepository($this->pdo());

        $this->tenantId = TenantId::generate();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId->value(), 'dom-test', 'DomainTest']);
    }

    public function test_add_then_find_round_trip(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->add(TenantDomain::create('newco-test.fi', $this->tenantId, true, $now));

        $found = $this->repo->find('newco-test.fi');
        self::assertNotNull($found);
        self::assertSame('newco-test.fi', $found->id());
        self::assertTrue($found->isPrimary());
    }

    public function test_findByTenant_orders_primary_first_then_alpha(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->add(TenantDomain::create('z.example',  $this->tenantId, false, $now));
        $this->repo->add(TenantDomain::create('a.example',  $this->tenantId, false, $now));
        $this->repo->add(TenantDomain::create('main.example', $this->tenantId, true, $now));

        $rows = $this->repo->findByTenant($this->tenantId);
        self::assertCount(3, $rows);
        self::assertSame('main.example', $rows[0]->id());
        self::assertTrue($rows[0]->isPrimary());
        self::assertSame('a.example', $rows[1]->id());
        self::assertSame('z.example', $rows[2]->id());
    }

    public function test_remove_deletes_the_row(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->add(TenantDomain::create('gone.example', $this->tenantId, false, $now));
        $this->repo->remove('gone.example');
        self::assertNull($this->repo->find('gone.example'));
    }

    public function test_setPrimary_atomically_swaps_within_a_tenant(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');
        $this->repo->add(TenantDomain::create('one.example',   $this->tenantId, true,  $now));
        $this->repo->add(TenantDomain::create('two.example',   $this->tenantId, false, $now));
        $this->repo->add(TenantDomain::create('three.example', $this->tenantId, false, $now));

        $this->repo->setPrimary($this->tenantId, 'two.example');

        $one   = $this->repo->find('one.example');
        $two   = $this->repo->find('two.example');
        $three = $this->repo->find('three.example');
        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotNull($three);
        self::assertFalse($one->isPrimary());
        self::assertTrue($two->isPrimary());
        self::assertFalse($three->isPrimary());
    }

    public function test_setPrimary_only_demotes_within_same_tenant(): void
    {
        $now = new DateTimeImmutable('2026-05-07 09:00:00');

        // Other tenant with its own primary
        $otherTenant = TenantId::generate();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$otherTenant->value(), 'other-dom-test', 'OtherTenant']);
        $this->repo->add(TenantDomain::create('other-primary.example', $otherTenant, true, $now));

        // Our tenant
        $this->repo->add(TenantDomain::create('mine-1.example', $this->tenantId, true,  $now));
        $this->repo->add(TenantDomain::create('mine-2.example', $this->tenantId, false, $now));

        $this->repo->setPrimary($this->tenantId, 'mine-2.example');

        $other = $this->repo->find('other-primary.example');
        self::assertNotNull($other);
        self::assertTrue($other->isPrimary(), 'other tenant primary must remain untouched');
    }
}
