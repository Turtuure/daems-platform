<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\AddTenantDomain;

use Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomain;
use Daems\Application\Backstage\Platform\AddTenantDomain\AddTenantDomainInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AddTenantDomainTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantDomainRepository $domains;
    private InMemoryUserRepository $users;
    private AddTenantDomain $uc;

    protected function setUp(): void
    {
        $this->tenants = new InMemoryTenantRepository();
        $this->domains = new InMemoryTenantDomainRepository();
        $this->users   = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->uc = new AddTenantDomain(
            $this->tenants,
            $this->domains,
            $this->users,
            FrozenClock::at('2026-05-07T10:00:00+00:00'),
        );
    }

    public function test_adds_a_non_primary_domain(): void
    {
        $this->uc->execute(new AddTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            hostname: 'foo.example',
            isPrimary: false,
        ));
        $row = $this->domains->find('foo.example');
        self::assertNotNull($row);
        self::assertFalse($row->isPrimary());
    }

    public function test_adds_primary_demotes_existing_primary(): void
    {
        // Seed an existing primary first.
        $this->domains->add(TenantDomain::create(
            'old.example',
            TenantId::fromString(self::TENANT_ID),
            true,
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));

        $this->uc->execute(new AddTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            hostname: 'new.example',
            isPrimary: true,
        ));

        self::assertFalse($this->domains->find('old.example')?->isPrimary());
        self::assertTrue($this->domains->find('new.example')?->isPrimary());
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new AddTenantDomainInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            hostname: 'x.example',
            isPrimary: false,
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
