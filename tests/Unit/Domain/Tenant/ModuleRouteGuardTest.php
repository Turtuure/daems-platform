<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleRouteGuard;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Module\ModuleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ModuleRouteGuardTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-0000000000aa';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT_ID);
    }

    /**
     * Build a real ModuleRegistry from temp module.json files + a temp catalog.
     *
     * @param list<array{name: string, isCore: bool, backstage: list<string>, api: list<string>}> $specs
     */
    private function makeRegistry(array $specs): ModuleRegistry
    {
        $root = sys_get_temp_dir() . '/mrgt_' . bin2hex(random_bytes(4));
        @mkdir($root, 0o777, true);
        $this->tempDirs[] = $root;

        $modulesDir = $root . '/modules';
        @mkdir($modulesDir, 0o777, true);

        foreach ($specs as $spec) {
            $dir = $modulesDir . '/' . $spec['name'];
            @mkdir($dir . '/src', 0o777, true);
            @mkdir($dir . '/migrations', 0o777, true);
            file_put_contents($dir . '/bindings.php', "<?php\nreturn function(\$c) {};\n");
            file_put_contents($dir . '/routes.php', "<?php\nreturn function(\$r, \$c) {};\n");
            file_put_contents($dir . '/module.json', json_encode([
                'name'             => $spec['name'],
                'version'          => '0.0.1',
                'namespace'        => 'DaemsModule\\' . ucfirst($spec['name']) . '\\',
                'src_path'         => 'src',
                'bindings'         => 'bindings.php',
                'routes'           => 'routes.php',
                'migrations_path'  => 'migrations',
            ]) ?: '{}');
        }

        $catalogLines = [
            "<?php",
            "use Daems\\Infrastructure\\Module\\RoutePrefixes;",
            "return [",
        ];
        foreach ($specs as $spec) {
            $name = $spec['name'];
            $isCore = $spec['isCore'] ? 'true' : 'false';
            $defaultAvailable = $spec['isCore'] ? 'true' : 'false';
            $backstage = "['" . implode("','", $spec['backstage']) . "']";
            if ($spec['backstage'] === []) {
                $backstage = '[]';
            }
            $api = "['" . implode("','", $spec['api']) . "']";
            if ($spec['api'] === []) {
                $api = '[]';
            }
            $catalogLines[] = "  '{$name}' => [";
            $catalogLines[] = "    'category' => null,";
            $catalogLines[] = "    'name_key' => null,";
            $catalogLines[] = "    'description_key' => null,";
            $catalogLines[] = "    'is_core' => {$isCore},";
            $catalogLines[] = "    'default_available' => {$defaultAvailable},";
            $catalogLines[] = "    'sidebar' => null,";
            $catalogLines[] = "    'route_prefixes' => new RoutePrefixes(backstage: {$backstage}, api: {$api}),";
            $catalogLines[] = "    'depends_on' => [],";
            $catalogLines[] = "  ],";
        }
        $catalogLines[] = "];";

        $catalogPath = $root . '/modules.php';
        file_put_contents($catalogPath, implode("\n", $catalogLines));

        $registry = new ModuleRegistry();
        $registry->discover($modulesDir, $catalogPath);
        return $registry;
    }

    /**
     * @param list<TenantModule> $rows
     */
    private function makeRepo(array $rows): TenantModulesRepositoryInterface
    {
        return new class($rows) implements TenantModulesRepositoryInterface {
            /** @param list<TenantModule> $rows */
            public function __construct(private readonly array $rows) {}

            public function findByTenant(TenantId $tenantId): array
            {
                return $this->rows;
            }

            public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
            {
                foreach ($this->rows as $r) {
                    if ($r->moduleSlug() === $moduleSlug) {
                        return $r;
                    }
                }
                return null;
            }

            public function save(TenantModule $tm): void {}

            /** @param list<ModuleAuditEntry> $auditEntries */
            public function revokeAvailability(TenantModule $tm, \DateTimeImmutable $now, array $auditEntries): void {}

            public function findEnabledByTenant(TenantId $tenantId): array
            {
                $out = [];
                foreach ($this->rows as $r) {
                    if ($r->isEnabled()) {
                        $out[] = $r;
                    }
                }
                return $out;
            }
        };
    }

    private function makeRow(string $slug, ?DateTimeImmutable $av, ?DateTimeImmutable $en): TenantModule
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        return new TenantModule(
            id: '01958000-0000-7000-8000-' . substr(md5($slug), 0, 12),
            tenantId: $this->tenantId(),
            moduleSlug: $slug,
            availableAt: $av,
            availableBy: $av !== null ? UserId::fromString(self::USER_ID) : null,
            enabledAt: $en,
            enabledBy: $en !== null ? UserId::fromString(self::USER_ID) : null,
            disabledAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function testPathWithNoModuleOwnerAllows(): void
    {
        // Empty registry — no module owns any path. Shell route, allow.
        $registry = $this->makeRegistry([]);
        $resolver = new TenantModuleResolver($registry, $this->makeRepo([]));
        $guard = new ModuleRouteGuard($registry, $resolver);

        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/backstage'));
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/some/random/path'));
    }

    public function testPathOwnedByEnabledModuleAllows(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false, 'backstage' => ['/backstage/forum'], 'api' => ['/api/v1/forum']],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $resolver = new TenantModuleResolver($registry, $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
        ]));
        $guard = new ModuleRouteGuard($registry, $resolver);

        // Exact prefix.
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/backstage/forum'));
        // Sub-path.
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/backstage/forum/threads/42'));
        // API prefix.
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/api/v1/forum/posts'));
    }

    public function testPathOwnedByDisabledModuleNotFound(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false, 'backstage' => ['/backstage/forum'], 'api' => ['/api/v1/forum']],
        ]);
        // No row → DISABLED.
        $resolver = new TenantModuleResolver($registry, $this->makeRepo([]));
        $guard = new ModuleRouteGuard($registry, $resolver);

        $this->assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId(), '/backstage/forum'));
        $this->assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId(), '/backstage/forum/threads'));
    }

    public function testCoreModuleAlwaysAllows(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'users', 'isCore' => true, 'backstage' => ['/backstage/members'], 'api' => ['/api/v1/users']],
        ]);
        // No row at all — but core is always on.
        $resolver = new TenantModuleResolver($registry, $this->makeRepo([]));
        $guard = new ModuleRouteGuard($registry, $resolver);

        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/backstage/members'));
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/api/v1/users/123'));
    }

    public function testLongestMatchWithinSingleModulePrefixes(): void
    {
        // Single module with both backstage + api prefixes; longer-path-under-prefix
        // matches correctly via findOwnerOfPath. The "longest prefix wins" semantic
        // is enforced by the registry — here we just confirm the guard honours the
        // ownership it returns. Cross-module overlap is rejected at boot, so the
        // only multi-prefix case the guard meets is per-module.
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false, 'backstage' => ['/backstage/forum'], 'api' => ['/api/v1/forum']],
        ]);
        // No row → DISABLED.
        $resolver = new TenantModuleResolver($registry, $this->makeRepo([]));
        $guard = new ModuleRouteGuard($registry, $resolver);

        // Both prefixes claim the path → DISABLED state propagates.
        $this->assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId(), '/backstage/forum/threads/42'));
        $this->assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId(), '/api/v1/forum/posts'));
        // Path not under either prefix → no owner → ALLOW.
        $this->assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId(), '/backstage/something-else'));
    }
}
