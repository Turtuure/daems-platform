<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\ListTenants;

use Daems\Application\Backstage\Platform\ListTenants\ListTenants;
use Daems\Application\Backstage\Platform\ListTenants\ListTenantsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListTenantsTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const T1        = '01958000-0000-7000-8000-000000000001';
    private const T2        = '01958000-0000-7000-8000-000000000002';

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantDomainRepository $domains;
    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryUserTenantRepository $userTenants;
    private InMemoryUserRepository $users;
    private ListTenants $uc;

    protected function setUp(): void
    {
        $this->tenants       = new InMemoryTenantRepository();
        $this->domains       = new InMemoryTenantDomainRepository();
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->userTenants   = new InMemoryUserTenantRepository();
        $this->users         = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);

        $this->tenants->seedTenant(TenantId::fromString(self::T1), 'acme', 'ACME');
        $this->tenants->seedTenant(TenantId::fromString(self::T2), 'beta', 'BETA');
        $this->tenants->suspend(
            TenantId::fromString(self::T2),
            'unpaid',
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        );

        $this->domains->add(TenantDomain::create(
            'acme.example',
            TenantId::fromString(self::T1),
            true,
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));
        $this->userTenants->attach(
            UserId::fromString(self::NORMAL_ID),
            TenantId::fromString(self::T1),
            UserTenantRole::Admin,
        );
        $this->tenantModules->save(new TenantModule(
            id: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            tenantId: TenantId::fromString(self::T1),
            moduleSlug: 'events',
            availableAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            availableBy: UserId::fromString(self::GSA_ID),
            enabledAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
            enabledBy: UserId::fromString(self::GSA_ID),
            disabledAt: null,
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
        ));

        $this->uc = new ListTenants(
            $this->tenants,
            $this->domains,
            $this->tenantModules,
            $this->userTenants,
            $this->users,
        );
    }

    public function test_returns_all_tenants_with_counts(): void
    {
        $output = $this->uc->execute(new ListTenantsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
        ));

        self::assertCount(2, $output->tenants);
        $bySlug = [];
        foreach ($output->tenants as $row) {
            $bySlug[$row['slug']] = $row;
        }
        self::assertSame('active', $bySlug['acme']['status']);
        self::assertSame(1, $bySlug['acme']['domainsCount']);
        self::assertSame(1, $bySlug['acme']['adminsCount']);
        self::assertSame(1, $bySlug['acme']['modulesEnabled']);
        self::assertSame(1, $bySlug['acme']['modulesAvailable']);
        self::assertSame('suspended', $bySlug['beta']['status']);
        self::assertNotNull($bySlug['beta']['suspendedAt']);
    }

    public function test_filters_by_status(): void
    {
        $output = $this->uc->execute(new ListTenantsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            statusFilter: 'suspended',
        ));
        self::assertCount(1, $output->tenants);
        self::assertSame('beta', $output->tenants[0]['slug']);
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new ListTenantsInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
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
