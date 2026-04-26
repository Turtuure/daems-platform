# daems-platform modular architecture — design spec

**Date:** 2026-04-26
**Status:** Approved (brainstorming complete, awaiting Phase 1 plan)
**Scope:** Vision for a multi-tenant modular architecture where each domain (insights, members, events, projects, forum, notifications, applications) lives as a self-contained, optional module that GSA can enable/disable per-tenant based on subscription tier. Plus the **detailed design for Phase 1** — the manifest convention + Insights pilot — which is the only part that gets a writing-plans plan from this spec.

**Out of scope of THIS spec (each gets its own future spec):**
- Phase 2: runtime module loader + `tenant_modules` table + 403-middleware
- Phase 3: GSA UI + service tier presets
- Phase 4: per-domain migrations of remaining 6 domains

---

## 1. Vision (end state, ~6+ months out)

Each domain lives as its own independent git repo in `C:\laragon\www\modules\<domain>\`. `daems-platform` discovers modules at boot by scanning `modules/*/module.json` manifests — it does not need to know in advance which modules exist. A `tenant_modules` table records which modules are enabled per tenant; middleware 403s API routes for disabled modules. A GSA UI at `/backstage/modules` toggles modules per tenant and applies service-tier presets (Basic/Pro/Enterprise) that flip a documented bundle of modules.

### Module definition (v1, A-isolation)

A module:
- Owns its backend code (Domain / Application / Infrastructure layers under its own `DaemsModule\<Name>\` PSR-4 namespace).
- Owns its migrations (under the module's own folder, prefixed with module name to avoid number collisions across modules).
- Owns its frontend code (PHP page templates, JS, CSS) consumed by `daem-society` (and any other tenant frontend) via the existing `/shared/<path>`-style asset router pattern.
- Declares itself via `module.json` listing: name, version, namespace, paths, bindings file, routes file, migrations folder, frontend folders, version requirements.
- May reference **only** the platform core: `Tenant`, `User`, `Auth`, `Locale`, `Shared`, `Config`, `Invite`, `Framework`. **Cross-module references are forbidden in v1.**

### Disable behavior (B mode chosen)

When GSA disables a module for a tenant:
- UI hides the module's nav entries / menu items.
- API routes return HTTP 403 Forbidden.
- Background jobs (scheduled publishes, queued tasks) **continue to run**. Data is preserved.
- Re-enabling restores access immediately. Anything that processed during the disabled window remains in the DB.

This is the typical SaaS "premium feature locked" pattern — data integrity preserved, monetisation gate respected.

### Future-evolution candidates (parked, not in v1)

These were considered and intentionally deferred during brainstorming:
- **D-disable mode (provision/deprovision)** — disabling triggers a grace period after which module data is deleted; re-enabling re-provisions from empty. Use when GDPR / regulatory data-deletion requirements emerge. Architecture supports the addition: `tenant_modules.status` is an enum from day one (not boolean), so new states `pending_deprovision` / `deprovisioning` / `deprovisioned` slot in non-breakingly. Implementation would need a scheduled job, audit log, and data-deletion queue.
- **B-isolation (declared cross-module dependencies)** — modules declare `requires: ["forum"]` in `module.json`; loader cascades disablement so dependents auto-disable. Adopt when the first cross-module feature is genuinely needed and the v1 strict-isolation rule starts blocking value. Additive change — does not break existing modules.
- **C-isolation (soft references with graceful degradation)** — modules check at runtime whether peer modules are enabled and conditionally hide features. Use only if B turns out to be too rigid in practice. Higher complexity (every cross-module call site needs an `if (Modules::enabled('forum'))` guard).
- **DB-per-tenant or DB-per-module isolation** — adopt only if tenant count grows massively or data-isolation regulation requires it. Today's all-in-`daems_db` model handles the foreseeable scale.
- **Composer-package per module** — adopt if formal semver, lockfiles, and PHP-package distribution become valuable. Today's filesystem-discovery + sibling-repo model is lighter and matches the actual deploy environment (local Laragon dev + manual production deploy).

---

## 2. Phasing

This spec covers **the vision + Phase 1 detail**. Each subsequent phase gets its own spec before implementation. Plan from this spec only covers Phase 1.

| Phase | Deliverable | Spec status |
|---|---|---|
| **1. Manifest convention + Insights pilot** | `module.json` schema, `ModuleRegistry` boot-time loader, autoloader registration, asset-router extension, migration-runner extension. Insights backend moves `daems-platform/src/{Domain,Application,Infrastructure}/Insight/` → `modules/insights/backend/src/...`. Frontend moves `daem-society/public/pages/{insights,backstage/insights}/` → `modules/insights/frontend/{public,backstage}/`. Insight-specific bindings/routes move from `bootstrap/app.php` and `routes/api.php` into the module's `bindings.php` + `routes.php`. **No runtime gating yet — every discovered module is always loaded for every tenant, exactly like today.** | THIS SPEC |
| **2. Module loader + tenant_modules** | `tenant_modules` table (`id`, `tenant_id`, `module_name`, `status` enum, `enabled_at`, `disabled_at`, `enabled_by_user_id`). Middleware that 403s disabled-module routes. Frontend `ModuleManifestReader` consults the table and hides menu items. Background job runner ignores the disable flag (B-mode behaviour). | Future Phase 2 spec |
| **3. GSA UI + service tiers** | `/backstage/modules` page where GSA sees + toggles modules per tenant. Service-tier definitions (`config/service-tiers.php` or DB) that map tier name → module bundle. Audit log of toggle events. | Future Phase 3 spec |
| **4. Migrate remaining domains** | Members, Events, Projects, Forum, Notifications, Applications follow the pilot's convention. One PR per domain. Cross-domain backstage code (e.g. `ListPendingApplicationsForAdmin` which currently joins Membership + Project + Forum repos) is resolved by either lifting it into core or splitting into per-module backstage controllers. | Per-domain future specs |

**Critical:** Phase 1 produces zero user-visible change. Insights works exactly as it does today. The benefit is purely architectural — the module convention is proven, and subsequent domains can follow it with confidence. This is intentional: separate the "prove the pattern" risk from the "actually gate features" risk.

---

## 3. Phase 1: technical design

### 3.1 Filesystem layout

```
C:\laragon\www\
├── daems-platform\        (own git repo — "core platform")
├── sites\
│   ├── daem-society\      (own git repo — frontend)
│   ├── ioca\
│   └── ...
└── modules\
    ├── insights\          (own git repo dp-insights — already created on GitHub)
    ├── shared\
    │   └── components\    (own git repo dp-components — already created on GitHub)
    └── (members/, events/, projects/, forum/, ... future)
```

Modules are siblings to `daems-platform` on disk, not git submodules. Each is cloned independently at deploy time. This matches the present state (user has already created `dp-insights` and `dp-components` as standalone repos) and avoids the UX warts of `git submodule`.

### 3.2 Module manifest — `module.json`

Example `modules/insights/module.json`:

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

Field semantics:
- `name` — kebab-case identifier, used as URL prefix and as `tenant_modules.module_name` value in Phase 2.
- `version` — semver. Used by `requires` checks across modules in future B-isolation phases. Phase 1 only validates that the field exists.
- `namespace` — PSR-4 namespace prefix the module declares. Always under `DaemsModule\\<Name>\\` to guarantee no collision with core `Daems\\`.
- `src_path` — relative path under the module dir where the namespace is rooted.
- `bindings` — path to a PHP file that returns a closure `function (Container $c): void`. The closure registers DI bindings for the module's repositories, use cases, controllers.
- `routes` — path to a PHP file that returns a closure `function (Router $r, Container $c): void`. The closure registers HTTP routes the module owns.
- `migrations_path` — directory containing the module's migration PHP files. Migration filenames MUST be prefixed with `<module>_` (e.g. `insights_001_create_insights_table.php`) to avoid number collisions across modules.
- `frontend.public_pages` — directory mapped to `/<name>/...` on consuming frontend sites.
- `frontend.backstage_pages` — directory mapped to `/backstage/<name>/...`.
- `frontend.assets` — directory mapped to `/modules/<name>/assets/...`.
- `requires.core` — minimum platform version. Phase 1 just validates presence; enforcement comes when platform versioning is introduced.

A JSON schema for `module.json` lives at `daems-platform/docs/schemas/module-manifest.schema.json`. The `ModuleRegistry` validates each manifest against it at boot and throws on schema mismatch.

### 3.3 ModuleRegistry — boot-time discovery

New class `daems-platform/src/Infrastructure/Module/ModuleRegistry.php`:

```php
final class ModuleRegistry {
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    public function discover(string $modulesDir): void {
        // Scan modulesDir/* for module.json, validate against schema,
        // construct ModuleManifest value object, store in $modules keyed by name.
        // Throws if two modules declare the same name or if a manifest fails schema validation.
    }

    public function all(): array { return $this->modules; }
    public function get(string $name): ?ModuleManifest { /* … */ }

    public function registerAutoloader(): void {
        // Tells composer's loader: namespace DaemsModule\<Name>\ → modules/<name>/<src_path>.
        // Uses Composer's runtime ClassLoader (require vendor/autoload.php returns it).
    }

    public function registerBindings(Container $c): void {
        // foreach $modules → require bindings.php → invoke returned closure with $c.
    }

    public function registerRoutes(Router $r, Container $c): void {
        // foreach $modules → require routes.php → invoke returned closure with $r, $c.
    }

    public function migrationPaths(): array {
        // Returns list of all modules' migrations directories.
    }
}
```

`bootstrap/app.php` gains roughly eight new lines, placed before existing core wiring:

```php
$registry = new \Daems\Infrastructure\Module\ModuleRegistry();
$registry->discover(__DIR__ . '/../../modules');
$registry->registerAutoloader();
$container->bind(\Daems\Infrastructure\Module\ModuleRegistry::class, fn() => $registry);

// … existing core bindings …

$registry->registerBindings($container);

// … existing core routes …

$registry->registerRoutes($router, $container);
```

The autoloader call must happen before any `class_exists()` checks elsewhere in bootstrap. The bindings call must happen after core bindings (so module bindings can depend on `Connection`, `Clock`, `IdGenerator`, etc). The routes call must happen after core routes and after all bindings are in place.

### 3.4 Migrations

`MigrationRunner` (currently single-path) gains the ability to scan multiple directories:

```php
$runner = new MigrationRunner($db);
$runner->addPath(__DIR__ . '/../database/migrations');  // core
foreach ($registry->migrationPaths() as $p) {
    $runner->addPath($p);
}
$runner->run();
```

Migration files keep their existing structure (PHP class with `up()` / `down()`), but filenames in module directories MUST be prefixed with the module name: `insights_001_create_insights_table.php`, `insights_002_add_featured_flag.php`, etc.

The `migrations` tracking table (which records ran migrations) gains a nullable `module` column (NULL = core migration). Existing rows already have the column NULL by default — no data migration required.

**Insights' existing migrations** (currently numbered `002`, `026`, `060`, `061`, `062` in `daems-platform/database/migrations/`) get **renamed and moved** to `modules/insights/backend/migrations/insights_001_*.php` through `insights_005_*.php`.

**Caveat for existing dev DBs:** the `migrations` tracking table records ran migrations by filename. After the rename + move, dev databases that already have the old `002_*.php` (etc.) names recorded would see the new `insights_001_*.php` (etc.) as fresh and try to re-run them — which would fail because the tables already exist. Two mitigation options, plan picks one:

1. **Data-fix migration** — a one-shot core migration in `daems-platform/database/migrations/` updates the `migrations` table rows from old filename → new filename. Idempotent (safe to re-run). This is the recommended option because it leaves the migration runner's contract clean.
2. **Idempotent migration runner** — extend the runner to detect that the migration's target schema already exists (table present with expected columns) and skip with a "already-applied" record. More invasive change to the runner with broader implications.

The plan defaults to option 1 unless a specific blocker emerges.

### 3.5 Frontend integration (daem-society)

`daem-society/public/index.php` gains a routing extension: when a request matches `/<module-name>/...` or `/backstage/<module-name>/...` and that module is registered, route into the module's frontend directory.

Concretely, after the existing `/shared/` asset-router block (`public/index.php:70-85`), insert a module-router block:

```php
// Module routes — modules/<name>/frontend/...
if (preg_match('#^/([a-z0-9-]+)(/.*)?$#', $uri, $m) ||
    preg_match('#^/backstage/([a-z0-9-]+)(/.*)?$#', $uri, $m)) {
    // Lookup module manifest by name; if found, route into its frontend dir.
    // Falls through to existing routing if module not registered.
}
```

A small `ModuleManifestReader` helper (in `daem-society/public/pages/_modules.php`) reads `modules/*/module.json` files (cached per request) and tells `index.php` which modules exist + where their frontend dirs are.

Asset routing for module assets:
- `/modules/<name>/assets/<file>` → `modules/<name>/frontend/assets/<file>`
- Same path-traversal-guarded `realpath` pattern as the existing `/shared/` router.

**Phase 1 integration constraint:** since Phase 2 (the runtime gate) doesn't exist yet, the frontend treats every discovered module as enabled. The `tenant_modules` consultation in `ModuleManifestReader` is added in Phase 2.

### 3.6 Bindings + routes extraction (Insights specifics)

The Insights-related bindings currently live in `daems-platform/bootstrap/app.php`. They will move verbatim into `modules/insights/backend/bindings.php`:

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Insights\Application\CreateInsight\CreateInsight;
// … etc

return function (Container $c): void {
    $c->singleton(
        \DaemsModule\Insights\Domain\InsightRepositoryInterface::class,
        fn(Container $c) => new \DaemsModule\Insights\Infrastructure\SqlInsightRepository(
            $c->make(\Daems\Infrastructure\Framework\Database\Connection::class),
        ),
    );
    $c->bind(CreateInsight::class, /* … */);
    // … all other Insight bindings move here verbatim
};
```

Insight-related routes from `routes/api.php` move into `modules/insights/backend/routes.php`:

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;
use DaemsModule\Insights\Controller\InsightController;

return function (Router $r, Container $c): void {
    $r->get('/api/v1/insights',         [$c->make(InsightController::class), 'index']);
    $r->get('/api/v1/insights/{slug}',  [$c->make(InsightController::class), 'show']);
    // … etc
};
```

The current `BackstageController` is a multi-domain monolith. The Insight-specific methods (`backstageInsightsList`, `backstageInsightCreate`, etc.) extract into a new `InsightBackstageController` that lives in the module. The backstage routes for Insights (`/api/v1/backstage/insights/*`) move from `routes/api.php` into the module's `routes.php`. The plan task list will enumerate exactly which methods extract and which stay in the core `BackstageController`.

### 3.7 Test infrastructure

`tests/Support/KernelHarness.php` mirrors the production container for E2E/integration tests with InMemory fakes. After Phase 1, it must also bootstrap the `ModuleRegistry`:

```php
$registry = new ModuleRegistry();
$registry->discover(__DIR__ . '/../../../modules');
$registry->registerAutoloader();
$registry->registerBindings($container);
$registry->registerRoutes($router, $container);
```

The catch: `KernelHarness` registers InMemory fakes (e.g., `InMemoryInsightRepository`) instead of SQL repos. After Insights moves into a module, the fake also moves: `tests/Support/Fake/InMemoryInsightRepository.php` → `modules/insights/backend/tests/Support/Fake/InMemoryInsightRepository.php`. The harness bootstrap registers the module's bindings file in **test mode** — module bindings files MUST therefore branch on environment:

Better solution: each module ships **two** binding files. `bindings.php` (production) and `bindings.test.php` (KernelHarness). The registry reads which one to use from the bootstrap caller. Avoids if/else inside the bindings file. The plan will define this convention precisely.

**Cross-cutting risk: the BOTH-wiring rule** (per CLAUDE.md): every new use case / repo binding must exist in both `bootstrap/app.php` AND `KernelHarness`. After Phase 1, the new rule is: every module ships both `bindings.php` and `bindings.test.php`, and ModuleRegistry's `registerBindings()` accepts a flag for which to load. If a module forgets `bindings.test.php`, E2E tests that touch that module fail loudly at boot — which is louder and earlier than the current silent KernelHarness gap. Better than the status quo.

### 3.8 Tooling & verification

Phase 1 must not regress:
- `composer analyse` → PHPStan level 9, 0 errors.
- `composer test` (Unit + Integration) → all pass.
- `composer test:e2e` → all pass.
- All 7 backstage KPI pages render identically (manual smoke-test, since the recent shared KPI-card extraction touched these).

PHPStan needs to know about the new `DaemsModule\<Name>\` namespaces. Two options:
1. Add `modules/*/backend/src/` paths to `phpstan.neon`'s `paths:` config explicitly.
2. Glob: `paths: [src/, ../modules/*/backend/src/]`.

Option 2 is the default — auto-discovers new modules as they're added. The plan validates that PHPStan picks up the Insights namespace correctly.

### 3.9 Versioning + GitHub workflow

`dp-insights` (the GitHub repo at `Turtuure/dp-insights`) is currently empty. After Phase 1:
- Branch `dev` becomes the working branch (matches `daems-platform` and `daem-society` conventions).
- Initial commit contains the moved Insights backend + frontend + manifest + tests.
- `git tag v1.0.0` after first successful end-to-end test in dev environment.
- `dp-insights` releases are independent of `daems-platform` releases. The `module.json` `requires.core` field documents minimum platform version.

`daems-platform`'s deploy script (or the dev's manual `git pull` flow) needs to know to also `git pull` each `modules/*/`. A simple shell script `scripts/pull-modules.sh` covers this for now. Phase 4 (when 7 modules exist) might warrant more sophisticated tooling.

---

## 4. Migration step list (high-level — actual plan covers this in detail)

```
PROVENANCE: Insights file inventory comes from existing daems-platform tree.
            Verify with grep before each move.

MOVE backend code:
  daems-platform/src/Domain/Insight/                  → modules/insights/backend/src/Domain/
  daems-platform/src/Application/Insight/             → modules/insights/backend/src/Application/
  daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php
                                                      → modules/insights/backend/src/Infrastructure/SqlInsightRepository.php

EXTRACT controllers:
  Insight-specific methods of BackstageController + the Insight public-API methods
                                                      → modules/insights/backend/src/Controller/InsightController.php
                                                        + InsightBackstageController.php

MOVE migrations (rename with insights_ prefix):
  database/migrations/{002,026,060,061,062}_*insight*.php
                                                      → modules/insights/backend/migrations/insights_{001..005}_*.php

MOVE frontend:
  daem-society/public/pages/insights/                 → modules/insights/frontend/public/
  daem-society/public/pages/backstage/insights/       → modules/insights/frontend/backstage/

MOVE tests:
  tests/Unit/Application/Insight/                     → modules/insights/backend/tests/Unit/
  tests/Integration/...Insight*.php                   → modules/insights/backend/tests/Integration/
  tests/Isolation/InsightTenantIsolationTest.php      → modules/insights/backend/tests/Isolation/
  tests/Isolation/InsightStatsTenantIsolationTest.php → modules/insights/backend/tests/Isolation/
  tests/Support/Fake/InMemoryInsightRepository.php    → modules/insights/backend/tests/Support/InMemoryInsightRepository.php

CREATE:
  modules/insights/module.json
  modules/insights/backend/bindings.php          (extracted from bootstrap/app.php)
  modules/insights/backend/bindings.test.php     (uses InMemory fake)
  modules/insights/backend/routes.php            (extracted from routes/api.php)
  modules/insights/README.md
  modules/insights/.gitignore

NEW IN daems-platform (core):
  src/Infrastructure/Module/ModuleRegistry.php
  src/Infrastructure/Module/ModuleManifest.php       (value object)
  docs/schemas/module-manifest.schema.json

EXTEND in daems-platform (core):
  src/Infrastructure/Framework/Database/MigrationRunner.php  (multi-path support)
  bootstrap/app.php                                          (registry boot)
  tests/Support/KernelHarness.php                            (registry boot in test mode)
  phpstan.neon                                               (paths glob)

EXTEND in daem-society (frontend):
  public/index.php                                           (module-route block)
  public/pages/_modules.php                                  (ModuleManifestReader helper)

NAMESPACE REWRITE:
  All Daems\Domain\Insight\*       → DaemsModule\Insights\Domain\*
  All Daems\Application\Insight\*  → DaemsModule\Insights\Application\*
  All Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository
                                   → DaemsModule\Insights\Infrastructure\SqlInsightRepository

DELETE (after move + green tests):
  Original Insight-related files in daems-platform/src/, /tests/, /database/migrations/
  Original Insight bindings in bootstrap/app.php
  Original Insight routes in routes/api.php
  Original Insight pages in daem-society/public/pages/{insights,backstage/insights}/

LEAVE UNTOUCHED:
  Core domains (Tenant, User, Auth, Locale, Shared, Config, Invite)
  Framework code (Database, Http, Container)
  daem-society backstage shell + shared partials (kpi-card module already shared)
  migrations table (gains nullable `module` column via a tiny core migration)
```

---

## 5. Risks + mitigations

| Risk | Mitigation |
|------|------------|
| Namespace rewrite (~50-80 use-statement updates) silently misses one site, causing runtime fatal at first request that exercises that path. | After namespace rewrite, run `composer analyse` (PHPStan level 9) — it surfaces every unresolved class. Then run all 3 test suites. Every Insights call site must be exercised by a test. |
| `BackstageController` extraction misses a method, so the new module is missing functionality. | Plan task enumerates every method that mentions `Insight` in its name or body. Code review compares old and new controller files line-by-line before deletion. |
| Migration rename causes the migration runner to re-run already-applied migrations on dev DBs. | Plan adds a one-shot core data-fix migration that updates existing `migrations` table rows from old filename → new filename. Tested against a snapshot of the dev DB. |
| KernelHarness boots the module registry but a test still expects the old `Daems\Domain\Insight\` namespace. | Test files move with the same namespace rewrite. Any `use` statement still pointing at the old namespace fails at PHPStan / test boot. |
| `dp-insights` repo getting out of sync with `daems-platform` (a platform commit assumes a module API change but the module hasn't shipped it). | Phase 1 ships both repos in lockstep — single coordinated PR sequence: platform PR adds registry + ModuleManifest; module repo PR adds the moved code. Future phases gain `requires.core` enforcement. |
| Frontend module routing in `daem-society/public/index.php` accidentally catches an existing non-module route. | The module-route block runs AFTER all existing specific routes, with the module-name match restricted to known module names from the registry. Falls through to existing routes if no manifest match. |

---

## 6. Success criteria for Phase 1

- `composer analyse` → 0 errors at PHPStan level 9
- `composer test:all` → all suites green (Unit, Integration, E2E)
- `modules/insights/` contains everything Insights-related, populated as the `dp-insights` repo's `dev` branch
- `daems-platform`'s `src/Domain/Insight/`, `src/Application/Insight/`, `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` no longer exist
- `daem-society/public/pages/insights/` and `.../backstage/insights/` no longer exist
- `bootstrap/app.php` has no Insight-specific bindings
- `routes/api.php` has no Insight-specific routes
- A request to `http://daem-society.local/backstage/insights` renders the same HTML as today
- A request to `GET /api/v1/insights` returns the same JSON as today
- A new `modules/test-module/module.json` could be added by hand and a smoke route would register correctly (proves the convention is real, not Insights-specific)

---

## 7. Decisions made during brainstorming (for the record)

- **Scope (Q1):** C (full end-to-end vision, but speccd in 4 phases).
- **Disable behaviour (Q2):** B (UI hide + API 403, jobs continue, data preserved). D (provision/deprovision) deferred to future phase.
- **Cross-module references (Q3):** A (strict isolation — modules may reference only core). B (declared dependencies) and C (soft references) deferred.
- **Pilot domain:** Insights (smallest by class count, only depends on Tenant + Locale, isolated migrations, already user-aligned via the pre-created `dp-insights` repo).
- **Phasing:** Vision spec covers all phases; only Phase 1 gets a writing-plans plan from this spec. Phases 2-4 each get their own future spec when their predecessor ships.
- **Out-of-scope of this spec:** runtime gate, GSA UI, service tiers, migrations of Members / Events / Projects / Forum / Notifications / Applications.
