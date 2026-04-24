<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlTenantRepositoryTest extends MigrationTestCase
{
    private SqlTenantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(57);
        $this->repo = $this->buildRepo();
    }

    private function buildRepo(): SqlTenantRepository
    {
        return new SqlTenantRepository($this->pdo());
    }

    public function testFindBySlugReturnsSeededTenant(): void
    {
        $t = $this->repo->findBySlug('daems');
        $this->assertNotNull($t);
        $this->assertSame('Daems Society', $t->name);
    }

    public function testFindBySlugReturnsNullForUnknown(): void
    {
        $this->assertNull($this->repo->findBySlug('nonexistent'));
    }

    public function testFindByDomainReturnsTenantForPrimaryDomain(): void
    {
        $t = $this->repo->findByDomain('daems.fi');
        $this->assertNotNull($t);
        $this->assertSame('daems', $t->slug->value());
    }

    public function testFindByDomainLowercasesInput(): void
    {
        $t = $this->repo->findByDomain('DAEMS.FI');
        $this->assertNotNull($t);
        $this->assertSame('daems', $t->slug->value());
    }

    public function testFindByDomainReturnsNullForUnknown(): void
    {
        $this->assertNull($this->repo->findByDomain('unknown.example'));
    }

    public function testFindById(): void
    {
        $id = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $t = $this->repo->findById($id);
        $this->assertNotNull($t);
        $this->assertSame('daems', $t->slug->value());
    }

    public function testFindAll(): void
    {
        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
        $slugs = array_map(fn ($t) => $t->slug->value(), $all);
        sort($slugs);
        $this->assertSame(['daems', 'sahegroup'], $slugs);
    }
}
