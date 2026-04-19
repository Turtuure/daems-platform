<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Infrastructure\Tenant\HostTenantResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HostTenantResolverTest extends TestCase
{
    private TenantRepositoryInterface $repo;
    private Tenant $daemsTenant;

    protected function setUp(): void
    {
        $this->daemsTenant = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2026-01-01'),
        );

        $this->repo = new class ($this->daemsTenant) implements TenantRepositoryInterface {
            public function __construct(private readonly Tenant $daems) {}
            public function findById(TenantId $id): ?Tenant { return null; }
            public function findBySlug(string $slug): ?Tenant
            {
                return $slug === 'daems' ? $this->daems : null;
            }
            public function findByDomain(string $domain): ?Tenant
            {
                return $domain === 'daems.fi' ? $this->daems : null;
            }
            public function findAll(): array { return [$this->daems]; }
        };
    }

    public function testDbMatchReturnsTenant(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        $this->assertSame($this->daemsTenant, $r->resolve('daems.fi'));
    }

    public function testFallbackWhenDbMisses(): void
    {
        $r = new HostTenantResolver($this->repo, ['daem-society.local' => 'daems']);
        $this->assertSame($this->daemsTenant, $r->resolve('daem-society.local'));
    }

    public function testDbPrimaryOverFallback(): void
    {
        // Fallback says 'sahegroup' for daems.fi, but DB returns daems — DB wins.
        $r = new HostTenantResolver($this->repo, ['daems.fi' => 'sahegroup']);
        $this->assertSame($this->daemsTenant, $r->resolve('daems.fi'));
    }

    public function testUnknownHostReturnsNull(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        $this->assertNull($r->resolve('unknown.example'));
    }

    public function testCaseInsensitive(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        $this->assertSame($this->daemsTenant, $r->resolve('DAEMS.FI'));
    }

    public function testTrimsWhitespace(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        $this->assertSame($this->daemsTenant, $r->resolve('  daems.fi  '));
    }

    public function testEmptyHostReturnsNull(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        $this->assertNull($r->resolve(''));
        $this->assertNull($r->resolve('   '));
    }

    public function testFallbackWithUnknownSlugReturnsNull(): void
    {
        $r = new HostTenantResolver($this->repo, ['devhost.test' => 'nonexistent']);
        $this->assertNull($r->resolve('devhost.test'));
    }
}
