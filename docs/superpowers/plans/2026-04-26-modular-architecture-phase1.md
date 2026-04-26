# Modular Architecture — Phase 1 (Insights Pilot) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the module-manifest convention + boot-time `ModuleRegistry` in `daems-platform`, then move the Insights domain into `modules/insights/` (the `dp-insights` repo) following that convention. Zero user-visible change — Insights must work exactly as it does today after the move.

**Architecture:** A new `ModuleRegistry` in `daems-platform` scans `C:\laragon\www\modules\*/module.json` at boot, registers each module's `DaemsModule\<Name>\` namespace via Composer's runtime ClassLoader, then invokes the module's own `bindings.php` and `routes.php` to wire DI + HTTP routes. Each module owns its own backend code, frontend code, migrations, and tests. The migration runner script is extended to scan module migration directories. The `daem-society` frontend gains a small router block that maps `/<module>/...`, `/backstage/<module>/...`, and `/modules/<module>/assets/...` URLs to the module's frontend folder. Insights is the first (pilot) module; other domains follow in later phases.

**Tech Stack:** PHP 8.3, Composer (runtime ClassLoader for dynamic PSR-4), MySQL 8.4, PHPUnit 10.5, PHPStan 1.12 level 9. No new external dependencies.

**Spec:** `docs/superpowers/specs/2026-04-26-modular-architecture-design.md` (commit `6a0412d`)

**Repos affected (3 repos, separate commit streams):**
- `C:\laragon\www\daems-platform\` (registry code, bootstrap wiring, migration script, namespace cleanup, data-fix migration, phpstan config, final deletes)
- `C:\laragon\www\modules\insights\` = `dp-insights` repo (initial skeleton + all moved Insights code; branch `dev`, currently empty)
- `C:\laragon\www\sites\daem-society\` (module router in `public/index.php`, deletes of moved frontend pages)

**Verification gates (must pass before final commit on any task that touches moved code):**
- `composer analyse` → 0 errors at PHPStan level 9
- `composer test` (Unit + Integration suites) → all green
- `composer test:e2e` → all green
- `GET http://daem-society.local/insights` renders public Insights page identically
- `GET http://daem-society.local/backstage/insights` renders backstage Insights list identically
- `GET /api/v1/insights` returns identical JSON to pre-migration

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa" instruction. `.claude/` never staged (`git reset HEAD .claude/` if needed).

**dp-insights pushaa cadence:** the `dp-insights` repo accumulates several commits during Phase 1 (skeleton, then code moves). Push is deferred until plan completion + user confirmation. `dp-components` is already pushed and not touched by this plan.

---

## File map (created / modified / deleted across all 3 repos)

### Created in `daems-platform/`
- `src/Infrastructure/Module/ModuleManifest.php` — value object representing a parsed `module.json`
- `src/Infrastructure/Module/ModuleRegistry.php` — boot-time module discovery + autoloader + bindings + routes registration
- `src/Infrastructure/Module/ManifestValidationException.php` — exception for invalid manifests
- `docs/schemas/module-manifest.schema.json` — JSON Schema for `module.json` validation
- `tests/Unit/Infrastructure/Module/ModuleManifestTest.php`
- `tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
- `tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php`
- `database/migrations/064_rename_insight_migrations_in_schema_migrations_table.sql` — data-fix to rename the existing schema_migrations rows from `002_create_insights_table.sql` etc. to `insights_001_create_insights_table.sql` etc.

### Modified in `daems-platform/`
- `bootstrap/app.php` — wire `ModuleRegistry` discovery + bindings + routes; remove all Insight-specific bindings (lines 476-494 + 617-627 + use statements 17-18, 49, 58, 67)
- `routes/api.php` — remove all Insight-specific routes (lines 64-71 public + 372-394 backstage)
- `tests/Support/KernelHarness.php` — wire `ModuleRegistry` with `bindings.test.php` mode; remove Insight-specific bindings + InMemory fake registration
- `phpstan.neon` — add `../modules/*/backend/src/` to `paths:`
- `scripts/apply_pending_migrations.php` — scan `../modules/*/backend/migrations/` in addition to `../database/migrations/`; handle both `.sql` and `.php` migration files

### Deleted in `daems-platform/`
- `src/Domain/Insight/` (entire dir)
- `src/Application/Insight/` (entire dir)
- `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php`
- `src/Infrastructure/Adapter/Api/Controller/InsightController.php`
- Insight-specific methods in `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` (`listInsights`, `createInsight`, `getInsight`, `updateInsight`, `deleteInsight`, `statsInsights`) — extracted to module
- `database/migrations/{002,026,060,062,063}_*insight*.sql` and `060_search_text_insights_backfill.php` (moved to module + renamed)
- `tests/Unit/Application/Insight/` (entire dir)
- `tests/Integration/Application/{BackstageInsightsIntegrationTest.php,SqlInsightRepositoryTest.php}`
- `tests/Integration/Http/BackstageInsightStatsTest.php`
- `tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php`
- `tests/Isolation/{InsightStatsTenantIsolationTest.php,InsightTenantIsolationTest.php}`
- `tests/Support/Fake/InMemoryInsightRepository.php`

### Created in `modules/insights/` (dp-insights repo)
- `module.json` — module manifest
- `README.md` — module documentation
- `.gitignore` — minimal (OS junk, editor scratch)
- `backend/bindings.php` — production DI bindings (uses `SqlInsightRepository`)
- `backend/bindings.test.php` — test DI bindings (uses `InMemoryInsightRepository`)
- `backend/routes.php` — HTTP route registrations
- `backend/migrations/insights_001_create_insights_table.sql`
- `backend/migrations/insights_002_add_tenant_id.sql`
- `backend/migrations/insights_003_search_text.sql`
- `backend/migrations/insights_004_search_text_backfill.php`
- `backend/migrations/insights_005_published_date_nullable.sql`
- `backend/migrations/insights_006_published_date_datetime.sql`
- `backend/src/Domain/Insight.php` (renamed namespace `DaemsModule\Insights\Domain\Insight`)
- `backend/src/Domain/InsightId.php`
- `backend/src/Domain/InsightRepositoryInterface.php`
- `backend/src/Application/CreateInsight/{CreateInsight,CreateInsightInput,CreateInsightOutput}.php`
- `backend/src/Application/GetInsight/{GetInsight,GetInsightInput,GetInsightOutput}.php`
- `backend/src/Application/ListInsights/{ListInsights,ListInsightsInput,ListInsightsOutput}.php`
- `backend/src/Application/ListInsightStats/{ListInsightStats,ListInsightStatsInput,ListInsightStatsOutput}.php`
- `backend/src/Application/UpdateInsight/{UpdateInsight,UpdateInsightInput,UpdateInsightOutput}.php`
- `backend/src/Application/DeleteInsight/{DeleteInsight,DeleteInsightInput}.php`
- `backend/src/Infrastructure/SqlInsightRepository.php`
- `backend/src/Controller/InsightController.php` (public reads)
- `backend/src/Controller/InsightBackstageController.php` (extracted from `daems-platform`'s `BackstageController`)
- `backend/tests/Support/InMemoryInsightRepository.php`
- `backend/tests/Unit/CreateInsightTest.php`, `DeleteInsightTest.php`, `ListInsightStatsTest.php`, `ListInsightsTest.php`, `UpdateInsightTest.php`
- `backend/tests/Integration/{BackstageInsightsIntegrationTest,SqlInsightRepositoryTest,BackstageInsightStatsTest,SqlInsightRepositoryStatsTest}.php`
- `backend/tests/Isolation/{InsightStatsTenantIsolationTest,InsightTenantIsolationTest}.php`
- `frontend/public/index.php`, `detail.php`, `hero.php`, `grid.php`, `cta.php`
- `frontend/public/detail/{hero,content,related}.php`
- `frontend/public/data/{ai-in-civil-society,digital-rights-2025,open-source-community}.php`
- `frontend/backstage/index.php`, `_form.php`, `insight-form.css`, `insight-form-page.js`, `insight-panel.js`, `empty-state.svg`
- `frontend/backstage/new/index.php`
- `frontend/backstage/edit/index.php`

### Modified in `daem-society/`
- `public/index.php` — add module router block after the existing `/shared/` block

### Deleted in `daem-society/`
- `public/pages/insights/` (entire dir)
- `public/pages/backstage/insights/` (entire dir)

---

## Task 1: ModuleManifest value object

**Files:**
- Create: `daems-platform/src/Infrastructure/Module/ModuleManifest.php`
- Create: `daems-platform/src/Infrastructure/Module/ManifestValidationException.php`
- Test: `daems-platform/tests/Unit/Infrastructure/Module/ModuleManifestTest.php`

**Repo for commits:** `daems-platform`

- [ ] **Step 1.1 — Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\ManifestValidationException;
use Daems\Infrastructure\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    public function test_parses_complete_manifest(): void
    {
        $data = [
            'name' => 'insights',
            'version' => '1.0.0',
            'description' => 'Articles + scheduled publishing',
            'namespace' => 'DaemsModule\\Insights\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
            'frontend' => [
                'public_pages' => 'frontend/public/',
                'backstage_pages' => 'frontend/backstage/',
                'assets' => 'frontend/assets/',
            ],
            'requires' => ['core' => '>=1.0.0'],
        ];
        $m = ModuleManifest::fromArray($data, '/path/to/modules/insights');
        self::assertSame('insights', $m->name());
        self::assertSame('1.0.0', $m->version());
        self::assertSame('DaemsModule\\Insights\\', $m->namespace());
        self::assertSame('/path/to/modules/insights/backend/src/', $m->absoluteSrcPath());
        self::assertSame('/path/to/modules/insights/backend/bindings.php', $m->absoluteBindingsPath());
        self::assertSame('/path/to/modules/insights/backend/bindings.test.php', $m->absoluteTestBindingsPath());
        self::assertSame('/path/to/modules/insights/backend/routes.php', $m->absoluteRoutesPath());
        self::assertSame('/path/to/modules/insights/backend/migrations/', $m->absoluteMigrationsPath());
    }

    public function test_throws_on_missing_required_field(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/missing.*name/i');
        ModuleManifest::fromArray(['version' => '1.0.0'], '/path');
    }

    public function test_throws_on_invalid_name_pattern(): void
    {
        $this->expectException(ManifestValidationException::class);
        ModuleManifest::fromArray([
            'name' => 'Insights With Spaces',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
        ], '/path');
    }

    public function test_namespace_must_have_trailing_backslash(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/namespace.*trailing/i');
        ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
        ], '/path');
    }
}
```

- [ ] **Step 1.2 — Run test to verify it fails**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleManifestTest.php`
Expected: FAIL — "Class ModuleManifest not found"

- [ ] **Step 1.3 — Implement ManifestValidationException**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

final class ManifestValidationException extends \RuntimeException {}
```

- [ ] **Step 1.4 — Implement ModuleManifest**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

final class ModuleManifest
{
    private function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $description,
        private readonly string $namespace,
        private readonly string $absoluteSrcPath,
        private readonly string $absoluteBindingsPath,
        private readonly string $absoluteTestBindingsPath,
        private readonly string $absoluteRoutesPath,
        private readonly string $absoluteMigrationsPath,
        private readonly ?string $absolutePublicPagesPath,
        private readonly ?string $absoluteBackstagePagesPath,
        private readonly ?string $absoluteAssetsPath,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $moduleDir): self
    {
        foreach (['name', 'version', 'namespace', 'src_path', 'bindings', 'routes', 'migrations_path'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new ManifestValidationException("module.json missing or empty required field: {$required}");
            }
        }

        $name = $data['name'];
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new ManifestValidationException("module.json: name must be kebab-case (lowercase letters, digits, hyphens; must start with a letter): got '{$name}'");
        }

        $namespace = $data['namespace'];
        if (!str_ends_with($namespace, '\\')) {
            throw new ManifestValidationException("module.json: namespace must have trailing backslash: got '{$namespace}'");
        }

        $base = rtrim($moduleDir, '/\\');
        $abs = static fn(string $rel): string => $base . '/' . ltrim($rel, '/');

        $bindingsPath = $abs($data['bindings']);
        // Test bindings live next to bindings.php with .test.php extension.
        $testBindingsPath = preg_replace('/\.php$/', '.test.php', $bindingsPath);
        if ($testBindingsPath === null) {
            throw new ManifestValidationException("module.json: bindings path must end in .php: got '{$data['bindings']}'");
        }

        $frontend = $data['frontend'] ?? [];

        return new self(
            name: $name,
            version: $data['version'],
            description: (string) ($data['description'] ?? ''),
            namespace: $namespace,
            absoluteSrcPath: $abs($data['src_path']),
            absoluteBindingsPath: $bindingsPath,
            absoluteTestBindingsPath: $testBindingsPath,
            absoluteRoutesPath: $abs($data['routes']),
            absoluteMigrationsPath: $abs($data['migrations_path']),
            absolutePublicPagesPath: isset($frontend['public_pages']) ? $abs($frontend['public_pages']) : null,
            absoluteBackstagePagesPath: isset($frontend['backstage_pages']) ? $abs($frontend['backstage_pages']) : null,
            absoluteAssetsPath: isset($frontend['assets']) ? $abs($frontend['assets']) : null,
        );
    }

    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }
    public function description(): string { return $this->description; }
    public function namespace(): string { return $this->namespace; }
    public function absoluteSrcPath(): string { return $this->absoluteSrcPath; }
    public function absoluteBindingsPath(): string { return $this->absoluteBindingsPath; }
    public function absoluteTestBindingsPath(): string { return $this->absoluteTestBindingsPath; }
    public function absoluteRoutesPath(): string { return $this->absoluteRoutesPath; }
    public function absoluteMigrationsPath(): string { return $this->absoluteMigrationsPath; }
    public function absolutePublicPagesPath(): ?string { return $this->absolutePublicPagesPath; }
    public function absoluteBackstagePagesPath(): ?string { return $this->absoluteBackstagePagesPath; }
    public function absoluteAssetsPath(): ?string { return $this->absoluteAssetsPath; }
}
```

- [ ] **Step 1.5 — Run test to verify it passes**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleManifestTest.php`
Expected: PASS, 4/4 tests, 12+ assertions

- [ ] **Step 1.6 — Run PHPStan**

Run: `cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -10`
Expected: 0 errors

- [ ] **Step 1.7 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  src/Infrastructure/Module/ModuleManifest.php \
  src/Infrastructure/Module/ManifestValidationException.php \
  tests/Unit/Infrastructure/Module/ModuleManifestTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): ModuleManifest value object + validation exception

First building block for the module-architecture Phase 1 (spec
2026-04-26-modular-architecture-design.md, commit 6a0412d).
ModuleManifest::fromArray() parses + validates a module.json payload
and exposes typed accessors for every absolute path the registry needs.
ManifestValidationException carries a clear message naming the offending
field. Test bindings path is derived from bindings path (sibling .test.php
file) — no separate manifest field needed."
```

---

## Task 2: Module manifest JSON Schema

**Files:**
- Create: `daems-platform/docs/schemas/module-manifest.schema.json`

**Repo for commits:** `daems-platform`

- [ ] **Step 2.1 — Create the schema**

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://daems.fi/schemas/module-manifest.schema.json",
  "title": "DAEMS module manifest",
  "type": "object",
  "required": ["name", "version", "namespace", "src_path", "bindings", "routes", "migrations_path"],
  "additionalProperties": false,
  "properties": {
    "name": {
      "type": "string",
      "pattern": "^[a-z][a-z0-9-]*$",
      "description": "Kebab-case identifier; URL prefix; tenant_modules.module_name value."
    },
    "version": {
      "type": "string",
      "pattern": "^\\d+\\.\\d+\\.\\d+(?:[-+].*)?$",
      "description": "Semver."
    },
    "description": {
      "type": "string"
    },
    "namespace": {
      "type": "string",
      "pattern": "^DaemsModule\\\\[A-Z][A-Za-z0-9]*\\\\$",
      "description": "PSR-4 namespace prefix. Must start with DaemsModule\\ and end with \\."
    },
    "src_path": {
      "type": "string",
      "description": "Relative path under module dir where the namespace is rooted."
    },
    "bindings": {
      "type": "string",
      "pattern": "\\.php$"
    },
    "routes": {
      "type": "string",
      "pattern": "\\.php$"
    },
    "migrations_path": {
      "type": "string"
    },
    "frontend": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "public_pages": { "type": "string" },
        "backstage_pages": { "type": "string" },
        "assets": { "type": "string" }
      }
    },
    "requires": {
      "type": "object",
      "additionalProperties": { "type": "string" }
    }
  }
}
```

- [ ] **Step 2.2 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add docs/schemas/module-manifest.schema.json
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): JSON Schema for module.json manifests

Draft 2020-12 schema documenting the seven required fields + optional
frontend object + optional requires map. ModuleManifest::fromArray()
implements the same contract in PHP — schema doubles as machine-readable
documentation for module authors and as a future validation source if
the registry adopts a JSON-Schema validator (currently the validation is
hand-rolled inside ModuleManifest)."
```

---

## Task 3: ModuleRegistry — discover()

**Files:**
- Create: `daems-platform/src/Infrastructure/Module/ModuleRegistry.php`
- Test: `daems-platform/tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`

**Repo for commits:** `daems-platform`

- [ ] **Step 3.1 — Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\ManifestValidationException;
use Daems\Infrastructure\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dr_module_test_' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_discovers_no_modules_in_empty_dir(): void
    {
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        self::assertSame([], $r->all());
    }

    public function test_discovers_one_module(): void
    {
        $modDir = $this->tmp . '/insights';
        mkdir($modDir);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'insights',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\Insights\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ]));

        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $modules = $r->all();
        self::assertCount(1, $modules);
        self::assertNotNull($r->get('insights'));
        self::assertSame('insights', $r->get('insights')->name());
    }

    public function test_skips_dirs_without_manifest(): void
    {
        mkdir($this->tmp . '/notamodule');
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        self::assertSame([], $r->all());
    }

    public function test_throws_on_duplicate_module_name(): void
    {
        foreach (['a', 'b'] as $sub) {
            $d = $this->tmp . '/' . $sub;
            mkdir($d);
            file_put_contents($d . '/module.json', json_encode([
                'name' => 'duplicate',
                'version' => '1.0.0',
                'namespace' => 'DaemsModule\\Duplicate\\',
                'src_path' => 'src/',
                'bindings' => 'b.php',
                'routes' => 'r.php',
                'migrations_path' => 'm/',
            ]));
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate.*module name/i');
        (new ModuleRegistry())->discover($this->tmp);
    }

    public function test_propagates_manifest_validation_errors(): void
    {
        $d = $this->tmp . '/broken';
        mkdir($d);
        file_put_contents($d . '/module.json', json_encode(['version' => '1.0.0'])); // missing name
        $this->expectException(ManifestValidationException::class);
        (new ModuleRegistry())->discover($this->tmp);
    }

    public function test_throws_on_invalid_json(): void
    {
        $d = $this->tmp . '/broken-json';
        mkdir($d);
        file_put_contents($d . '/module.json', '{not valid');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid json/i');
        (new ModuleRegistry())->discover($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 3.2 — Run test to verify it fails**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: FAIL — "Class ModuleRegistry not found"

- [ ] **Step 3.3 — Implement ModuleRegistry::discover()**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

use Composer\Autoload\ClassLoader;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;

final class ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    /**
     * Scan $modulesDir/* for module.json files. For each one found, parse,
     * validate, and store keyed by module name. Throws on duplicate names
     * or invalid JSON. ManifestValidationException propagates through.
     */
    public function discover(string $modulesDir): void
    {
        $real = realpath($modulesDir);
        if ($real === false || !is_dir($real)) {
            return; // No modules dir on this host — nothing to discover.
        }
        $entries = scandir($real) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $real . '/' . $entry . '/module.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $raw = (string) file_get_contents($manifestPath);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid JSON in {$manifestPath}");
            }
            $manifest = ModuleManifest::fromArray($data, dirname($manifestPath));
            if (isset($this->modules[$manifest->name()])) {
                throw new \RuntimeException(
                    "Duplicate module name '{$manifest->name()}' (already registered from another directory)"
                );
            }
            $this->modules[$manifest->name()] = $manifest;
        }
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $name): ?ModuleManifest
    {
        return $this->modules[$name] ?? null;
    }
}
```

- [ ] **Step 3.4 — Run tests to verify they pass**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: PASS, 6/6 tests

- [ ] **Step 3.5 — Run PHPStan**

Run: `cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -10`
Expected: 0 errors

- [ ] **Step 3.6 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  src/Infrastructure/Module/ModuleRegistry.php \
  tests/Unit/Infrastructure/Module/ModuleRegistryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): ModuleRegistry::discover() — boot-time module scan

Scans modules-dir/*/module.json, parses + validates each via
ModuleManifest::fromArray(), stores keyed by module name. Throws on
duplicate names or invalid JSON; ManifestValidationException propagates.
Returns silently if modules-dir doesn't exist on the host (e.g. test
environments without modules wired in). registerAutoloader / registerBindings
/ registerRoutes follow in the next tasks."
```

---

## Task 4: ModuleRegistry — registerAutoloader()

**Files:**
- Modify: `daems-platform/src/Infrastructure/Module/ModuleRegistry.php`
- Modify: `daems-platform/tests/Unit/Infrastructure/Module/ModuleRegistryTest.php` (add tests)

**Repo for commits:** `daems-platform`

- [ ] **Step 4.1 — Add the failing test**

Append to `ModuleRegistryTest.php`:

```php
public function test_register_autoloader_maps_module_namespace_to_src_dir(): void
{
    $modDir = $this->tmp . '/widgets';
    mkdir($modDir . '/backend/src', 0777, true);
    file_put_contents($modDir . '/module.json', json_encode([
        'name' => 'widgets',
        'version' => '1.0.0',
        'namespace' => 'DaemsModule\\Widgets\\',
        'src_path' => 'backend/src/',
        'bindings' => 'backend/bindings.php',
        'routes' => 'backend/routes.php',
        'migrations_path' => 'backend/migrations/',
    ]));
    file_put_contents($modDir . '/backend/src/Hello.php',
        "<?php namespace DaemsModule\\Widgets; class Hello { public function name(): string { return 'widgets'; } }"
    );

    $loader = new \Composer\Autoload\ClassLoader();
    $loader->register();

    $r = new ModuleRegistry();
    $r->discover($this->tmp);
    $r->registerAutoloader($loader);

    self::assertTrue(class_exists('DaemsModule\\Widgets\\Hello'));
    $obj = new \DaemsModule\Widgets\Hello();
    self::assertSame('widgets', $obj->name());

    $loader->unregister();
}
```

- [ ] **Step 4.2 — Run the test, expect failure**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit --filter test_register_autoloader_maps_module_namespace_to_src_dir tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: FAIL — "Method registerAutoloader does not exist"

- [ ] **Step 4.3 — Implement registerAutoloader()**

Add method to `ModuleRegistry` (and `use Composer\Autoload\ClassLoader;` at top):

```php
/**
 * Register each discovered module's namespace with Composer's runtime
 * ClassLoader. After this returns, classes under DaemsModule\<Name>\
 * become resolvable to files under modules/<name>/<src_path>.
 */
public function registerAutoloader(ClassLoader $loader): void
{
    foreach ($this->modules as $manifest) {
        $loader->addPsr4($manifest->namespace(), $manifest->absoluteSrcPath());
    }
}
```

- [ ] **Step 4.4 — Run the test, expect pass**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: 7/7 PASS

- [ ] **Step 4.5 — Run PHPStan**

Run: `composer analyse 2>&1 | tail -10`
Expected: 0 errors

- [ ] **Step 4.6 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  src/Infrastructure/Module/ModuleRegistry.php \
  tests/Unit/Infrastructure/Module/ModuleRegistryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): ModuleRegistry::registerAutoloader()

Plugs each module's PSR-4 namespace into Composer's runtime ClassLoader.
Test creates a fake module on disk + verifies classes under its declared
namespace become loadable. Real boot will fetch the loader from
require __DIR__.'/../vendor/autoload.php'."
```

---

## Task 5: ModuleRegistry — registerBindings() + registerRoutes() + migrationPaths()

**Files:**
- Modify: `daems-platform/src/Infrastructure/Module/ModuleRegistry.php`
- Modify: `daems-platform/tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
- Test: `daems-platform/tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php`

**Repo for commits:** `daems-platform`

- [ ] **Step 5.1 — Add unit tests for the three new methods**

Append to `ModuleRegistryTest.php`:

```php
public function test_register_bindings_invokes_module_binding_closure(): void
{
    $modDir = $this->tmp . '/foo';
    mkdir($modDir . '/backend', 0777, true);
    file_put_contents($modDir . '/module.json', json_encode([
        'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
        'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
        'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
    ]));
    file_put_contents($modDir . '/backend/bindings.php',
        "<?php return function (\$container) { \$container->bind('foo.invoked', fn() => 'yes'); };"
    );

    $container = new \Daems\Infrastructure\Framework\Container\Container();
    $r = new ModuleRegistry();
    $r->discover($this->tmp);
    $r->registerBindings($container, ModuleRegistry::PROD);
    self::assertSame('yes', $container->make('foo.invoked'));
}

public function test_register_bindings_in_test_mode_uses_test_bindings_file(): void
{
    $modDir = $this->tmp . '/foo';
    mkdir($modDir . '/backend', 0777, true);
    file_put_contents($modDir . '/module.json', json_encode([
        'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
        'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
        'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
    ]));
    file_put_contents($modDir . '/backend/bindings.php',
        "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'prod'); };"
    );
    file_put_contents($modDir . '/backend/bindings.test.php',
        "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'test'); };"
    );

    $container = new \Daems\Infrastructure\Framework\Container\Container();
    $r = new ModuleRegistry();
    $r->discover($this->tmp);
    $r->registerBindings($container, ModuleRegistry::TEST);
    self::assertSame('test', $container->make('foo.flavor'));
}

public function test_register_bindings_in_test_mode_falls_back_to_prod_when_no_test_file(): void
{
    $modDir = $this->tmp . '/foo';
    mkdir($modDir . '/backend', 0777, true);
    file_put_contents($modDir . '/module.json', json_encode([
        'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
        'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
        'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
    ]));
    file_put_contents($modDir . '/backend/bindings.php',
        "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'prod'); };"
    );

    $container = new \Daems\Infrastructure\Framework\Container\Container();
    $r = new ModuleRegistry();
    $r->discover($this->tmp);
    $r->registerBindings($container, ModuleRegistry::TEST);
    self::assertSame('prod', $container->make('foo.flavor'));
}

public function test_migration_paths_lists_each_modules_migrations_dir(): void
{
    foreach (['a', 'b'] as $name) {
        $d = $this->tmp . '/' . $name;
        mkdir($d . '/backend/migrations', 0777, true);
        file_put_contents($d . '/module.json', json_encode([
            'name' => $name, 'version' => '1.0.0',
            'namespace' => 'DaemsModule\\' . ucfirst($name) . '\\',
            'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
        ]));
    }
    $r = new ModuleRegistry();
    $r->discover($this->tmp);
    $paths = $r->migrationPaths();
    self::assertCount(2, $paths);
    self::assertStringContainsString('/a/backend/migrations/', $paths[0]);
    self::assertStringContainsString('/b/backend/migrations/', $paths[1]);
}
```

- [ ] **Step 5.2 — Run tests to verify failure**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: 4 new tests fail with "Method does not exist" or "Undefined constant"

- [ ] **Step 5.3 — Implement the three methods + the mode constants**

Add to `ModuleRegistry`:

```php
public const PROD = 'prod';
public const TEST = 'test';

/**
 * Invoke each discovered module's bindings file. The file must `return`
 * a closure that takes the Container.
 *
 * @param self::PROD|self::TEST $mode  PROD loads bindings.php; TEST tries
 *                                     bindings.test.php first and falls
 *                                     back to bindings.php if absent.
 */
public function registerBindings(Container $container, string $mode = self::PROD): void
{
    foreach ($this->modules as $manifest) {
        $path = $mode === self::TEST && is_file($manifest->absoluteTestBindingsPath())
              ? $manifest->absoluteTestBindingsPath()
              : $manifest->absoluteBindingsPath();
        if (!is_file($path)) {
            throw new \RuntimeException("Module '{$manifest->name()}' bindings file not found: {$path}");
        }
        $closure = require $path;
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("Module '{$manifest->name()}' bindings file must return a Closure");
        }
        $closure($container);
    }
}

/**
 * Invoke each discovered module's routes file. The file must `return`
 * a closure that takes (Router, Container).
 */
public function registerRoutes(Router $router, Container $container): void
{
    foreach ($this->modules as $manifest) {
        $path = $manifest->absoluteRoutesPath();
        if (!is_file($path)) {
            throw new \RuntimeException("Module '{$manifest->name()}' routes file not found: {$path}");
        }
        $closure = require $path;
        if (!$closure instanceof \Closure) {
            throw new \RuntimeException("Module '{$manifest->name()}' routes file must return a Closure");
        }
        $closure($router, $container);
    }
}

/**
 * Return absolute paths to each module's migrations directory.
 *
 * @return list<string>
 */
public function migrationPaths(): array
{
    $paths = [];
    foreach ($this->modules as $manifest) {
        $paths[] = $manifest->absoluteMigrationsPath();
    }
    return $paths;
}
```

- [ ] **Step 5.4 — Run tests, expect pass**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
Expected: 11/11 PASS

- [ ] **Step 5.5 — Add an end-to-end integration test that exercises all 4 registry methods**

Create `daems-platform/tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Module;

use Composer\Autoload\ClassLoader;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryDiscoveryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dr_module_e2e_' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_full_discover_autoload_bind_route_cycle(): void
    {
        $modDir = $this->tmp . '/demo';
        mkdir($modDir . '/backend/src/Controller', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'demo', 'version' => '1.0.0',
            'namespace' => 'DaemsModule\\Demo\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/src/Controller/DemoController.php',
            "<?php namespace DaemsModule\\Demo\\Controller;
             class DemoController { public function ping(): string { return 'pong'; } }"
        );
        file_put_contents($modDir . '/backend/bindings.php',
            "<?php return function (\$c) {
                \$c->bind(\\DaemsModule\\Demo\\Controller\\DemoController::class,
                    fn() => new \\DaemsModule\\Demo\\Controller\\DemoController());
             };"
        );
        file_put_contents($modDir . '/backend/routes.php',
            "<?php return function (\$r, \$c) {
                \$r->get('/demo/ping', fn() => null);
             };"
        );

        $loader = new ClassLoader();
        $loader->register();

        $registry = new ModuleRegistry();
        $registry->discover($this->tmp);
        $registry->registerAutoloader($loader);

        $container = new Container();
        $router = new Router();
        $registry->registerBindings($container, ModuleRegistry::PROD);
        $registry->registerRoutes($router, $container);

        // Assert: class loadable, container builds it, and ping returns 'pong'.
        $controller = $container->make(\DaemsModule\Demo\Controller\DemoController::class);
        self::assertSame('pong', $controller->ping());

        $loader->unregister();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 5.6 — Run integration test**

Run: `cd C:/laragon/www/daems-platform && vendor/bin/phpunit tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php`
Expected: 1/1 PASS

- [ ] **Step 5.7 — Run PHPStan**

Run: `composer analyse 2>&1 | tail -10`
Expected: 0 errors

- [ ] **Step 5.8 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  src/Infrastructure/Module/ModuleRegistry.php \
  tests/Unit/Infrastructure/Module/ModuleRegistryTest.php \
  tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): ModuleRegistry bindings + routes + migrations APIs

registerBindings(\$container, PROD|TEST) requires the module's
bindings.php (or bindings.test.php in test mode, with PROD fallback if
absent) and invokes the returned closure. registerRoutes(\$router, \$c)
mirrors that for routes.php. migrationPaths() returns the list of
absolute migrations dirs the runner should scan in addition to the
core path.

Integration test exercises the full cycle (discover → registerAutoloader
→ registerBindings → registerRoutes) against a synthetic on-disk module."
```

---

## Task 6: Wire ModuleRegistry into bootstrap/app.php + KernelHarness

**Files:**
- Modify: `daems-platform/bootstrap/app.php` (top of file, after Container init, before existing bindings)
- Modify: `daems-platform/tests/Support/KernelHarness.php`
- Modify: `daems-platform/phpstan.neon`

**Repo for commits:** `daems-platform`

This task wires the registry but discovers nothing yet (modules dir at `../../modules/` may exist but contains no `module.json` files at this point — `dp-insights` is empty git-init'd, `dp-components` has no manifest because it's not a backend module). So the wiring is a no-op functionally, but proves the boot path.

- [ ] **Step 6.1 — Locate Container init in bootstrap/app.php**

Run: `cd C:/laragon/www/daems-platform && grep -n "new Container\|ClassLoader" bootstrap/app.php | head -5`
Expected: shows where the DI container is constructed and (ideally) where Composer's autoload is required.

If Composer's autoloader isn't captured into a variable today, the boot process probably calls `require __DIR__ . '/../vendor/autoload.php';` and discards the returned `ClassLoader`. Capture it:

```php
// Before:
require __DIR__ . '/../vendor/autoload.php';

// After:
$composerLoader = require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 6.2 — Add registry wiring after Container init, before any bindings**

Insert in `bootstrap/app.php` after `$container = new Container();`:

```php
// Module registry — discover modules under C:\laragon\www\modules\* at boot.
// Phase 1 (insights): the registry registers each module's autoloader before
// any other binding so cross-module type references resolve. Bindings and
// routes are registered after core bindings (further down).
$moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
$moduleRegistry->discover(__DIR__ . '/../../modules');
$moduleRegistry->registerAutoloader($composerLoader);
$container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $moduleRegistry);
```

- [ ] **Step 6.3 — Add bindings + routes registration calls at end of bootstrap**

After the existing core bindings block ends (locate via `grep -n "// Insights" bootstrap/app.php` — module registration goes immediately after this section, but BEFORE the file's terminating `return $container;`-equivalent):

```php
// Module bindings — invoke each discovered module's bindings.php.
$moduleRegistry->registerBindings($container, \Daems\Infrastructure\Module\ModuleRegistry::PROD);
```

The route registration call goes in the routes file. Locate `routes/api.php` and add at the very end (after all core routes registered):

```php
// Module routes — invoke each discovered module's routes.php.
$moduleRegistry = $container->make(\Daems\Infrastructure\Module\ModuleRegistry::class);
$moduleRegistry->registerRoutes($router, $container);
```

- [ ] **Step 6.4 — Mirror the wiring in KernelHarness**

Open `tests/Support/KernelHarness.php`. Find the analogous container construction. Add the same registry wiring but with `TEST` mode:

```php
// Before container.bind() calls, after the new Container():
$composerLoader = require __DIR__ . '/../../vendor/autoload.php';  // path may differ
$moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
$moduleRegistry->discover(__DIR__ . '/../../../modules');
$moduleRegistry->registerAutoloader($composerLoader);
$container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $moduleRegistry);

// After core bindings:
$moduleRegistry->registerBindings($container, \Daems\Infrastructure\Module\ModuleRegistry::TEST);

// After core routes registered:
$moduleRegistry->registerRoutes($router, $container);
```

The exact path adjustments depend on where the harness sits relative to the modules dir. Verify the relative path with `realpath` once and hardcode it (it doesn't change at runtime).

- [ ] **Step 6.5 — Update phpstan.neon to include module src paths**

Open `phpstan.neon`. Find the `paths:` block. Add:

```yaml
paths:
  - src/
  - tests/
  - ../modules/  # auto-discovers DaemsModule\<Name>\ namespaces under modules/*/backend/src/
```

PHPStan's PSR-4 detection uses composer.json. We need composer.json to know about `DaemsModule\\` for analysis purposes. Since we can't add a glob to composer.json (it requires explicit prefix → path mapping), add the autoload-dev-style entries one per module as we go. For Phase 1 with only `insights`:

Open `composer.json`. In the `autoload-dev` block, add:

```json
"autoload-dev": {
  "psr-4": {
    "Daems\\Tests\\": "tests/",
    "DaemsModule\\Insights\\": "../modules/insights/backend/src/"
  }
}
```

Then run: `composer dump-autoload`

(Phase 4 will add similar entries for member, event, project, forum modules. Future tooling could automate this from the manifests at composer-install time, but for Phase 1 the manual entry is fine.)

- [ ] **Step 6.6 — Run all tests + PHPStan to verify no regression**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
composer test 2>&1 | tail -5
composer test:e2e 2>&1 | tail -5
```
Expected: PHPStan 0 errors. All test suites green. (No moves yet, so nothing should change.)

- [ ] **Step 6.7 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  bootstrap/app.php routes/api.php tests/Support/KernelHarness.php \
  phpstan.neon composer.json composer.lock
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(module): ModuleRegistry into bootstrap, routes, KernelHarness, phpstan

bootstrap/app.php and routes/api.php now boot the registry and invoke
its bindings + routes registration after the core wiring. KernelHarness
mirrors the same pattern with TEST mode (uses bindings.test.php when
present). phpstan.neon includes ../modules/ in its paths and composer.json
declares the DaemsModule\\Insights\\ namespace for static analysis.

Wiring is currently a no-op — modules/insights/module.json doesn't exist
yet. Next task creates it. This commit is verification that boot doesn't
break when no modules are present."
```

---

## Task 7: Extend migration runner script for multi-path

**Files:**
- Modify: `daems-platform/scripts/apply_pending_migrations.php`
- Create: `daems-platform/database/migrations/064_rename_insight_migrations_in_schema_migrations_table.sql` (data fix, applied automatically when present)

**Repo for commits:** `daems-platform`

This task extends the migration runner to scan module migrations dirs, supports both `.sql` and `.php` migration files, and adds a one-shot data-fix migration that renames Insight migration filenames in `schema_migrations` to match the new module-prefixed names. Phase 1's actual file renames happen in Task 11 — but this Task 7 prepares the runner so Task 11's renamed files don't trigger re-runs.

- [ ] **Step 7.1 — Read current script + design the extension**

Re-read `scripts/apply_pending_migrations.php`. Plan: add (a) call to ModuleRegistry to fetch module migration paths, (b) glob both `.sql` and `.php` patterns, (c) for `.php` files, `require` instead of running raw SQL.

- [ ] **Step 7.2 — Rewrite the script**

Replace `scripts/apply_pending_migrations.php` with:

```php
<?php

declare(strict_types=1);

// Migration runner: scan core + module migration dirs, apply pending .sql/.php
// files, record by filename in schema_migrations.
//
// Filename ordering: alphabetical across ALL dirs. Module migrations are
// expected to be named '<module>_NNN_<desc>.{sql,php}' so they sort
// independently of core migrations (NNN_<desc>.{sql,php}).

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'daems_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'salasana';

$pdo = new PDO(
    "mysql:host={$host};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// Discover migration dirs: core + each module's backend/migrations/.
$dirs = [__DIR__ . '/../database/migrations'];

// Boot the registry to fetch module dirs without going through full app bootstrap.
require __DIR__ . '/../vendor/autoload.php';
$registry = new \Daems\Infrastructure\Module\ModuleRegistry();
$registry->discover(__DIR__ . '/../../modules');
foreach ($registry->migrationPaths() as $modPath) {
    if (is_dir($modPath)) {
        $dirs[] = rtrim($modPath, '/\\');
    }
}

// Glob .sql and .php files in each dir, then sort the combined list alphabetically.
$files = [];
foreach ($dirs as $dir) {
    foreach ((array) glob($dir . '/*.{sql,php}', GLOB_BRACE) as $f) {
        if (is_string($f)) {
            $files[] = $f;
        }
    }
}
sort($files);

$runSql = static function (PDO $pdo, string $file): void {
    $sql = (string) file_get_contents($file);
    $statements = preg_split('/;[\r\n]+/', $sql) ?: [];
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $lines = array_filter(
            preg_split('/\r?\n/', $stmt) ?: [],
            static fn(string $l): bool => !str_starts_with(trim($l), '--') && trim($l) !== ''
        );
        $clean = implode("\n", $lines);
        if (trim($clean) !== '') {
            $pdo->exec($clean);
        }
    }
};

$runPhp = static function (PDO $pdo, string $file): void {
    // PHP migration files manage their own DB connection today (legacy form).
    // Standard contract going forward: file may either (a) connect itself, or
    // (b) reference a $pdo variable provided by the runner.
    require $file;
};

$ranAny = false;
foreach ($files as $file) {
    $base = basename($file);
    if (isset($applied[$base])) {
        continue;
    }
    echo "Applying {$base} … ";
    try {
        if (str_ends_with($file, '.sql')) {
            $runSql($pdo, $file);
        } else {
            $runPhp($pdo, $file);
        }
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$base]);
        echo "OK\n";
        $ranAny = true;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $idempotentMarkers = [
            'already exists',
            'Duplicate column name',
            'Duplicate key name',
            "check that column/key exists",
        ];
        $isBenign = false;
        foreach ($idempotentMarkers as $m) {
            if (stripos($msg, $m) !== false) { $isBenign = true; break; }
        }
        if ($isBenign) {
            echo "ALREADY APPLIED (recording): {$msg}\n";
            $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute([$base]);
        } else {
            echo "FAILED\n";
            throw $e;
        }
    }
}

if (!$ranAny) {
    echo "No pending migrations.\n";
}
```

- [ ] **Step 7.3 — Add the data-fix migration**

Create `daems-platform/database/migrations/064_rename_insight_migrations_in_schema_migrations_table.sql`:

```sql
-- Phase 1 modular architecture: Insights migrations move into modules/insights/
-- and get renamed from NNN_<desc>.{sql,php} to insights_NNN_<desc>.{sql,php}.
-- This data-fix updates the schema_migrations table so the runner sees the
-- renamed files as already-applied and doesn't try to re-run them.
--
-- Idempotent: re-running this migration is a no-op (UPDATE WHERE filename = ...
-- matches at most one row, and the new filename will already be in the table on
-- subsequent runs so the WHERE condition fails).

UPDATE schema_migrations SET filename = 'insights_001_create_insights_table.sql'
    WHERE filename = '002_create_insights_table.sql';

UPDATE schema_migrations SET filename = 'insights_002_add_tenant_id.sql'
    WHERE filename = '026_add_tenant_id_to_insights.sql';

UPDATE schema_migrations SET filename = 'insights_003_search_text.sql'
    WHERE filename = '060_search_text_insights.sql';

UPDATE schema_migrations SET filename = 'insights_004_search_text_backfill.php'
    WHERE filename = '060_search_text_insights_backfill.php';

UPDATE schema_migrations SET filename = 'insights_005_published_date_nullable.sql'
    WHERE filename = '062_make_insights_published_date_nullable.sql';

UPDATE schema_migrations SET filename = 'insights_006_published_date_datetime.sql'
    WHERE filename = '063_change_insights_published_date_to_datetime.sql';
```

- [ ] **Step 7.4 — Verify the script still works on the dev DB**

Run: `cd C:/laragon/www/daems-platform && php scripts/apply_pending_migrations.php`
Expected: applies migration `064_rename_insight_migrations_in_schema_migrations_table.sql` (and possibly nothing else if dev DB is current). Output should be either "Applying 064_… OK" or "ALREADY APPLIED (recording)".

After running, verify the rename in the DB:

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT filename FROM schema_migrations WHERE filename LIKE 'insights_%' OR filename LIKE '%insight%' ORDER BY filename;"
```
Expected: 6 rows starting with `insights_001` through `insights_006`. No rows with the old filenames.

- [ ] **Step 7.5 — Run all tests + PHPStan**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
composer test 2>&1 | tail -5
composer test:e2e 2>&1 | tail -5
```
Expected: 0 errors, all green. (Tests run against `daems_db_test` which is reset by `MigrationTestCase` — the rename will get re-applied there too.)

- [ ] **Step 7.6 — Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  scripts/apply_pending_migrations.php \
  database/migrations/064_rename_insight_migrations_in_schema_migrations_table.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Extend(migrations): multi-path runner + Insights filename rename data-fix

apply_pending_migrations.php now boots ModuleRegistry to discover module
migration dirs, scans both .sql and .php files across all dirs, and
sorts the combined list alphabetically before applying. Module-prefixed
filenames (insights_001_*.sql etc.) sort independently of core
(NNN_*.sql) so ordering stays predictable.

Migration 064 renames the existing schema_migrations rows for the 6
Insights migrations from old core-only names to the new module-prefixed
names. Idempotent — re-running matches zero rows on the second pass.
Required so Task 11's file-rename + move doesn't trigger re-runs.

Wiring is in place but no module migrations exist yet — that's Task 11."
```

---

## Task 8: Initial dp-insights skeleton commit

**Files (in `modules/insights/`):**
- Create: `module.json`
- Create: `README.md`
- Create: `.gitignore`
- Create: `backend/` (empty dir, will populate in later tasks)
- Create: `frontend/` (empty dir)

**Repo for commits:** `dp-insights` (= `C:\laragon\www\modules\insights\`)

- [ ] **Step 8.1 — Create module.json**

Write `C:\laragon\www\modules\insights\module.json`:

```json
{
  "name": "insights",
  "version": "1.0.0",
  "description": "Articles + scheduled publishing + featured highlighting",
  "namespace": "DaemsModule\\Insights\\",
  "src_path": "backend/src/",
  "bindings": "backend/bindings.php",
  "routes": "backend/routes.php",
  "migrations_path": "backend/migrations/",
  "frontend": {
    "public_pages": "frontend/public/",
    "backstage_pages": "frontend/backstage/",
    "assets": "frontend/assets/"
  },
  "requires": {
    "core": ">=1.0.0"
  }
}
```

- [ ] **Step 8.2 — Create README.md**

Write `C:\laragon\www\modules\insights\README.md`:

```markdown
# dp-insights

Articles module for `daems-platform`. Provides public read API + backstage
CRUD admin + i18n-aware sparkline stats + scheduled-publishing lifecycle.

## Layout

```
backend/
├── bindings.php          Production DI bindings (uses SqlInsightRepository)
├── bindings.test.php     Test DI bindings (uses InMemoryInsightRepository)
├── routes.php            HTTP route registrations
├── migrations/           Schema + data migrations (applied by core runner)
├── src/                  PSR-4 root for DaemsModule\Insights\
└── tests/                PHPUnit tests (Unit/Integration/Isolation)

frontend/
├── public/               Public-facing pages (mounted at /insights/*)
├── backstage/            Admin pages (mounted at /backstage/insights/*)
└── assets/               Per-module CSS/JS/images (mounted at /modules/insights/assets/*)

module.json               Manifest read by core's ModuleRegistry at boot
```

## Loading

Sites consume this module by symlinking or cloning into
`C:\laragon\www\modules\insights\` next to `daems-platform`. The platform's
`ModuleRegistry` scans `modules/*/module.json` at boot — no per-module
config needed in the consuming platform.

## Verification commands (from `daems-platform/`)

```
composer analyse
composer test
composer test:e2e
```

## License

Internal use, daems-platform tenants.
```

- [ ] **Step 8.3 — Create .gitignore**

Write `C:\laragon\www\modules\insights\.gitignore`:

```
.DS_Store
Thumbs.db
desktop.ini

*.swp
*.swo
*~
.vscode/
.idea/

vendor/
composer.lock
```

- [ ] **Step 8.4 — Create empty backend/ and frontend/ directories with .gitkeep**

```bash
mkdir -p C:/laragon/www/modules/insights/backend/migrations
mkdir -p C:/laragon/www/modules/insights/backend/src
mkdir -p C:/laragon/www/modules/insights/backend/tests
mkdir -p C:/laragon/www/modules/insights/frontend/public
mkdir -p C:/laragon/www/modules/insights/frontend/backstage
mkdir -p C:/laragon/www/modules/insights/frontend/assets
touch C:/laragon/www/modules/insights/backend/migrations/.gitkeep
touch C:/laragon/www/modules/insights/backend/src/.gitkeep
touch C:/laragon/www/modules/insights/backend/tests/.gitkeep
touch C:/laragon/www/modules/insights/frontend/public/.gitkeep
touch C:/laragon/www/modules/insights/frontend/backstage/.gitkeep
touch C:/laragon/www/modules/insights/frontend/assets/.gitkeep
```

- [ ] **Step 8.5 — Verify ModuleRegistry now discovers the module**

Run: `cd C:/laragon/www/daems-platform && php -r "require 'vendor/autoload.php'; \$r = new Daems\Infrastructure\Module\ModuleRegistry(); \$r->discover(__DIR__.'/../modules'); var_dump(array_keys(\$r->all()));"`
Expected: `array(1) { [0]=> string(8) "insights" }`

- [ ] **Step 8.6 — Commit on dp-insights**

```bash
cd C:/laragon/www/modules/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add module.json README.md .gitignore backend/ frontend/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Initial: module manifest + skeleton dirs

First commit on the dp-insights repo. module.json declares the
DaemsModule\\Insights\\ namespace, file paths for bindings/routes/
migrations, and frontend mount points. Empty backend/ and frontend/ dirs
get .gitkeep markers so the structure exists before subsequent commits
populate it with moved code from daems-platform + daem-society."
```

DO NOT push yet. SHA reported in final report.

---

## Task 9: Move Insights backend (Domain + Application + Infrastructure + Controllers)

This is the largest single task in the plan. It moves ~25 PHP files, rewrites their namespaces from `Daems\<Layer>\Insight\` → `DaemsModule\Insights\<Layer>\`, and extracts Insight-specific methods from `daems-platform`'s `BackstageController` into a new `InsightBackstageController` in the module.

**Files moved (from `daems-platform` to `modules/insights`):**

| From (`daems-platform/`) | To (`modules/insights/`) |
|---|---|
| `src/Domain/Insight/Insight.php` | `backend/src/Domain/Insight.php` |
| `src/Domain/Insight/InsightId.php` | `backend/src/Domain/InsightId.php` |
| `src/Domain/Insight/InsightRepositoryInterface.php` | `backend/src/Domain/InsightRepositoryInterface.php` |
| `src/Application/Insight/CreateInsight/*.php` (3 files) | `backend/src/Application/CreateInsight/*.php` |
| `src/Application/Insight/GetInsight/*.php` (3 files) | `backend/src/Application/GetInsight/*.php` |
| `src/Application/Insight/ListInsights/*.php` (3 files) | `backend/src/Application/ListInsights/*.php` |
| `src/Application/Insight/ListInsightStats/*.php` (3 files) | `backend/src/Application/ListInsightStats/*.php` |
| `src/Application/Insight/UpdateInsight/*.php` (3 files) | `backend/src/Application/UpdateInsight/*.php` |
| `src/Application/Insight/DeleteInsight/*.php` (2 files) | `backend/src/Application/DeleteInsight/*.php` |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` | `backend/src/Infrastructure/SqlInsightRepository.php` |
| `src/Infrastructure/Adapter/Api/Controller/InsightController.php` | `backend/src/Controller/InsightController.php` |
| (extract from) `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` methods `listInsights, createInsight, getInsight, updateInsight, deleteInsight, statsInsights` | `backend/src/Controller/InsightBackstageController.php` (new file) |

**Repos for commits:** TWO commits — one on `dp-insights` (the moves + new files), one on `daems-platform` (the deletes + BackstageController surgery).

- [ ] **Step 9.1 — Copy Domain files (NOT cut yet) and rewrite namespaces**

For each file, copy + edit the namespace declaration:

```bash
# Example for Insight.php
cp C:/laragon/www/daems-platform/src/Domain/Insight/Insight.php \
   C:/laragon/www/modules/insights/backend/src/Domain/Insight.php
```

Then in each new file, change:
- `namespace Daems\Domain\Insight;` → `namespace DaemsModule\Insights\Domain;`
- All `use Daems\Domain\Insight\X;` cross-references collapse to same-namespace (delete the use)
- `use Daems\Domain\Tenant\X;` → keep (Tenant stays in core, no namespace change)
- `use Daems\Domain\Locale\X;` → keep (Locale stays in core)
- `use Daems\Domain\Shared\X;` → keep (Shared stays in core)

Repeat for `InsightId.php` and `InsightRepositoryInterface.php`.

- [ ] **Step 9.2 — Copy Application files and rewrite namespaces**

For all 17 files under `src/Application/Insight/{Create,Get,List,ListStats,Update,Delete}/`:

```bash
# Example for CreateInsight.php
cp C:/laragon/www/daems-platform/src/Application/Insight/CreateInsight/CreateInsight.php \
   C:/laragon/www/modules/insights/backend/src/Application/CreateInsight/CreateInsight.php
```

Namespace edits:
- `namespace Daems\Application\Insight\CreateInsight;` → `namespace DaemsModule\Insights\Application\CreateInsight;`
- `use Daems\Domain\Insight\X;` → `use DaemsModule\Insights\Domain\X;`
- `use Daems\Application\Insight\<sub>\X;` → same-namespace (delete) or `use DaemsModule\Insights\Application\<sub>\X;`
- Tenant/User/Auth/Locale/Shared use statements stay unchanged (these are core).

- [ ] **Step 9.3 — Copy Infrastructure files (SqlInsightRepository) and rewrite**

```bash
cp C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php \
   C:/laragon/www/modules/insights/backend/src/Infrastructure/SqlInsightRepository.php
```

Edit:
- `namespace Daems\Infrastructure\Adapter\Persistence\Sql;` → `namespace DaemsModule\Insights\Infrastructure;`
- `use Daems\Domain\Insight\Insight;` → `use DaemsModule\Insights\Domain\Insight;`
- `use Daems\Domain\Insight\InsightId;` → `use DaemsModule\Insights\Domain\InsightId;`
- `use Daems\Domain\Insight\InsightRepositoryInterface;` → `use DaemsModule\Insights\Domain\InsightRepositoryInterface;`
- `use Daems\Domain\Tenant\TenantId;` → keep
- `use Daems\Infrastructure\Framework\Database\Connection;` → keep

- [ ] **Step 9.4 — Copy InsightController and rewrite**

```bash
cp C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/InsightController.php \
   C:/laragon/www/modules/insights/backend/src/Controller/InsightController.php
```

Edit:
- `namespace Daems\Infrastructure\Adapter\Api\Controller;` → `namespace DaemsModule\Insights\Controller;`
- All `use Daems\Application\Insight\<sub>\X;` → `use DaemsModule\Insights\Application\<sub>\X;`
- All `use Daems\Domain\Insight\X;` → `use DaemsModule\Insights\Domain\X;`
- Framework + Tenant uses stay unchanged.

- [ ] **Step 9.5 — Extract InsightBackstageController from BackstageController**

This is the surgery step. Open `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`. Locate the 6 Insight methods (per Task 4 of recon: `listInsights` line 1525, `createInsight` line 1540, `getInsight` line 1571, `updateInsight` line 1589, `deleteInsight` line 1625, `statsInsights` line 1646).

Create `modules/insights/backend/src/Controller/InsightBackstageController.php`:

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Insights\Controller;

use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Adapter\Api\Exception\ForbiddenException;
use DaemsModule\Insights\Application\CreateInsight\CreateInsight;
use DaemsModule\Insights\Application\CreateInsight\CreateInsightInput;
use DaemsModule\Insights\Application\DeleteInsight\DeleteInsight;
use DaemsModule\Insights\Application\DeleteInsight\DeleteInsightInput;
use DaemsModule\Insights\Application\GetInsight\GetInsight;
use DaemsModule\Insights\Application\GetInsight\GetInsightInput;
use DaemsModule\Insights\Application\ListInsights\ListInsights;
use DaemsModule\Insights\Application\ListInsights\ListInsightsInput;
use DaemsModule\Insights\Application\ListInsightStats\ListInsightStats;
use DaemsModule\Insights\Application\ListInsightStats\ListInsightStatsInput;
use DaemsModule\Insights\Application\UpdateInsight\UpdateInsight;
use DaemsModule\Insights\Application\UpdateInsight\UpdateInsightInput;

final class InsightBackstageController
{
    public function __construct(
        private readonly ListInsights $listInsights,
        private readonly GetInsight $getInsight,
        private readonly CreateInsight $createInsight,
        private readonly UpdateInsight $updateInsight,
        private readonly DeleteInsight $deleteInsight,
        private readonly ListInsightStats $listInsightStats,
    ) {}

    // Copy the bodies of these methods VERBATIM from BackstageController.php
    // — they reference $this->listInsights / $this->getInsight / etc. which now
    // resolve to the constructor-injected services above.
    public function list(Request $request): Response { /* paste body */ }
    public function create(Request $request): Response { /* paste body */ }
    public function get(Request $request, array $params): Response { /* paste body */ }
    public function update(Request $request, array $params): Response { /* paste body */ }
    public function delete(Request $request, array $params): Response { /* paste body */ }
    public function stats(Request $request): Response { /* paste body */ }

    // Helper methods used by the above (e.g. requireInsightsAdmin, requireTenant)
    // — copy verbatim if they're already private and Insight-specific. If they're
    // shared with other domains, leave them in BackstageController and call them
    // via a small wrapper service. Audit each helper case-by-case.
}
```

The method names lose their `Insight` prefix (e.g. `listInsights` → `list`) because the controller's class name now carries the domain context. Routes file in Task 10 references the new short names.

- [ ] **Step 9.6 — Verify the moved code parses + autoloads**

```bash
cd C:/laragon/www/daems-platform
composer dump-autoload
php -r "require 'vendor/autoload.php'; var_dump(class_exists('DaemsModule\\Insights\\Domain\\Insight'));"
```
Expected: `bool(true)`

If false: composer.json needs the `DaemsModule\\Insights\\` autoload entry (added in Task 6.5). Verify it's there.

- [ ] **Step 9.7 — Run all unit tests (the moves haven't broken anything yet because both copies exist)**

```bash
cd C:/laragon/www/daems-platform
composer test 2>&1 | tail -10
```
Expected: all green. (Tests still reference `Daems\Domain\Insight\` namespace; those classes still exist in `src/`; we haven't deleted yet.)

- [ ] **Step 9.8 — Commit the moved files on dp-insights**

```bash
cd C:/laragon/www/modules/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(backend): Domain + Application + Infrastructure + Controllers from daems-platform

Verbatim file-by-file move of:
- 3 Domain classes (Insight, InsightId, InsightRepositoryInterface)
- 17 Application classes (Create/Get/List/ListStats/Update/Delete + I/O DTOs)
- 1 Infrastructure class (SqlInsightRepository)
- 1 public Controller (InsightController)
- 1 backstage Controller extracted from BackstageController (InsightBackstageController
  consolidates the 6 Insight-specific backstage methods)

Namespaces rewritten Daems\\<L>\\Insight\\X → DaemsModule\\Insights\\<L>\\X.
Cross-Insight imports collapsed to same-namespace where possible. Core
imports (Tenant, User, Auth, Locale, Shared) kept verbatim — modules
may reference core but not other modules per design A-isolation.

The originals in daems-platform are NOT deleted yet (Task 12 handles
cleanup once tests + routes + bindings are wired). Coexistence is
intentional during Tasks 9-11; both autoload separately because the
namespaces differ."
```

DO NOT push.

---

## Task 10: Module bindings.php + bindings.test.php + routes.php

**Files (in `modules/insights/`):**
- Create: `backend/bindings.php`
- Create: `backend/bindings.test.php`
- Create: `backend/routes.php`

**Repo for commits:** `dp-insights`

- [ ] **Step 10.1 — Write backend/bindings.php**

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Insights\Application\CreateInsight\CreateInsight;
use DaemsModule\Insights\Application\DeleteInsight\DeleteInsight;
use DaemsModule\Insights\Application\GetInsight\GetInsight;
use DaemsModule\Insights\Application\ListInsights\ListInsights;
use DaemsModule\Insights\Application\ListInsightStats\ListInsightStats;
use DaemsModule\Insights\Application\UpdateInsight\UpdateInsight;
use DaemsModule\Insights\Controller\InsightBackstageController;
use DaemsModule\Insights\Controller\InsightController;
use DaemsModule\Insights\Domain\InsightRepositoryInterface;
use DaemsModule\Insights\Infrastructure\SqlInsightRepository;

return function (Container $container): void {
    $container->singleton(InsightRepositoryInterface::class,
        static fn(Container $c) => new SqlInsightRepository($c->make(Connection::class)),
    );
    $container->bind(ListInsights::class,
        static fn(Container $c) => new ListInsights($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(GetInsight::class,
        static fn(Container $c) => new GetInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(CreateInsight::class,
        static fn(Container $c) => new CreateInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(UpdateInsight::class,
        static fn(Container $c) => new UpdateInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(DeleteInsight::class,
        static fn(Container $c) => new DeleteInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(ListInsightStats::class,
        static fn(Container $c) => new ListInsightStats($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(InsightController::class,
        static fn(Container $c) => new InsightController(
            $c->make(ListInsights::class),
            $c->make(GetInsight::class),
        ),
    );
    $container->bind(InsightBackstageController::class,
        static fn(Container $c) => new InsightBackstageController(
            $c->make(ListInsights::class),
            $c->make(GetInsight::class),
            $c->make(CreateInsight::class),
            $c->make(UpdateInsight::class),
            $c->make(DeleteInsight::class),
            $c->make(ListInsightStats::class),
        ),
    );
};
```

- [ ] **Step 10.2 — Write backend/bindings.test.php**

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Insights\Application\CreateInsight\CreateInsight;
use DaemsModule\Insights\Application\DeleteInsight\DeleteInsight;
use DaemsModule\Insights\Application\GetInsight\GetInsight;
use DaemsModule\Insights\Application\ListInsights\ListInsights;
use DaemsModule\Insights\Application\ListInsightStats\ListInsightStats;
use DaemsModule\Insights\Application\UpdateInsight\UpdateInsight;
use DaemsModule\Insights\Controller\InsightBackstageController;
use DaemsModule\Insights\Controller\InsightController;
use DaemsModule\Insights\Domain\InsightRepositoryInterface;
use DaemsModule\Insights\Tests\Support\InMemoryInsightRepository;

return function (Container $container): void {
    $container->singleton(InsightRepositoryInterface::class,
        static fn() => new InMemoryInsightRepository(),
    );
    $container->bind(ListInsights::class,
        static fn(Container $c) => new ListInsights($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(GetInsight::class,
        static fn(Container $c) => new GetInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(CreateInsight::class,
        static fn(Container $c) => new CreateInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(UpdateInsight::class,
        static fn(Container $c) => new UpdateInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(DeleteInsight::class,
        static fn(Container $c) => new DeleteInsight($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(ListInsightStats::class,
        static fn(Container $c) => new ListInsightStats($c->make(InsightRepositoryInterface::class)),
    );
    $container->bind(InsightController::class,
        static fn(Container $c) => new InsightController(
            $c->make(ListInsights::class),
            $c->make(GetInsight::class),
        ),
    );
    $container->bind(InsightBackstageController::class,
        static fn(Container $c) => new InsightBackstageController(
            $c->make(ListInsights::class),
            $c->make(GetInsight::class),
            $c->make(CreateInsight::class),
            $c->make(UpdateInsight::class),
            $c->make(DeleteInsight::class),
            $c->make(ListInsightStats::class),
        ),
    );
};
```

- [ ] **Step 10.3 — Write backend/routes.php**

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use DaemsModule\Insights\Controller\InsightBackstageController;
use DaemsModule\Insights\Controller\InsightController;

return function (Router $router, Container $container): void {
    // Public reads
    $router->get('/api/v1/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/insights/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightController::class)->show($req, $params);
    }, [TenantContextMiddleware::class]);

    // Backstage CRUD
    $router->get('/api/v1/backstage/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightBackstageController::class)->list($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/insights/stats', static function (Request $req) use ($container): Response {
        return $container->make(InsightBackstageController::class)->stats($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightBackstageController::class)->create($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/insights/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightBackstageController::class)->get($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/insights/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightBackstageController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/insights/{id}/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightBackstageController::class)->delete($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
};
```

- [ ] **Step 10.4 — Verify both daems-platform's BackstageController bindings AND the new module bindings still work**

At this point, daems-platform/bootstrap/app.php still has the old Insight bindings AND the module's bindings.php is also active (registry calls it after core). Container will likely complain about duplicate bindings on `InsightController::class` etc.

**Tactic:** comment out the module's `InsightController` and `InsightBackstageController` bindings in `backend/bindings.php` for now (not the use cases). Task 12 deletes the originals from bootstrap, at which point we uncomment.

Actually a cleaner tactic: Task 12 happens BEFORE this verification. Let me reorder.

**Effective order:** Task 9 (move files), Task 10 (write bindings + routes — but DON'T register in container yet), Task 11 (move tests + migrations), Task 12 (delete originals + uncomment module bindings + deletes routes from routes/api.php). The verification gate runs after Task 12.

For Step 10.4 specifically: just verify the bindings + routes files are syntactically valid PHP:

```bash
php -l C:/laragon/www/modules/insights/backend/bindings.php
php -l C:/laragon/www/modules/insights/backend/bindings.test.php
php -l C:/laragon/www/modules/insights/backend/routes.php
```
Expected: "No syntax errors detected" for each.

- [ ] **Step 10.5 — Commit on dp-insights**

```bash
cd C:/laragon/www/modules/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/bindings.php backend/bindings.test.php backend/routes.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(backend): bindings (prod + test) + routes for the moved Insight code

bindings.php uses SqlInsightRepository (production) — exact mirror of
the bindings being deleted from daems-platform/bootstrap/app.php in Task 12.

bindings.test.php uses InMemoryInsightRepository (test mode) — picked
by KernelHarness when it boots the registry in TEST mode. Matches the
fake binding being deleted from KernelHarness in Task 12.

routes.php declares the same 8 routes (2 public + 6 backstage) being
deleted from daems-platform/routes/api.php in Task 12. Backstage routes
target the new InsightBackstageController (extracted from
BackstageController) — methods renamed listInsights → list etc. since
the class name carries the domain.

Bindings + routes are dormant until Task 12 deletes the originals —
otherwise the container would see duplicate registrations."
```

DO NOT push.

---

## Task 11: Move tests + InMemory fake + migrations

**Repos for commits:** `dp-insights` (move target) + `daems-platform` (deletes)

This task moves test files and migrations to the module, with namespace rewrites for the tests. Each move creates the file in the module first, then deletes from daems-platform after verification.

- [ ] **Step 11.1 — Move InMemory fake**

```bash
cp C:/laragon/www/daems-platform/tests/Support/Fake/InMemoryInsightRepository.php \
   C:/laragon/www/modules/insights/backend/tests/Support/InMemoryInsightRepository.php
```

Edit the new file:
- `namespace Daems\Tests\Support\Fake;` → `namespace DaemsModule\Insights\Tests\Support;`
- `use Daems\Domain\Insight\Insight;` → `use DaemsModule\Insights\Domain\Insight;`
- `use Daems\Domain\Insight\InsightId;` → `use DaemsModule\Insights\Domain\InsightId;`
- `use Daems\Domain\Insight\InsightRepositoryInterface;` → `use DaemsModule\Insights\Domain\InsightRepositoryInterface;`
- `use Daems\Domain\Tenant\TenantId;` → keep

- [ ] **Step 11.2 — Move Unit tests**

For each file in `daems-platform/tests/Unit/Application/Insight/`:

```bash
cp <src>.php C:/laragon/www/modules/insights/backend/tests/Unit/<basename>.php
```

Files: `CreateInsightTest.php`, `DeleteInsightTest.php`, `ListInsightStatsTest.php`, `ListInsightsTest.php`, `UpdateInsightTest.php`.

Namespace edits:
- `namespace Daems\Tests\Unit\Application\Insight;` → `namespace DaemsModule\Insights\Tests\Unit;`
- `use Daems\Application\Insight\<sub>\X;` → `use DaemsModule\Insights\Application\<sub>\X;`
- `use Daems\Domain\Insight\X;` → `use DaemsModule\Insights\Domain\X;`
- All other use statements unchanged.

- [ ] **Step 11.3 — Move Integration tests**

```bash
cp C:/laragon/www/daems-platform/tests/Integration/Application/BackstageInsightsIntegrationTest.php \
   C:/laragon/www/modules/insights/backend/tests/Integration/BackstageInsightsIntegrationTest.php

cp C:/laragon/www/daems-platform/tests/Integration/Application/SqlInsightRepositoryTest.php \
   C:/laragon/www/modules/insights/backend/tests/Integration/SqlInsightRepositoryTest.php

cp C:/laragon/www/daems-platform/tests/Integration/Http/BackstageInsightStatsTest.php \
   C:/laragon/www/modules/insights/backend/tests/Integration/BackstageInsightStatsTest.php

cp C:/laragon/www/daems-platform/tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php \
   C:/laragon/www/modules/insights/backend/tests/Integration/SqlInsightRepositoryStatsTest.php
```

Each gets its namespace flattened to `namespace DaemsModule\Insights\Tests\Integration;` and use statements rewritten.

- [ ] **Step 11.4 — Move Isolation tests**

```bash
cp C:/laragon/www/daems-platform/tests/Isolation/InsightStatsTenantIsolationTest.php \
   C:/laragon/www/modules/insights/backend/tests/Isolation/InsightStatsTenantIsolationTest.php

cp C:/laragon/www/daems-platform/tests/Isolation/InsightTenantIsolationTest.php \
   C:/laragon/www/modules/insights/backend/tests/Isolation/InsightTenantIsolationTest.php
```

Namespace: `namespace DaemsModule\Insights\Tests\Isolation;`. Update use statements.

- [ ] **Step 11.5 — Update phpunit.xml in daems-platform to discover module tests**

Open `daems-platform/phpunit.xml`. Find `<testsuites>` section. Add module test paths:

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
        <directory>../modules/*/backend/tests/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>tests/Integration</directory>
        <directory>../modules/*/backend/tests/Integration</directory>
    </testsuite>
    <testsuite name="Isolation">
        <directory>tests/Isolation</directory>
        <directory>../modules/*/backend/tests/Isolation</directory>
    </testsuite>
    <testsuite name="E2E">
        <directory>tests/E2E</directory>
    </testsuite>
</testsuites>
```

Update composer.json autoload-dev to include the test namespace:

```json
"autoload-dev": {
  "psr-4": {
    "Daems\\Tests\\": "tests/",
    "DaemsModule\\Insights\\": "../modules/insights/backend/src/",
    "DaemsModule\\Insights\\Tests\\": "../modules/insights/backend/tests/"
  }
}
```

Run: `cd C:/laragon/www/daems-platform && composer dump-autoload`

- [ ] **Step 11.6 — Move + rename migrations**

```bash
# Move + rename each Insight migration to module dir with insights_NNN_ prefix.
cp C:/laragon/www/daems-platform/database/migrations/002_create_insights_table.sql \
   C:/laragon/www/modules/insights/backend/migrations/insights_001_create_insights_table.sql
cp C:/laragon/www/daems-platform/database/migrations/026_add_tenant_id_to_insights.sql \
   C:/laragon/www/modules/insights/backend/migrations/insights_002_add_tenant_id.sql
cp C:/laragon/www/daems-platform/database/migrations/060_search_text_insights.sql \
   C:/laragon/www/modules/insights/backend/migrations/insights_003_search_text.sql
cp C:/laragon/www/daems-platform/database/migrations/060_search_text_insights_backfill.php \
   C:/laragon/www/modules/insights/backend/migrations/insights_004_search_text_backfill.php
cp C:/laragon/www/daems-platform/database/migrations/062_make_insights_published_date_nullable.sql \
   C:/laragon/www/modules/insights/backend/migrations/insights_005_published_date_nullable.sql
cp C:/laragon/www/daems-platform/database/migrations/063_change_insights_published_date_to_datetime.sql \
   C:/laragon/www/modules/insights/backend/migrations/insights_006_published_date_datetime.sql
```

Open each `.sql` file in the module dir and verify the SQL is portable (no relative path includes, etc.). The `.php` migration file may need a small edit: it currently does `new PDO('mysql:...', ...)` directly with hardcoded credentials. Leave as-is for now (Phase 1 isn't refactoring migration runtime contracts).

- [ ] **Step 11.7 — Commit moves on dp-insights**

```bash
cd C:/laragon/www/modules/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/ backend/migrations/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(backend): tests + InMemory fake + migrations from daems-platform

Tests:
- 5 Unit tests (Create/Delete/List/ListStats/Update)
- 4 Integration tests (BackstageInsightsIntegration, SqlInsightRepository,
  BackstageInsightStats, SqlInsightRepositoryStats)
- 2 Isolation tests (InsightTenantIsolation, InsightStatsTenantIsolation)
- 1 InMemory fake (used by KernelHarness via bindings.test.php)

Migrations: 6 files moved + renamed with insights_NNN_ prefix.
- 002_create_insights_table.sql       → insights_001_create_insights_table.sql
- 026_add_tenant_id_to_insights.sql   → insights_002_add_tenant_id.sql
- 060_search_text_insights.sql        → insights_003_search_text.sql
- 060_search_text_insights_backfill.php → insights_004_search_text_backfill.php
- 062_make_insights_published_date_nullable.sql → insights_005_published_date_nullable.sql
- 063_change_insights_published_date_to_datetime.sql → insights_006_published_date_datetime.sql

Schema_migrations table rows are renamed by daems-platform's migration
064 (committed in Task 7) so the runner sees these as already-applied
and doesn't re-run them. Originals in daems-platform are deleted in
Task 12.

Test namespaces follow DaemsModule\\Insights\\Tests\\<L>\\X. composer.json
in daems-platform updated to autoload them. phpunit.xml updated to scan
module test directories."
```

DO NOT push.

---

## Task 12: Delete originals in daems-platform + verify

This is the gate task. After this, the originals are gone and only the module copies serve traffic. All tests + PHPStan must pass before commit.

**Repo for commits:** `daems-platform`

- [ ] **Step 12.1 — Delete Insight bindings from bootstrap/app.php**

Remove these lines (line numbers from recon — actual may have shifted by ±5):
- Lines 17-18 (use statements for `Insight` Application classes)
- Line 49 (CreateInsight use), Line 58 (UpdateInsight use), Line 67 (DeleteInsight use) — may already be in 17-18
- Lines 476-494 (CreateInsight, UpdateInsight, DeleteInsight, ListInsightStats bindings)
- Lines 617-627 (Repository singleton + ListInsights, GetInsight, InsightController bindings)

Use `grep -n "Insight" bootstrap/app.php` to enumerate all remaining lines, then delete each.

- [ ] **Step 12.2 — Delete Insight routes from routes/api.php**

Remove lines 64-71 (public Insight reads) and 372-394 (backstage Insight CRUD). Use `grep -n "insight" routes/api.php` to confirm none remain.

- [ ] **Step 12.3 — Delete Insight methods from BackstageController**

Open `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`. Delete the 6 methods extracted in Task 9.5 (`listInsights`, `createInsight`, `getInsight`, `updateInsight`, `deleteInsight`, `statsInsights`) and their associated `use` statements at the top of the file (any `use Daems\Application\Insight\...` or `use Daems\Domain\Insight\...` should be gone).

- [ ] **Step 12.4 — Delete Insight Domain + Application + Infrastructure source dirs**

```bash
cd C:/laragon/www/daems-platform
rm -rf src/Domain/Insight
rm -rf src/Application/Insight
rm src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php
rm src/Infrastructure/Adapter/Api/Controller/InsightController.php
```

- [ ] **Step 12.5 — Delete Insight tests + InMemory fake**

```bash
cd C:/laragon/www/daems-platform
rm -rf tests/Unit/Application/Insight
rm tests/Integration/Application/BackstageInsightsIntegrationTest.php
rm tests/Integration/Application/SqlInsightRepositoryTest.php
rm tests/Integration/Http/BackstageInsightStatsTest.php
rm tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php
rm tests/Isolation/InsightStatsTenantIsolationTest.php
rm tests/Isolation/InsightTenantIsolationTest.php
rm tests/Support/Fake/InMemoryInsightRepository.php
```

- [ ] **Step 12.6 — Delete Insight bindings from KernelHarness**

Open `tests/Support/KernelHarness.php`. Remove all bindings that reference `InsightRepositoryInterface`, `InMemoryInsightRepository`, or any `ListInsights/GetInsight/CreateInsight/UpdateInsight/DeleteInsight/ListInsightStats/InsightController` class. Use `grep -n "Insight" tests/Support/KernelHarness.php` to enumerate.

- [ ] **Step 12.7 — Delete original Insight migrations**

```bash
cd C:/laragon/www/daems-platform
rm database/migrations/002_create_insights_table.sql
rm database/migrations/026_add_tenant_id_to_insights.sql
rm database/migrations/060_search_text_insights.sql
rm database/migrations/060_search_text_insights_backfill.php
rm database/migrations/062_make_insights_published_date_nullable.sql
rm database/migrations/063_change_insights_published_date_to_datetime.sql
```

(Migration `064_rename_insight_migrations_in_schema_migrations_table.sql` from Task 7 STAYS — it's a daems-platform-owned data fix.)

- [ ] **Step 12.8 — Run all verification gates**

```bash
cd C:/laragon/www/daems-platform
composer dump-autoload
composer analyse 2>&1 | tail -10
composer test 2>&1 | tail -10
composer test:e2e 2>&1 | tail -10
```

Expected:
- PHPStan: 0 errors. If there are errors about missing `Daems\Domain\Insight\`-class references, find the call site and update the import to `DaemsModule\Insights\Domain\X`.
- Unit + Integration tests: all green. The Insights tests now run from the module's directory (phpunit.xml configured in Task 11.5).
- E2E: all green. KernelHarness boots the module's `bindings.test.php` automatically.

If anything fails: do NOT commit. Diagnose, fix, re-run gates. Common failure modes:
- Forgot to update a use statement in BackstageController surgery → PHPStan flags
- Forgot to delete a binding → container has duplicate registration → fatal at boot
- Migration runner re-ran a moved file because schema_migrations rename didn't include it → check Task 7's migration ran

- [ ] **Step 12.9 — Test the actual API endpoints**

```bash
# Public read
curl -s "http://daems-platform.local/api/v1/insights" | head -c 200

# Backstage stats (requires auth — adjust as your environment supports)
curl -s "http://daems-platform.local/api/v1/backstage/insights/stats" | head -c 200
```

Expected: JSON responses identical to pre-migration shape. If 404, the routes file isn't being invoked — check that `routes/api.php` calls `$moduleRegistry->registerRoutes(...)` (added in Task 6.3).

- [ ] **Step 12.10 — Commit all daems-platform deletes**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -A
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(insight): legacy Insight code — moved to modules/insights/

After Tasks 9-11 created the dp-insights module with full backend +
tests + migrations, this commit removes the originals from daems-platform:
- 22 source files (Domain, Application, Infrastructure, Controllers)
- 12 test files (Unit, Integration, Isolation) + 1 InMemory fake
- 6 migration files (now in module dir, schema_migrations rows renamed
  by migration 064)
- All Insight-specific bindings from bootstrap/app.php (~30 lines)
- All Insight-specific routes from routes/api.php (~30 lines)
- 6 Insight methods extracted from BackstageController to module's
  InsightBackstageController
- Insight bindings from tests/Support/KernelHarness.php

After this commit, daems-platform contains zero Insight-specific code.
ModuleRegistry boot loads the module + its bindings + routes. PHPStan
level 9 = 0 errors. All test suites green. API responses identical.

Single source of truth: Insights lives ONLY in modules/insights/."
```

---

## Task 13: Move Insights frontend pages from daem-society

**Repos for commits:** `dp-insights` (move target) + `daem-society` (deletes)

- [ ] **Step 13.1 — Copy public pages**

```bash
cp -r C:/laragon/www/sites/daem-society/public/pages/insights/* \
      C:/laragon/www/modules/insights/frontend/public/
```

(Copies all .php files, the data/ subfolder with seed data, and detail/ subfolder.)

- [ ] **Step 13.2 — Copy backstage pages**

```bash
cp -r C:/laragon/www/sites/daem-society/public/pages/backstage/insights/* \
      C:/laragon/www/modules/insights/frontend/backstage/
```

(Copies index.php, _form.php, JS, CSS, SVG, new/ and edit/ subdirs.)

- [ ] **Step 13.3 — Verify the copies are byte-identical**

```bash
diff -r C:/laragon/www/sites/daem-society/public/pages/insights \
        C:/laragon/www/modules/insights/frontend/public

diff -r C:/laragon/www/sites/daem-society/public/pages/backstage/insights \
        C:/laragon/www/modules/insights/frontend/backstage
```
Expected: no output from either (files identical).

- [ ] **Step 13.4 — Commit on dp-insights**

```bash
cd C:/laragon/www/modules/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/public/ frontend/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend): public + backstage pages from daem-society

Verbatim file-by-file copy of:
- frontend/public/      (was daem-society/public/pages/insights/)
  - index.php, detail.php, hero.php, grid.php, cta.php
  - detail/{hero,content,related}.php
  - data/{ai-in-civil-society,digital-rights-2025,open-source-community}.php
- frontend/backstage/   (was daem-society/public/pages/backstage/insights/)
  - index.php, _form.php
  - new/index.php, edit/index.php
  - insight-form.css, insight-form-page.js, insight-panel.js, empty-state.svg

No content changes — daem-society's index.php gains a module-router block
in Task 14 to mount these from the new location. Originals are deleted
from daem-society in Task 14."
```

DO NOT push.

---

## Task 14: Add module router to daem-society + delete originals

**Repos for commits:** `daem-society`

- [ ] **Step 14.1 — Read current index.php asset router (recon shows it lives at lines 70-85)**

Verify by `grep -n "/shared/" C:/laragon/www/sites/daem-society/public/index.php | head -5`

- [ ] **Step 14.2 — Add module asset router AFTER /shared/ block**

Insert immediately after the `/shared/` block:

```php
// Module assets — files live at C:\laragon\www\modules\<name>\frontend\assets\* and
// are served under /modules/<name>/assets/* on every consuming site. Path-traversal
// guarded. Same MIME mapping as /shared/.
if (preg_match('#^/modules/([a-z][a-z0-9-]*)/assets/(.+)$#', $uri, $m)) {
    $module = $m[1];
    $rel = $m[2];
    $base = realpath(__DIR__ . '/../../../modules/' . $module . '/frontend/assets');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}
```

- [ ] **Step 14.3 — Discover known modules at top of index.php**

Add near the top of `index.php` (after session bootstrap, before any routing):

```php
// Discover modules (filesystem scan of ../../../modules/*/module.json) so the
// router below knows which /<name>/* and /backstage/<name>/* URLs to forward
// to module frontend dirs.
$daemsKnownModules = [];
$modulesBase = realpath(__DIR__ . '/../../../modules');
if ($modulesBase !== false && is_dir($modulesBase)) {
    foreach ((array) glob($modulesBase . '/*/module.json') as $manifestPath) {
        if (!is_string($manifestPath)) continue;
        $data = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) continue;
        $name = $data['name'];
        $modDir = dirname($manifestPath);
        $publicDir = isset($data['frontend']['public_pages'])
                   ? $modDir . '/' . $data['frontend']['public_pages'] : null;
        $backstageDir = isset($data['frontend']['backstage_pages'])
                      ? $modDir . '/' . $data['frontend']['backstage_pages'] : null;
        $daemsKnownModules[$name] = [
            'public' => $publicDir !== null ? rtrim($publicDir, '/\\') : null,
            'backstage' => $backstageDir !== null ? rtrim($backstageDir, '/\\') : null,
        ];
    }
}
```

- [ ] **Step 14.4 — Add module page router BEFORE existing pages router**

Find where `index.php` currently routes to `pages/<X>/index.php` files (likely a switch on `$uri` or a series of `if (str_starts_with($uri, '/insights')) { require '../pages/insights/index.php'; exit; }`-style blocks).

Add immediately BEFORE that section:

```php
// Module page routing — match /<name>/[<sub-path>] or /backstage/<name>/[<sub-path>]
// where <name> is a known module. Forwards into modules/<name>/frontend/{public,backstage}/.
if (preg_match('#^/backstage/([a-z][a-z0-9-]*)(/.*)?$#', $uri, $m)
    && isset($daemsKnownModules[$m[1]])
    && $daemsKnownModules[$m[1]]['backstage'] !== null
) {
    $module = $m[1];
    $sub = ltrim($m[2] ?? '', '/');
    $base = realpath($daemsKnownModules[$module]['backstage']);
    if ($base !== false) {
        // Default to index.php if no sub-path; otherwise resolve <sub>/index.php first, then <sub>.php.
        $candidates = [];
        if ($sub === '') {
            $candidates[] = $base . '/index.php';
        } else {
            $candidates[] = $base . '/' . $sub . '/index.php';
            $candidates[] = $base . '/' . $sub . '.php';
        }
        foreach ($candidates as $cand) {
            $real = realpath($cand);
            if ($real !== false && str_starts_with($real, $base) && is_file($real)) {
                require $real;
                exit;
            }
        }
    }
    // Fall through if not matched — existing routing may still serve it.
}

if (preg_match('#^/([a-z][a-z0-9-]*)(/.*)?$#', $uri, $m)
    && isset($daemsKnownModules[$m[1]])
    && $daemsKnownModules[$m[1]]['public'] !== null
) {
    $module = $m[1];
    $sub = ltrim($m[2] ?? '', '/');
    $base = realpath($daemsKnownModules[$module]['public']);
    if ($base !== false) {
        $candidates = [];
        if ($sub === '') {
            $candidates[] = $base . '/index.php';
        } else {
            $candidates[] = $base . '/' . $sub . '/index.php';
            $candidates[] = $base . '/' . $sub . '.php';
        }
        foreach ($candidates as $cand) {
            $real = realpath($cand);
            if ($real !== false && str_starts_with($real, $base) && is_file($real)) {
                require $real;
                exit;
            }
        }
    }
    // Fall through if not matched.
}
```

- [ ] **Step 14.5 — Smoke-test that module routing serves the moved pages**

Open in browser:
- `http://daem-society.local/insights` — should render the public Insights index (from `modules/insights/frontend/public/index.php`)
- `http://daem-society.local/backstage/insights` — should render the backstage Insights list

Expected: identical visual output to pre-migration. If you see "Page not found" or a different layout, check the module router block fires (add temporary `error_log("MODULE ROUTE MATCHED: $module/$sub")` for debugging).

- [ ] **Step 14.6 — Delete original Insights pages from daem-society**

```bash
cd C:/laragon/www/sites/daem-society
rm -rf public/pages/insights
rm -rf public/pages/backstage/insights
```

- [ ] **Step 14.7 — Re-test browser**

Same URLs as Step 14.5. Expected: identical output. If broken, the module router isn't matching — check the regex + the `$daemsKnownModules` lookup.

- [ ] **Step 14.8 — Commit on daem-society**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" rm -r public/pages/insights public/pages/backstage/insights
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Migrate(frontend): Insights pages → modules/insights/frontend/

public/index.php gains:
- A /modules/<name>/assets/* asset router (mirrors /shared/* pattern,
  realpath-guarded).
- A module-discovery scan that reads modules/*/module.json once per request
  to know which module names are valid.
- A page router that forwards /<known-module>/[<path>] and
  /backstage/<known-module>/[<path>] requests into the module's frontend
  dir. Falls through to existing routing if no module match.

Original Insights pages deleted (16 files):
- public/pages/insights/{index,detail,hero,grid,cta}.php + detail/* + data/*
- public/pages/backstage/insights/{index,_form,new/index,edit/index}.php + assets

Visual output is unchanged — files moved verbatim to modules/insights/
in Task 13. Browser verified."
```

---

## Task 15: Final verification + push

**Repo for commits:** none (verification only)

- [ ] **Step 15.1 — Full PHPStan + test sweep**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
composer test 2>&1 | tail -10
composer test:e2e 2>&1 | tail -10
```
Expected: 0 errors, all suites green.

- [ ] **Step 15.2 — Manual smoke-test browser**

Open each URL and confirm identical pre-migration behaviour:

- `http://daem-society.local/insights` — public Insights grid + filter buttons
- `http://daem-society.local/insights/<known-slug>` — Insights detail page
- `http://daem-society.local/backstage/insights` — backstage list with KPI strip
- `http://daem-society.local/backstage/insights/new` — create form
- `http://daem-society.local/backstage/insights/edit?id=<known-id>` — edit form

Test the create flow end-to-end (create insight → save → verify it appears in list → edit → delete).

- [ ] **Step 15.3 — Smoke-test API endpoints with curl**

```bash
curl -s "http://daems-platform.local/api/v1/insights" | head -c 500
# Expected: JSON list with insights array

curl -s "http://daems-platform.local/api/v1/insights/<known-slug>" | head -c 500
# Expected: JSON object for single insight
```

- [ ] **Step 15.4 — Commit grep audit**

```bash
# In each repo, run a grep for stragglers
cd C:/laragon/www/daems-platform
grep -rn "Daems\\\\Domain\\\\Insight\\|Daems\\\\Application\\\\Insight\\|SqlInsightRepository\\|src/Domain/Insight\\|src/Application/Insight" \
  src/ tests/ bootstrap/ routes/ 2>&1 | head -20
# Expected: zero matches in live code

cd C:/laragon/www/sites/daem-society
grep -rn "pages/insights\\|pages/backstage/insights" public/ 2>&1 | head -10
# Expected: zero matches
```

If any matches surface, fix and re-commit on the appropriate repo.

- [ ] **Step 15.5 — Report all SHAs to user**

Compile final report listing:
- daems-platform: 7 commits (Tasks 1, 2, 3, 4, 5, 6, 7, 12)
- modules/insights: 4 commits (Tasks 8, 9, 10, 11, 13)
- daem-society: 1 commit (Task 14)

Do NOT push. Wait for explicit "pushaa" instruction.

---

## Self-review notes

### Spec coverage check

Spec sections vs. plan tasks:

| Spec section | Covered by |
|---|---|
| §1 Vision | Plan header (architectural intent recapped) |
| §2 Phasing | Plan scope clearly says "Phase 1 only"; future phases noted |
| §3.1 Filesystem layout | Tasks 8 (skeleton) + 13 (frontend move) realize it |
| §3.2 Module manifest schema | Tasks 1, 2, 8 (defines + validates + creates first manifest) |
| §3.3 ModuleRegistry | Tasks 3, 4, 5, 6 (build + wire in) |
| §3.4 Migrations | Task 7 (runner extension + data fix), Task 11 (move + rename) |
| §3.5 Frontend integration | Task 14 (module router in daem-society) |
| §3.6 Bindings + routes extraction | Task 10 (write module's bindings.php + routes.php), Task 12 (delete from core) |
| §3.7 Test infrastructure | Task 5 (TEST mode in registry), Task 6 (KernelHarness wiring), Task 11 (move tests + InMemory fake) |
| §3.8 Tooling & verification | Task 6 (phpstan + composer.json), Task 12 (verification gate), Task 15 (final) |
| §3.9 Versioning + GitHub workflow | Task 8 (initial commit on dp-insights/dev), Task 15 (final report — push deferred) |
| §4 Migration step list | Tasks 9-13 implement it file-by-file |
| §5 Risks + mitigations | Task 12 (verification gate addresses each risk before delete commits) |
| §6 Success criteria | Task 15 (manual + automated checks) |

No spec gaps.

### Placeholder scan

Searched for "TBD", "TODO", "fill in", "implement later" — none in the plan body. All "Similar to Step N" patterns include the actual code (Step 14.4 mirrors 14.3 but each is fully spelled out).

The `// Copy the bodies of these methods VERBATIM` comment in Step 9.5 is borderline — it tells the implementer to literally copy 6 method bodies from the existing `BackstageController.php` into the new file. Implementer needs to read those methods. This is acceptable because (a) the source location is named precisely (line numbers from recon), (b) "copy verbatim" is a concrete instruction, not "implement later".

### Type/path consistency

- `ModuleManifest` accessor names: `name()`, `version()`, `namespace()`, `absoluteSrcPath()`, `absoluteBindingsPath()`, `absoluteTestBindingsPath()`, `absoluteRoutesPath()`, `absoluteMigrationsPath()`, `absolutePublicPagesPath()`, `absoluteBackstagePagesPath()`, `absoluteAssetsPath()` — all consistent across Tasks 1, 3, 4, 5, 6.
- `ModuleRegistry` method names: `discover()`, `all()`, `get()`, `registerAutoloader(ClassLoader)`, `registerBindings(Container, mode)`, `registerRoutes(Router, Container)`, `migrationPaths()` — consistent.
- Mode constants: `ModuleRegistry::PROD`, `ModuleRegistry::TEST` — consistent in Tasks 5, 6.
- Module filesystem layout: `modules/<name>/{module.json, README.md, .gitignore, backend/{src,bindings.php,bindings.test.php,routes.php,migrations,tests}, frontend/{public,backstage,assets}}` — consistent across Tasks 8, 9, 10, 11, 13.
- Namespace: `DaemsModule\Insights\<L>\X` — consistent across Tasks 9, 10, 11.
- Migration file naming: `insights_NNN_<desc>.{sql,php}` — consistent in Tasks 7, 11.
- Route paths: `/api/v1/insights`, `/api/v1/insights/{slug}`, `/api/v1/backstage/insights/*` — match recon and Task 10.
