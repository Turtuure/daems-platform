<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Tenant\ModuleRouteGuard;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use Daems\Tests\Support\ModuleRegistryFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ModuleRouteGuard end-to-end behaviour for the backstage front-controller.
 *
 * NOTE on dispatch path: backstage routing is a CGI-style script
 * (`public/backstage/router.php`), NOT a Kernel-mounted route — see
 * `public/backstage/_module-guard.php` for the integration. This test
 * therefore exercises the SAME service the script consumes
 * (`ModuleRouteGuard` resolved from the harness's container, with the
 * harness's TenantModulesRepositoryInterface as the state source) rather
 * than going through `$harness->kernel->handle()` for backstage paths.
 *
 * The two assertions that matter:
 *   1. Disabled module path → NOT_FOUND (script translates to 404).
 *   2. The guard is queryable WITHOUT an authenticated actor — proving it
 *      runs before any auth check would. We achieve this by NOT seeding
 *      a user/token at all and resolving the guard directly.
 *
 * For API paths (/api/v1/*), the kernel does dispatch through the router,
 * but the route guard is not yet a kernel middleware (Phase 2). Today the
 * behaviour mirrors backstage: the api-router.php script invokes the same
 * guard. The test therefore exercises both the backstage AND API paths
 * via the guard directly; the kernel-level test for API would be a no-op
 * because no middleware enforces it on the kernel side yet.
 */
final class ModuleRouteGuardE2ETest extends TestCase
{
    private KernelHarness $h;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-07T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            ModuleRegistryFactory::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function rebindRegistry(ModuleRegistry $registry): void
    {
        $this->h->container->bind(
            ModuleRegistry::class,
            static fn (): ModuleRegistry => $registry,
        );
        // The TenantModuleResolver singleton captures the registry on first
        // resolve; force re-creation so the test gets the fresh registry.
        $this->h->container->bind(
            \Daems\Domain\Tenant\TenantModuleResolver::class,
            fn (\Daems\Infrastructure\Framework\Container\Container $c): \Daems\Domain\Tenant\TenantModuleResolver
                => new \Daems\Domain\Tenant\TenantModuleResolver(
                    $c->make(ModuleRegistry::class),
                    $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
                ),
        );
        $this->h->container->bind(
            ModuleRouteGuard::class,
            fn (\Daems\Infrastructure\Framework\Container\Container $c): ModuleRouteGuard
                => new ModuleRouteGuard(
                    $c->make(ModuleRegistry::class),
                    $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
                ),
        );
    }

    private function seedRow(string $slug, ?DateTimeImmutable $enabledAt): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $tm = new TenantModule(
            id: TenantId::generate()->value(),
            tenantId: $this->h->testTenantId,
            moduleSlug: $slug,
            availableAt: $now,
            availableBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            enabledAt: $enabledAt,
            enabledBy: $enabledAt !== null ? UserId::fromString('01958000-0000-7000-8000-0000000000aa') : null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->h->tenantModules->save($tm);
    }

    private function buildForumRegistry(): ModuleRegistry
    {
        // Use ModuleRegistryFactory so the manifest carries proper route
        // prefixes — necessary for findOwnerOfPath() to claim the URL.
        [$registry, $tmpDir] = ModuleRegistryFactory::build([
            ['name' => 'forum', 'isCore' => false, 'defaultAvailable' => true],
        ]);
        $this->tempDirs[] = $tmpDir;
        return $registry;
    }

    public function test_backstage_path_returns_allow_when_module_enabled(): void
    {
        $this->rebindRegistry($this->buildForumRegistry());
        $this->seedRow('forum', enabledAt: new DateTimeImmutable('2026-05-07T10:00:00+00:00'));

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);

        self::assertSame(
            ModuleRouteGuard::ALLOW,
            $guard->authorize($this->h->testTenantId, '/backstage/forum'),
        );
        self::assertSame(
            ModuleRouteGuard::ALLOW,
            $guard->authorize($this->h->testTenantId, '/backstage/forum/threads/42'),
        );
    }

    public function test_backstage_path_returns_not_found_when_module_disabled(): void
    {
        $this->rebindRegistry($this->buildForumRegistry());
        // No row at all → DISABLED state.

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);

        self::assertSame(
            ModuleRouteGuard::NOT_FOUND,
            $guard->authorize($this->h->testTenantId, '/backstage/forum'),
        );
        self::assertSame(
            ModuleRouteGuard::NOT_FOUND,
            $guard->authorize($this->h->testTenantId, '/backstage/forum/threads/42'),
        );
    }

    public function test_backstage_path_returns_not_found_when_module_revoked(): void
    {
        $this->rebindRegistry($this->buildForumRegistry());
        // Available + enabled, then explicit revoke (avAt = null after).
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $tm = new TenantModule(
            id: TenantId::generate()->value(),
            tenantId: $this->h->testTenantId,
            moduleSlug: 'forum',
            availableAt: null,
            availableBy: null,
            enabledAt: null,
            enabledBy: null,
            disabledAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->h->tenantModules->save($tm);

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);
        self::assertSame(
            ModuleRouteGuard::NOT_FOUND,
            $guard->authorize($this->h->testTenantId, '/backstage/forum'),
        );
    }

    public function test_guard_runs_before_auth_no_token_required(): void
    {
        // The whole point of running the guard ahead of auth: an
        // unauthenticated visitor must see exactly the same 404 a tenant
        // with no forum sees. Resolving the guard never touches the
        // AuthMiddleware — we exercise it without any token at all and
        // assert the same NOT_FOUND verdict.
        $this->rebindRegistry($this->buildForumRegistry());

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);

        // Disabled forum → NOT_FOUND, no auth involved.
        self::assertSame(
            ModuleRouteGuard::NOT_FOUND,
            $guard->authorize($this->h->testTenantId, '/backstage/forum'),
        );

        // Sanity: an unauthenticated kernel request to a backstage API path
        // (the kernel side) returns 401 from AuthMiddleware. This contrast
        // confirms that bypassing auth is the guard's responsibility, not
        // an accident of routing.
        $resp = $this->h->request('GET', '/api/v1/backstage/notifications/stats');
        self::assertSame(401, $resp->status());
    }

    public function test_api_path_owned_by_disabled_module_returns_not_found(): void
    {
        // The API mirror of the backstage guard. /api/v1/forum/* resolves
        // to forum module ownership; disabled state → NOT_FOUND.
        $this->rebindRegistry($this->buildForumRegistry());

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);

        self::assertSame(
            ModuleRouteGuard::NOT_FOUND,
            $guard->authorize($this->h->testTenantId, '/api/v1/forum/posts'),
        );
    }

    public function test_unowned_path_always_allows(): void
    {
        // Paths no module claims (login, the homepage etc.) must always
        // be ALLOWed — gating happens further downstream.
        $this->rebindRegistry($this->buildForumRegistry());

        /** @var ModuleRouteGuard $guard */
        $guard = $this->h->container->make(ModuleRouteGuard::class);

        self::assertSame(
            ModuleRouteGuard::ALLOW,
            $guard->authorize($this->h->testTenantId, '/backstage/login'),
        );
        self::assertSame(
            ModuleRouteGuard::ALLOW,
            $guard->authorize($this->h->testTenantId, '/some/random/path'),
        );
    }
}
