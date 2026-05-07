<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\UpdateTenantDomain;

use Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomain;
use Daems\Application\Backstage\Platform\UpdateTenantDomain\UpdateTenantDomainInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantDomain;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantDomainRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UpdateTenantDomainTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantDomainRepository $domains;
    private InMemoryUserRepository $users;
    private UpdateTenantDomain $uc;

    protected function setUp(): void
    {
        $this->domains = new InMemoryTenantDomainRepository();
        $this->users   = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->domains->add(TenantDomain::create(
            'a.example',
            TenantId::fromString(self::TENANT_ID),
            true,
            new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        ));
        $this->domains->add(TenantDomain::create(
            'b.example',
            TenantId::fromString(self::TENANT_ID),
            false,
            new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
        ));
        $this->uc = new UpdateTenantDomain($this->domains, $this->users);
    }

    public function test_promotes_to_primary_demotes_other(): void
    {
        $this->uc->execute(new UpdateTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'b.example',
            hostname: null,
            isPrimary: true,
        ));
        self::assertTrue($this->domains->find('b.example')?->isPrimary());
        self::assertFalse($this->domains->find('a.example')?->isPrimary());
    }

    public function test_demotes_primary(): void
    {
        $this->uc->execute(new UpdateTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
            hostname: null,
            isPrimary: false,
        ));
        self::assertFalse($this->domains->find('a.example')?->isPrimary());
    }

    public function test_rejects_hostname_change(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/immutable/i');
        $this->uc->execute(new UpdateTenantDomainInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
            hostname: 'renamed.example',
            isPrimary: null,
        ));
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new UpdateTenantDomainInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            domainId: 'a.example',
            hostname: null,
            isPrimary: true,
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
