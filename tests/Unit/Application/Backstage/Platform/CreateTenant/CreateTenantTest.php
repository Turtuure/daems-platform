<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Platform\CreateTenant;

use Daems\Application\Backstage\Platform\CreateTenant\CreateTenant;
use Daems\Application\Backstage\Platform\CreateTenant\CreateTenantInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\ModuleRegistryFactory;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CreateTenantTest extends TestCase
{
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const NORMAL_ID = '01958000-0000-7000-8000-0000000000bb';

    /** @var list<string> */
    private array $tempDirs = [];

    private InMemoryTenantRepository $tenants;
    private InMemoryTenantModulesRepository $tenantModules;
    private InMemoryUserRepository $users;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->tenants       = new InMemoryTenantRepository();
        $this->tenantModules = new InMemoryTenantModulesRepository();
        $this->users         = new InMemoryUserRepository();
        $this->clock         = FrozenClock::at('2026-05-07T10:00:00+00:00');

        $this->seedUser(self::GSA_ID, 'gsa@test.local', isPlatformAdmin: true);
        $this->seedUser(self::NORMAL_ID, 'user@test.local', isPlatformAdmin: false);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_creates_tenant_with_provided_fields(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'core-mod', 'isCore' => true],
        ]);
        $this->tempDirs[] = $root;

        $uc = new CreateTenant($this->tenants, $this->tenantModules, $this->users, $registry, $this->clock);
        $output = $uc->execute(new CreateTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            slug: 'acme',
            displayNamesI18n: ['en_GB' => 'ACME Co', 'fi_FI' => 'ACME Oy'],
            publicDescriptionsI18n: ['en_GB' => 'Public.'],
            supportedLocales: ['en_GB', 'fi_FI'],
            defaultLocale: 'en_GB',
            memberNumberPrefix: 'ACME',
        ));

        $tenant = $this->tenants->findBySlug('acme');
        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->slug->value());
        self::assertSame('ACME Co', $tenant->displayName('en_GB'));
        self::assertSame('ACME Oy', $tenant->displayName('fi_FI'));
        self::assertSame(['en_GB', 'fi_FI'], $tenant->supportedLocales());
        self::assertSame('en_GB', $tenant->defaultLocale());
        self::assertSame('ACME', $tenant->memberNumberPrefix);
        self::assertTrue($tenant->id->equals($output->tenantId));
        self::assertSame('2026-05-07', $output->createdAt->format('Y-m-d'));
    }

    public function test_seeds_default_available_modules_with_available_at_set(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([
            ['name' => 'core-mod',  'isCore' => true],
            ['name' => 'events',    'defaultAvailable' => true],
            ['name' => 'projects',  'defaultAvailable' => true],
            ['name' => 'analytics', 'defaultAvailable' => false],
        ]);
        $this->tempDirs[] = $root;

        $uc = new CreateTenant($this->tenants, $this->tenantModules, $this->users, $registry, $this->clock);
        $output = $uc->execute($this->validInput('acme'));

        $rows = $this->tenantModules->findByTenant($output->tenantId);
        $slugs = array_map(static fn ($r) => $r->moduleSlug(), $rows);
        sort($slugs);
        self::assertSame(['events', 'projects'], $slugs); // core skipped, analytics skipped
        foreach ($rows as $row) {
            self::assertNotNull($row->availableAt());
            self::assertNull($row->enabledAt());
            $availableBy = $row->availableBy();
            self::assertNotNull($availableBy);
            self::assertSame(self::GSA_ID, $availableBy->value());
        }
    }

    public function test_rejects_duplicate_slug(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([]);
        $this->tempDirs[] = $root;

        $uc = new CreateTenant($this->tenants, $this->tenantModules, $this->users, $registry, $this->clock);
        $uc->execute($this->validInput('acme'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches("/already exists/i");
        $uc->execute($this->validInput('acme'));
    }

    public function test_rejects_non_platform_admin(): void
    {
        [$registry, $root] = ModuleRegistryFactory::build([]);
        $this->tempDirs[] = $root;

        $uc = new CreateTenant($this->tenants, $this->tenantModules, $this->users, $registry, $this->clock);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new CreateTenantInput(
            actingUserId: UserId::fromString(self::NORMAL_ID),
            slug: 'acme',
            displayNamesI18n: ['en_GB' => 'ACME'],
            publicDescriptionsI18n: [],
            supportedLocales: ['en_GB'],
            defaultLocale: 'en_GB',
            memberNumberPrefix: 'ACME',
        ));
    }

    private function validInput(string $slug): CreateTenantInput
    {
        return new CreateTenantInput(
            actingUserId: UserId::fromString(self::GSA_ID),
            slug: $slug,
            displayNamesI18n: ['en_GB' => 'ACME'],
            publicDescriptionsI18n: [],
            supportedLocales: ['en_GB'],
            defaultLocale: 'en_GB',
            memberNumberPrefix: 'ACME',
        );
    }

    private function seedUser(string $id, string $email, bool $isPlatformAdmin): void
    {
        $user = new User(
            id: UserId::fromString($id),
            name: 'Test ' . substr($id, -2),
            email: $email,
            passwordHash: 'hash',
            dateOfBirth: null,
            country: 'FI',
            membershipType: 'individual',
            membershipStatus: 'active',
            memberNumber: null,
            createdAt: '2026-01-01 00:00:00',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $this->users->save($user);
    }
}
