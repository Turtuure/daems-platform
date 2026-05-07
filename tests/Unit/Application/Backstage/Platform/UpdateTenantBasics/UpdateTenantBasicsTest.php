<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\UpdateTenantBasics;

use Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics;
use Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasicsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\Exception\TenantSlugImmutableException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UpdateTenantBasicsTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';

    private InMemoryTenantRepository $tenants;
    private InMemoryUserRepository $users;
    private UpdateTenantBasics $uc;

    protected function setUp(): void
    {
        $this->tenants = new InMemoryTenantRepository();
        $this->users   = new InMemoryUserRepository();
        $this->seedUser(self::GSA_ID, true);
        $this->seedUser(self::NORMAL_ID, false);
        $this->tenants->seedTenant(TenantId::fromString(self::TENANT_ID), 'acme', 'ACME');
        $this->uc = new UpdateTenantBasics($this->tenants, $this->users);
    }

    public function test_updates_editable_fields(): void
    {
        $this->uc->execute($this->validInput(slug: 'acme', defaultLocale: 'fi_FI', prefix: 'NEW'));

        $tenant = $this->tenants->findById(TenantId::fromString(self::TENANT_ID));
        self::assertNotNull($tenant);
        self::assertSame('NEW', $tenant->memberNumberPrefix);
        self::assertSame('fi_FI', $tenant->defaultLocale());
        self::assertSame('Renamed', $tenant->displayName('en_GB'));
    }

    public function test_rejects_slug_change(): void
    {
        $this->expectException(TenantSlugImmutableException::class);
        $this->uc->execute($this->validInput(slug: 'evil-rename'));
    }

    public function test_rejects_non_platform_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc->execute(new UpdateTenantBasicsInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            slug: 'acme',
            displayNamesI18n: ['en_GB' => 'X'],
            publicDescriptionsI18n: [],
            supportedLocales: ['en_GB'],
            defaultLocale: 'en_GB',
            memberNumberPrefix: 'X',
        ));
    }

    public function test_rejects_unknown_tenant(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->uc->execute(new UpdateTenantBasicsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString('01958000-0000-7000-8000-00000000ffff'),
            slug: 'whatever',
            displayNamesI18n: ['en_GB' => 'X'],
            publicDescriptionsI18n: [],
            supportedLocales: ['en_GB'],
            defaultLocale: 'en_GB',
            memberNumberPrefix: 'X',
        ));
    }

    private function validInput(string $slug, string $defaultLocale = 'en_GB', string $prefix = 'NEW'): UpdateTenantBasicsInput
    {
        return new UpdateTenantBasicsInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            tenantId: TenantId::fromString(self::TENANT_ID),
            slug: $slug,
            displayNamesI18n: ['en_GB' => 'Renamed'],
            publicDescriptionsI18n: [],
            supportedLocales: ['en_GB', 'fi_FI'],
            defaultLocale: $defaultLocale,
            memberNumberPrefix: $prefix,
        );
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
