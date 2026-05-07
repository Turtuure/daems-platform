<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\GetTenantDetail;

use Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail;
use Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetailInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class GetTenantDetailTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantDomainRepository $domains;
    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;
    private GetTenantDetail $uc;

    protected function setUp(): void
    {
        $this->tenants       = new InMemoryTenantRepository();
        $this->domains       = new InMemoryTenantDomainRepository();
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->userTenants   = new InMemoryUserTenantRepository();
        $this->users         = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->domains->add(TenantDomain::create(
            'acme.example',
            TenantId::fromString(self::TENANT_ID),
            true,
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));
        $this->uc = new GetTenantDetail(
            $this->tenants,
            $this->domains,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
        );
    }

    public function test_returns_full_detail(): void
    {
        $output = $this->uc->execute(new GetTenantDetailInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
        $d = $output->detail;
        self::assertSame('acme', $d['slug']);
        self::assertSame('active', $d['status']);
        self::assertCount(1, $d['domains']);
        self::assertSame('acme.example', $d['domains'][0]['hostname']);
        self::assertTrue($d['domains'][0]['isPrimary']);
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new GetTenantDetailInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
        ));
    }

    public function test_rejects_unknown_tenant(): void
    {
        $this->expectException(DomainException::class);
        $this->uc->execute(new GetTenantDetailInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString('01958000-0000-7000-8000-00000000ffff'),
        ));
    }

    private function seedUser(string $id, bool $isPlatformAdmin): void
    {
        $user = new User(
            id: UserId::fromString($id),
            name: 'Test',
            email: 'u-' . substr($id, -4) . '@test.local',
            passwordHash: 'hash',
            dateOfBirth: null,
            country: 'FI',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $this->users->save($user);
    }
}
