# Module Registry, Tenant Management UI & Default Public Site Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add platform-level tenant module gating (GSA grants availability → tenant admin enables), build the GSA TenantManagement backstage UI, ship a default public site fallback, and flip the platform locale default to `en_GB`.

**Architecture:** Extend the existing `Daems\Infrastructure\Module\ModuleRegistry` (already discovers `c:/laragon/www/modules/<name>/module.json`) with platform-level metadata sourced from a new `config/modules.php` file. Add a `tenant_modules` table holding two-tier state (`available_at` set by GSA, `enabled_at` set by tenant admin). A new `TenantModuleResolver` reads merged manifests + state. A new `ModuleRouteGuard` enforces 404-before-auth on disabled modules. New backstage pages `public/backstage/pages/platform/` give GSA tenant CRUD, domain mgmt, admin assignment, and module availability toggling. Tenant admin gets `/backstage/settings/modules`. A new `public/sites/_default/` directory + `public/sites-router.php` serve any tenant whose `c:/laragon/www/sites/{slug}/` directory does not exist.

**Tech Stack:** PHP 8.3, Composer (existing autoloader), MySQL 8.4, PHPUnit 10.5, PHPStan 2.x level 9. No new external dependencies.

**Spec:** [docs/superpowers/specs/2026-05-07-module-registry-tenant-management-design.md](../specs/2026-05-07-module-registry-tenant-management-design.md) (commits 1942dbc + 683990d).

**Repo affected:** Single repo — `c:/laragon/www/daems-platform/` only. The five extracted modules (events, forum, insights, members, projects in their own sibling repos) are NOT modified. The platform owns `config/modules.php`; each module's own `module.json` is unchanged.

**Branch base:** Cut `module-registry-tenant-mgmt` from `dev` once `backstage-to-platform` merges. If `backstage-to-platform` is still open at execution start, branch from `backstage-to-platform` instead and re-base when the upstream merges.

**Verification gates (must pass before final commit on any code-changing task):**

- `composer analyse` → 0 errors at PHPStan level 9
- `composer test` (Unit + Integration) → all green
- `composer test:e2e` → all green
- `composer test:all` → all green (Unit, Integration, Isolation, E2E)
- Manual smoke checklist (see Task K1 below) executed and recorded

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa". `.claude/` never staged (`git reset HEAD .claude/` if needed).

**Forbidden:** do NOT call `mcp__code-review-graph__*` tools — they previously hung subagent sessions.

**Estimated commit count:** 45–55 tasks → 45–55 commits.

---

## File structure

### New files

```text
config/
  modules.php                                      # platform-level catalog

database/migrations/
  069_create_tenant_modules_table.sql
  070_create_module_audit_table.sql
  071_extend_tenants_for_management_ui.sql
  072_seed_tenant_modules.sql

src/Infrastructure/Module/
  SidebarEntry.php                                 # NEW value object
  RoutePrefixes.php                                # NEW value object

src/Domain/Tenant/
  ModuleState.php                                  # NEW enum
  TenantModule.php                                 # NEW entity
  TenantModulesRepositoryInterface.php             # NEW port
  TenantModuleResolver.php                         # NEW
  ModuleRouteGuard.php                             # NEW
  ModuleAuditEntry.php                             # NEW entity
  ModuleAuditAction.php                            # NEW enum
  ModuleAuditRepositoryInterface.php               # NEW port
  Exception/ModuleNotAvailableException.php
  Exception/ModuleDependencyUnmetException.php
  Exception/ModuleDependentEnabledException.php
  Exception/TenantSlugImmutableException.php
  Exception/TenantPrimaryDomainRequiredException.php
  Exception/TenantSuspendedException.php

src/Domain/Tenant/                                 # extended
  Tenant.php                                       # +displayName/publicDescription/supportedLocales/defaultLocale/suspended/suspendedReason
  TenantRepositoryInterface.php                    # +update/suspend/reactivate
  TenantDomainRepositoryInterface.php              # NEW (tenant_domains write port)

src/Application/Backstage/Platform/
  CreateTenant/{CreateTenant.php, CreateTenantInput.php, CreateTenantOutput.php}
  UpdateTenantBasics/{UpdateTenantBasics.php, UpdateTenantBasicsInput.php}
  SuspendTenant/{SuspendTenant.php, SuspendTenantInput.php}
  ReactivateTenant/{ReactivateTenant.php, ReactivateTenantInput.php}
  AddTenantDomain/{AddTenantDomain.php, AddTenantDomainInput.php}
  UpdateTenantDomain/{UpdateTenantDomain.php, UpdateTenantDomainInput.php}
  RemoveTenantDomain/{RemoveTenantDomain.php, RemoveTenantDomainInput.php}
  GrantAdminToUser/{GrantAdminToUser.php, GrantAdminToUserInput.php}
  RevokeAdminFromUser/{RevokeAdminFromUser.php, RevokeAdminFromUserInput.php}
  GrantModuleAvailability/{GrantModuleAvailability.php, GrantModuleAvailabilityInput.php}
  RevokeModuleAvailability/{RevokeModuleAvailability.php, RevokeModuleAvailabilityInput.php}
  ListTenants/{ListTenants.php, ListTenantsOutput.php}
  GetTenantDetail/{GetTenantDetail.php, GetTenantDetailOutput.php}
  ListTenantModules/{ListTenantModules.php, ListTenantModulesOutput.php}

src/Application/Backstage/Tenant/
  EnableModuleForTenant/{EnableModuleForTenant.php, EnableModuleForTenantInput.php}
  DisableModuleForTenant/{DisableModuleForTenant.php, DisableModuleForTenantInput.php}
  ListTenantModulesForCurrentTenant/{ListTenantModulesForCurrentTenant.php, ListTenantModulesForCurrentTenantOutput.php}

src/Infrastructure/Adapter/Api/Controller/
  Backstage/Platform/TenantsController.php
  Backstage/Platform/TenantDomainsController.php
  Backstage/Platform/TenantAdminsController.php
  Backstage/Platform/PlatformTenantModulesController.php
  Backstage/Tenant/TenantSelfModulesController.php

src/Infrastructure/Adapter/Persistence/Sql/
  SqlTenantModulesRepository.php
  SqlModuleAuditRepository.php
  SqlTenantDomainRepository.php

src/Frontend/
  BackstageSidebar.php                             # NEW

tests/Support/Fake/
  InMemoryTenantModulesRepository.php
  InMemoryModuleAuditRepository.php
  InMemoryTenantDomainRepository.php

tests/Unit/Infrastructure/Module/
  SidebarEntryTest.php
  RoutePrefixesTest.php
  (extends ModuleManifestTest, ModuleRegistryTest)

tests/Unit/Domain/Tenant/
  TenantModuleResolverTest.php
  ModuleRouteGuardTest.php
  TenantModuleTest.php
  ModuleAuditEntryTest.php
  TenantTest.php

tests/Unit/Frontend/
  BackstageSidebarTest.php

tests/Integration/Infrastructure/Persistence/Sql/
  SqlTenantModulesRepositoryTest.php
  SqlModuleAuditRepositoryTest.php
  SqlTenantDomainRepositoryTest.php
  SqlTenantRepositoryExtensionTest.php
  Migration069Test.php
  Migration070Test.php
  Migration071Test.php
  Migration072Test.php

tests/Isolation/
  TenantModulesIsolationTest.php

tests/E2E/Backstage/
  Platform/TenantsE2ETest.php
  Platform/TenantDomainsE2ETest.php
  Platform/TenantAdminsE2ETest.php
  Platform/TenantModulesE2ETest.php
  Tenant/TenantSelfModulesE2ETest.php
  ModuleRouteGuardE2ETest.php

tests/E2E/Public/
  DefaultPublicSiteE2ETest.php

public/backstage/pages/platform/
  index.php                                        # tenants list
  edit.php                                         # tenant edit (5 tabs)
  edit.css
  edit.js

public/backstage/pages/settings/
  modules.php                                      # tenant-admin modules
  modules.css
  modules.js

public/sites/_default/
  index.php
  join.php
  login.php
  suspended.php
  partials/header.php
  partials/footer.php
  partials/join-form.php
  assets/default.css
  assets/default.js
  lang/fi_FI.php
  lang/en_GB.php
  lang/sw_TZ.php

public/
  sites-router.php                                 # NEW front-controller helper
```

### Modified files

```text
src/Infrastructure/Module/ModuleRegistry.php       # +platform metadata merge, +graph validation
src/Infrastructure/Module/ModuleManifest.php       # +platform-metadata getters

src/Domain/Tenant/Tenant.php                       # +new fields
src/Domain/Tenant/TenantRepositoryInterface.php    # +update/suspend/reactivate

src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php  # extensions

src/Frontend/I18n.php                              # DEFAULT_LOCALE: 'fi_FI' → 'en_GB'

bootstrap/app.php                                  # +DI bindings for all new services
tests/Support/KernelHarness.php                    # +bindings for new services + InMemory fakes
tests/Integration/MigrationTestCase.php            # bump high-water mark to 072 (verify path)
tests/Isolation/IsolationTestCase.php              # bump high-water mark to 072

public/backstage/router.php                        # integrate ModuleRouteGuard
public/backstage/api-router.php                    # integrate ModuleRouteGuard
public/backstage/pages/_shared.php                 # use BackstageSidebar
public/index.php                                   # add sites-router.php fallback (or new entry)

lang/fi_FI.php                                     # +modules.*, platform.*, settings.modules.*, default.*
lang/en_GB.php                                     # same keys
lang/sw_TZ.php                                     # same keys

CLAUDE.md                                          # architecture + module recipe + locale notes
```

---

## Task waves (dependency order)

```text
Wave A (data layer, parallel-safe internally)
├── A1: Branch + skeleton (worktree handled by execution skill)
├── A2: Migration 069 (tenant_modules table)
├── A3: Migration 070 (module_audit table)
├── A4: Migration 071 (tenants extension + backfill)
├── A5: Migration 072 (seed tenant_modules for 5×2 = 10 rows)
└── A6: Migration tests (069/070/071/072) + IsolationTestCase HWM bump

Wave B (registry extension, depends on A)
├── B1: SidebarEntry value object + tests
├── B2: RoutePrefixes value object + tests (longest-match algorithm)
├── B3: ModuleManifest extension (new optional getters) + tests
├── B4: config/modules.php with 5 module entries
├── B5: ModuleRegistry::discover() merges config/modules.php + dependency-graph validation + tests
└── B6: Wire bootstrap to load config/modules.php (no-op if discovery already calls a hook)

Wave C (gating domain, depends on B)
├── C1: ModuleState enum + ModuleAuditAction enum
├── C2: TenantModule entity + tests
├── C3: ModuleAuditEntry entity + tests
├── C4: Domain exceptions (6 of them) + tests
├── C5: Repository ports (TenantModulesRepositoryInterface, ModuleAuditRepositoryInterface, TenantDomainRepositoryInterface)
├── C6: TenantModuleResolver + tests (state machine)
├── C7: ModuleRouteGuard + tests (longest-prefix match, 404 logic)
└── C8: Tenant entity extensions (displayName, suspended, etc.) + tests

Wave D (persistence, depends on C)
├── D1: InMemoryTenantModulesRepository + tests
├── D2: InMemoryModuleAuditRepository + tests
├── D3: InMemoryTenantDomainRepository + tests
├── D4: SqlTenantModulesRepository + Integration test
├── D5: SqlModuleAuditRepository + Integration test
├── D6: SqlTenantDomainRepository + Integration test
└── D7: SqlTenantRepository extensions + Integration test

Wave E (Application layer, depends on D)
├── E1: Platform — CreateTenant + Output + tests
├── E2: Platform — UpdateTenantBasics + tests
├── E3: Platform — SuspendTenant + ReactivateTenant + tests
├── E4: Platform — AddTenantDomain + UpdateTenantDomain + RemoveTenantDomain + tests
├── E5: Platform — GrantAdminToUser + RevokeAdminFromUser + tests
├── E6: Platform — GrantModuleAvailability + tests
├── E7: Platform — RevokeModuleAvailability (with cascade) + tests
├── E8: Platform — ListTenants + GetTenantDetail + ListTenantModules read models + tests
├── E9: Tenant — EnableModuleForTenant + DisableModuleForTenant + tests
└── E10: Tenant — ListTenantModulesForCurrentTenant + tests

Wave F (Controllers + DI, depends on E)
├── F1: TenantsController + TenantDomainsController + TenantAdminsController
├── F2: PlatformTenantModulesController + TenantSelfModulesController
└── F3: Wire bootstrap/app.php + KernelHarness for all Wave C-F additions

Wave G (Backstage shell integration, depends on F)
├── G1: BackstageSidebar + tests
├── G2: ModuleRouteGuard integrated into router.php + api-router.php
└── G3: _shared.php sidebar render

Wave H (Backstage UI pages, depends on G)
├── H1: Platform tenants list page + JS
├── H2: Tenant edit shell + tab routing
├── H3: Basics tab UI + API wiring
├── H4: Domains tab UI + API wiring
├── H5: Admins tab UI + API wiring
├── H6: Modules tab UI + API wiring (cascade dialog)
├── H7: Danger zone tab UI + API wiring
└── H8: Settings → Modules tenant-admin page

Wave I (Default public site, can run parallel with H)
├── I1: Default site directory + assets + lang + partials
├── I2: Index page (home) + suspended page
├── I3: Join form + login redirect
└── I4: sites-router.php front-controller integration

Wave J (Locale flip + i18n, depends on H + I)
├── J1: I18n::DEFAULT_LOCALE flip + lang/en_GB.php completeness audit
└── J2: All module/platform/settings/default i18n keys (3 locales)

Wave K (E2E + Isolation tests, depends on G + H + I + J)
├── K1: TenantsE2ETest + TenantDomainsE2ETest + TenantAdminsE2ETest
├── K2: TenantModulesE2ETest (Platform side, with cascade)
├── K3: TenantSelfModulesE2ETest (Tenant side, with dependency locks)
├── K4: ModuleRouteGuardE2ETest
├── K5: DefaultPublicSiteE2ETest
└── K6: TenantModulesIsolationTest

Wave L (final polish)
├── L1: PHPStan level 9 = 0 errors
├── L2: Manual smoke checklist run
├── L3: CLAUDE.md updates
└── L4: Memory updates (project_module_registry.md, project_default_public_site.md, feedback_module_manifests_are_truth.md, project_i18n_milestone.md correction)
```

---

# Wave A — Data layer

## Task A1: Branch + skeleton

**Files:** none yet — branch creation only.

- [ ] **Step 1: Verify clean working tree (or stash any in-progress work)**

```bash
git status --short
```

If anything is uncommitted besides this plan being read, stash:

```bash
git stash push -m "pre-module-registry stash"
```

- [ ] **Step 2: Create branch from dev (or current branch if backstage-to-platform unmerged)**

```bash
git fetch origin
# Prefer base on dev once backstage-to-platform merges:
git checkout -b module-registry-tenant-mgmt origin/dev
# OR if backstage-to-platform is still open:
git checkout -b module-registry-tenant-mgmt backstage-to-platform
```

- [ ] **Step 3: Confirm pristine state of relevant files**

```bash
ls src/Infrastructure/Module/ModuleRegistry.php
ls config/modules.php 2>&1 || echo "expected: not present yet"
```

Expected: `ModuleRegistry.php` exists; `config/modules.php` does not.

- [ ] **Step 4: No commit on this task** — branch creation alone does not produce a commit. Proceed to A2.

---

## Task A2: Migration 069 — tenant_modules table

**Files:**

- Create: `database/migrations/069_create_tenant_modules_table.sql`

- [ ] **Step 1: Write migration**

```sql
-- 069_create_tenant_modules_table.sql
-- Per-tenant module gating state. GSA grants availability (available_at);
-- tenant admin enables (enabled_at). Both NULL = not in gating system.

CREATE TABLE IF NOT EXISTS tenant_modules (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    module_slug     VARCHAR(64)  NOT NULL,
    available_at    DATETIME     NULL,
    available_by    CHAR(36)     NULL,
    enabled_at      DATETIME     NULL,
    enabled_by      CHAR(36)     NULL,
    disabled_at     DATETIME     NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_module (tenant_id, module_slug),
    KEY idx_tenant_enabled (tenant_id, enabled_at),
    CONSTRAINT fk_tm_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_avail_by FOREIGN KEY (available_by) REFERENCES users(id),
    CONSTRAINT fk_tm_enab_by  FOREIGN KEY (enabled_by)   REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply migration to dev DB**

```bash
composer migrate
```

Expected: `069_create_tenant_modules_table.sql` listed as applied.

- [ ] **Step 3: Verify table created**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e "DESCRIBE tenant_modules;"
```

Expected output: 11 columns matching the migration.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/069_create_tenant_modules_table.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): migration 069 — tenant_modules table for two-tier gating"
```

---

## Task A3: Migration 070 — module_audit table

**Files:**

- Create: `database/migrations/070_create_module_audit_table.sql`

- [ ] **Step 1: Write migration**

```sql
-- 070_create_module_audit_table.sql
-- Append-only audit log of every module-state change (grant/revoke/enable/disable).
-- Used by the GSA Tenant Edit "Audit" tab and for compliance review.

CREATE TABLE IF NOT EXISTS module_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    module_slug     VARCHAR(64)  NOT NULL,
    action          ENUM('made_available','revoked_availability','enabled','disabled') NOT NULL,
    actor_user_id   CHAR(36)     NOT NULL,
    actor_role      VARCHAR(32)  NOT NULL,
    reason          TEXT         NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tenant_slug_time (tenant_id, module_slug, created_at),
    CONSTRAINT fk_ma_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ma_actor  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply migration**

```bash
composer migrate
```

- [ ] **Step 3: Verify table**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e "DESCRIBE module_audit;"
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/070_create_module_audit_table.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): migration 070 — module_audit append-only log"
```

---

## Task A4: Migration 071 — tenants extension

**Files:**

- Create: `database/migrations/071_extend_tenants_for_management_ui.sql`

- [ ] **Step 1: Write migration**

```sql
-- 071_extend_tenants_for_management_ui.sql
-- Adds locale + display + suspension fields to tenants for TenantManagement UI.
-- Backfills existing daems and sahegroup rows.

ALTER TABLE tenants
    ADD COLUMN display_name_i18n       JSON         NULL          AFTER name,
    ADD COLUMN public_description_i18n JSON         NULL          AFTER display_name_i18n,
    ADD COLUMN supported_locales       VARCHAR(255) NOT NULL DEFAULT 'en_GB' AFTER public_description_i18n,
    ADD COLUMN default_locale          VARCHAR(8)   NOT NULL DEFAULT 'en_GB' AFTER supported_locales,
    ADD COLUMN suspended_at            DATETIME     NULL          AFTER status,
    ADD COLUMN suspended_reason        TEXT         NULL          AFTER suspended_at;

-- Backfill: Daem Society runs in Finnish today; preserve.
UPDATE tenants
SET display_name_i18n  = JSON_OBJECT('fi_FI', 'Daem Society ry', 'en_GB', 'Daem Society'),
    supported_locales  = 'fi_FI,en_GB,sw_TZ',
    default_locale     = 'fi_FI'
WHERE slug = 'daems';

-- Backfill: Sahegroup defaults to platform default en_GB.
UPDATE tenants
SET display_name_i18n  = JSON_OBJECT('en_GB', 'Sahe Group'),
    supported_locales  = 'fi_FI,en_GB,sw_TZ',
    default_locale     = 'en_GB'
WHERE slug = 'sahegroup';
```

- [ ] **Step 2: Apply migration**

```bash
composer migrate
```

- [ ] **Step 3: Verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e "SELECT slug, display_name_i18n, supported_locales, default_locale FROM tenants;"
```

Expected: Daem Society shows fi_FI default, Sahegroup shows en_GB default.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/071_extend_tenants_for_management_ui.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): migration 071 — tenants display+locale+suspension fields"
```

---

## Task A5: Migration 072 — seed tenant_modules

**Files:**

- Create: `database/migrations/072_seed_tenant_modules.sql`

- [ ] **Step 1: Write migration**

```sql
-- 072_seed_tenant_modules.sql
-- Backfill: every existing tenant gets a tenant_modules row per shipping module
-- with available_at = NOW() and enabled_at = NOW(), preserving current behaviour.
-- Hardcoded list of 5 modules: events, forum, insights, members, projects.
-- Future modules onboard via GSA UI, not via additional migrations.

-- Helper variables.
SET @now := UTC_TIMESTAMP();

-- One INSERT per (existing tenant × module). Use UUID() per row.
INSERT INTO tenant_modules
    (id, tenant_id, module_slug, available_at, available_by, enabled_at, enabled_by, disabled_at, created_at, updated_at)
SELECT UUID(), t.id, m.slug, @now, NULL, @now, NULL, NULL, @now, @now
FROM tenants t
CROSS JOIN (
    SELECT 'events'   AS slug UNION ALL
    SELECT 'forum'             UNION ALL
    SELECT 'insights'          UNION ALL
    SELECT 'members'           UNION ALL
    SELECT 'projects'
) m
WHERE NOT EXISTS (
    SELECT 1 FROM tenant_modules tm
    WHERE tm.tenant_id = t.id AND tm.module_slug = m.slug
);
```

The `WHERE NOT EXISTS` makes the migration idempotent on re-run.

- [ ] **Step 2: Apply migration**

```bash
composer migrate
```

- [ ] **Step 3: Verify 5 × N rows present (N = existing tenants count, expect 2)**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e "SELECT COUNT(*) AS cnt, COUNT(DISTINCT tenant_id) AS tenants, COUNT(DISTINCT module_slug) AS modules FROM tenant_modules;"
```

Expected: `cnt=10, tenants=2, modules=5`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/072_seed_tenant_modules.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): migration 072 — seed tenant_modules for 5×2 existing tenants"
```

---

## Task A6: Migration tests + IsolationTestCase HWM bump

**Files:**

- Create: `tests/Integration/Infrastructure/Persistence/Sql/Migration069Test.php`
- Create: `tests/Integration/Infrastructure/Persistence/Sql/Migration070Test.php`
- Create: `tests/Integration/Infrastructure/Persistence/Sql/Migration071Test.php`
- Create: `tests/Integration/Infrastructure/Persistence/Sql/Migration072Test.php`
- Modify: `tests/Isolation/IsolationTestCase.php`

- [ ] **Step 1: Write Migration069Test**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration069Test extends MigrationTestCase
{
    public function test_creates_tenant_modules_table(): void
    {
        $this->migrateThrough('069_create_tenant_modules_table.sql');

        $row = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_modules'
             ORDER BY ORDINAL_POSITION"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $expected = [
            'id', 'tenant_id', 'module_slug',
            'available_at', 'available_by',
            'enabled_at', 'enabled_by',
            'disabled_at', 'created_at', 'updated_at',
        ];
        self::assertSame($expected, $row);
    }

    public function test_uniqueness_constraint_on_tenant_id_and_slug(): void
    {
        $this->migrateThrough('069_create_tenant_modules_table.sql');

        $tenantId = $this->seedTenant('test', 'Test');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->exec(
            "INSERT INTO tenant_modules (id, tenant_id, module_slug, created_at, updated_at)
             VALUES ('11111111-1111-1111-1111-111111111111', '{$tenantId}', 'forum', '{$now}', '{$now}')"
        );

        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            "INSERT INTO tenant_modules (id, tenant_id, module_slug, created_at, updated_at)
             VALUES ('22222222-2222-2222-2222-222222222222', '{$tenantId}', 'forum', '{$now}', '{$now}')"
        );
    }
}
```

- [ ] **Step 2: Write Migration070Test (same shape, target columns of `module_audit`)**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration070Test extends MigrationTestCase
{
    public function test_creates_module_audit_table(): void
    {
        $this->migrateThrough('070_create_module_audit_table.sql');

        $cols = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_audit'
             ORDER BY ORDINAL_POSITION"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([
            'id', 'tenant_id', 'module_slug', 'action',
            'actor_user_id', 'actor_role', 'reason', 'created_at',
        ], $cols);
    }

    public function test_action_enum_values(): void
    {
        $this->migrateThrough('070_create_module_audit_table.sql');

        $row = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_audit' AND COLUMN_NAME = 'action'"
        )->fetchColumn();

        self::assertStringContainsString("'made_available'", (string) $row);
        self::assertStringContainsString("'revoked_availability'", (string) $row);
        self::assertStringContainsString("'enabled'", (string) $row);
        self::assertStringContainsString("'disabled'", (string) $row);
    }
}
```

- [ ] **Step 3: Write Migration071Test**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration071Test extends MigrationTestCase
{
    public function test_adds_new_columns_to_tenants(): void
    {
        $this->migrateThrough('071_extend_tenants_for_management_ui.sql');

        $cols = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['display_name_i18n', 'public_description_i18n',
                  'supported_locales', 'default_locale',
                  'suspended_at', 'suspended_reason'] as $needed) {
            self::assertContains($needed, $cols, "expected column {$needed}");
        }
    }

    public function test_backfills_daems_to_finnish(): void
    {
        $this->migrateThrough('071_extend_tenants_for_management_ui.sql');

        $row = $this->pdo->query(
            "SELECT default_locale, supported_locales, display_name_i18n FROM tenants WHERE slug = 'daems'"
        )->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            self::markTestSkipped('No daems tenant in this fresh test DB; backfill is no-op.');
        }
        self::assertSame('fi_FI', $row['default_locale']);
        self::assertStringContainsString('Daem Society ry', (string) $row['display_name_i18n']);
    }
}
```

- [ ] **Step 4: Write Migration072Test**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration072Test extends MigrationTestCase
{
    public function test_seeds_five_modules_per_existing_tenant(): void
    {
        // Ensure two tenants exist for the seed to populate.
        $this->seedTenant('daems', 'Daem Society ry');
        $this->seedTenant('sahegroup', 'Sahe Group');

        $this->migrateThrough('072_seed_tenant_modules.sql');

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM tenant_modules"
        )->fetchColumn();

        self::assertSame(10, $count, 'expected 5 modules × 2 tenants');

        $slugs = $this->pdo->query(
            "SELECT DISTINCT module_slug FROM tenant_modules ORDER BY module_slug"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['events', 'forum', 'insights', 'members', 'projects'], $slugs);
    }

    public function test_idempotent_on_rerun(): void
    {
        $this->seedTenant('daems', 'Daem Society ry');
        $this->migrateThrough('072_seed_tenant_modules.sql');
        $first = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_modules")->fetchColumn();

        // Re-apply the migration — WHERE NOT EXISTS should keep counts stable.
        $sql = (string) file_get_contents(__DIR__ . '/../../../../database/migrations/072_seed_tenant_modules.sql');
        $this->pdo->exec($sql);

        $second = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_modules")->fetchColumn();
        self::assertSame($first, $second);
    }
}
```

If `MigrationTestCase` lacks `migrateThrough()` or `seedTenant()`, inspect existing migration tests under `tests/Integration/` for the helper names and adapt; do not invent new helper names.

- [ ] **Step 5: Bump IsolationTestCase HWM**

In `tests/Isolation/IsolationTestCase.php`, find the migration high-water mark constant or property and bump it from its current value (likely 035 per CLAUDE.md memory) to `072`. The exact line depends on the existing implementation:

```bash
grep -n "035\|migration\|HWM\|high_water" tests/Isolation/IsolationTestCase.php
```

Edit the line that gates which migrations run. Example expected change:

```php
// Before
private const MIGRATION_HIGH_WATER = 35;
// After
private const MIGRATION_HIGH_WATER = 72;
```

- [ ] **Step 6: Run tests**

```bash
composer test -- --testsuite=Integration --filter "Migration07[0-2]Test"
```

Expected: 4 test classes pass, ≥10 assertions.

- [ ] **Step 7: Commit**

```bash
git add tests/Integration/Infrastructure/Persistence/Sql/Migration069Test.php \
        tests/Integration/Infrastructure/Persistence/Sql/Migration070Test.php \
        tests/Integration/Infrastructure/Persistence/Sql/Migration071Test.php \
        tests/Integration/Infrastructure/Persistence/Sql/Migration072Test.php \
        tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): migration tests 069-072 + bump IsolationTestCase HWM to 72"
```

---

# Wave B — Module registry extension

## Task B1: SidebarEntry value object + tests

**Files:**

- Create: `src/Infrastructure/Module/SidebarEntry.php`
- Create: `tests/Unit/Infrastructure/Module/SidebarEntryTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\SidebarEntry;
use PHPUnit\Framework\TestCase;

final class SidebarEntryTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $e = new SidebarEntry(group: 'community', order: 30, icon: 'forum', href: '/backstage/forum');
        self::assertSame('community', $e->group());
        self::assertSame(30, $e->order());
        self::assertSame('forum', $e->icon());
        self::assertSame('/backstage/forum', $e->href());
    }

    public function test_rejects_negative_order(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SidebarEntry(group: 'community', order: -1, icon: 'forum', href: '/backstage/forum');
    }

    public function test_rejects_empty_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SidebarEntry(group: '', order: 30, icon: 'forum', href: '/backstage/forum');
    }

    public function test_rejects_href_not_starting_with_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SidebarEntry(group: 'community', order: 30, icon: 'forum', href: 'backstage/forum');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test -- --testsuite=Unit --filter SidebarEntryTest
```

Expected: 4 tests fail with class-not-found.

- [ ] **Step 3: Write SidebarEntry value object**

```php
<?php declare(strict_types=1);

namespace Daems\Infrastructure\Module;

final class SidebarEntry
{
    public function __construct(
        private readonly string $group,
        private readonly int $order,
        private readonly string $icon,
        private readonly string $href,
    ) {
        if ($group === '') {
            throw new \InvalidArgumentException('SidebarEntry group must be non-empty');
        }
        if ($order < 0) {
            throw new \InvalidArgumentException('SidebarEntry order must be >= 0');
        }
        if ($icon === '') {
            throw new \InvalidArgumentException('SidebarEntry icon must be non-empty');
        }
        if (! str_starts_with($href, '/')) {
            throw new \InvalidArgumentException('SidebarEntry href must start with /');
        }
    }

    public function group(): string { return $this->group; }
    public function order(): int    { return $this->order; }
    public function icon(): string  { return $this->icon; }
    public function href(): string  { return $this->href; }
}
```

- [ ] **Step 4: Run tests**

```bash
composer test -- --testsuite=Unit --filter SidebarEntryTest
```

Expected: 4 pass.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Module/SidebarEntry.php tests/Unit/Infrastructure/Module/SidebarEntryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): SidebarEntry value object for platform-level manifest metadata"
```

---

## Task B2: RoutePrefixes value object + tests

**Files:**

- Create: `src/Infrastructure/Module/RoutePrefixes.php`
- Create: `tests/Unit/Infrastructure/Module/RoutePrefixesTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\RoutePrefixes;
use PHPUnit\Framework\TestCase;

final class RoutePrefixesTest extends TestCase
{
    public function test_empty_prefixes_match_nothing(): void
    {
        $rp = new RoutePrefixes(backstage: [], api: []);
        self::assertNull($rp->longestMatch('/backstage/anything'));
        self::assertNull($rp->longestMatch('/api/v1/x'));
    }

    public function test_matches_exact_backstage_prefix(): void
    {
        $rp = new RoutePrefixes(backstage: ['/backstage/forum'], api: []);
        self::assertSame('/backstage/forum', $rp->longestMatch('/backstage/forum'));
        self::assertSame('/backstage/forum', $rp->longestMatch('/backstage/forum/threads'));
        self::assertNull($rp->longestMatch('/backstage/forums'));         // not the same prefix
        self::assertNull($rp->longestMatch('/backstage'));                // shorter than prefix
    }

    public function test_matches_api_prefix(): void
    {
        $rp = new RoutePrefixes(backstage: [], api: ['/api/v1/forum']);
        self::assertSame('/api/v1/forum', $rp->longestMatch('/api/v1/forum/categories'));
    }

    public function test_returns_longest_match_among_multiple(): void
    {
        $rp = new RoutePrefixes(
            backstage: ['/backstage/forum', '/backstage/forum/admin'],
            api:       []
        );
        self::assertSame('/backstage/forum/admin', $rp->longestMatch('/backstage/forum/admin/x'));
        self::assertSame('/backstage/forum',       $rp->longestMatch('/backstage/forum/threads'));
    }

    public function test_overlaps_with_detects_shared_prefix(): void
    {
        $a = new RoutePrefixes(backstage: ['/backstage/forum'], api: []);
        $b = new RoutePrefixes(backstage: ['/backstage/forum/admin'], api: []);
        self::assertTrue($a->overlapsWith($b));
        self::assertTrue($b->overlapsWith($a));

        $c = new RoutePrefixes(backstage: ['/backstage/insights'], api: []);
        self::assertFalse($a->overlapsWith($c));
    }

    public function test_rejects_prefix_without_leading_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RoutePrefixes(backstage: ['backstage/x'], api: []);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
composer test -- --testsuite=Unit --filter RoutePrefixesTest
```

- [ ] **Step 3: Write RoutePrefixes**

```php
<?php declare(strict_types=1);

namespace Daems\Infrastructure\Module;

final class RoutePrefixes
{
    /**
     * @param list<string> $backstage
     * @param list<string> $api
     */
    public function __construct(
        private readonly array $backstage,
        private readonly array $api,
    ) {
        foreach ([...$backstage, ...$api] as $p) {
            if (! is_string($p) || ! str_starts_with($p, '/')) {
                throw new \InvalidArgumentException(
                    "Route prefix must be a string starting with /, got: " . var_export($p, true)
                );
            }
        }
    }

    /** @return list<string> */
    public function backstagePrefixes(): array { return $this->backstage; }

    /** @return list<string> */
    public function apiPrefixes(): array { return $this->api; }

    /**
     * Return the longest prefix from this object's lists that matches $path,
     * or null if no prefix matches. A prefix matches when $path equals the
     * prefix exactly OR begins with `<prefix>/`.
     */
    public function longestMatch(string $path): ?string
    {
        $best = null;
        foreach ([...$this->backstage, ...$this->api] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                if ($best === null || strlen($prefix) > strlen($best)) {
                    $best = $prefix;
                }
            }
        }
        return $best;
    }

    /**
     * Two prefix collections overlap when any prefix in one is a path-prefix
     * of any prefix in the other (or equal). Used at boot to reject manifests
     * that would create ambiguous route ownership.
     */
    public function overlapsWith(self $other): bool
    {
        foreach ([...$this->backstage, ...$this->api] as $a) {
            foreach ([...$other->backstage, ...$other->api] as $b) {
                if ($a === $b) {
                    return true;
                }
                if (str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/')) {
                    return true;
                }
            }
        }
        return false;
    }
}
```

- [ ] **Step 4: Run tests; pass**

```bash
composer test -- --testsuite=Unit --filter RoutePrefixesTest
```

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Module/RoutePrefixes.php tests/Unit/Infrastructure/Module/RoutePrefixesTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): RoutePrefixes value object with longest-match + overlap detection"
```

---

## Task B3: Extend ModuleManifest with platform-level getters

**Files:**

- Modify: `src/Infrastructure/Module/ModuleManifest.php`
- Modify: `tests/Unit/Infrastructure/Module/ModuleManifestTest.php`

- [ ] **Step 1: Read existing class**

```bash
cat src/Infrastructure/Module/ModuleManifest.php | head -200
cat tests/Unit/Infrastructure/Module/ModuleManifestTest.php | head -50
```

- [ ] **Step 2: Add new optional fields to ModuleManifest constructor + factory + getters**

Add to `ModuleManifest` (private readonly properties + constructor parameters at the END of the existing list, all defaulting to safe values):

```php
private readonly ?string $category,
private readonly ?string $nameKey,
private readonly ?string $descriptionKey,
private readonly bool $isCore,
private readonly bool $defaultAvailable,
private readonly ?SidebarEntry $sidebar,
private readonly RoutePrefixes $routePrefixes,
/** @var list<string> */ private readonly array $dependsOn,
```

Add a static `withPlatformMetadata` factory method:

```php
/**
 * Returns a NEW manifest instance copied from $this with the platform-level
 * metadata fields populated. Existing module.json fields are preserved unchanged.
 *
 * @param list<string> $dependsOn
 */
public function withPlatformMetadata(
    ?string $category,
    ?string $nameKey,
    ?string $descriptionKey,
    bool $isCore,
    bool $defaultAvailable,
    ?SidebarEntry $sidebar,
    RoutePrefixes $routePrefixes,
    array $dependsOn,
): self {
    $clone = clone $this;
    $rc = new \ReflectionClass($clone);
    foreach ([
        'category' => $category,
        'nameKey' => $nameKey,
        'descriptionKey' => $descriptionKey,
        'isCore' => $isCore,
        'defaultAvailable' => $defaultAvailable,
        'sidebar' => $sidebar,
        'routePrefixes' => $routePrefixes,
        'dependsOn' => $dependsOn,
    ] as $prop => $value) {
        $rc->getProperty($prop)->setValue($clone, $value);
    }
    return $clone;
}
```

(If readonly-property mutation by reflection is not viable in PHP 8.3, change the constructor signature to accept these fields directly and have `ModuleRegistry::discover()` build the merged manifest in one pass instead of cloning. Adjust the implementation accordingly — same observable behaviour.)

Add getters:

```php
public function category(): ?string         { return $this->category; }
public function nameKey(): ?string          { return $this->nameKey; }
public function descriptionKey(): ?string   { return $this->descriptionKey; }
public function isCore(): bool              { return $this->isCore; }
public function defaultAvailable(): bool    { return $this->defaultAvailable; }
public function sidebar(): ?SidebarEntry    { return $this->sidebar; }
public function routePrefixes(): RoutePrefixes { return $this->routePrefixes; }
/** @return list<string> */
public function dependsOn(): array          { return $this->dependsOn; }
```

Update `fromArray()` to default the new fields to safe values when constructing from `module.json` alone (category=null, isCore=false, defaultAvailable=false, sidebar=null, routePrefixes=empty, dependsOn=[]). The actual platform metadata is layered on later in `ModuleRegistry`.

- [ ] **Step 3: Add test cases for new getters**

Append to `tests/Unit/Infrastructure/Module/ModuleManifestTest.php`:

```php
public function test_safe_defaults_for_platform_metadata_when_not_set(): void
{
    $m = ModuleManifest::fromArray($this->validMinimalData(), '/tmp/forum');
    self::assertNull($m->category());
    self::assertNull($m->nameKey());
    self::assertFalse($m->isCore());
    self::assertFalse($m->defaultAvailable());
    self::assertNull($m->sidebar());
    self::assertSame([], $m->routePrefixes()->backstagePrefixes());
    self::assertSame([], $m->dependsOn());
}

public function test_with_platform_metadata_returns_populated_clone(): void
{
    $m = ModuleManifest::fromArray($this->validMinimalData(), '/tmp/forum');
    $sidebar = new \Daems\Infrastructure\Module\SidebarEntry('community', 30, 'forum', '/backstage/forum');
    $rp = new \Daems\Infrastructure\Module\RoutePrefixes(['/backstage/forum'], ['/api/v1/forum']);
    $m2 = $m->withPlatformMetadata(
        category: 'community',
        nameKey: 'modules.forum.name',
        descriptionKey: 'modules.forum.description',
        isCore: false,
        defaultAvailable: true,
        sidebar: $sidebar,
        routePrefixes: $rp,
        dependsOn: [],
    );
    self::assertSame('community', $m2->category());
    self::assertSame('modules.forum.name', $m2->nameKey());
    self::assertTrue($m2->defaultAvailable());
    self::assertSame($sidebar, $m2->sidebar());
    self::assertSame(['/backstage/forum'], $m2->routePrefixes()->backstagePrefixes());
    // Original is untouched.
    self::assertNull($m->category());
}

private function validMinimalData(): array
{
    return [
        'name' => 'forum',
        'version' => '1.0.0',
        'namespace' => 'DaemsModule\\Forum\\',
        'src_path' => 'backend/src/',
        'bindings' => 'backend/bindings.php',
        'routes' => 'backend/routes.php',
        'migrations_path' => 'backend/migrations/',
    ];
}
```

- [ ] **Step 4: Run all module tests**

```bash
composer test -- --testsuite=Unit --filter "ModuleManifestTest"
```

Expected: pre-existing tests still green, two new tests green.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Module/ModuleManifest.php tests/Unit/Infrastructure/Module/ModuleManifestTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): platform-metadata getters on ModuleManifest with safe defaults"
```

---

## Task B4: config/modules.php with 5 module entries

**Files:**

- Create: `config/modules.php`

- [ ] **Step 1: Write platform catalog**

```php
<?php declare(strict_types=1);

use Daems\Infrastructure\Module\RoutePrefixes;
use Daems\Infrastructure\Module\SidebarEntry;

/**
 * Platform-level catalog of modules available in this deployment.
 *
 * Keyed by the module's `name` field from its module.json. The platform
 * merges this metadata with the discovered ModuleManifest at boot. A
 * discovered module without an entry here is silently inert in this
 * deployment (autoloaded but invisible to the gating UI).
 *
 * To add a new module to the gating system: add an entry below, ensure the
 * module's own module.json exists at c:/laragon/www/modules/<name>/module.json,
 * then add the i18n keys (modules.<name>.name and modules.<name>.description)
 * to lang/{fi_FI,en_GB,sw_TZ}.php.
 */
return [
    'members' => [
        'category'          => 'members',
        'name_key'          => 'modules.members.name',
        'description_key'   => 'modules.members.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar' => new SidebarEntry(
            group: 'members',
            order: 10,
            icon: 'users',
            href: '/backstage/members',
        ),
        'route_prefixes' => new RoutePrefixes(
            backstage: ['/backstage/members'],
            api: ['/api/v1/backstage/members', '/api/v1/members', '/api/v1/applications'],
        ),
        'depends_on' => [],
    ],

    'events' => [
        'category'          => 'content',
        'name_key'          => 'modules.events.name',
        'description_key'   => 'modules.events.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar' => new SidebarEntry(
            group: 'content',
            order: 20,
            icon: 'calendar',
            href: '/backstage/events',
        ),
        'route_prefixes' => new RoutePrefixes(
            backstage: ['/backstage/events'],
            api: ['/api/v1/backstage/events', '/api/v1/events'],
        ),
        'depends_on' => [],
    ],

    'projects' => [
        'category'          => 'content',
        'name_key'          => 'modules.projects.name',
        'description_key'   => 'modules.projects.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar' => new SidebarEntry(
            group: 'content',
            order: 21,
            icon: 'folder',
            href: '/backstage/projects',
        ),
        'route_prefixes' => new RoutePrefixes(
            backstage: ['/backstage/projects', '/backstage/project-proposals'],
            api: ['/api/v1/backstage/projects', '/api/v1/projects', '/api/v1/project-proposals'],
        ),
        'depends_on' => [],
    ],

    'forum' => [
        'category'          => 'community',
        'name_key'          => 'modules.forum.name',
        'description_key'   => 'modules.forum.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar' => new SidebarEntry(
            group: 'community',
            order: 30,
            icon: 'forum',
            href: '/backstage/forum',
        ),
        'route_prefixes' => new RoutePrefixes(
            backstage: ['/backstage/forum'],
            api: ['/api/v1/backstage/forum', '/api/v1/forum'],
        ),
        'depends_on' => [],
    ],

    'insights' => [
        'category'          => 'content',
        'name_key'          => 'modules.insights.name',
        'description_key'   => 'modules.insights.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar' => new SidebarEntry(
            group: 'content',
            order: 40,
            icon: 'edit',
            href: '/backstage/insights',
        ),
        'route_prefixes' => new RoutePrefixes(
            backstage: ['/backstage/insights'],
            api: ['/api/v1/backstage/insights', '/api/v1/insights'],
        ),
        'depends_on' => [],
    ],
];
```

Verify exact route prefixes against each module's `routes.php`:

```bash
grep -E "router->(get|post|put|patch|delete)" c:/laragon/www/modules/forum/backend/routes.php | head -20
grep -E "router->(get|post|put|patch|delete)" c:/laragon/www/modules/events/backend/routes.php | head -20
grep -E "router->(get|post|put|patch|delete)" c:/laragon/www/modules/insights/backend/routes.php | head -20
grep -E "router->(get|post|put|patch|delete)" c:/laragon/www/modules/members/backend/routes.php | head -20
grep -E "router->(get|post|put|patch|delete)" c:/laragon/www/modules/projects/backend/routes.php | head -20
```

Adjust each module's `route_prefixes` array in `config/modules.php` to cover ALL prefixes that module's `routes.php` actually registers. If a route falls outside the module's prefix declaration, the route guard cannot 404 it on disable, which leaks the module's existence.

- [ ] **Step 2: No test yet — registry tests in Task B5 cover this. Commit the catalog**

```bash
git add config/modules.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): config/modules.php — 5-module catalog with sidebar+route_prefixes"
```

---

## Task B5: Extend ModuleRegistry — merge config/modules.php + graph validation

**Files:**

- Modify: `src/Infrastructure/Module/ModuleRegistry.php`
- Modify: `tests/Unit/Infrastructure/Module/ModuleRegistryTest.php`
- Create: `tests/Integration/Infrastructure/Module/ModuleRegistryDiscoveryTest.php` (extend if exists)

- [ ] **Step 1: Read existing tests**

```bash
cat tests/Unit/Infrastructure/Module/ModuleRegistryTest.php | head -80
```

Note the existing test patterns and helper methods to reuse them.

- [ ] **Step 2: Extend ModuleRegistry with `discover()` overload accepting platform metadata path**

Modify `discover()` signature:

```php
public function discover(string $modulesDir, ?string $platformCatalogPath = null): void
{
    // ... existing scan loop unchanged ...

    if ($platformCatalogPath !== null && is_file($platformCatalogPath)) {
        $this->mergePlatformMetadata($platformCatalogPath);
    }

    $this->validateGraph();
}
```

Add private helpers:

```php
private function mergePlatformMetadata(string $path): void
{
    /** @var array<string, array<string, mixed>> $catalog */
    $catalog = require $path;
    if (! is_array($catalog)) {
        throw new \RuntimeException("config/modules.php must return an array, got " . gettype($catalog));
    }

    foreach ($catalog as $name => $entry) {
        if (! is_string($name) || ! isset($this->modules[$name])) {
            // Platform-level entry references a module that wasn't discovered.
            // This is a configuration error — the entry is dead weight.
            throw new \RuntimeException(
                "config/modules.php references module '{$name}' which is not discoverable; remove or fix"
            );
        }
        $sidebar = $entry['sidebar'] ?? null;
        $routePrefixes = $entry['route_prefixes'] ?? null;
        if ($sidebar !== null && ! $sidebar instanceof SidebarEntry) {
            throw new \RuntimeException("modules.{$name}.sidebar must be SidebarEntry|null");
        }
        if (! $routePrefixes instanceof RoutePrefixes) {
            throw new \RuntimeException("modules.{$name}.route_prefixes must be RoutePrefixes");
        }
        $isCore = (bool) ($entry['is_core'] ?? false);
        $defaultAvailable = (bool) ($entry['default_available'] ?? false);
        if ($isCore && ! $defaultAvailable) {
            throw new \RuntimeException(
                "modules.{$name}: is_core=true requires default_available=true (would be permanently invisible otherwise)"
            );
        }
        $dependsOn = $entry['depends_on'] ?? [];
        if (! is_array($dependsOn) || array_filter($dependsOn, 'is_string') !== $dependsOn) {
            throw new \RuntimeException("modules.{$name}.depends_on must be a list of module names");
        }
        $this->modules[$name] = $this->modules[$name]->withPlatformMetadata(
            category:         isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : null,
            nameKey:          isset($entry['name_key']) && is_string($entry['name_key']) ? $entry['name_key'] : null,
            descriptionKey:   isset($entry['description_key']) && is_string($entry['description_key']) ? $entry['description_key'] : null,
            isCore:           $isCore,
            defaultAvailable: $defaultAvailable,
            sidebar:          $sidebar,
            routePrefixes:    $routePrefixes,
            dependsOn:        array_values($dependsOn),
        );
    }
}

private function validateGraph(): void
{
    // 1. Every depends_on target must reference a module that has a platform-level entry.
    foreach ($this->modules as $name => $m) {
        foreach ($m->dependsOn() as $dep) {
            if (! isset($this->modules[$dep])) {
                throw new \RuntimeException("module '{$name}' depends_on '{$dep}' which is not in this deployment");
            }
        }
    }

    // 2. No cycles.
    $color = []; // 0=white, 1=grey, 2=black
    $visit = function (string $name) use (&$visit, &$color): void {
        if (($color[$name] ?? 0) === 2) return;
        if (($color[$name] ?? 0) === 1) {
            throw new \RuntimeException("dependency cycle through module '{$name}'");
        }
        $color[$name] = 1;
        foreach ($this->modules[$name]->dependsOn() as $dep) {
            $visit($dep);
        }
        $color[$name] = 2;
    };
    foreach (array_keys($this->modules) as $name) {
        $visit($name);
    }

    // 3. No two manifests have overlapping route prefixes.
    $names = array_keys($this->modules);
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $this->modules[$names[$i]];
            $b = $this->modules[$names[$j]];
            if ($a->routePrefixes()->overlapsWith($b->routePrefixes())) {
                throw new \RuntimeException(
                    "modules '{$names[$i]}' and '{$names[$j]}' have overlapping route_prefixes"
                );
            }
        }
    }
}
```

Add public query methods:

```php
public function categoryOf(string $name): ?string
{
    return $this->modules[$name]?->category();
}

/** @return list<string> */
public function coreModules(): array
{
    return array_keys(array_filter($this->modules, fn($m) => $m->isCore()));
}

/** @return list<string> */
public function toggleableModules(): array
{
    return array_keys(array_filter($this->modules, fn($m) => ! $m->isCore()));
}

/**
 * Modules that depend on $name (i.e., would break if $name is disabled).
 *
 * @return list<string>
 */
public function dependents(string $name): array
{
    $out = [];
    foreach ($this->modules as $candidateName => $m) {
        if (in_array($name, $m->dependsOn(), true)) {
            $out[] = $candidateName;
        }
    }
    return $out;
}

/**
 * Modules that $name depends on.
 *
 * @return list<string>
 */
public function dependencies(string $name): array
{
    return $this->modules[$name]?->dependsOn() ?? [];
}

/**
 * Find the manifest whose route_prefixes have the longest match for $path.
 * Returns null if no manifest claims this path (the request is for shell or
 * unknown territory and should be allowed through).
 */
public function findOwnerOfPath(string $path): ?ModuleManifest
{
    $best = null;
    $bestLen = 0;
    foreach ($this->modules as $m) {
        $match = $m->routePrefixes()->longestMatch($path);
        if ($match !== null && strlen($match) > $bestLen) {
            $best = $m;
            $bestLen = strlen($match);
        }
    }
    return $best;
}
```

- [ ] **Step 3: Add tests**

```php
// tests/Unit/Infrastructure/Module/ModuleRegistryTest.php — append

public function test_merges_platform_metadata_into_discovered_manifest(): void
{
    $tmp = $this->writeTempModulesDir(['forum' => $this->minimalForumManifest()]);
    $catalog = $tmp . '/catalog.php';
    file_put_contents($catalog, '<?php
        use Daems\Infrastructure\Module\RoutePrefixes;
        use Daems\Infrastructure\Module\SidebarEntry;
        return [
            "forum" => [
                "category" => "community",
                "name_key" => "modules.forum.name",
                "description_key" => "modules.forum.description",
                "is_core" => false,
                "default_available" => true,
                "sidebar" => new SidebarEntry("community", 30, "forum", "/backstage/forum"),
                "route_prefixes" => new RoutePrefixes(["/backstage/forum"], ["/api/v1/forum"]),
                "depends_on" => [],
            ],
        ];
    ');

    $r = new ModuleRegistry();
    $r->discover($tmp . '/modules', $catalog);

    $forum = $r->get('forum');
    self::assertNotNull($forum);
    self::assertSame('community', $forum->category());
    self::assertTrue($forum->defaultAvailable());
}

public function test_rejects_catalog_entry_for_undiscovered_module(): void
{
    $tmp = $this->writeTempModulesDir([]); // no modules
    $catalog = $tmp . '/catalog.php';
    file_put_contents($catalog, '<?php
        use Daems\Infrastructure\Module\RoutePrefixes;
        return ["ghost" => [
            "is_core" => false, "default_available" => true,
            "route_prefixes" => new RoutePrefixes([], []),
            "depends_on" => [],
        ]];
    ');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/ghost.*not discoverable/');

    $r = new ModuleRegistry();
    $r->discover($tmp . '/modules', $catalog);
}

public function test_rejects_dependency_cycle(): void
{
    $tmp = $this->writeTempModulesDir([
        'a' => $this->minimalManifest('a'),
        'b' => $this->minimalManifest('b'),
    ]);
    $catalog = $tmp . '/catalog.php';
    file_put_contents($catalog, '<?php
        use Daems\Infrastructure\Module\RoutePrefixes;
        return [
            "a" => ["is_core"=>false, "default_available"=>true, "route_prefixes" => new RoutePrefixes(["/a"], []), "depends_on" => ["b"]],
            "b" => ["is_core"=>false, "default_available"=>true, "route_prefixes" => new RoutePrefixes(["/b"], []), "depends_on" => ["a"]],
        ];
    ');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/cycle/');

    $r = new ModuleRegistry();
    $r->discover($tmp . '/modules', $catalog);
}

public function test_rejects_overlapping_route_prefixes(): void
{
    $tmp = $this->writeTempModulesDir([
        'a' => $this->minimalManifest('a'),
        'b' => $this->minimalManifest('b'),
    ]);
    $catalog = $tmp . '/catalog.php';
    file_put_contents($catalog, '<?php
        use Daems\Infrastructure\Module\RoutePrefixes;
        return [
            "a" => ["is_core"=>false, "default_available"=>true, "route_prefixes" => new RoutePrefixes(["/x"], []), "depends_on" => []],
            "b" => ["is_core"=>false, "default_available"=>true, "route_prefixes" => new RoutePrefixes(["/x/sub"], []), "depends_on" => []],
        ];
    ');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/overlapping route_prefixes/');

    $r = new ModuleRegistry();
    $r->discover($tmp . '/modules', $catalog);
}

public function test_findOwnerOfPath_returns_longest_match(): void
{
    // ... build registry with two non-overlapping modules and assert
    //     findOwnerOfPath() returns the right manifest for paths under each.
}
```

Implement helpers `writeTempModulesDir()`, `minimalManifest()`, `minimalForumManifest()` to write fake `module.json` files into a tmp dir for the test to discover. Reuse helpers from the existing test class if any.

- [ ] **Step 4: Run tests**

```bash
composer test -- --testsuite=Unit --filter ModuleRegistryTest
composer test -- --testsuite=Integration --filter ModuleRegistryDiscoveryTest
```

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Module/ModuleRegistry.php tests/Unit/Infrastructure/Module/ModuleRegistryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(module): merge config/modules.php into ModuleRegistry + graph validation"
```

---

## Task B6: Wire bootstrap to load config/modules.php

**Files:**

- Modify: `bootstrap/app.php`

- [ ] **Step 1: Read current discover() call site**

```bash
grep -n "discover" bootstrap/app.php
```

Current state (per CLAUDE.md prior work):

```php
$moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
$moduleRegistry->discover(__DIR__ . '/../../modules');
```

- [ ] **Step 2: Update to pass platform catalog path**

```php
$moduleRegistry = new \Daems\Infrastructure\Module\ModuleRegistry();
$moduleRegistry->discover(
    __DIR__ . '/../../modules',
    __DIR__ . '/../config/modules.php',
);
```

- [ ] **Step 3: Run smoke**

```bash
composer test -- --testsuite=Integration --filter ModuleRegistryDiscoveryTest
php -r "require 'vendor/autoload.php'; require 'bootstrap/app.php';" && echo OK
```

Expected: registry loads, `OK` printed, no exception thrown.

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): wire bootstrap to load config/modules.php at registry discovery"
```

---

# Wave C — Gating domain layer

## Task C1: ModuleState + ModuleAuditAction enums

**Files:**

- Create: `src/Domain/Tenant/ModuleState.php`
- Create: `src/Domain/Tenant/ModuleAuditAction.php`

- [ ] **Step 1: Write enums**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

enum ModuleState: string
{
    case ENABLED               = 'enabled';
    case AVAILABLE_NOT_ENABLED = 'available';
    case DISABLED              = 'disabled';
    case CORE                  = 'core';

    /** True when the module's routes should be served (sidebar entry shown if applicable). */
    public function isActive(): bool
    {
        return $this === self::ENABLED || $this === self::CORE;
    }
}
```

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

enum ModuleAuditAction: string
{
    case MADE_AVAILABLE        = 'made_available';
    case REVOKED_AVAILABILITY  = 'revoked_availability';
    case ENABLED               = 'enabled';
    case DISABLED              = 'disabled';
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Domain/Tenant/ModuleState.php src/Domain/Tenant/ModuleAuditAction.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): ModuleState + ModuleAuditAction enums for gating layer"
```

---

## Task C2: TenantModule entity + tests

**Files:**

- Create: `src/Domain/Tenant/TenantModule.php`
- Create: `tests/Unit/Domain/Tenant/TenantModuleTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantModuleTest extends TestCase
{
    public function test_constructs_with_only_available(): void
    {
        $tm = new TenantModule(
            id:         '01928000-0000-7000-8000-000000000001',
            tenantId:   TenantId::fromString('01928000-0000-7000-8000-000000000aaa'),
            moduleSlug: 'forum',
            availableAt: new DateTimeImmutable('2026-05-07 12:00:00'),
            availableBy: UserId::fromString('01928000-0000-7000-8000-000000000bbb'),
            enabledAt:   null, enabledBy: null, disabledAt: null,
            createdAt:   new DateTimeImmutable('2026-05-07 12:00:00'),
            updatedAt:   new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        self::assertSame('forum', $tm->moduleSlug());
        self::assertTrue($tm->isAvailable());
        self::assertFalse($tm->isEnabled());
    }

    public function test_rejects_enabled_without_available(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TenantModule(
            id: 'x', tenantId: TenantId::fromString('01928000-0000-7000-8000-000000000aaa'),
            moduleSlug: 'forum',
            availableAt: null, availableBy: null,
            enabledAt:   new DateTimeImmutable('2026-05-07'), enabledBy: UserId::fromString('01928000-0000-7000-8000-000000000bbb'),
            disabledAt:  null,
            createdAt:   new DateTimeImmutable('2026-05-07'),
            updatedAt:   new DateTimeImmutable('2026-05-07'),
        );
    }

    public function test_isEnabled_true_when_both_timestamps_set(): void
    {
        $tm = new TenantModule(
            id: 'x', tenantId: TenantId::fromString('01928000-0000-7000-8000-000000000aaa'),
            moduleSlug: 'forum',
            availableAt: new DateTimeImmutable('2026-05-07'),
            availableBy: null,
            enabledAt:   new DateTimeImmutable('2026-05-07'),
            enabledBy:   null,
            disabledAt:  null,
            createdAt:   new DateTimeImmutable('2026-05-07'),
            updatedAt:   new DateTimeImmutable('2026-05-07'),
        );
        self::assertTrue($tm->isEnabled());
    }
}
```

- [ ] **Step 2: Run failing**

```bash
composer test -- --testsuite=Unit --filter TenantModuleTest
```

- [ ] **Step 3: Write entity**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class TenantModule
{
    public function __construct(
        private readonly string $id,
        private readonly TenantId $tenantId,
        private readonly string $moduleSlug,
        private readonly ?DateTimeImmutable $availableAt,
        private readonly ?UserId $availableBy,
        private readonly ?DateTimeImmutable $enabledAt,
        private readonly ?UserId $enabledBy,
        private readonly ?DateTimeImmutable $disabledAt,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {
        if ($enabledAt !== null && $availableAt === null) {
            throw new \InvalidArgumentException(
                "TenantModule '{$moduleSlug}': cannot be enabled without being available first"
            );
        }
        if ($moduleSlug === '') {
            throw new \InvalidArgumentException('moduleSlug must be non-empty');
        }
    }

    public function id(): string                          { return $this->id; }
    public function tenantId(): TenantId                  { return $this->tenantId; }
    public function moduleSlug(): string                  { return $this->moduleSlug; }
    public function availableAt(): ?DateTimeImmutable     { return $this->availableAt; }
    public function availableBy(): ?UserId                { return $this->availableBy; }
    public function enabledAt(): ?DateTimeImmutable       { return $this->enabledAt; }
    public function enabledBy(): ?UserId                  { return $this->enabledBy; }
    public function disabledAt(): ?DateTimeImmutable      { return $this->disabledAt; }
    public function createdAt(): DateTimeImmutable        { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable        { return $this->updatedAt; }

    public function isAvailable(): bool { return $this->availableAt !== null; }
    public function isEnabled(): bool   { return $this->isAvailable() && $this->enabledAt !== null; }
}
```

- [ ] **Step 4: Run; pass**

```bash
composer test -- --testsuite=Unit --filter TenantModuleTest
```

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Tenant/TenantModule.php tests/Unit/Domain/Tenant/TenantModuleTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): TenantModule entity with state-invariant enforcement"
```

---

## Task C3: ModuleAuditEntry entity + tests

**Files:**

- Create: `src/Domain/Tenant/ModuleAuditEntry.php`
- Create: `tests/Unit/Domain/Tenant/ModuleAuditEntryTest.php`

- [ ] **Step 1-4: Same TDD shape as C2**

ModuleAuditEntry holds: id (UUID string), tenantId (TenantId), moduleSlug (string), action (ModuleAuditAction), actorUserId (UserId), actorRole (string, validated as 'platform_admin'|'tenant_admin'), reason (?string), createdAt (DateTimeImmutable). Constructor validates moduleSlug non-empty and actorRole in the allowed set.

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): ModuleAuditEntry entity"
```

---

## Task C4: Domain exceptions (six classes)

**Files:** under `src/Domain/Tenant/Exception/`

- Create: `ModuleNotAvailableException.php`
- Create: `ModuleDependencyUnmetException.php`
- Create: `ModuleDependentEnabledException.php`
- Create: `TenantSlugImmutableException.php`
- Create: `TenantPrimaryDomainRequiredException.php`
- Create: `TenantSuspendedException.php`

- [ ] **Step 1: Each exception is a thin subclass of `\DomainException`**

Example:

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class ModuleNotAvailableException extends \DomainException
{
    public static function for(string $moduleSlug, string $tenantSlug): self
    {
        return new self("Module '{$moduleSlug}' is not available for tenant '{$tenantSlug}'");
    }
}
```

Repeat the same pattern for each, with a static `for()` factory tailored to its parameters:

- `ModuleDependencyUnmetException::for(string $module, array $missingDeps): self`
- `ModuleDependentEnabledException::for(string $module, array $blockingDependents): self`
- `TenantSlugImmutableException::for(string $oldSlug, string $newSlug): self`
- `TenantPrimaryDomainRequiredException::for(string $tenantSlug): self`
- `TenantSuspendedException::for(string $tenantSlug, string $reason): self`

- [ ] **Step 2: Single test per exception (asserts message format)**

Bundle all exception tests into `tests/Unit/Domain/Tenant/Exception/TenantExceptionsTest.php`.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): 6 domain exceptions for gating + tenant management invariants"
```

---

## Task C5: Repository ports

**Files:**

- Create: `src/Domain/Tenant/TenantModulesRepositoryInterface.php`
- Create: `src/Domain/Tenant/ModuleAuditRepositoryInterface.php`
- Create: `src/Domain/Tenant/TenantDomainRepositoryInterface.php`

- [ ] **Step 1: TenantModulesRepositoryInterface**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantModulesRepositoryInterface
{
    /** @return list<TenantModule> ordered by module_slug asc */
    public function findByTenant(TenantId $tenantId): array;

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule;

    public function save(TenantModule $tm): void;

    /**
     * Atomically: clear available_at + enabled_at on the row, write a disabled_at
     * timestamp, AND insert audit rows for the cascade. Used by RevokeModuleAvailability.
     *
     * @param ModuleAuditEntry[] $auditEntries
     */
    public function revokeAvailability(
        TenantModule $tm,
        \DateTimeImmutable $now,
        array $auditEntries,
    ): void;

    /** @return list<TenantModule> all rows for the tenant where enabled_at IS NOT NULL */
    public function findEnabledByTenant(TenantId $tenantId): array;
}
```

- [ ] **Step 2: ModuleAuditRepositoryInterface**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface ModuleAuditRepositoryInterface
{
    public function append(ModuleAuditEntry $entry): void;

    /**
     * @return list<ModuleAuditEntry> ordered by created_at DESC
     */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;

    /**
     * @return list<ModuleAuditEntry>
     */
    public function listForModule(TenantId $tenantId, string $moduleSlug, int $limit = 100): array;
}
```

- [ ] **Step 3: TenantDomainRepositoryInterface**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantDomainRepositoryInterface
{
    /** @return list<TenantDomain> */
    public function findByTenant(TenantId $tenantId): array;

    public function find(string $domainId): ?TenantDomain;

    public function add(TenantDomain $domain): void;

    /** Updates hostname and/or primary flag. Caller pre-validates uniqueness. */
    public function update(TenantDomain $domain): void;

    public function remove(string $domainId): void;

    /** Set $domainId as primary; demote any other primary in the same tenant atomically. */
    public function setPrimary(TenantId $tenantId, string $domainId): void;
}
```

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): 3 repository ports — TenantModules, ModuleAudit, TenantDomain"
```

---

## Task C6: TenantModuleResolver + tests

**Files:**

- Create: `src/Domain/Tenant/TenantModuleResolver.php`
- Create: `tests/Unit/Domain/Tenant/TenantModuleResolverTest.php`

- [ ] **Step 1: Write tests covering the full state machine**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\ModuleState;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Infrastructure\Module\ModuleRegistry;
use Daems\Tests\Support\Fake\InMemoryTenantModulesRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantModuleResolverTest extends TestCase
{
    private TenantId $tenantId;
    private InMemoryTenantModulesRepository $repo;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01928000-0000-7000-8000-000000000aaa');
        $this->repo = new InMemoryTenantModulesRepository();
    }

    public function test_unknown_module_returns_DISABLED(): void
    {
        $registry = $this->buildRegistry([]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId, 'nonexistent'));
    }

    public function test_is_core_returns_CORE_regardless_of_db_state(): void
    {
        $registry = $this->buildRegistry(['platform_health' => ['is_core' => true]]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::CORE, $resolver->stateFor($this->tenantId, 'platform_health'));
    }

    public function test_no_row_returns_DISABLED(): void
    {
        $registry = $this->buildRegistry(['forum' => []]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId, 'forum'));
    }

    public function test_available_no_enabled_returns_AVAILABLE_NOT_ENABLED(): void
    {
        $this->repo->add($this->makeRow('forum', availableAt: new DateTimeImmutable('-1 day'), enabledAt: null));
        $registry = $this->buildRegistry(['forum' => []]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::AVAILABLE_NOT_ENABLED, $resolver->stateFor($this->tenantId, 'forum'));
    }

    public function test_available_and_enabled_returns_ENABLED(): void
    {
        $this->repo->add($this->makeRow('forum', availableAt: new DateTimeImmutable('-1 day'), enabledAt: new DateTimeImmutable('-1 hour')));
        $registry = $this->buildRegistry(['forum' => []]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::ENABLED, $resolver->stateFor($this->tenantId, 'forum'));
    }

    public function test_available_revoked_back_to_DISABLED(): void
    {
        // Row exists but available_at is NULL (was revoked).
        $this->repo->add($this->makeRow('forum', availableAt: null, enabledAt: null));
        $registry = $this->buildRegistry(['forum' => []]);
        $resolver = new TenantModuleResolver($registry, $this->repo);
        self::assertSame(ModuleState::DISABLED, $resolver->stateFor($this->tenantId, 'forum'));
    }

    public function test_statesForTenant_returns_map_for_every_known_module(): void
    {
        $registry = $this->buildRegistry([
            'forum' => [],
            'events' => ['is_core' => true],
        ]);
        $this->repo->add($this->makeRow('forum',
            availableAt: new DateTimeImmutable('-1 day'),
            enabledAt:   new DateTimeImmutable('-1 hour')));
        $resolver = new TenantModuleResolver($registry, $this->repo);

        $states = $resolver->statesForTenant($this->tenantId);
        self::assertSame(ModuleState::ENABLED, $states['forum']);
        self::assertSame(ModuleState::CORE, $states['events']);
    }

    private function buildRegistry(array $modulesByName): ModuleRegistry
    {
        // Helper: build an in-process registry with stub manifests.
        // Reuses the writeTempModulesDir helper from the registry test class
        // OR mocks it. Implement to taste — a simple inline subclass works:
        $r = new class extends ModuleRegistry {
            public array $stub = [];
            public function get(string $name): ?\Daems\Infrastructure\Module\ModuleManifest
            {
                return $this->stub[$name] ?? null;
            }
            public function all(): array { return $this->stub; }
        };
        foreach ($modulesByName as $name => $opts) {
            $r->stub[$name] = $this->makeManifest($name, $opts);
        }
        return $r;
    }

    private function makeManifest(string $name, array $opts): \Daems\Infrastructure\Module\ModuleManifest
    {
        // Build a fake ModuleManifest for the test; the resolver only uses
        // ->name() and ->isCore(). If ModuleManifest's constructor requires
        // many fields, use an anonymous-class double instead. Pseudocode:
        // return new class($name, $opts['is_core'] ?? false) extends ModuleManifest { ... };
        // (Real implementation depends on ModuleManifest's actual API.)
        throw new \LogicException('Implement makeManifest based on ModuleManifest API at execution time');
    }

    private function makeRow(string $slug, ?DateTimeImmutable $availableAt, ?DateTimeImmutable $enabledAt): TenantModule
    {
        $now = new DateTimeImmutable();
        return new TenantModule(
            id: bin2hex(random_bytes(16)),
            tenantId: $this->tenantId,
            moduleSlug: $slug,
            availableAt: $availableAt, availableBy: null,
            enabledAt: $enabledAt, enabledBy: null,
            disabledAt: null,
            createdAt: $now, updatedAt: $now,
        );
    }
}
```

- [ ] **Step 2: Run failing**

- [ ] **Step 3: Write resolver**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Infrastructure\Module\ModuleRegistry;

final class TenantModuleResolver
{
    /** @var array<string, array<string, ModuleState>> per-request memo */
    private array $cache = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModulesRepositoryInterface $repo,
    ) {}

    public function stateFor(TenantId $tenantId, string $moduleSlug): ModuleState
    {
        return $this->statesForTenant($tenantId)[$moduleSlug] ?? ModuleState::DISABLED;
    }

    public function isEnabledFor(TenantId $tenantId, string $moduleSlug): bool
    {
        return $this->stateFor($tenantId, $moduleSlug)->isActive();
    }

    /** @return array<string, ModuleState> */
    public function statesForTenant(TenantId $tenantId): array
    {
        $key = $tenantId->toString();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $rows = [];
        foreach ($this->repo->findByTenant($tenantId) as $row) {
            $rows[$row->moduleSlug()] = $row;
        }

        $states = [];
        foreach ($this->registry->all() as $name => $manifest) {
            if ($manifest->isCore()) {
                $states[$name] = ModuleState::CORE;
                continue;
            }
            $row = $rows[$name] ?? null;
            if ($row === null) {
                $states[$name] = ModuleState::DISABLED;
                continue;
            }
            if (! $row->isAvailable()) {
                $states[$name] = ModuleState::DISABLED;
                continue;
            }
            $states[$name] = $row->isEnabled()
                ? ModuleState::ENABLED
                : ModuleState::AVAILABLE_NOT_ENABLED;
        }

        // Orphan rows (DB row exists but no manifest) — surface as DISABLED but
        // keep the slug in the result so the GSA UI can offer cleanup.
        foreach ($rows as $slug => $_) {
            if (! isset($states[$slug])) {
                $states[$slug] = ModuleState::DISABLED;
            }
        }

        return $this->cache[$key] = $states;
    }

    /** @return list<string> enabled module names */
    public function enabledSlugsFor(TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->statesForTenant($tenantId) as $slug => $state) {
            if ($state->isActive()) $out[] = $slug;
        }
        return $out;
    }

    /** Clear per-request memo. Use after a write that changes tenant state in the same request. */
    public function invalidate(TenantId $tenantId): void
    {
        unset($this->cache[$tenantId->toString()]);
    }
}
```

- [ ] **Step 4: Run pass**

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): TenantModuleResolver — full state machine + per-request memo"
```

---

## Task C7: ModuleRouteGuard + tests

**Files:**

- Create: `src/Domain/Tenant/ModuleRouteGuard.php`
- Create: `tests/Unit/Domain/Tenant/ModuleRouteGuardTest.php`

- [ ] **Step 1: Write tests**

```php
final class ModuleRouteGuardTest extends TestCase
{
    public function test_path_with_no_module_owner_is_ALLOWED(): void
    {
        $guard = $this->buildGuard(modules: [], enabled: []);
        self::assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId, '/backstage/login'));
    }

    public function test_path_owned_by_enabled_module_is_ALLOWED(): void
    {
        $guard = $this->buildGuard(
            modules: ['forum' => ['/backstage/forum']],
            enabled: ['forum'],
        );
        self::assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId, '/backstage/forum'));
        self::assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId, '/backstage/forum/threads/123'));
    }

    public function test_path_owned_by_disabled_module_is_NOT_FOUND(): void
    {
        $guard = $this->buildGuard(
            modules: ['forum' => ['/backstage/forum']],
            enabled: [],
        );
        self::assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId, '/backstage/forum'));
    }

    public function test_is_core_module_is_ALLOWED_regardless_of_db(): void
    {
        $guard = $this->buildGuard(
            modules: ['platform_health' => ['/backstage/platform_health']],
            core: ['platform_health'],
            enabled: [],
        );
        self::assertSame(ModuleRouteGuard::ALLOW, $guard->authorize($this->tenantId, '/backstage/platform_health'));
    }

    public function test_longest_prefix_wins(): void
    {
        // Build registry where two modules CLAIM separate prefixes and a path
        // matches one cleanly.
        $guard = $this->buildGuard(
            modules: [
                'a' => ['/backstage/a'],
                'b' => ['/backstage/b'],
            ],
            enabled: ['a'], // a enabled, b disabled
        );
        self::assertSame(ModuleRouteGuard::ALLOW,     $guard->authorize($this->tenantId, '/backstage/a/x'));
        self::assertSame(ModuleRouteGuard::NOT_FOUND, $guard->authorize($this->tenantId, '/backstage/b/y'));
    }
}
```

- [ ] **Step 2: Write guard**

```php
<?php declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Infrastructure\Module\ModuleRegistry;

final class ModuleRouteGuard
{
    public const ALLOW     = 'allow';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModuleResolver $resolver,
    ) {}

    /** @return self::ALLOW|self::NOT_FOUND */
    public function authorize(TenantId $tenantId, string $path): string
    {
        $owner = $this->registry->findOwnerOfPath($path);
        if ($owner === null) {
            return self::ALLOW; // shell route or unowned path
        }
        $state = $this->resolver->stateFor($tenantId, $owner->name());
        return $state->isActive() ? self::ALLOW : self::NOT_FOUND;
    }
}
```

- [ ] **Step 3: Run pass**

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): ModuleRouteGuard — 404 disabled modules before auth"
```

---

## Task C8: Tenant entity extensions + tests

**Files:**

- Modify: `src/Domain/Tenant/Tenant.php`
- Modify: `src/Domain/Tenant/TenantRepositoryInterface.php`
- Modify: `tests/Unit/Domain/Tenant/TenantTest.php` (create if missing)

- [ ] **Step 1: Read existing Tenant class**

```bash
cat src/Domain/Tenant/Tenant.php
cat src/Domain/Tenant/TenantRepositoryInterface.php
```

- [ ] **Step 2: Add fields and methods to Tenant**

Append constructor parameters (with defaults) and getters:

```php
// Add at end of constructor parameter list:
private readonly ?array $displayNameI18n,        // ['fi_FI' => '...', 'en_GB' => '...']
private readonly ?array $publicDescriptionI18n,
/** @var list<string> */ private readonly array $supportedLocales,
private readonly string $defaultLocale,
private readonly ?\DateTimeImmutable $suspendedAt,
private readonly ?string $suspendedReason,
```

Add getters:

```php
public function displayName(string $locale): string
{
    if ($this->displayNameI18n !== null && isset($this->displayNameI18n[$locale])) {
        return $this->displayNameI18n[$locale];
    }
    if ($this->displayNameI18n !== null && isset($this->displayNameI18n['en_GB'])) {
        return $this->displayNameI18n['en_GB'];
    }
    return $this->name; // ultimate fallback to slug-class identifier
}

public function publicDescription(string $locale): ?string
{
    if ($this->publicDescriptionI18n === null) return null;
    return $this->publicDescriptionI18n[$locale]
        ?? $this->publicDescriptionI18n['en_GB']
        ?? null;
}

/** @return list<string> */
public function supportedLocales(): array { return $this->supportedLocales; }

public function defaultLocale(): string { return $this->defaultLocale; }
public function suspended(): bool { return $this->suspendedAt !== null; }
public function suspendedAt(): ?\DateTimeImmutable { return $this->suspendedAt; }
public function suspendedReason(): ?string { return $this->suspendedReason; }
```

- [ ] **Step 3: Add new methods to TenantRepositoryInterface**

```php
public function update(Tenant $tenant): void;
public function suspend(TenantId $tenantId, string $reason, \DateTimeImmutable $now): void;
public function reactivate(TenantId $tenantId): void;
```

- [ ] **Step 4: Update SqlTenantRepository to implement these (just signature stubs that throw — actual impl comes in Wave D Task D7).**

```php
public function update(Tenant $tenant): void
{
    throw new \LogicException('Not implemented in this commit; see D7');
}
// Same for suspend, reactivate.
```

- [ ] **Step 5: Add tests for the new getters in TenantTest**

```php
public function test_displayName_uses_locale_then_en_GB_then_name(): void
{
    $t = $this->makeTenant(displayNameI18n: ['fi_FI' => 'Daem ry', 'en_GB' => 'Daem']);
    self::assertSame('Daem ry', $t->displayName('fi_FI'));
    self::assertSame('Daem',    $t->displayName('en_GB'));
    self::assertSame('Daem',    $t->displayName('sw_TZ')); // fallback to en_GB
}

public function test_displayName_falls_back_to_name_when_i18n_null(): void
{
    $t = $this->makeTenant(displayNameI18n: null, name: 'daems');
    self::assertSame('daems', $t->displayName('fi_FI'));
}

public function test_suspended_true_when_suspendedAt_set(): void
{
    $t = $this->makeTenant(suspendedAt: new \DateTimeImmutable('2026-05-01'));
    self::assertTrue($t->suspended());
}
```

- [ ] **Step 6: Run pass; commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): Tenant entity locale + suspension extensions"
```

---

# Wave D — Persistence

## Task D1: InMemoryTenantModulesRepository + tests

**Files:**

- Create: `tests/Support/Fake/InMemoryTenantModulesRepository.php`
- Create: `tests/Unit/Tests/Support/Fake/InMemoryTenantModulesRepositoryTest.php`

(Path under `tests/` so it cohabits with other in-memory fakes; existing pattern in `tests/Support/Fake/`.)

- [ ] **Step 1: Implement the InMemory repo**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use DateTimeImmutable;

final class InMemoryTenantModulesRepository implements TenantModulesRepositoryInterface
{
    /** @var array<string, TenantModule> keyed by "<tenantId>::<slug>" */
    private array $rows = [];
    /** @var list<ModuleAuditEntry> */
    public array $audits = [];

    public function add(TenantModule $tm): void
    {
        $this->rows[$this->key($tm->tenantId(), $tm->moduleSlug())] = $tm;
    }

    public function findByTenant(TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->tenantId()->equals($tenantId)) {
                $out[] = $row;
            }
        }
        usort($out, fn(TenantModule $a, TenantModule $b) => $a->moduleSlug() <=> $b->moduleSlug());
        return $out;
    }

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
    {
        return $this->rows[$this->key($tenantId, $moduleSlug)] ?? null;
    }

    public function save(TenantModule $tm): void
    {
        $this->rows[$this->key($tm->tenantId(), $tm->moduleSlug())] = $tm;
    }

    public function revokeAvailability(TenantModule $tm, DateTimeImmutable $now, array $auditEntries): void
    {
        // Build a new TenantModule with cleared timestamps.
        $cleared = new TenantModule(
            id: $tm->id(),
            tenantId: $tm->tenantId(),
            moduleSlug: $tm->moduleSlug(),
            availableAt: null, availableBy: null,
            enabledAt:   null, enabledBy:   null,
            disabledAt:  $now,
            createdAt:   $tm->createdAt(),
            updatedAt:   $now,
        );
        $this->save($cleared);
        foreach ($auditEntries as $a) {
            $this->audits[] = $a;
        }
    }

    public function findEnabledByTenant(TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->findByTenant($tenantId) as $row) {
            if ($row->isEnabled()) $out[] = $row;
        }
        return $out;
    }

    private function key(TenantId $tenantId, string $slug): string
    {
        return $tenantId->toString() . '::' . $slug;
    }
}
```

- [ ] **Step 2: Smoke test**

```php
final class InMemoryTenantModulesRepositoryTest extends TestCase
{
    public function test_add_and_find(): void
    {
        $repo = new InMemoryTenantModulesRepository();
        $t = TenantId::fromString('01928000-0000-7000-8000-000000000aaa');
        $tm = $this->makeRow($t, 'forum');
        $repo->save($tm);
        self::assertSame($tm, $repo->find($t, 'forum'));
    }
    // ... etc
}
```

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): InMemoryTenantModulesRepository for tests"
```

---

## Task D2-D3: InMemoryModuleAuditRepository + InMemoryTenantDomainRepository

**Files:** mirror D1 shape under `tests/Support/Fake/`.

- [ ] **Step 1-3: Implement and test each, commit individually**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): InMemoryModuleAuditRepository for tests"
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): InMemoryTenantDomainRepository for tests"
```

---

## Task D4: SqlTenantModulesRepository + Integration test

**Files:**

- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantModulesRepository.php`
- Create: `tests/Integration/Infrastructure/Persistence/Sql/SqlTenantModulesRepositoryTest.php`

- [ ] **Step 1: Read existing SQL repo for pattern reference**

```bash
cat src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php | head -80
```

Match the connection injection, prepared-statement pattern, and UUID handling used there.

- [ ] **Step 2: Write integration test first**

```php
<?php declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantModulesRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlTenantModulesRepositoryTest extends MigrationTestCase
{
    private SqlTenantModulesRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateAll();
        $this->tenantId = TenantId::fromString($this->seedTenant('test', 'Test'));
        $this->repo = new SqlTenantModulesRepository($this->connection());
    }

    public function test_save_and_find(): void
    {
        $tm = $this->makeRow('forum');
        $this->repo->save($tm);

        $found = $this->repo->find($this->tenantId, 'forum');
        self::assertNotNull($found);
        self::assertSame('forum', $found->moduleSlug());
        self::assertTrue($found->isAvailable());
    }

    public function test_save_idempotent_on_same_id(): void
    {
        $tm = $this->makeRow('forum');
        $this->repo->save($tm);
        $this->repo->save($tm); // same ID, second save is an update, not a duplicate
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_modules WHERE module_slug='forum'")->fetchColumn();
        self::assertSame(1, $count);
    }

    public function test_findByTenant_orders_by_slug(): void
    {
        $this->repo->save($this->makeRow('forum'));
        $this->repo->save($this->makeRow('events'));
        $this->repo->save($this->makeRow('insights'));

        $rows = $this->repo->findByTenant($this->tenantId);
        $slugs = array_map(fn(TenantModule $r) => $r->moduleSlug(), $rows);
        self::assertSame(['events', 'forum', 'insights'], $slugs);
    }

    public function test_revokeAvailability_clears_state_and_writes_audit(): void
    {
        $tm = $this->makeRow('forum',
            availableAt: new DateTimeImmutable('-1 day'),
            enabledAt:   new DateTimeImmutable('-1 hour'));
        $this->repo->save($tm);

        $now = new DateTimeImmutable();
        $auditEntry = $this->makeAuditEntry('forum', 'revoked_availability');
        $this->repo->revokeAvailability($tm, $now, [$auditEntry]);

        $after = $this->repo->find($this->tenantId, 'forum');
        self::assertNull($after?->availableAt());
        self::assertNull($after?->enabledAt());
        self::assertNotNull($after?->disabledAt());

        $audits = (int) $this->pdo->query("SELECT COUNT(*) FROM module_audit")->fetchColumn();
        self::assertSame(1, $audits);
    }

    private function makeRow(string $slug, ?DateTimeImmutable $availableAt = null, ?DateTimeImmutable $enabledAt = null): TenantModule
    {
        $now = new DateTimeImmutable();
        return new TenantModule(
            id: bin2hex(random_bytes(8)) . '-1234-5678-9abc-def012345678',
            tenantId: $this->tenantId,
            moduleSlug: $slug,
            availableAt: $availableAt ?? new DateTimeImmutable('-1 day'),
            availableBy: null,
            enabledAt: $enabledAt, enabledBy: null,
            disabledAt: null,
            createdAt: $now, updatedAt: $now,
        );
    }
}
```

- [ ] **Step 3: Implement SqlTenantModulesRepository**

```php
<?php declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\ModuleAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlTenantModulesRepository implements TenantModulesRepositoryInterface
{
    public function __construct(private readonly Connection $conn) {}

    public function findByTenant(TenantId $tenantId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tenant_modules WHERE tenant_id = :tid ORDER BY module_slug ASC"
        );
        $stmt->execute(['tid' => $tenantId->toString()]);
        return array_map(
            fn(array $row) => $this->hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function find(TenantId $tenantId, string $moduleSlug): ?TenantModule
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tenant_modules WHERE tenant_id = :tid AND module_slug = :slug LIMIT 1"
        );
        $stmt->execute(['tid' => $tenantId->toString(), 'slug' => $moduleSlug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function save(TenantModule $tm): void
    {
        $sql = "INSERT INTO tenant_modules
            (id, tenant_id, module_slug, available_at, available_by, enabled_at, enabled_by, disabled_at, created_at, updated_at)
            VALUES (:id, :tid, :slug, :avail_at, :avail_by, :enab_at, :enab_by, :dis_at, :created, :updated)
            ON DUPLICATE KEY UPDATE
                available_at = VALUES(available_at),
                available_by = VALUES(available_by),
                enabled_at   = VALUES(enabled_at),
                enabled_by   = VALUES(enabled_by),
                disabled_at  = VALUES(disabled_at),
                updated_at   = VALUES(updated_at)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            'id'        => $tm->id(),
            'tid'       => $tm->tenantId()->toString(),
            'slug'      => $tm->moduleSlug(),
            'avail_at'  => $tm->availableAt()?->format('Y-m-d H:i:s'),
            'avail_by'  => $tm->availableBy()?->toString(),
            'enab_at'   => $tm->enabledAt()?->format('Y-m-d H:i:s'),
            'enab_by'   => $tm->enabledBy()?->toString(),
            'dis_at'    => $tm->disabledAt()?->format('Y-m-d H:i:s'),
            'created'   => $tm->createdAt()->format('Y-m-d H:i:s'),
            'updated'   => $tm->updatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAvailability(TenantModule $tm, DateTimeImmutable $now, array $auditEntries): void
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                "UPDATE tenant_modules
                 SET available_at = NULL, available_by = NULL,
                     enabled_at = NULL, enabled_by = NULL,
                     disabled_at = :now, updated_at = :now
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $tm->id(), 'now' => $now->format('Y-m-d H:i:s')]);

            $audit = $this->conn->prepare(
                "INSERT INTO module_audit
                 (id, tenant_id, module_slug, action, actor_user_id, actor_role, reason, created_at)
                 VALUES (:id, :tid, :slug, :action, :actor, :role, :reason, :created)"
            );
            foreach ($auditEntries as $entry) {
                $audit->execute([
                    'id'      => $entry->id(),
                    'tid'     => $entry->tenantId()->toString(),
                    'slug'    => $entry->moduleSlug(),
                    'action'  => $entry->action()->value,
                    'actor'   => $entry->actorUserId()->toString(),
                    'role'    => $entry->actorRole(),
                    'reason'  => $entry->reason(),
                    'created' => $entry->createdAt()->format('Y-m-d H:i:s'),
                ]);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    public function findEnabledByTenant(TenantId $tenantId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tenant_modules
             WHERE tenant_id = :tid AND enabled_at IS NOT NULL
             ORDER BY module_slug ASC"
        );
        $stmt->execute(['tid' => $tenantId->toString()]);
        return array_map(fn($r) => $this->hydrate($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): TenantModule
    {
        return new TenantModule(
            id:         (string) $row['id'],
            tenantId:   TenantId::fromString((string) $row['tenant_id']),
            moduleSlug: (string) $row['module_slug'],
            availableAt: $row['available_at'] !== null ? new DateTimeImmutable((string) $row['available_at']) : null,
            availableBy: $row['available_by'] !== null ? UserId::fromString((string) $row['available_by']) : null,
            enabledAt:   $row['enabled_at']   !== null ? new DateTimeImmutable((string) $row['enabled_at'])   : null,
            enabledBy:   $row['enabled_by']   !== null ? UserId::fromString((string) $row['enabled_by'])     : null,
            disabledAt:  $row['disabled_at']  !== null ? new DateTimeImmutable((string) $row['disabled_at'])  : null,
            createdAt:   new DateTimeImmutable((string) $row['created_at']),
            updatedAt:   new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
```

- [ ] **Step 4: Run pass**

```bash
composer test -- --testsuite=Integration --filter SqlTenantModulesRepositoryTest
```

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): SqlTenantModulesRepository — save/find/revokeAvailability with audit"
```

---

## Task D5: SqlModuleAuditRepository + Integration test

Mirror D4's shape. Two methods to implement: `append` and `listForTenant`/`listForModule` (separate prepared statements).

- [ ] **Step 1-5: Implement, test, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): SqlModuleAuditRepository for module_audit append-only log"
```

---

## Task D6: SqlTenantDomainRepository + Integration test

The existing `tenant_domains` table from migration 019 is read-only via `tenant-fallback.php` config. This task adds a write-capable repo.

- [ ] **Step 1-5: Implement five methods (`findByTenant`, `find`, `add`, `update`, `remove`, `setPrimary`), enforce single-primary invariant via transaction, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): SqlTenantDomainRepository — write-capable domain CRUD"
```

---

## Task D7: SqlTenantRepository extensions + Integration test

Implement the previously-stubbed `update`, `suspend`, `reactivate` methods on SqlTenantRepository.

- [ ] **Step 1-5: Implement, test, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): SqlTenantRepository.update/suspend/reactivate + i18n field roundtrip"
```

---

# Wave E — Application layer

Each Application task follows TDD: write the unit test first using InMemory repos, then implement the use case. All use cases follow the existing project pattern: `<UseCaseName>Input` (constructor-injected request data), `<UseCaseName>Output` (immutable result), and a single class with one public `execute(<Input>): <Output>` method.

## Task E1: CreateTenant use case

**Files:**

- Create: `src/Application/Backstage/Platform/CreateTenant/CreateTenant.php`
- Create: `src/Application/Backstage/Platform/CreateTenant/CreateTenantInput.php`
- Create: `src/Application/Backstage/Platform/CreateTenant/CreateTenantOutput.php`
- Create: `tests/Unit/Application/Backstage/Platform/CreateTenant/CreateTenantTest.php`

- [ ] **Step 1: Write Input + Output value objects**

`CreateTenantInput`: actingUserId (UserId), slug (string), displayNamesI18n (array), publicDescriptionsI18n (array), supportedLocales (list<string>), defaultLocale (string), memberNumberPrefix (string).

`CreateTenantOutput`: tenantId (TenantId), createdAt (DateTimeImmutable).

- [ ] **Step 2: Write the use case test**

```php
public function test_creates_tenant_and_seeds_default_available_modules(): void
{
    $useCase = $this->buildUseCase();
    $output = $useCase->execute(new CreateTenantInput(
        actingUserId: $this->gsaUser->id(),
        slug: 'newco',
        displayNamesI18n: ['en_GB' => 'New Co'],
        publicDescriptionsI18n: ['en_GB' => 'A new association'],
        supportedLocales: ['en_GB'],
        defaultLocale: 'en_GB',
        memberNumberPrefix: 'NC-',
    ));

    self::assertNotNull($output->tenantId());
    $tenant = $this->tenants->find($output->tenantId());
    self::assertSame('newco', $tenant?->slug()->toString());

    // 5 default_available modules pre-seeded as available (not enabled)
    $modules = $this->tenantModules->findByTenant($output->tenantId());
    self::assertCount(5, $modules);
    foreach ($modules as $m) {
        self::assertTrue($m->isAvailable(), "expected available_at set for {$m->moduleSlug()}");
        self::assertFalse($m->isEnabled(), "expected enabled_at NOT set for {$m->moduleSlug()}");
    }
}

public function test_rejects_duplicate_slug(): void
{
    $useCase = $this->buildUseCase();
    $useCase->execute($this->validInput('newco'));

    $this->expectException(\DomainException::class);
    $useCase->execute($this->validInput('newco'));
}

public function test_rejects_non_platform_admin_actor(): void
{
    $useCase = $this->buildUseCase();
    $this->expectException(\Daems\Domain\ForbiddenException::class);
    $useCase->execute(new CreateTenantInput(
        actingUserId: $this->normalUser->id(),
        slug: 'newco',
        // ...
    ));
}
```

- [ ] **Step 3: Implement the use case**

```php
<?php declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\CreateTenant;

use Daems\Domain\ForbiddenException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantModule;
use Daems\Domain\Tenant\TenantModulesRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Module\ModuleRegistry;

final class CreateTenant
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantModulesRepositoryInterface $tenantModules,
        private readonly UserRepositoryInterface $users,
        private readonly ModuleRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function execute(CreateTenantInput $input): CreateTenantOutput
    {
        $actor = $this->users->findById($input->actingUserId());
        if ($actor === null || ! $actor->isPlatformAdmin()) {
            throw new ForbiddenException('Only platform admins can create tenants');
        }

        // Reject duplicate slug.
        if ($this->tenants->findBySlug($input->slug()) !== null) {
            throw new \DomainException("Tenant with slug '{$input->slug()}' already exists");
        }

        $now = $this->clock->now();
        $tenant = Tenant::create(/* ... use existing factory or constructor with all new fields ... */);
        $this->tenants->save($tenant);

        // Seed tenant_modules rows for every default_available module.
        foreach ($this->registry->all() as $name => $manifest) {
            if ($manifest->isCore()) continue;
            if (! $manifest->defaultAvailable()) continue;
            $tm = new TenantModule(
                id:         /* uuidv7 */ ...,
                tenantId:   $tenant->id(),
                moduleSlug: $name,
                availableAt: $now,
                availableBy: $input->actingUserId(),
                enabledAt:  null, enabledBy: null, disabledAt: null,
                createdAt:  $now, updatedAt: $now,
            );
            $this->tenantModules->save($tm);
        }

        return new CreateTenantOutput($tenant->id(), $now);
    }
}
```

- [ ] **Step 4: Run tests; pass**

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): CreateTenant use case + auto-seed default_available modules"
```

---

## Task E2-E10: Remaining Application use cases

For each use case below, follow the same TDD shape as E1: write Input/Output, write test (covering happy path + at least one rejection path), implement, commit.

**E2 — UpdateTenantBasics**

- Updates display_name_i18n, public_description_i18n, supported_locales, default_locale, member_number_prefix on an existing tenant.
- Rejects slug change (throws `TenantSlugImmutableException` if input slug differs from stored).
- Rejects non-GSA actor.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): UpdateTenantBasics use case"
```

**E3 — SuspendTenant + ReactivateTenant**

- Both single-method use cases. Suspend requires reason; reactivate clears reason.
- Both reject non-GSA.
- Suspending a tenant does NOT change its tenant_modules rows; resolver simply behaves as if it does (and TenantSuspendedException is thrown by route guard's enclosing layer when needed).

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): SuspendTenant + ReactivateTenant use cases"
```

**E4 — Tenant domain CRUD (3 use cases)**

- AddTenantDomain — adds new row, validates hostname format `/^[a-z0-9.-]+$/`, optionally promotes to primary.
- UpdateTenantDomain — toggles primary, updates hostname.
- RemoveTenantDomain — refuses to delete the last primary domain (throws `TenantPrimaryDomainRequiredException`).

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): tenant domain CRUD — add/update/remove with primary invariant"
```

**E5 — Tenant admin assignment (2 use cases)**

- GrantAdminToUserForTenant — upserts `user_tenants(user_id, tenant_id, role='admin')`.
- RevokeAdminFromUserForTenant — sets role to 'member' (preserves the user-tenant link).
- Both reject non-GSA actors.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): GrantAdminToUserForTenant + RevokeAdminFromUserForTenant"
```

**E6 — GrantModuleAvailability**

- Idempotent re-grant on already-available module is a no-op for state but still writes an audit row.
- Validates module exists in registry.
- Validates all `depends_on` modules are themselves available for the tenant (NOT enabled — just available); throws `ModuleDependencyUnmetException` otherwise.
- Writes audit row with action=`MADE_AVAILABLE`.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): GrantModuleAvailability with depends_on validation"
```

**E7 — RevokeModuleAvailability (with cascade)**

- Atomically disables the module if currently enabled, AND clears available_at, AND writes audit rows: one for `REVOKED_AVAILABILITY` and one for the implicit `DISABLED` if the module was previously enabled.
- Cascades through dependents: any module that depends_on this one and is currently enabled is also force-disabled (one audit row each, action=`DISABLED`, reason="cascade from <root>").
- Rejects non-GSA.

The cascade logic uses `ModuleRegistry::dependents($name)` to walk transitively.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): RevokeModuleAvailability with transitive dependent cascade + audit"
```

**E8 — Read models: ListTenants + GetTenantDetail + ListTenantModules**

- Three small use cases that fetch read-shaped DTOs for the GSA UI.
- ListTenants paginates; GetTenantDetail merges domain count, admin count, modules ratio.
- ListTenantModules merges manifest data + tenant_modules rows into a single output array.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): ListTenants + GetTenantDetail + ListTenantModules read models"
```

**E9 — EnableModuleForTenant + DisableModuleForTenant (Tenant scope)**

- EnableModuleForTenant requires a `tenant_modules` row with available_at non-null AND all depends_on are themselves enabled. Throws `ModuleNotAvailableException` or `ModuleDependencyUnmetException` accordingly.
- DisableModuleForTenant rejects if any dependent module is currently enabled. Throws `ModuleDependentEnabledException`.
- Actor must be tenant_admin OR platform_admin acting on the tenant.
- Each writes an audit row.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): EnableModuleForTenant + DisableModuleForTenant with dependency locks"
```

**E10 — ListTenantModulesForCurrentTenant**

- Reads from request-resolved tenant context.
- Returns three lists: enabled, available_not_enabled, disabled (the last for orphan rows + manifest-known not-yet-available).

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant): ListTenantModulesForCurrentTenant — Settings → Modules read model"
```

---

# Wave F — Controllers + DI wiring

## Task F1: Three GSA-side controllers

**Files:**

- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Platform/TenantsController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Platform/TenantDomainsController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Platform/TenantAdminsController.php`

Each controller is a thin HTTP wrapper:

1. Read existing `BackstageController` or per-module backstage controllers for the request/response shape.
2. Each public method on the controller corresponds to one HTTP route.
3. Authorization: every method calls `$this->requirePlatformAdmin($request)` first.
4. Domain exceptions map to HTTP statuses: `ForbiddenException` → 403, `\DomainException` (slug duplicate, last primary domain) → 422 with JSON body `{error: 'message'}`, `\InvalidArgumentException` → 422.

- [ ] **Step 1-3: Implement each controller, smoke-test each method**

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform): TenantsController + TenantDomainsController + TenantAdminsController"
```

---

## Task F2: Two TenantModulesController (Platform + Tenant)

**Files:**

- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Platform/PlatformTenantModulesController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Tenant/TenantSelfModulesController.php`

Two separate classes because the authorization predicates differ (`requirePlatformAdmin` vs `requireTenantAdmin`).

- [ ] **Step 1-3: Implement both, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform/tenant): TenantModules controllers — Platform + Tenant scopes"
```

---

## Task F3: Wire bootstrap + KernelHarness

**Files:**

- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

Add bindings for every new class introduced in Waves C–F:

- 5 new domain ports (TenantModulesRepositoryInterface, ModuleAuditRepositoryInterface, TenantDomainRepositoryInterface) bound to either Sql impls (bootstrap) or InMemory fakes (KernelHarness)
- 1 new domain service: TenantModuleResolver (singleton)
- 1 new domain service: ModuleRouteGuard (singleton)
- 16 new use cases (E1–E10 enumerated)
- 5 new controllers (F1+F2)

Add new HTTP routes for every controller method to `routes/api.php` (existing routes file — read its current shape first; update to include the new prefixes `/api/v1/backstage/platform/tenants*`, `/api/v1/backstage/platform/tenants/{id}/domains*`, etc., AND the tenant-side `/api/v1/backstage/tenant/modules*`).

- [ ] **Step 1: Read current bootstrap/app.php and KernelHarness**

```bash
grep -n "container->bind\|container->singleton" bootstrap/app.php | head -40
grep -n "container->bind\|container->singleton" tests/Support/KernelHarness.php | head -40
```

- [ ] **Step 2: Add bindings (bootstrap first)**

```php
// bootstrap/app.php — additions
$container->singleton(
    \Daems\Domain\Tenant\TenantModulesRepositoryInterface::class,
    fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantModulesRepository($c->make(Connection::class))
);
$container->singleton(
    \Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class,
    fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlModuleAuditRepository($c->make(Connection::class))
);
$container->singleton(
    \Daems\Domain\Tenant\TenantDomainRepositoryInterface::class,
    fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantDomainRepository($c->make(Connection::class))
);
$container->singleton(
    \Daems\Domain\Tenant\TenantModuleResolver::class,
    fn(Container $c) => new \Daems\Domain\Tenant\TenantModuleResolver(
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
    )
);
$container->singleton(
    \Daems\Domain\Tenant\ModuleRouteGuard::class,
    fn(Container $c) => new \Daems\Domain\Tenant\ModuleRouteGuard(
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
    )
);
// Use cases — one bind() per class, follows existing pattern.
$container->bind(
    \Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class,
    fn(Container $c) => new \Daems\Application\Backstage\Platform\CreateTenant\CreateTenant(
        $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
        $c->make(\Daems\Domain\Tenant\TenantModulesRepositoryInterface::class),
        $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
        $c->make(\Daems\Infrastructure\Module\ModuleRegistry::class),
        $c->make(\Daems\Domain\Shared\Clock::class),
    )
);
// ... repeat for the other 15 use cases.
// Controllers:
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class,
    fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController(
        $c->make(\Daems\Application\Backstage\Platform\CreateTenant\CreateTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\UpdateTenantBasics\UpdateTenantBasics::class),
        $c->make(\Daems\Application\Backstage\Platform\SuspendTenant\SuspendTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\ReactivateTenant\ReactivateTenant::class),
        $c->make(\Daems\Application\Backstage\Platform\ListTenants\ListTenants::class),
        $c->make(\Daems\Application\Backstage\Platform\GetTenantDetail\GetTenantDetail::class),
    )
);
// ... and the four other controllers.
```

- [ ] **Step 3: Mirror in KernelHarness**

Same shape but using InMemory fakes for repositories. Add public properties `$tenantModules`, `$moduleAudit`, `$tenantDomains` to make them addressable from tests.

```php
// tests/Support/KernelHarness.php — additions in __construct
$this->tenantModules = new \Daems\Tests\Support\Fake\InMemoryTenantModulesRepository();
$this->moduleAudit   = new \Daems\Tests\Support\Fake\InMemoryModuleAuditRepository();
$this->tenantDomains = new \Daems\Tests\Support\Fake\InMemoryTenantDomainRepository();

$this->container->singleton(
    \Daems\Domain\Tenant\TenantModulesRepositoryInterface::class,
    fn() => $this->tenantModules
);
$this->container->singleton(
    \Daems\Domain\Tenant\ModuleAuditRepositoryInterface::class,
    fn() => $this->moduleAudit
);
$this->container->singleton(
    \Daems\Domain\Tenant\TenantDomainRepositoryInterface::class,
    fn() => $this->tenantDomains
);
// Resolver, RouteGuard, every use case, every controller — same as bootstrap.
```

- [ ] **Step 4: Add routes**

```bash
cat routes/api.php | head -40
```

Add (appending; not replacing):

```php
// Platform admin (GSA-only)
$router->get(   '/api/v1/backstage/platform/tenants',                ...);
$router->post(  '/api/v1/backstage/platform/tenants',                ...);
$router->get(   '/api/v1/backstage/platform/tenants/{id}',            ...);
$router->patch( '/api/v1/backstage/platform/tenants/{id}',            ...);
$router->post(  '/api/v1/backstage/platform/tenants/{id}/suspend',    ...);
$router->post(  '/api/v1/backstage/platform/tenants/{id}/reactivate', ...);
$router->get(   '/api/v1/backstage/platform/tenants/{id}/domains',    ...);
$router->post(  '/api/v1/backstage/platform/tenants/{id}/domains',    ...);
$router->patch( '/api/v1/backstage/platform/tenants/{id}/domains/{did}', ...);
$router->delete('/api/v1/backstage/platform/tenants/{id}/domains/{did}', ...);
$router->get(   '/api/v1/backstage/platform/tenants/{id}/admins',     ...);
$router->post(  '/api/v1/backstage/platform/tenants/{id}/admins',     ...);
$router->delete('/api/v1/backstage/platform/tenants/{id}/admins/{uid}', ...);
$router->get(   '/api/v1/backstage/platform/tenants/{id}/modules',    ...);
$router->post(  '/api/v1/backstage/platform/tenants/{id}/modules/{slug}/availability', ...);

// Tenant admin (current-tenant scoped)
$router->get(   '/api/v1/backstage/tenant/modules',           ...);
$router->post(  '/api/v1/backstage/tenant/modules/{slug}/state', ...);
```

- [ ] **Step 5: Smoke**

```bash
php -r "require 'vendor/autoload.php'; require 'bootstrap/app.php';" && echo OK
composer test -- --testsuite=Unit
composer test -- --testsuite=Integration
```

- [ ] **Step 6: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform/tenant): wire DI bindings + routes for module registry + tenant mgmt"
```

---

# Wave G — Backstage shell integration

## Task G1: BackstageSidebar + tests

**Files:**

- Create: `src/Frontend/BackstageSidebar.php`
- Create: `tests/Unit/Frontend/BackstageSidebarTest.php`

The class produces a structured array consumed by `_shared.php` to render the sidebar HTML.

- [ ] **Step 1: Test for shell items**

```php
public function test_renders_dashboard_settings_search_for_any_user(): void
{
    $sidebar = $this->buildSidebar(enabledModules: []);
    $items = $sidebar->buildFor($this->tenant, $this->normalUser);
    $hrefs = array_column($items, 'href');
    self::assertContains('/backstage/',          $hrefs); // dashboard
    self::assertContains('/backstage/settings',  $hrefs);
    self::assertContains('/backstage/search',    $hrefs);
}

public function test_adds_platform_group_only_for_platform_admin(): void
{
    $sidebar = $this->buildSidebar(enabledModules: []);
    $itemsForGsa     = $sidebar->buildFor($this->tenant, $this->gsaUser);
    $itemsForRegular = $sidebar->buildFor($this->tenant, $this->normalUser);
    self::assertContains('/backstage/platform/tenants', array_column($itemsForGsa, 'href'));
    self::assertNotContains('/backstage/platform/tenants', array_column($itemsForRegular, 'href'));
}

public function test_includes_enabled_modules_only(): void
{
    $sidebar = $this->buildSidebar(enabledModules: ['forum', 'events']);
    $items = $sidebar->buildFor($this->tenant, $this->normalUser);
    $hrefs = array_column($items, 'href');
    self::assertContains('/backstage/forum',  $hrefs);
    self::assertContains('/backstage/events', $hrefs);
    self::assertNotContains('/backstage/insights', $hrefs); // not enabled
}

public function test_orders_by_sidebar_order(): void
{
    // Members order=10, Events order=20, Projects order=21, Forum order=30, Insights order=40
    $sidebar = $this->buildSidebar(enabledModules: ['insights', 'forum', 'members']);
    $items = $sidebar->buildFor($this->tenant, $this->normalUser);
    // Filter to only the module items (drop Dashboard/Settings/Search shell):
    $moduleHrefs = array_values(array_filter(
        array_column($items, 'href'),
        fn($h) => str_starts_with($h, '/backstage/') && ! in_array($h, ['/backstage/', '/backstage/settings', '/backstage/search'], true)
    ));
    // Members < Forum < Insights by registry order.
    self::assertSame(['/backstage/members', '/backstage/forum', '/backstage/insights'], $moduleHrefs);
}
```

- [ ] **Step 2: Implement BackstageSidebar**

```php
<?php declare(strict_types=1);

namespace Daems\Frontend;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\User\User;
use Daems\Infrastructure\Module\ModuleRegistry;

final class BackstageSidebar
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModuleResolver $resolver,
    ) {}

    /**
     * @return list<array{group:string, label_key:string, href:string, icon:string, order:int}>
     */
    public function buildFor(Tenant $tenant, User $user): array
    {
        $items = [];

        // Shell items (always present).
        $items[] = ['group' => 'shell', 'label_key' => 'shell.dashboard', 'href' => '/backstage/',         'icon' => 'home',     'order' => 0];
        $items[] = ['group' => 'shell', 'label_key' => 'shell.search',    'href' => '/backstage/search',   'icon' => 'search',   'order' => 1];
        $items[] = ['group' => 'shell', 'label_key' => 'shell.settings',  'href' => '/backstage/settings', 'icon' => 'settings', 'order' => 999];

        // Platform group (GSA-only).
        if ($user->isPlatformAdmin()) {
            $items[] = ['group' => 'platform', 'label_key' => 'platform.tenants.title', 'href' => '/backstage/platform/tenants', 'icon' => 'layers', 'order' => 0];
        }

        // Module items.
        $states = $this->resolver->statesForTenant($tenant->id());
        foreach ($this->registry->all() as $name => $manifest) {
            if (! ($states[$name] ?? null)?->isActive()) {
                continue;
            }
            $sb = $manifest->sidebar();
            if ($sb === null) continue;
            $items[] = [
                'group'     => $sb->group(),
                'label_key' => $manifest->nameKey() ?? "modules.{$name}.name",
                'href'      => $sb->href(),
                'icon'      => $sb->icon(),
                'order'     => $sb->order(),
            ];
        }

        // Group-aware sort: shell first, then platform, then by group + order.
        usort($items, function (array $a, array $b): int {
            $groupRank = ['shell' => 0, 'platform' => 1, 'members' => 2, 'content' => 3, 'community' => 4, 'governance' => 5];
            $ag = $groupRank[$a['group']] ?? 99;
            $bg = $groupRank[$b['group']] ?? 99;
            return $ag <=> $bg ?: $a['order'] <=> $b['order'];
        });

        return $items;
    }
}
```

- [ ] **Step 3: Run pass; commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(frontend): BackstageSidebar — shell + platform + enabled-module items"
```

---

## Task G2: Integrate ModuleRouteGuard into routers

**Files:**

- Modify: `public/backstage/router.php`
- Modify: `public/backstage/api-router.php`

- [ ] **Step 1: Read router.php**

```bash
cat public/backstage/router.php
```

- [ ] **Step 2: Add guard call before auth check**

In each router file, near the top (after tenant context resolution, before `_guard.php` include):

```php
$guard = $container->make(\Daems\Domain\Tenant\ModuleRouteGuard::class);
$tenantId = /* from TenantContextMiddleware-resolved request */;
$path = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH) ?: '/';

if ($guard->authorize($tenantId, $path) === \Daems\Domain\Tenant\ModuleRouteGuard::NOT_FOUND) {
    http_response_code(404);
    require __DIR__ . '/pages/_shared/404.php'; // create a generic 404 page if absent
    exit;
}
```

- [ ] **Step 3: Smoke**

Browser: visit `/backstage/forum` while Forum is disabled (use SQL `UPDATE tenant_modules SET enabled_at=NULL WHERE module_slug='forum'`). Expected: 404 page; no redirect to login.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): integrate ModuleRouteGuard into router.php + api-router.php"
```

---

## Task G3: _shared.php sidebar render

**Files:**

- Modify: `public/backstage/pages/_shared.php`

- [ ] **Step 1: Read current sidebar render**

- [ ] **Step 2: Replace hardcoded sidebar HTML with BackstageSidebar-driven rendering**

```php
$sidebar = $container->make(\Daems\Frontend\BackstageSidebar::class);
$items = $sidebar->buildFor($currentTenant, $currentUser);

foreach ($items as $item) {
    $label = \Daems\Frontend\I18n::t($item['label_key']);
    $isActive = str_starts_with($currentPath, $item['href']);
    // emit <a class="..." href="..."> ... </a>
}
```

- [ ] **Step 3: Visual smoke**

Open backstage; verify all currently-enabled modules + Dashboard/Settings/Search appear; verify Platform group appears for GSA only.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): _shared.php uses BackstageSidebar for nav rendering"
```

---

# Wave H — Backstage UI pages

## Task H1: Platform tenants list page

**Files:**

- Create: `public/backstage/pages/platform/index.php`
- Create: `public/backstage/pages/platform/list.css`
- Create: `public/backstage/pages/platform/list.js`

- [ ] **Step 1-3: Implement list view**

Markup: HTML table with `<thead>` columns Slug, Name, Status, Domains, Members, Admins, Modules. `<tbody>` filled by JS that calls `GET /api/v1/backstage/platform/tenants` on page load. "New tenant" button opens a modal that POSTs to the same endpoint.

JS pattern: existing dashboard fetch+render shape from `public/backstage/assets/js/`.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenants list page + new-tenant modal"
```

---

## Task H2-H7: Tenant edit shell + 5 tabs

For each of the 5 tabs (Basics, Domains, Admins, Modules, Danger zone), one task:

- [ ] **Step 1: PHP page rendering tab content; tabs state in `?tab=` query param**
- [ ] **Step 2: JS that fetches tab data + handles form submits**
- [ ] **Step 3: Manual smoke for that tab**
- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenant edit Basics tab"
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenant edit Domains tab"
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenant edit Admins tab"
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenant edit Modules tab + cascade-revoke dialog"
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(platform-ui): tenant edit Danger zone tab — suspend/reactivate"
```

---

## Task H8: Settings → Modules tenant-admin page

**Files:**

- Create: `public/backstage/pages/settings/modules.php`
- Create: `public/backstage/pages/settings/modules.css`
- Create: `public/backstage/pages/settings/modules.js`

Layout: two card lists — "In use" (enabled, with Disable buttons; greyed if a dependent is enabled) and "Available, not activated" (with Activate buttons; greyed if a dependency is disabled).

- [ ] **Step 1-3: Implement, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tenant-ui): Settings → Modules — tenant admin enable/disable"
```

---

# Wave I — Default public site

## Task I1: Skeleton + assets

**Files:**

- Create: directory tree as in §15 of the spec — `public/sites/_default/{index.php,join.php,login.php,suspended.php,partials/{header,footer,join-form}.php,assets/{default.css,default.js},lang/{fi_FI,en_GB,sw_TZ}.php}`

- [ ] **Step 1: Create stub files**

Each `partials/*.php` and main page file gets a minimal skeleton that requires the partials and accesses `$tenant` (resolved from request context).

- [ ] **Step 2: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(public): default-site directory skeleton + partials"
```

---

## Task I2: Index page (home) + suspended page

**Files:**

- Modify: `public/sites/_default/index.php`
- Modify: `public/sites/_default/suspended.php`

- [ ] **Step 1: Render home with tenant data**

```php
<?php
$locale = \Daems\Frontend\I18n::currentLocale();
$tenant = \Daems\Domain\Tenant\Tenant::fromRequestContext(); // or however TenantContextMiddleware exposes it
?><!DOCTYPE html>
<html lang="<?=substr($locale, 0, 2)?>">
<head>
    <meta charset="utf-8">
    <title><?=htmlspecialchars($tenant->displayName($locale))?></title>
    <link rel="stylesheet" href="/sites/_default/assets/default.css">
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>
<main>
    <h1><?=htmlspecialchars(\Daems\Frontend\I18n::t('default.home.welcome', $tenant->displayName($locale)))?></h1>
    <?php $desc = $tenant->publicDescription($locale); if ($desc): ?>
        <p><?=htmlspecialchars($desc)?></p>
    <?php endif; ?>
    <p>
        <a class="cta" href="/join"><?=htmlspecialchars(\Daems\Frontend\I18n::t('default.home.cta_join'))?></a>
        <a class="link" href="/backstage/login"><?=htmlspecialchars(\Daems\Frontend\I18n::t('default.home.cta_login'))?></a>
    </p>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
```

Suspended page: `http_response_code(503)` + minimal HTML with i18n text.

- [ ] **Step 2: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(public): default site home + suspended page (503)"
```

---

## Task I3: Join form + login redirect

**Files:**

- Modify: `public/sites/_default/join.php`
- Modify: `public/sites/_default/login.php`
- Modify: `public/sites/_default/partials/join-form.php`

Join form posts to `/api/v1/applications` (existing endpoint). Login is a 302 to `/backstage/login`.

- [ ] **Step 1-3: Implement, commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(public): default site join form + login redirect"
```

---

## Task I4: sites-router.php front-controller

**Files:**

- Create: `public/sites-router.php`
- Modify: `public/index.php` (or whichever is the platform's front controller — verify which)

- [ ] **Step 1: Implement the resolver**

```php
<?php declare(strict_types=1);
// public/sites-router.php — included from the platform's front controller
// AFTER tenant context is resolved.

$tenantSlug = $tenant->slug()->toString();
$customSitePath = realpath(__DIR__ . '/../../sites/' . $tenantSlug . '/public/index.php');

if ($customSitePath !== false && is_file($customSitePath)) {
    require $customSitePath;
    exit;
}

if ($tenant->suspended()) {
    require __DIR__ . '/sites/_default/suspended.php';
    exit;
}

require __DIR__ . '/sites/_default/' . match (true) {
    str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/join')  => 'join.php',
    str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/login') => 'login.php',
    default => 'index.php',
};
```

- [ ] **Step 2: Wire from public/index.php** so that any path NOT under `/backstage/` or `/api/` falls through to `sites-router.php`.

- [ ] **Step 3: Apache vhost note**

Add docs in CLAUDE.md showing how to point a new tenant's vhost at the platform's `public/`. Out-of-band manual step; not code.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(public): sites-router.php fallback to default site when tenant has no /sites/ dir"
```

---

# Wave J — Locale flip + i18n keys

## Task J1: I18n::DEFAULT_LOCALE flip

**Files:**

- Modify: `src/Frontend/I18n.php`

- [ ] **Step 1: Change line 28**

```php
public const DEFAULT_LOCALE = 'en_GB';
```

- [ ] **Step 2: Verify lang/en_GB.php has every key that lang/fi_FI.php has**

```bash
php -r '
$fi = require "lang/fi_FI.php";
$en = require "lang/en_GB.php";
$missing = array_diff_key($fi, $en);
foreach ($missing as $k => $_) echo "MISSING en_GB: $k\n";
exit(empty($missing) ? 0 : 1);
'
```

If any key is missing, add the English translation to `lang/en_GB.php`.

- [ ] **Step 3: Run all tests; some may need locale-specific assertions adjusted**

```bash
composer test:all
```

Fix any tests that break because they assumed Finnish defaults. Most tests should be unaffected.

- [ ] **Step 4: Commit**

```bash
git add src/Frontend/I18n.php lang/en_GB.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(frontend): I18n::DEFAULT_LOCALE fi_FI -> en_GB; en_GB completeness"
```

---

## Task J2: All i18n keys for new features

**Files:**

- Modify: `lang/fi_FI.php`
- Modify: `lang/en_GB.php`
- Modify: `lang/sw_TZ.php`

- [ ] **Step 1: Add the keys listed in spec §16**

Module names + descriptions × 5 modules × 3 locales = 30 keys.
Categories × 5 × 3 = 15 keys.
Platform group keys × ~12 × 3 = 36.
Settings → Modules keys × ~7 × 3 = 21.
Default site keys × ~7 × 3 = 21.

Total: ~123 new key entries.

- [ ] **Step 2: Run lang-completeness check**

Same script as J1 but also against sw_TZ.

- [ ] **Step 3: Commit**

```bash
git add lang/fi_FI.php lang/en_GB.php lang/sw_TZ.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(i18n): module/platform/settings/default keys (fi_FI/en_GB/sw_TZ)"
```

---

# Wave K — E2E + Isolation tests

Each test class in this wave exercises a complete request → response path through the kernel using `KernelHarness`, asserting both DB state and JSON output.

## Task K1: TenantsE2ETest + TenantDomainsE2ETest + TenantAdminsE2ETest

- [ ] **Step 1-4: Implement three E2E test classes covering the GSA flows; commit each**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): GSA E2E — tenants + domains + admins"
```

## Task K2: TenantModulesE2ETest (Platform-side cascade)

Test the full cascade: GSA grants A, grants B (which depends on A), tenant admin enables A then B; GSA revokes A; assertions: B is auto-disabled, two audit rows written, both modules show DISABLED state in subsequent reads.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): TenantModulesE2ETest — GSA cascade revoke through dependents"
```

## Task K3: TenantSelfModulesE2ETest (Tenant-side)

Tests dependency lock: tenant admin tries to enable B without A enabled → 422 with appropriate error code; enable A first → enables; then try to disable A while B enabled → 422.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): TenantSelfModulesE2ETest — dependency locks"
```

## Task K4: ModuleRouteGuardE2ETest

Test that `GET /backstage/forum` returns 404 when Forum is disabled (via direct DB manipulation in test setup) AND that the response body shape matches a generic 404, not a redirect.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): ModuleRouteGuardE2ETest — 404 disabled module before auth"
```

## Task K5: DefaultPublicSiteE2ETest

Test `GET /` against a tenant whose `c:/laragon/www/sites/{slug}/` does not exist — assert default site renders. Assert `POST /join` creates an `applications` row. Assert suspended tenant returns 503.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): DefaultPublicSiteE2ETest — fallback + join + suspended"
```

## Task K6: TenantModulesIsolationTest

Verify that tenant A's `tenant_modules` rows do not leak to tenant B via any API path; that GSA's responses include all tenants; that tenant B's admin gets 403 attempting to toggle tenant A's modules.

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests): TenantModulesIsolationTest — cross-tenant isolation"
```

---

# Wave L — Final polish

## Task L1: PHPStan level 9 = 0 errors

- [ ] **Step 1: Run analyse**

```bash
composer analyse
```

- [ ] **Step 2: Fix any new errors introduced by this branch**

The phpstan baseline (`phpstan-baseline.neon`) should not need any new entries — new code is held to a strict bar. If unavoidable, document each baseline addition in the commit message.

- [ ] **Step 3: Commit (only if fixes needed)**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Fix(static): resolve PHPStan level 9 errors introduced by module registry feature"
```

---

## Task L2: Manual smoke checklist + commit recording

Run through the smoke list documented in spec §17.

- [ ] **Step 1-9: Each step executed and logged**

- [ ] **Step 10: Record results in a smoke-log entry under docs/superpowers/plans/**

```bash
cat > docs/superpowers/plans/2026-05-07-module-registry-tenant-management-smoke.md <<'EOF'
# Smoke log — 2026-05-07 module registry feature

[Date] [Operator]

1. ✅ GSA creates tenant `testi-yhdistys` ...
2. ...
EOF
git add docs/superpowers/plans/2026-05-07-module-registry-tenant-management-smoke.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Doc(plan): smoke log for module registry feature"
```

---

## Task L3: CLAUDE.md updates

**Files:**

- Modify: `CLAUDE.md`

- [ ] **Step 1: Edit per spec §19**

Add Architecture subsection on module manifest split, Adding-a-new-module recipe, Default-public-site explanation, locale-default note, DI BOTH containers warning still highlighted with new class names.

- [ ] **Step 2: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Doc(CLAUDE): module-registry section + locale default + module recipe"
```

---

## Task L4: Memory updates

**Files:**

- Create: `C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/project_module_registry.md`
- Create: `C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/project_default_public_site.md`
- Create: `C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/feedback_module_manifests_are_truth.md`
- Modify: `C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/project_i18n_milestone.md`
- Modify: `C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/MEMORY.md`

- [ ] **Step 1: Write each memory file per spec §20 contents**

- [ ] **Step 2: Update MEMORY.md index**

- [ ] **Step 3: NO COMMIT — memory files are user-private and never staged. Update happens out-of-band.**

---

## Final verification gate

Before declaring the plan complete and awaiting "pushaa":

- [ ] `composer analyse` → 0 errors
- [ ] `composer test:all` → all green
- [ ] Manual smoke complete and logged
- [ ] Branch is on `module-registry-tenant-mgmt`, no uncommitted changes
- [ ] No `.claude/` or other accidental files staged

```bash
git status
git log --oneline backstage-to-platform..HEAD | wc -l   # confirm commit count in target range
```

Then wait for explicit "pushaa" before any push.
