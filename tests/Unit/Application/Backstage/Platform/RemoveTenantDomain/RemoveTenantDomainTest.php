<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\RemoveTenantDomain;

use Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomain;
use Daems\Application\Backstage\Platform\RemoveTenantDomain\RemoveTenantDomainInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\TenantPrimaryDomainRequiredException;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RemoveTenantDomainTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantDomainRepository $domains;
    private InMemoryUserRepository $users;
    private RemoveTenantDomain $uc;

    protected function setUp(): void
    {
        $this->tenants = new InMemoryTenantRepository();
        $this->domains = new InMemoryTenantDomainRepository();
        $this->users   = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->uc = new RemoveTenantDomain($this->tenants, $this->domains, $this->users);
    }

    public function test_removes_a_non_primary_domain(): void
    {
        $this->seedDomain('a.example', isPrimary: true);
        $this->seedDomain('b.example', isPrimary: false);

        $this->uc->execute(new RemoveTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'b.example',
        ));
        self::assertNull($this->domains->find('b.example'));
    }

    public function test_rejects_removing_only_primary(): void
    {
        $this->seedDomain('a.example', isPrimary: true);
        $this->seedDomain('b.example', isPrimary: false);

        $this->expectException(TenantPrimaryDomainRequiredException::class);
        $this->uc->execute(new RemoveTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
        ));
    }

    public function test_can_remove_primary_when_another_exists(): void
    {
        $this->seedDomain('a.example', isPrimary: true);
        $this->seedDomain('b.example', isPrimary: true);

        $this->uc->execute(new RemoveTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
        ));
        self::assertNull($this->domains->find('a.example'));
        self::assertTrue($this->domains->find('b.example')?->isPrimary());
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->seedDomain('a.example', isPrimary: false);
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new RemoveTenantDomainInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
        ));
    }

    private function seedDomain(string $hostname, bool $isPrimary): void
    {
        $this->domains->add(TenantDomain::create(
            $hostname,
            TenantId::fromString(self::TENANT_ID),
            $isPrimary,
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
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
