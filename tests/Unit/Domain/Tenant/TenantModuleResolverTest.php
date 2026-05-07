<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\ModuleState;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Module\ModuleManifest;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Infrastructure\Module\RoutePrefixes;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantModuleResolverTest extends TestCase
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
     * Create a real ModuleRegistry populated by writing temp module.json files
     * + a temp config/modules.php that supplies platform metadata.
     *
     * @param list<array{name: string, isCore: bool}> $specs
     */
    private function makeRegistry(array $specs): ModuleRegistry
    {
        $root = sys_get_temp_dir() . '/tmrt_' . bin2hex(random_bytes(4));
        @mkdir($root, 0o777, true);
        $this->tempDirs[] = $root;

        $modulesDir = $root . '/modules';
        @mkdir($modulesDir, 0o777, true);

        // Each module gets a directory + module.json + empty bindings/routes/migrations.
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

        // Build config/modules.php content.
        $catalogLines = [
            "<?php",
            "use Daems\\Infrastructure\\Module\\RoutePrefixes;",
            "return [",
        ];
        foreach ($specs as $spec) {
            $name = $spec['name'];
            $isCore = $spec['isCore'] ? 'true' : 'false';
            $defaultAvailable = $spec['isCore'] ? 'true' : 'false';
            // Each module gets a unique route prefix to avoid overlap.
            $catalogLines[] = "  '{$name}' => [";
            $catalogLines[] = "    'category' => null,";
            $catalogLines[] = "    'name_key' => null,";
            $catalogLines[] = "    'description_key' => null,";
            $catalogLines[] = "    'is_core' => {$isCore},";
            $catalogLines[] = "    'default_available' => {$defaultAvailable},";
            $catalogLines[] = "    'sidebar' => null,";
            $catalogLines[] = "    'route_prefixes' => new RoutePrefixes(backstage: ['/backstage/{$name}'], api: ['/api/v1/{$name}']),";
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
    private function makeRepo(array $rows, ?int &$callCount = null): TenantModulesRepositoryInterface
    {
        $callCount = 0;
        $countRef = &$callCount;
        return new class($rows, $countRef) implements TenantModulesRepositoryInterface {
            /**
             * @param list<TenantModule> $rows
             */
            public function __construct(private readonly array $rows, private int &$callCount) {}

            public function findByTenant(TenantId $tenantId): array
            {
                $this->callCount++;
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

    private function makeRow(string $slug, ?DateTimeImmutable $av, ?DateTimeImmutable $en, ?DateTimeImmutable $dis = null): TenantModule
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
            disabledAt: $dis,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function testUnknownModuleReturnsDisabled(): void
    {
        $registry = $this->makeRegistry([]);
        $repo = $this->makeRepo([]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId(), 'never-heard-of-it'));
    }

    public function testIsCoreReturnsCoreRegardlessOfDb(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'users', 'isCore' => true],
        ]);
        // No row exists for 'users'; CORE wins.
        $repo = $this->makeRepo([]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::CORE, $resolver->stateFor($this->tenantId(), 'users'));
        $this->assertTrue($resolver->isEnabledFor($this->tenantId(), 'users'));
    }

    public function testNoRowReturnsDisabledForNonCore(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $repo = $this->makeRepo([]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId(), 'forum'));
    }

    public function testAvailableNotEnabledReturnsAvailable(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $now, en: null),
        ]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::AVAILABLE_NOT_ENABLED, $resolver->stateFor($this->tenantId(), 'forum'));
        $this->assertFalse($resolver->isEnabledFor($this->tenantId(), 'forum'));
    }

    public function testAvailableAndEnabledReturnsEnabled(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
        ]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::ENABLED, $resolver->stateFor($this->tenantId(), 'forum'));
        $this->assertTrue($resolver->isEnabledFor($this->tenantId(), 'forum'));
    }

    public function testAvailableAtNullWithExistingRowReturnsDisabled(): void
    {
        // Post-revoke state: row exists but available_at + enabled_at are null,
        // disabled_at is set.
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $dis = new DateTimeImmutable('2026-05-07T12:00:00+00:00');
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: null, en: null, dis: $dis),
        ]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $this->assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId(), 'forum'));
    }

    public function testStatesForTenantReturnsMapForKnownPlusOrphans(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'users', 'isCore' => true],
            ['name' => 'forum', 'isCore' => false],
            ['name' => 'events', 'isCore' => false],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        // forum: ENABLED. events: AVAILABLE_NOT_ENABLED. users: CORE (no row).
        // Plus orphan 'legacy-thing' that is in DB but not in registry.
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
            $this->makeRow('events', av: $av, en: null),
            $this->makeRow('legacy-thing', av: $av, en: $en),
        ]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $states = $resolver->statesForTenant($this->tenantId());
        $this->assertSame(ModuleState::CORE, $states['users']);
        $this->assertSame(ModuleState::ENABLED, $states['forum']);
        $this->assertSame(ModuleState::AVAILABLE_NOT_ENABLED, $states['events']);
        $this->assertArrayHasKey('legacy-thing', $states);
        $this->assertSame(ModuleState::DISABLED, $states['legacy-thing']);
    }

    public function testPerRequestMemoSecondCallNoRepoHit(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $callCount = 0;
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
        ], $callCount);
        $resolver = new TenantModuleResolver($registry, $repo);

        $resolver->statesForTenant($this->tenantId());
        $resolver->statesForTenant($this->tenantId());
        $resolver->stateFor($this->tenantId(), 'forum');

        $this->assertSame(1, $callCount, 'Expected exactly 1 repo hit; subsequent calls should use memo');
    }

    public function testInvalidateClearsMemo(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'forum', 'isCore' => false],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $callCount = 0;
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
        ], $callCount);
        $resolver = new TenantModuleResolver($registry, $repo);

        $resolver->statesForTenant($this->tenantId());
        $resolver->invalidate($this->tenantId());
        $resolver->statesForTenant($this->tenantId());

        $this->assertSame(2, $callCount, 'Expected 2 repo hits after invalidate');
    }

    public function testEnabledSlugsForReturnsEnabledAndCore(): void
    {
        $registry = $this->makeRegistry([
            ['name' => 'users', 'isCore' => true],
            ['name' => 'forum', 'isCore' => false],
            ['name' => 'events', 'isCore' => false],
        ]);
        $av = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $en = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $repo = $this->makeRepo([
            $this->makeRow('forum', av: $av, en: $en),
            $this->makeRow('events', av: $av, en: null),
        ]);
        $resolver = new TenantModuleResolver($registry, $repo);

        $slugs = $resolver->enabledSlugsFor($this->tenantId());
        sort($slugs);
        $this->assertSame(['forum', 'users'], $slugs);
    }
}
