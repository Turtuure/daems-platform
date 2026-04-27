# Modular Architecture — Phase 1 (Projects extraction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the Projects domain from `daems-platform` core into `modules/projects/` (= `dp-projects` repo), following the convention proven by the Insights pilot and the Forum extraction (943/943 tests green, 2026-04-27). Zero user-visible change.

**Architecture:** All Projects backend code (`src/Application/Project/`, 12 sibling dirs of `src/Application/Backstage/<UseCase>/`, 3 SQL repos, public + backstage controllers) moves under `modules/projects/backend/src/` with namespace `DaemsModule\Projects\*`. **`src/Domain/Project/` (14 files) STAYS in core** because three core consumers (`ListPendingApplicationsForAdmin`, `ListProposalsForAdmin`, `ListNotificationsStats`) reference Project domain types — Forum lesson 1. A new single `ProjectsBackstageController` extracts 12 Project methods (10 named + 2 proposal-decision) from the monolith `BackstageController`. All Project-specific bindings + 25 routes + tests + 10 project-pure migrations move with the code. Migrations 053/054/059 (mixed events+projects) STAY in core. The `daem-society` frontend `public/pages/{projects,backstage/projects}/` directories move to `modules/projects/frontend/{public,backstage,assets}/`. The Insights/Forum-era `ModuleRegistry` + autoloader + module-router pick everything up automatically.

**Tech Stack:** PHP 8.3, Composer (runtime ClassLoader), MySQL 8.4, PHPUnit 10.5, PHPStan 1.12 level 9. No new external dependencies.

**Spec:** `docs/superpowers/specs/2026-04-27-modular-architecture-phase1-projects-design.md` (commit `dbfc1de`)

**Repos affected (3 commit streams):**
- `C:\laragon\www\daems-platform\` (branch `dev`) — new `066_*` data-fix migration + autoload-dev/phpstan paths commit + Wave E removals + KernelHarness cleanup
- `C:\laragon\www\modules\projects\` = `dp-projects` repo (existing local `.git`, origin `Turtuure/dp-projects`, branch `dev`) — manifest + all moved Projects code
- `C:\laragon\www\sites\daem-society\` (branch `dev`) — frontend deletes only (module-router already in place)

**Verification gates (must pass before final commit on any task touching moved code):**
- `composer analyse` → 0 errors at PHPStan level 9
- `composer test` (Unit + Integration) → all green
- `composer test:e2e` → all green
- `composer test:all` → all green (run as 4 separate suites if total exceeds 600s — Forum gotcha 3)
- `GET /projects`, `/projects/{slug}`, `/projects/new`, `/backstage/projects` render identically to pre-move
- `GET /api/v1/projects` returns identical JSON to pre-move
- `POST /api/v1/projects/{slug}/comments`, `/likeComment`, `/join`, `/leave`, `/archive` work
- All admin endpoints under `/api/v1/backstage/projects/*` and `/backstage/proposals/*` work — exercise create/update/status/featured/translation/comment-delete/approve/reject flows
- JS console: zero `payload is undefined` errors; Network: every `/modules/projects/assets/backstage/*` returns 200

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa". `.claude/` never staged (`git reset HEAD .claude/` if needed).

**dp-projects push cadence:** repo accumulates commits during plan execution. Push deferred until plan completion + user confirmation.

**Forbidden:** do NOT call `mcp__code-review-graph__*` tools — they hung subagent sessions during PR 3 Task 1.

---

## Task waves (dependency order)

```
Wave A (parallel-safe)
├── Task 1: dp-projects skeleton (manifest + README + .gitignore + phpunit + composer + STUB bindings/routes)
├── Task 2: Core data-fix migration 066_*
└── Task 3: Projects file inventory verification (read-only)

Wave B (sequential, depends on Wave A)
├── Task 5: Move 3 SQL repos + namespace rewrite
├── Task 6: Move 3 InMemory fakes + namespace rewrite
├── Task 7: Move Application/Project (13 dirs, 38 files) + namespace rewrite
├── Task 8: Move 12 admin use case dirs (29 files) + namespace rewrite
├── Task 9: Move ProjectController + namespace rewrite
├── Task 9.5: NEW infra commit on daems-platform — autoload-dev + phpstan paths
├── Task 10: Extract ProjectsBackstageController (TDD, 12 methods)
└── Task 12: Move 10 migrations with project_NNN_* rename

Wave C (wiring, depends on Wave B)
├── Task 13: bindings.php (production) — production-container smoke after
├── Task 14: bindings.test.php (test container)
└── Task 15: routes.php (25 routes)

Wave D (test moves, depends on Wave B + C)
├── Task 17: tests/Unit/Application/Project (2 files)
├── Task 18: 10 Backstage Project unit tests
├── Task 19: 5 integration tests
└── Task 20: isolation (4) + E2E (2)

Wave E (core cleanup — production-smoke after EACH task)
├── Task 21: Remove Projects bindings from bootstrap/app.php
├── Task 22: Remove Projects routes from routes/api.php (-25 routes)
├── Task 23: Remove 12 Project methods from BackstageController
├── Task 24: Remove Projects bindings from KernelHarness + update 6 cross-domain test imports
└── Task 25: Apply 066_* data-fix migration; delete originals + 4 dangling Migration tests

Wave F (frontend, can run parallel with Wave E)
├── Task 26: Move 9 daem-society public pages + __DIR__ rewrite
├── Task 27: Move 1 daem-society backstage page + asset URL update (3 hrefs)
└── Task 28: Move 3 assets (1 CSS + 2 JS) + delete originals

Wave G (verification gate)
└── Task 29: Final verification — PHPStan + composer test:all + production-smoke + git grep + browser smoke
```

**Tasks 4 (Move Domain) + 11 (Second controller) + 16 (Move Domain unit tests) explicitly DROPPED** per Forum lesson 1 + F4=A decision + no Domain Project unit tests exist.

---

## File map summary

**Created in `daems-platform/`:**
- `database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql`

**Modified in `daems-platform/`:**
- `composer.json` (autoload-dev `DaemsModule\\Projects\\` + `DaemsModule\\Projects\\Tests\\`)
- `phpstan.neon` (paths += `../modules/projects/backend/src`)
- `bootstrap/app.php` — remove ~13 import lines + ~30 binding lines for Projects
- `routes/api.php` — remove 25 Projects routes (lines 63–115, 263–296, 364–378)
- `tests/Support/KernelHarness.php` — remove Projects bindings + InMemory fake registrations
- `tests/Unit/Application/Backstage/{ListPendingApplicationsForAdminTest,ListProposalsForAdminTest,ListNotificationsStatsTest}.php` — update InMemoryProject* imports to module namespace
- `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php` — update InMemoryProject* imports
- `tests/E2E/F007_IdentitySpoofingTest.php` — update InMemoryProject* imports
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 12 Project methods (lines 416–786 + ~525–565)

**Deleted in `daems-platform/`:**
- `src/Application/Project/` (entire dir, 13 sub-dirs, 38 files)
- 12 admin sibling dirs under `src/Application/Backstage/`: `AdminUpdateProject`, `ApproveProjectProposal`, `ChangeProjectStatus`, `CreateProjectAsAdmin`, `DeleteProjectCommentAsAdmin`, `GetProjectWithAllTranslations`, `ListProjectCommentsForAdmin`, `ListProjectsForAdmin`, `Projects` (only `ListProjectsStats` inside), `RejectProjectProposal`, `SetProjectFeatured`, `UpdateProjectTranslation` (29 files)
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProject{,CommentModerationAudit,Proposal}Repository.php` (3 files)
- `src/Infrastructure/Adapter/Api/Controller/ProjectController.php`
- `database/migrations/{003,009,010,016,027,031,044,046,052,055}_*.sql` (10 files)
- `tests/Unit/Application/Project/{CreateProjectTest,UpdateProjectTest}.php` (2)
- `tests/Unit/Application/Backstage/{AdminUpdateProject,ApproveProjectProposal,ChangeProjectStatus,CreateProjectAsAdmin,DeleteProjectCommentAsAdmin,ListProjectCommentsForAdmin,ListProjectsForAdmin,ListProjectsStats,RejectProjectProposal,SetProjectFeatured}Test.php` (10)
- `tests/Integration/Application/{ProjectCommentModerationIntegrationTest,ProjectsAdminIntegrationTest}.php` (2)
- `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- `tests/Integration/{ProjectProposalStatsTest,ProjectStatsTest}.php` (2)
- `tests/Isolation/{ProjectTenantIsolationTest,ProjectsAdminTenantIsolationTest,ProjectsI18nTenantIsolationTest,ProjectsStatsTenantIsolationTest}.php` (4)
- `tests/E2E/{F004_UnauthProjectMutationTest,ProjectsLocaleE2ETest}.php` (2)
- `tests/Support/Fake/InMemoryProject{,CommentModerationAudit,Proposal}Repository.php` (3)
- `tests/Integration/Migration/{Migration027Test,Migration031Test,Migration044Test,Migration046Test}.php` (4)

**Retained in `daems-platform/` (NOT deleted):**
- `src/Domain/Project/` (14 files) — Forum lesson 1
- `src/Application/Backstage/{ListPendingApplications,ListProposalsForAdmin,Notifications}/*` — cross-domain consumers
- Migrations `database/migrations/{053_backfill_events_projects_i18n,054_drop_translated_columns_from_events_projects,059_fulltext_events_projects_i18n}.sql` — mixed scope

**Created in `modules/projects/` (dp-projects):**
- `module.json`, `README.md`, `.gitignore`, `phpunit.xml.dist`, `composer.json`
- `backend/bindings.php`, `backend/bindings.test.php`, `backend/routes.php`
- `backend/migrations/project_001..010_*.sql` (10 files)
- `backend/src/Application/Project/*` (38 files)
- `backend/src/Application/Backstage/*` (29 files in 12 dirs)
- `backend/src/Infrastructure/SqlProject*Repository.php` (3 files)
- `backend/src/Controller/ProjectController.php`
- `backend/src/Controller/ProjectsBackstageController.php`
- `backend/tests/Support/InMemoryProject*.php` (3 files)
- `backend/tests/Unit/Application/Project/*.php` (2)
- `backend/tests/Unit/Application/Backstage/*.php` (10)
- `backend/tests/Integration/Application/*.php` (2)
- `backend/tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- `backend/tests/Integration/{ProjectProposalStatsTest,ProjectStatsTest}.php`
- `backend/tests/Isolation/Project*Test.php` (4)
- `backend/tests/E2E/{F004_UnauthProjectMutationTest,ProjectsLocaleE2ETest}.php`
- `frontend/public/{cta,detail,edit,grid,hero,index,new}.php` (7) + `frontend/public/detail/{content,hero}.php` (2) = 9
- `frontend/backstage/index.php` (1)
- `frontend/assets/backstage/{project-modal.css,project-modal.js,projects-stats.js}` (3)

**Modified in `daem-society/`:** none (module-router already handles Projects after manifest discovery)

**Deleted in `daem-society/`:**
- `public/pages/projects/` (entire dir)
- `public/pages/backstage/projects/` (entire dir)

---

## Task 1: dp-projects repo skeleton

**Repo for commits:** `dp-projects` (the new module). Local `.git` already initialised at `C:\laragon\www\modules\projects\` with `origin = https://github.com/Turtuure/dp-projects.git`, HEAD → `refs/heads/dev`. Working copy is empty.

**Files:**
- Create: `C:/laragon/www/modules/projects/module.json`
- Create: `C:/laragon/www/modules/projects/README.md`
- Create: `C:/laragon/www/modules/projects/.gitignore`
- Create: `C:/laragon/www/modules/projects/phpunit.xml.dist`
- Create: `C:/laragon/www/modules/projects/composer.json`
- Create: `C:/laragon/www/modules/projects/backend/bindings.php` (STUB)
- Create: `C:/laragon/www/modules/projects/backend/bindings.test.php` (STUB)
- Create: `C:/laragon/www/modules/projects/backend/routes.php` (STUB)
- Create: `C:/laragon/www/modules/projects/backend/migrations/.gitkeep`

- [ ] **Step 1: Verify GitHub repo exists**

Run:
```bash
gh repo view Turtuure/dp-projects 2>&1 | head -3
```

If "GraphQL: Could not resolve to a Repository" appears, create:
```bash
gh repo create Turtuure/dp-projects --public --description "Projects module for daems-platform — extracted Phase 1" --homepage "https://daems.fi"
```

Otherwise skip.

- [ ] **Step 2: Create `module.json`**

Path: `C:/laragon/www/modules/projects/module.json`
```json
{
  "name": "projects",
  "version": "1.0.0",
  "description": "Projects + project comments + project updates + project proposals",
  "namespace": "DaemsModule\\Projects\\",
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

- [ ] **Step 3: Create `README.md`**

Path: `C:/laragon/www/modules/projects/README.md`
```markdown
# dp-projects — Projects module

Extracted from `daems-platform` Phase 1, 2026-04-27. Pattern proven by Insights pilot + Forum extraction.

## Structure

- `module.json` — manifest read by core's `ModuleRegistry`
- `backend/src/` — PHP code under namespace `DaemsModule\Projects\`
- `backend/bindings.php` — production DI bindings
- `backend/bindings.test.php` — test container bindings (InMemory fakes)
- `backend/routes.php` — public + backstage HTTP route registrations
- `backend/migrations/` — `project_NNN_*.sql` and `.php` migrations
- `frontend/public/`, `frontend/backstage/`, `frontend/assets/` — daem-society UI

## Conventions

- PHPStan level 9 = 0 errors
- Tests use core's `KernelHarness`; per-module `bindings.test.php` swaps InMemory fakes
- Domain interfaces (`Daems\Domain\Project\*`) live in core; module binds them to module impls
- No `Co-Authored-By` in commits; identity is `Dev Team <dev@daems.fi>`
```

- [ ] **Step 4: Create `.gitignore`**

Path: `C:/laragon/www/modules/projects/.gitignore`
```
/vendor/
/.phpunit.cache/
/.phpunit.result.cache
*.log
.idea/
.vscode/
.DS_Store
Thumbs.db
```

- [ ] **Step 5: Create `phpunit.xml.dist`**

Path: `C:/laragon/www/modules/projects/phpunit.xml.dist`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="../../daems-platform/vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true">
  <testsuites>
    <testsuite name="Unit">
      <directory>backend/tests/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
      <directory>backend/tests/Integration</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 6: Create `composer.json`**

Path: `C:/laragon/www/modules/projects/composer.json`
```json
{
  "name": "daems/dp-projects",
  "description": "Projects module for daems-platform",
  "type": "project",
  "license": "proprietary",
  "autoload": {
    "psr-4": {
      "DaemsModule\\Projects\\": "backend/src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "DaemsModule\\Projects\\Tests\\": "backend/tests/"
    }
  },
  "require": {
    "php": "^8.3"
  }
}
```

- [ ] **Step 7: Create STUB `backend/bindings.php`**

Path: `C:/laragon/www/modules/projects/backend/bindings.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Bindings will be added in Task 13.
};
```

- [ ] **Step 8: Create STUB `backend/bindings.test.php`**

Path: `C:/laragon/www/modules/projects/backend/bindings.test.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Test bindings will be added in Task 14.
};
```

- [ ] **Step 9: Create STUB `backend/routes.php`**

Path: `C:/laragon/www/modules/projects/backend/routes.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;

return static function (Router $router, Container $container): void {
    // Routes will be added in Task 15.
};
```

- [ ] **Step 10: Create migrations directory marker**

Path: `C:/laragon/www/modules/projects/backend/migrations/.gitkeep` (empty file)

- [ ] **Step 11: Run core tests to verify ModuleRegistry boots cleanly with empty stubs**

Run from `C:/laragon/www/daems-platform/`:
```bash
vendor/bin/phpunit --testsuite=E2E --filter=ModuleRegistry 2>&1 | tail -20
```

Expected: zero failures from ModuleRegistry (it should detect `module.json` for `projects` and load the empty stubs without error).

If a test like `ModuleRegistryDiscoveryTest::test_registers_known_modules` already lists expected module names, that's OK — extending it to assert `projects` is present can wait for Task 13.

- [ ] **Step 12: Commit in `dp-projects`**

Run from `C:/laragon/www/modules/projects/`:
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add module.json README.md .gitignore phpunit.xml.dist composer.json backend/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Skeleton: module manifest + stub bindings/routes + composer/phpunit/README"
```

Expected: one commit on local `dev` branch. Do NOT push.

---

## Task 2: Core data-fix migration 066_*

**Repo for commits:** `daems-platform`

**Why:** when 10 project migrations move to `modules/projects/backend/migrations/` with new filenames (`project_001..010`), the existing `schema_migrations` table on dev/test DBs still references the OLD filenames (`003_create_projects_table.sql` etc.). Without a rename data-fix, the migration runner re-applies the moved migrations under their new names → duplicate tables / "already exists" errors.

**Files:**
- Create: `database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql`

- [ ] **Step 1: Create `066_*` migration with conditional, idempotent renames**

Path: `database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql`
```sql
-- 066_rename_project_migrations_in_schema_migrations_table.sql
-- Rename schema_migrations rows for project migrations that moved to modules/projects/.
-- Idempotent: each UPDATE is gated by a SELECT that checks the old row exists.
-- Safe to re-run on dev DBs that already saw the rename.

-- Guard helper: only run if schema_migrations table itself exists
SET @smt := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_001_create_projects_table.sql' WHERE migration = '003_create_projects_table.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_002_create_project_extras.sql' WHERE migration = '009_create_project_extras.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_003_create_project_proposals.sql' WHERE migration = '010_create_project_proposals.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_004_add_owner_id_to_projects.sql' WHERE migration = '016_add_owner_id_to_projects.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_005_add_tenant_id_to_projects.sql' WHERE migration = '027_add_tenant_id_to_projects.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_006_add_tenant_id_to_project_extras.sql' WHERE migration = '031_add_tenant_id_to_project_extras.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_007_add_featured_to_projects.sql' WHERE migration = '044_add_featured_to_projects.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_008_add_decision_metadata_to_project_proposals.sql' WHERE migration = '046_add_decision_metadata_to_project_proposals.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_009_create_projects_i18n.sql' WHERE migration = '052_create_projects_i18n.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET migration = 'project_010_add_source_locale_to_project_proposals.sql' WHERE migration = '055_add_source_locale_to_project_proposals.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 2: Verify migration parses**

Run from `C:/laragon/www/daems-platform/`:
```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db_test < database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql
echo "Exit: $?"
```

Expected: Exit 0. (Empty test DB has no `schema_migrations` rows yet, so updates affect 0 rows but parse cleanly.)

- [ ] **Step 3: Verify idempotency by running twice**

Run again:
```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db_test < database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql
echo "Exit: $?"
```

Expected: Exit 0 (no-op since already-renamed rows don't match the WHERE clause).

- [ ] **Step 4: Commit**

Run from `C:/laragon/www/daems-platform/`:
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Migration(066): rename project migrations in schema_migrations for module move"
```

Expected: one commit on `dev`. Do NOT push.

---

## Task 3: Projects file inventory verification (read-only)

**Repo for commits:** none — read-only confirmation step.

**Why:** before moving anything, freeze the exact file list this plan refers to. If any file count differs from the spec, surface it to the user and pause.

**Files:** none modified.

- [ ] **Step 1: Verify backend file counts**

Run from `C:/laragon/www/daems-platform/`:
```bash
echo "=== Application/Project (expect 13 dirs, 38 files) ==="
ls -d src/Application/Project/*/ | wc -l
find src/Application/Project -name "*.php" | wc -l

echo "=== Application/Backstage 12 admin dirs (expect 29 files total) ==="
find src/Application/Backstage/AdminUpdateProject src/Application/Backstage/ApproveProjectProposal src/Application/Backstage/ChangeProjectStatus src/Application/Backstage/CreateProjectAsAdmin src/Application/Backstage/DeleteProjectCommentAsAdmin src/Application/Backstage/GetProjectWithAllTranslations src/Application/Backstage/ListProjectCommentsForAdmin src/Application/Backstage/ListProjectsForAdmin src/Application/Backstage/Projects src/Application/Backstage/RejectProjectProposal src/Application/Backstage/SetProjectFeatured src/Application/Backstage/UpdateProjectTranslation -name "*.php" | wc -l

echo "=== SQL repos (expect 3) ==="
ls src/Infrastructure/Adapter/Persistence/Sql/SqlProject*.php | wc -l

echo "=== ProjectController (expect 1) ==="
ls src/Infrastructure/Adapter/Api/Controller/ProjectController.php | wc -l

echo "=== Project methods in BackstageController (expect 10) ==="
grep -cE "function .*[Pp]roject" src/Infrastructure/Adapter/Api/Controller/BackstageController.php

echo "=== Project unit tests (expect 2 in Application/Project) ==="
find tests/Unit/Application/Project -name "*.php" | wc -l

echo "=== Backstage Project unit tests (expect 10) ==="
ls tests/Unit/Application/Backstage/*Project*.php | wc -l

echo "=== Project integration tests (expect 5) ==="
ls tests/Integration/Application/Project*.php tests/Integration/Infrastructure/SqlProject*.php tests/Integration/Project*.php 2>/dev/null | wc -l

echo "=== Project isolation tests (expect 4) ==="
ls tests/Isolation/Project*.php | wc -l

echo "=== Project E2E tests (expect 2) ==="
ls tests/E2E/F004_UnauthProjectMutationTest.php tests/E2E/ProjectsLocaleE2ETest.php | wc -l

echo "=== InMemory fakes (expect 3) ==="
ls tests/Support/Fake/InMemoryProject*.php | wc -l

echo "=== Migration tests dangling after extract (expect 4) ==="
ls tests/Integration/Migration/Migration027Test.php tests/Integration/Migration/Migration031Test.php tests/Integration/Migration/Migration044Test.php tests/Integration/Migration/Migration046Test.php | wc -l

echo "=== Project pure migrations (expect 10) ==="
ls database/migrations/003_create_projects_table.sql database/migrations/009_create_project_extras.sql database/migrations/010_create_project_proposals.sql database/migrations/016_add_owner_id_to_projects.sql database/migrations/027_add_tenant_id_to_projects.sql database/migrations/031_add_tenant_id_to_project_extras.sql database/migrations/044_add_featured_to_projects.sql database/migrations/046_add_decision_metadata_to_project_proposals.sql database/migrations/052_create_projects_i18n.sql database/migrations/055_add_source_locale_to_project_proposals.sql | wc -l

echo "=== Mixed migrations stay in core (expect 3) ==="
ls database/migrations/053_backfill_events_projects_i18n.sql database/migrations/054_drop_translated_columns_from_events_projects.sql database/migrations/059_fulltext_events_projects_i18n.sql | wc -l
```

Expected exact counts: 13 / 38 / 29 / 3 / 1 / 10 / 2 / 10 / 5 / 4 / 2 / 3 / 4 / 10 / 3.

If ANY count differs, STOP, surface to user, don't continue.

- [ ] **Step 2: Verify frontend file counts**

Run from `C:/laragon/www/sites/daem-society/`:
```bash
echo "=== Public projects/ (expect 7 PHP top + 2 PHP in detail/) ==="
ls public/pages/projects/*.php | wc -l
ls public/pages/projects/detail/*.php | wc -l

echo "=== Backstage projects/ (expect 1 PHP + 1 CSS + 2 JS) ==="
ls public/pages/backstage/projects/*.php | wc -l
ls public/pages/backstage/projects/*.css | wc -l
ls public/pages/backstage/projects/*.js | wc -l
```

Expected: 7 / 2 / 1 / 1 / 2.

- [ ] **Step 3: Verify cross-domain core consumers (will need import updates in Wave E)**

Run from `C:/laragon/www/daems-platform/`:
```bash
grep -rln "Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryProject" src tests | sort -u
```

Expected: at minimum `tests/Support/KernelHarness.php` + `tests/Unit/Application/Backstage/{ListPendingApplicationsForAdminTest,ListProposalsForAdminTest,ListNotificationsStatsTest}.php`. Note any additional files for Task 24.

- [ ] **Step 4: No commit**

Inventory step is read-only. Capture counts in session memory; proceed to Task 5 (or surface mismatch to user).

---

## Task 5: Move 3 SQL repositories

**Repo for commits:** `dp-projects`

**Files:**
- Move (with namespace rewrite): `daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php` → `modules/projects/backend/src/Infrastructure/SqlProjectRepository.php`
- Move: `SqlProjectCommentModerationAuditRepository.php` → `modules/projects/backend/src/Infrastructure/SqlProjectCommentModerationAuditRepository.php`
- Move: `SqlProjectProposalRepository.php` → `modules/projects/backend/src/Infrastructure/SqlProjectProposalRepository.php`

- [ ] **Step 1: Copy 3 files into module**

Run from `C:/laragon/www/`:
```bash
mkdir -p modules/projects/backend/src/Infrastructure
cp daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php modules/projects/backend/src/Infrastructure/SqlProjectRepository.php
cp daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php modules/projects/backend/src/Infrastructure/SqlProjectCommentModerationAuditRepository.php
cp daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php modules/projects/backend/src/Infrastructure/SqlProjectProposalRepository.php
```

- [ ] **Step 2: Rewrite `namespace` declaration in each**

For each of the 3 files, replace:
```
namespace Daems\Infrastructure\Adapter\Persistence\Sql;
```
with:
```
namespace DaemsModule\Projects\Infrastructure;
```

Use Edit tool per file (single-line replace).

- [ ] **Step 3: Verify imports**

For each moved file, scan its `use` statements. Allowed imports (must remain `Daems\` core namespace):
- `Daems\Domain\Project\*` (interfaces + entities — Forum lesson 1, Domain stays in core)
- `Daems\Domain\Tenant\*`, `Daems\Domain\User\*`, `Daems\Domain\Locale\*`
- `Daems\Infrastructure\Framework\*` (Connection, etc.)

Run:
```bash
grep -hE "^use " modules/projects/backend/src/Infrastructure/SqlProject*.php | sort -u
```

Expected: only `Daems\` core imports + `Doctrine\DBAL\*` if the SQL repos use it. NO `DaemsModule\` self-imports (the repos don't reference each other directly). NO Forum/Insights/other-module imports.

- [ ] **Step 4: PHPStan check on moved files**

PHPStan paths in `daems-platform/phpstan.neon` already include `../modules/*/backend/src/` per the foundation; module's own composer autoload will pick it up after Task 9.5. For now, verify no syntax errors:
```bash
php -l modules/projects/backend/src/Infrastructure/SqlProjectRepository.php
php -l modules/projects/backend/src/Infrastructure/SqlProjectCommentModerationAuditRepository.php
php -l modules/projects/backend/src/Infrastructure/SqlProjectProposalRepository.php
```

Expected: each prints "No syntax errors detected".

- [ ] **Step 5: Commit in dp-projects**

Run from `C:/laragon/www/modules/projects/`:
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Infrastructure/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(infra): 3 SQL repositories with namespace rewrite"
```

**Note:** the originals in `daems-platform/src/Infrastructure/Adapter/Persistence/Sql/` are NOT yet deleted. They remain valid until Wave E (Task 21–25). Until then, both core and module copies coexist (only the module copy is autoloaded after Task 9.5 + 13).

---

## Task 6: Move 3 InMemory fakes

**Repo for commits:** `dp-projects`

**Files:**
- Move: `daems-platform/tests/Support/Fake/InMemoryProjectRepository.php` → `modules/projects/backend/tests/Support/InMemoryProjectRepository.php`
- Move: `InMemoryProjectCommentModerationAuditRepository.php` → same name in module
- Move: `InMemoryProjectProposalRepository.php` → same name in module

- [ ] **Step 1: Copy 3 files**

Run from `C:/laragon/www/`:
```bash
mkdir -p modules/projects/backend/tests/Support
cp daems-platform/tests/Support/Fake/InMemoryProjectRepository.php modules/projects/backend/tests/Support/InMemoryProjectRepository.php
cp daems-platform/tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php modules/projects/backend/tests/Support/InMemoryProjectCommentModerationAuditRepository.php
cp daems-platform/tests/Support/Fake/InMemoryProjectProposalRepository.php modules/projects/backend/tests/Support/InMemoryProjectProposalRepository.php
```

- [ ] **Step 2: Rewrite namespace per file**

In each, replace:
```
namespace Daems\Tests\Support\Fake;
```
with:
```
namespace DaemsModule\Projects\Tests\Support;
```

- [ ] **Step 3: Syntax check**

```bash
php -l modules/projects/backend/tests/Support/InMemoryProjectRepository.php
php -l modules/projects/backend/tests/Support/InMemoryProjectCommentModerationAuditRepository.php
php -l modules/projects/backend/tests/Support/InMemoryProjectProposalRepository.php
```

Expected: each "No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Support/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests-support): 3 InMemory fakes with namespace rewrite"
```

Originals stay in `daems-platform/tests/Support/Fake/` until Task 24.

---

## Task 7: Move Application/Project (13 use case dirs, 38 files)

**Repo for commits:** `dp-projects`

**Files:**
- Move: 13 sub-directories under `daems-platform/src/Application/Project/` → `modules/projects/backend/src/Application/Project/`
  - `AddProjectComment/` (3) | `AddProjectUpdate/` (3) | `ArchiveProject/` (3) | `CreateProject/` (3) | `GetProject/` (3) | `GetProjectBySlugForLocale/` (3) | `JoinProject/` (3) | `LeaveProject/` (3) | `LikeProjectComment/` (2) | `ListProjects/` (3) | `ListProjectsForLocale/` (3) | `SubmitProjectProposal/` (3) | `UpdateProject/` (3)

- [ ] **Step 1: Copy entire directory tree**

Run from `C:/laragon/www/`:
```bash
mkdir -p modules/projects/backend/src/Application
cp -r daems-platform/src/Application/Project modules/projects/backend/src/Application/Project
echo "Files copied: $(find modules/projects/backend/src/Application/Project -name '*.php' | wc -l)"
```

Expected: 38 files copied.

- [ ] **Step 2: Mass namespace rewrite**

For every `.php` file under `modules/projects/backend/src/Application/Project/`:

Replace:
```
namespace Daems\Application\Project\
```
with:
```
namespace DaemsModule\Projects\Application\Project\
```

Replace within `use` statements:
```
use Daems\Application\Project\
```
with:
```
use DaemsModule\Projects\Application\Project\
```

Run via PowerShell (in `C:/laragon/www/modules/projects/backend/src/Application/Project/`):
```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    (Get-Content $_.FullName -Raw) `
        -replace 'namespace Daems\\Application\\Project\\', 'namespace DaemsModule\Projects\Application\Project\' `
        -replace 'use Daems\\Application\\Project\\', 'use DaemsModule\Projects\Application\Project\' `
    | Set-Content $_.FullName -Encoding utf8
}
```

- [ ] **Step 3: Verify allowed imports remain (Forum lesson 1)**

`Daems\Domain\Project\*`, `Daems\Domain\Tenant\*`, `Daems\Domain\User\*`, `Daems\Domain\Locale\*`, `Daems\Domain\Membership\*`, `Daems\Application\Shared\*` are all permitted (Domain stays in core).

Run:
```bash
grep -rhE "^use Daems\\\\" modules/projects/backend/src/Application/Project/ | sort -u
```

Inspect output: all should be in {Domain, Application/Shared, Infrastructure/Framework}. NO `Daems\Application\Forum\`, `Daems\Application\Event\`, etc.

- [ ] **Step 4: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/src/Application/Project 2>&1 | tail -20
```

Expected: 0 errors. (Will work even before Task 9.5 because PHPStan paths in `phpstan.neon` already include `../modules/*/backend/src/`.)

- [ ] **Step 5: Commit**

Run from `C:/laragon/www/modules/projects/`:
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Application/Project/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(app): Application/Project — 38 files in 13 use-case dirs"
```

Originals remain in `daems-platform/src/Application/Project/` until Task 25.

---

## Task 8: Move 12 admin use case sibling dirs (29 files)

**Repo for commits:** `dp-projects`

**Files:**
- Move: 12 dirs under `daems-platform/src/Application/Backstage/` → `modules/projects/backend/src/Application/Backstage/`:
  - `AdminUpdateProject/` (3), `ApproveProjectProposal/` (3), `ChangeProjectStatus/` (2), `CreateProjectAsAdmin/` (3), `DeleteProjectCommentAsAdmin/` (2), `GetProjectWithAllTranslations/` (3), `ListProjectCommentsForAdmin/` (3), `ListProjectsForAdmin/` (3), `Projects/` (only contains `ListProjectsStats/` with 3 files), `RejectProjectProposal/` (2), `SetProjectFeatured/` (2), `UpdateProjectTranslation/` (3)

- [ ] **Step 1: Copy each sibling dir**

Run from `C:/laragon/www/`:
```bash
mkdir -p modules/projects/backend/src/Application/Backstage
for d in AdminUpdateProject ApproveProjectProposal ChangeProjectStatus CreateProjectAsAdmin DeleteProjectCommentAsAdmin GetProjectWithAllTranslations ListProjectCommentsForAdmin ListProjectsForAdmin Projects RejectProjectProposal SetProjectFeatured UpdateProjectTranslation; do
  cp -r "daems-platform/src/Application/Backstage/$d" "modules/projects/backend/src/Application/Backstage/$d"
done
echo "Files copied: $(find modules/projects/backend/src/Application/Backstage -name '*.php' | wc -l)"
```

Expected: 29.

- [ ] **Step 2: Mass namespace rewrite**

For every `.php` file under `modules/projects/backend/src/Application/Backstage/`:

Replace `namespace Daems\Application\Backstage\<UseCase>\` → `namespace DaemsModule\Projects\Application\Backstage\<UseCase>\` for each of the 12 use case names. Same for `use ` statements.

PowerShell (in `C:/laragon/www/modules/projects/backend/src/Application/Backstage/`):
```powershell
$useCases = @('AdminUpdateProject','ApproveProjectProposal','ChangeProjectStatus','CreateProjectAsAdmin','DeleteProjectCommentAsAdmin','GetProjectWithAllTranslations','ListProjectCommentsForAdmin','ListProjectsForAdmin','Projects\\ListProjectsStats','RejectProjectProposal','SetProjectFeatured','UpdateProjectTranslation')
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $content = Get-Content $_.FullName -Raw
    foreach ($uc in $useCases) {
        $escaped = $uc -replace '\\\\','\\'
        $content = $content -replace ("namespace Daems\\Application\\Backstage\\$escaped"), ("namespace DaemsModule\Projects\Application\Backstage\$uc")
        $content = $content -replace ("use Daems\\Application\\Backstage\\$escaped"), ("use DaemsModule\Projects\Application\Backstage\$uc")
    }
    Set-Content -Path $_.FullName -Value $content -Encoding utf8
}
```

- [ ] **Step 3: Verify imports**

```bash
grep -rhE "^(namespace|use Daems\\\\)" modules/projects/backend/src/Application/Backstage/ | sort -u | head -50
```

Inspect: all `namespace` lines should start with `DaemsModule\Projects\Application\Backstage\`. All `use Daems\` imports should be in {Domain, Application/Shared, Infrastructure/Framework, Application/Project (intra-module)}.

- [ ] **Step 4: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/src/Application/Backstage 2>&1 | tail -20
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Application/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(app): 12 admin use-case dirs (29 files) with namespace rewrite"
```

---

## Task 9: Move ProjectController

**Repo for commits:** `dp-projects`

**Files:**
- Move: `daems-platform/src/Infrastructure/Adapter/Api/Controller/ProjectController.php` → `modules/projects/backend/src/Controller/ProjectController.php`

- [ ] **Step 1: Copy file**

Run from `C:/laragon/www/`:
```bash
mkdir -p modules/projects/backend/src/Controller
cp daems-platform/src/Infrastructure/Adapter/Api/Controller/ProjectController.php modules/projects/backend/src/Controller/ProjectController.php
```

- [ ] **Step 2: Rewrite namespace**

Replace:
```
namespace Daems\Infrastructure\Adapter\Api\Controller;
```
with:
```
namespace DaemsModule\Projects\Controller;
```

Then update all `use Daems\Application\Project\` imports to `use DaemsModule\Projects\Application\Project\`.

PowerShell:
```powershell
$file = 'C:\laragon\www\modules\projects\backend\src\Controller\ProjectController.php'
$content = Get-Content $file -Raw
$content = $content -replace 'namespace Daems\\Infrastructure\\Adapter\\Api\\Controller;', 'namespace DaemsModule\Projects\Controller;'
$content = $content -replace 'use Daems\\Application\\Project\\', 'use DaemsModule\Projects\Application\Project\'
Set-Content -Path $file -Value $content -Encoding utf8
```

- [ ] **Step 3: Verify imports + syntax**

```bash
php -l modules/projects/backend/src/Controller/ProjectController.php
grep -E "^(namespace|use )" modules/projects/backend/src/Controller/ProjectController.php
```

Expected: namespace `DaemsModule\Projects\Controller`, all `use Daems\` imports point to Domain or Framework, all `use DaemsModule\Projects\` point to module use cases.

- [ ] **Step 4: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/src/Controller/ProjectController.php 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Controller/ProjectController.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(controller): ProjectController to module"
```

---

## Task 9.5: NEW infra commit on daems-platform — autoload-dev + phpstan paths

**Repo for commits:** `daems-platform`

**Why (Forum lesson 3):** core's PHPStan + Composer autoloader cannot see the module's namespace until we register it. Without this, Tasks 17–20 (test moves) and Task 13 (production binding) fail because the module's classes are unresolvable.

**Files:**
- Modify: `composer.json` (add module namespaces to `autoload-dev`)
- Modify: `phpstan.neon` (add module path)

- [ ] **Step 1: Read current `composer.json` autoload-dev**

```bash
cd C:/laragon/www/daems-platform
grep -A 20 '"autoload-dev"' composer.json | head -30
```

Capture the existing `psr-4` map structure. (Forum already added `DaemsModule\\Forum\\` and `DaemsModule\\Forum\\Tests\\` — model after that.)

- [ ] **Step 2: Add Projects entries to `composer.json` autoload-dev**

Edit `C:/laragon/www/daems-platform/composer.json`. Inside `autoload-dev.psr-4`, add (after the Forum entries):
```json
"DaemsModule\\Projects\\": "../modules/projects/backend/src/",
"DaemsModule\\Projects\\Tests\\": "../modules/projects/backend/tests/"
```

Verify the JSON parses:
```bash
php -r "json_decode(file_get_contents('composer.json'), false, 512, JSON_THROW_ON_ERROR); echo 'OK';"
```

- [ ] **Step 3: Add module path to `phpstan.neon`**

Read current paths block:
```bash
grep -A 5 "^parameters:" phpstan.neon | head -15
grep -E "^\s+paths:" phpstan.neon
grep -A 20 "^\s+paths:" phpstan.neon | head -30
```

The Forum extraction added `- ../modules/forum/backend/src/`. Add an equivalent line for Projects.

Edit `C:/laragon/www/daems-platform/phpstan.neon`, in the `paths:` list under `parameters:`, add:
```
        - ../modules/projects/backend/src/
```

Maintain identical indentation as the existing path entries.

- [ ] **Step 4: Run `composer dump-autoload`**

```bash
composer dump-autoload 2>&1 | tail -10
```

Expected: "Generated optimized autoload files" or similar; no errors.

- [ ] **Step 5: Verify autoload picks up module classes**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('DaemsModule\\\\Projects\\\\Infrastructure\\\\SqlProjectRepository') ? 'OK' : 'NO'; echo PHP_EOL;"
```

Expected output: `OK`.

- [ ] **Step 6: PHPStan baseline still passes**

```bash
composer analyse 2>&1 | tail -10
```

Expected: 0 errors. Module classes that previously had unresolved imports may now show new errors — those should be addressed by ensuring all moves done in Tasks 5–9 are namespace-clean.

If PHPStan flags errors in module files, return to the relevant task's namespace rewrite step and fix.

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add composer.json composer.lock phpstan.neon
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(modules): Projects autoload-dev + phpstan path"
```

---

## Task 10: Extract ProjectsBackstageController (TDD, 12 methods)

**Repo for commits:** `dp-projects`

**Why:** the 10 Project methods + 2 proposal-decision methods (`approveProjectProposal`, `rejectProjectProposal`) currently live as methods on the monolith `BackstageController` in core. Move them into a single new module-owned `ProjectsBackstageController`. F4=A locked.

**Files:**
- Source (read-only this task): `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`
- Create: `modules/projects/backend/src/Controller/ProjectsBackstageController.php`
- Create: `modules/projects/backend/tests/Unit/Controller/ProjectsBackstageControllerSignatureTest.php`

### Method inventory (12 methods to extract)

From `BackstageController.php`:

| # | Method | Source line | Calls use case |
|---|---|---|---|
| 1 | `listProjectsAdmin` | 416 | `ListProjectsForAdmin` |
| 2 | `createProjectAdmin` | 434 | `CreateProjectAsAdmin` |
| 3 | `updateProjectAdmin` | 458 | `AdminUpdateProject` |
| 4 | `changeProjectStatus` | 486 | `ChangeProjectStatus` |
| 5 | `setProjectFeatured` | 506 | `SetProjectFeatured` |
| 6 | `listProjectComments` | 574 | `ListProjectCommentsForAdmin` |
| 7 | `deleteProjectComment` | 590 | `DeleteProjectCommentAsAdmin` |
| 8 | `statsProjects` | 656 | `Backstage\Projects\ListProjectsStats\ListProjectsStats` |
| 9 | `getProjectWithTranslations` | 767 | `GetProjectWithAllTranslations` |
| 10 | `updateProjectTranslation` | 786 | `UpdateProjectTranslation` |
| 11 | `approveProjectProposal` | locate via grep — bound to route at line 291 of routes/api.php; controller method is in BackstageController around lines 525–545 | `ApproveProjectProposal` |
| 12 | `rejectProjectProposal` | locate via grep — bound to route at line 295 of routes/api.php; controller method is in BackstageController around lines 545–565 | `RejectProjectProposal` |

- [ ] **Step 1: Read each source method body**

For each of the 12 methods, locate exact line range in `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`:
```bash
cd C:/laragon/www/daems-platform
grep -nE "function (listProjectsAdmin|createProjectAdmin|updateProjectAdmin|changeProjectStatus|setProjectFeatured|listProjectComments|deleteProjectComment|statsProjects|getProjectWithTranslations|updateProjectTranslation|approveProjectProposal|rejectProjectProposal)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

Capture each method's start line. The body extends to the matching closing `}` — read each method body via Read tool.

- [ ] **Step 2: Identify constructor dependencies**

For each method, note which use case classes it calls. The new `ProjectsBackstageController` needs ALL 12 use case dependencies in its constructor.

Aggregate the unique use case dependencies:
1. `ListProjectsForAdmin`
2. `CreateProjectAsAdmin`
3. `AdminUpdateProject`
4. `ChangeProjectStatus`
5. `SetProjectFeatured`
6. `ListProjectCommentsForAdmin`
7. `DeleteProjectCommentAsAdmin`
8. `Backstage\Projects\ListProjectsStats\ListProjectsStats`
9. `GetProjectWithAllTranslations`
10. `UpdateProjectTranslation`
11. `ApproveProjectProposal`
12. `RejectProjectProposal`

If any method also accesses a non-use-case dependency (e.g. `LoggerInterface`, `Connection`, `RequestContextResolver`), add to constructor too.

- [ ] **Step 3: Write Reflection signature test FIRST (TDD)**

Path: `modules/projects/backend/tests/Unit/Controller/ProjectsBackstageControllerSignatureTest.php`

```php
<?php

declare(strict_types=1);

namespace DaemsModule\Projects\Tests\Unit\Controller;

use DaemsModule\Projects\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use DaemsModule\Projects\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use DaemsModule\Projects\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use DaemsModule\Projects\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin;
use DaemsModule\Projects\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use DaemsModule\Projects\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations;
use DaemsModule\Projects\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin;
use DaemsModule\Projects\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use DaemsModule\Projects\Application\Backstage\Projects\ListProjectsStats\ListProjectsStats;
use DaemsModule\Projects\Application\Backstage\RejectProjectProposal\RejectProjectProposal;
use DaemsModule\Projects\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use DaemsModule\Projects\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation;
use DaemsModule\Projects\Controller\ProjectsBackstageController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class ProjectsBackstageControllerSignatureTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(ProjectsBackstageController::class));
    }

    public function test_constructor_takes_all_12_use_cases(): void
    {
        $ref = new ReflectionClass(ProjectsBackstageController::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);

        $expected = [
            ListProjectsForAdmin::class,
            CreateProjectAsAdmin::class,
            AdminUpdateProject::class,
            ChangeProjectStatus::class,
            SetProjectFeatured::class,
            ListProjectCommentsForAdmin::class,
            DeleteProjectCommentAsAdmin::class,
            ListProjectsStats::class,
            GetProjectWithAllTranslations::class,
            UpdateProjectTranslation::class,
            ApproveProjectProposal::class,
            RejectProjectProposal::class,
        ];

        $params = $ctor->getParameters();
        $this->assertCount(count($expected), $params, 'constructor must take exactly 12 use cases');

        foreach ($params as $i => $p) {
            $type = $p->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame($expected[$i], $type->getName(), "param #$i wrong type");
        }
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function methodNamesProvider(): array
    {
        return [
            ['listProjectsAdmin'],
            ['createProjectAdmin'],
            ['updateProjectAdmin'],
            ['changeProjectStatus'],
            ['setProjectFeatured'],
            ['listProjectComments'],
            ['deleteProjectComment'],
            ['statsProjects'],
            ['getProjectWithTranslations'],
            ['updateProjectTranslation'],
            ['approveProjectProposal'],
            ['rejectProjectProposal'],
        ];
    }

    /**
     * @dataProvider methodNamesProvider
     */
    public function test_method_exists(string $method): void
    {
        $ref = new ReflectionClass(ProjectsBackstageController::class);
        $this->assertTrue($ref->hasMethod($method), "missing public method: $method");
        $m = $ref->getMethod($method);
        $this->assertTrue($m->isPublic(), "$method must be public");
    }
}
```

- [ ] **Step 4: Run test to verify it fails (controller doesn't exist yet)**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Controller/ProjectsBackstageControllerSignatureTest.php 2>&1 | tail -20
```

Expected: FAIL with "Class DaemsModule\\Projects\\Controller\\ProjectsBackstageController not found".

- [ ] **Step 5: Create skeleton `ProjectsBackstageController`**

Path: `modules/projects/backend/src/Controller/ProjectsBackstageController.php`

```php
<?php

declare(strict_types=1);

namespace DaemsModule\Projects\Controller;

use DaemsModule\Projects\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use DaemsModule\Projects\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use DaemsModule\Projects\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use DaemsModule\Projects\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin;
use DaemsModule\Projects\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use DaemsModule\Projects\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations;
use DaemsModule\Projects\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin;
use DaemsModule\Projects\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use DaemsModule\Projects\Application\Backstage\Projects\ListProjectsStats\ListProjectsStats;
use DaemsModule\Projects\Application\Backstage\RejectProjectProposal\RejectProjectProposal;
use DaemsModule\Projects\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use DaemsModule\Projects\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ProjectsBackstageController
{
    public function __construct(
        private ListProjectsForAdmin $listProjects,
        private CreateProjectAsAdmin $createProject,
        private AdminUpdateProject $updateProject,
        private ChangeProjectStatus $changeStatus,
        private SetProjectFeatured $setFeatured,
        private ListProjectCommentsForAdmin $listComments,
        private DeleteProjectCommentAsAdmin $deleteComment,
        private ListProjectsStats $stats,
        private GetProjectWithAllTranslations $getWithTranslations,
        private UpdateProjectTranslation $updateTranslation,
        private ApproveProjectProposal $approveProposal,
        private RejectProjectProposal $rejectProposal,
    ) {
    }

    public function listProjectsAdmin(Request $request): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function createProjectAdmin(Request $request): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function updateProjectAdmin(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function changeProjectStatus(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function setProjectFeatured(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function listProjectComments(Request $request): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function deleteProjectComment(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function statsProjects(Request $request): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function getProjectWithTranslations(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function updateProjectTranslation(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function approveProjectProposal(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }

    public function rejectProjectProposal(Request $request, array $params): Response
    {
        throw new \LogicException('not implemented yet');
    }
}
```

Note method signatures: methods that need URL-bound parameters (e.g. `{id}`, `{slug}`, `{locale}`, `{comment_id}`) take `array $params`; pure-list/stats methods take only `Request`. Match the source `BackstageController` signatures exactly — verify in next step.

- [ ] **Step 6: Run signature test, verify it passes**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Controller/ProjectsBackstageControllerSignatureTest.php 2>&1 | tail -10
```

Expected: 14 tests pass (1 class_exists + 1 constructor + 12 method exists, dataProvider).

- [ ] **Step 7: Copy each method body from source `BackstageController` into module controller**

For each of the 12 methods, replace the placeholder `throw new \LogicException('not implemented yet');` with the actual body from `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`.

For each body, rewrite class references:
- `$this->listProjectsForAdmin` → `$this->listProjects` (match constructor property names defined above)
- `$this->createProjectAsAdmin` → `$this->createProject`
- etc. (rename to match shorthand property names; or keep source names and update constructor to match)

**Decision:** keep source-style property names to minimise body diff. Rename constructor parameters in Step 5 to match exactly what the body references. Re-run signature test to confirm.

For example, if the source `BackstageController::listProjectsAdmin` does:
```php
$out = $this->listProjectsForAdmin->execute(new ListProjectsForAdminInput(...));
```
then the module controller's constructor parameter must be `private ListProjectsForAdmin $listProjectsForAdmin` (not `$listProjects`). Update both Step 5's constructor AND the test in Step 3 to match.

After this consistency pass, re-run:
```bash
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Controller/ProjectsBackstageControllerSignatureTest.php
```

- [ ] **Step 8: Method body audit — input class imports**

Each method body uses `use DaemsModule\Projects\Application\Backstage\<UseCase>\<UseCase>Input` (or `Output`) classes — those `use` statements must exist at the top of `ProjectsBackstageController.php`. Add the 12 Input/Output imports if any method body references them.

- [ ] **Step 9: PHPStan verify**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/src/Controller/ProjectsBackstageController.php 2>&1 | tail -20
```

Expected: 0 errors.

- [ ] **Step 10: Run full module test directory to confirm**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Controller/ 2>&1 | tail -10
```

Expected: all signature tests pass.

- [ ] **Step 11: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Controller/ProjectsBackstageController.php backend/tests/Unit/Controller/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Extract: ProjectsBackstageController (TDD, 12 methods, single controller)"
```

The original 12 methods on core `BackstageController` are NOT yet deleted — that's Task 23.

---

## Task 12: Move 10 migrations with project_NNN_* rename

**Repo for commits:** `dp-projects`

**Files:**
- Move (with rename) 10 files from `daems-platform/database/migrations/` → `modules/projects/backend/migrations/`:

| Old name | New name |
|---|---|
| `003_create_projects_table.sql` | `project_001_create_projects_table.sql` |
| `009_create_project_extras.sql` | `project_002_create_project_extras.sql` |
| `010_create_project_proposals.sql` | `project_003_create_project_proposals.sql` |
| `016_add_owner_id_to_projects.sql` | `project_004_add_owner_id_to_projects.sql` |
| `027_add_tenant_id_to_projects.sql` | `project_005_add_tenant_id_to_projects.sql` |
| `031_add_tenant_id_to_project_extras.sql` | `project_006_add_tenant_id_to_project_extras.sql` |
| `044_add_featured_to_projects.sql` | `project_007_add_featured_to_projects.sql` |
| `046_add_decision_metadata_to_project_proposals.sql` | `project_008_add_decision_metadata_to_project_proposals.sql` |
| `052_create_projects_i18n.sql` | `project_009_create_projects_i18n.sql` |
| `055_add_source_locale_to_project_proposals.sql` | `project_010_add_source_locale_to_project_proposals.sql` |

- [ ] **Step 1: Copy each migration with rename**

Run from `C:/laragon/www/`:
```bash
cp daems-platform/database/migrations/003_create_projects_table.sql modules/projects/backend/migrations/project_001_create_projects_table.sql
cp daems-platform/database/migrations/009_create_project_extras.sql modules/projects/backend/migrations/project_002_create_project_extras.sql
cp daems-platform/database/migrations/010_create_project_proposals.sql modules/projects/backend/migrations/project_003_create_project_proposals.sql
cp daems-platform/database/migrations/016_add_owner_id_to_projects.sql modules/projects/backend/migrations/project_004_add_owner_id_to_projects.sql
cp daems-platform/database/migrations/027_add_tenant_id_to_projects.sql modules/projects/backend/migrations/project_005_add_tenant_id_to_projects.sql
cp daems-platform/database/migrations/031_add_tenant_id_to_project_extras.sql modules/projects/backend/migrations/project_006_add_tenant_id_to_project_extras.sql
cp daems-platform/database/migrations/044_add_featured_to_projects.sql modules/projects/backend/migrations/project_007_add_featured_to_projects.sql
cp daems-platform/database/migrations/046_add_decision_metadata_to_project_proposals.sql modules/projects/backend/migrations/project_008_add_decision_metadata_to_project_proposals.sql
cp daems-platform/database/migrations/052_create_projects_i18n.sql modules/projects/backend/migrations/project_009_create_projects_i18n.sql
cp daems-platform/database/migrations/055_add_source_locale_to_project_proposals.sql modules/projects/backend/migrations/project_010_add_source_locale_to_project_proposals.sql
echo "Migrations copied: $(ls modules/projects/backend/migrations/project_*.sql | wc -l)"
```

Expected: 10.

- [ ] **Step 2: Audit each migration body for cross-table ALTER**

Per Forum lesson 4 — if any migration ALTERs a non-`project*`-named table, it needs a conditional guard. Audit:

```bash
grep -nE "^(ALTER|UPDATE|INSERT INTO|DELETE FROM)" modules/projects/backend/migrations/project_*.sql | grep -vE "project|projects"
```

Expected: empty output (no migrations touch non-project tables — `events_*` is left in core via 053/054/059).

If any cross-table ALTER appears, wrap it in the `SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE ...); SET @sql := IF(@t > 0, '...', 'DO 0'); PREPARE...EXECUTE...DEALLOCATE` pattern from Task 2.

- [ ] **Step 3: Verify migrations parse against test DB**

Pre-step: ensure `daems_db_test` is at the latest pre-extraction state. (Migrations 053, 054, 055, 059 in core depend on `projects_i18n` table existing from `052`, which we're moving. Our new module migration `project_009_*` creates it; the migration runner that loads modules will see it. Test the ordered chain runs cleanly.)

For now, just SQL-parse each:
```bash
for f in modules/projects/backend/migrations/project_*.sql; do
  C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db_test -e "$(cat $f)" 2>&1 | tail -3
  echo "---- $f ----"
done
```

(This may error on already-applied migrations against a populated test DB; that's OK at this step — we're checking SQL syntax, not runtime application.)

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/migrations/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(migrations): 10 project migrations renamed to project_001..010"
```

Originals NOT deleted yet — that's Task 25.

---

## Task 13: bindings.php (production)

**Repo for commits:** `dp-projects`

**Why:** populate the module's `backend/bindings.php` with all production DI bindings: 3 repos + 13 public use cases + 12 admin use cases + 2 controllers = 30 bindings. After this task, `bootstrap/app.php` Kernel can resolve module classes via core's container.

**Files:**
- Modify: `modules/projects/backend/bindings.php` (replace stub with full bindings)

- [ ] **Step 1: Read existing Project bindings in core for reference**

```bash
cd C:/laragon/www/daems-platform
grep -n "Project" bootstrap/app.php | head -60
```

Capture the existing bindings (around lines 23–35 imports + 225–360 bindings) — use them as the model.

- [ ] **Step 2: Write full `bindings.php`**

Path: `modules/projects/backend/bindings.php`
```php
<?php

declare(strict_types=1);

use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Persistence\Connection;
use DaemsModule\Projects\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use DaemsModule\Projects\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use DaemsModule\Projects\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use DaemsModule\Projects\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin;
use DaemsModule\Projects\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use DaemsModule\Projects\Application\Backstage\GetProjectWithAllTranslations\GetProjectWithAllTranslations;
use DaemsModule\Projects\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin;
use DaemsModule\Projects\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use DaemsModule\Projects\Application\Backstage\Projects\ListProjectsStats\ListProjectsStats;
use DaemsModule\Projects\Application\Backstage\RejectProjectProposal\RejectProjectProposal;
use DaemsModule\Projects\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use DaemsModule\Projects\Application\Backstage\UpdateProjectTranslation\UpdateProjectTranslation;
use DaemsModule\Projects\Application\Project\AddProjectComment\AddProjectComment;
use DaemsModule\Projects\Application\Project\AddProjectUpdate\AddProjectUpdate;
use DaemsModule\Projects\Application\Project\ArchiveProject\ArchiveProject;
use DaemsModule\Projects\Application\Project\CreateProject\CreateProject;
use DaemsModule\Projects\Application\Project\GetProject\GetProject;
use DaemsModule\Projects\Application\Project\GetProjectBySlugForLocale\GetProjectBySlugForLocale;
use DaemsModule\Projects\Application\Project\JoinProject\JoinProject;
use DaemsModule\Projects\Application\Project\LeaveProject\LeaveProject;
use DaemsModule\Projects\Application\Project\LikeProjectComment\LikeProjectComment;
use DaemsModule\Projects\Application\Project\ListProjects\ListProjects;
use DaemsModule\Projects\Application\Project\ListProjectsForLocale\ListProjectsForLocale;
use DaemsModule\Projects\Application\Project\SubmitProjectProposal\SubmitProjectProposal;
use DaemsModule\Projects\Application\Project\UpdateProject\UpdateProject;
use DaemsModule\Projects\Controller\ProjectController;
use DaemsModule\Projects\Controller\ProjectsBackstageController;
use DaemsModule\Projects\Infrastructure\SqlProjectCommentModerationAuditRepository;
use DaemsModule\Projects\Infrastructure\SqlProjectProposalRepository;
use DaemsModule\Projects\Infrastructure\SqlProjectRepository;

return static function (Container $container): void {
    // Repository interface → module SQL impl bindings (Forum lesson 1: interfaces are core)
    $container->bind(ProjectRepositoryInterface::class,
        static fn(Container $c) => new SqlProjectRepository($c->make(Connection::class)));

    $container->bind(ProjectCommentModerationAuditRepositoryInterface::class,
        static fn(Container $c) => new SqlProjectCommentModerationAuditRepository($c->make(Connection::class)));

    $container->bind(ProjectProposalRepositoryInterface::class,
        static fn(Container $c) => new SqlProjectProposalRepository($c->make(Connection::class)));

    // Public use cases — copy each binding from current bootstrap/app.php
    // (For reach use case, look at lines 225–306 of bootstrap/app.php — copy the binding closure verbatim,
    // changing only the FQN from Daems\Application\Project\X\X to DaemsModule\Projects\Application\Project\X\X.)
    // Each binding will look like:
    //   $container->bind(CreateProject::class, static fn(Container $c) => new CreateProject(
    //       $c->make(ProjectRepositoryInterface::class),
    //       $c->make(EventBus::class),  // example — actual deps depend on use case
    //   ));
    // Replicate for: AddProjectComment, AddProjectUpdate, ArchiveProject, CreateProject, GetProject,
    // GetProjectBySlugForLocale, JoinProject, LeaveProject, LikeProjectComment, ListProjects,
    // ListProjectsForLocale, SubmitProjectProposal, UpdateProject (13 total).

    // Admin use cases — copy each binding from current bootstrap/app.php (lines 307–360)
    // For: AdminUpdateProject, ApproveProjectProposal, ChangeProjectStatus, CreateProjectAsAdmin,
    // DeleteProjectCommentAsAdmin, GetProjectWithAllTranslations, ListProjectCommentsForAdmin,
    // ListProjectsForAdmin, ListProjectsStats, RejectProjectProposal, SetProjectFeatured,
    // UpdateProjectTranslation (12 total).

    // Controllers
    $container->bind(ProjectController::class,
        static fn(Container $c) => new ProjectController(
            // Constructor params — read from modules/projects/backend/src/Controller/ProjectController.php and
            // hand-copy each $c->make(...) line. Example: $c->make(GetProject::class), etc.
        ));

    $container->bind(ProjectsBackstageController::class,
        static fn(Container $c) => new ProjectsBackstageController(
            $c->make(ListProjectsForAdmin::class),
            $c->make(CreateProjectAsAdmin::class),
            $c->make(AdminUpdateProject::class),
            $c->make(ChangeProjectStatus::class),
            $c->make(SetProjectFeatured::class),
            $c->make(ListProjectCommentsForAdmin::class),
            $c->make(DeleteProjectCommentAsAdmin::class),
            $c->make(ListProjectsStats::class),
            $c->make(GetProjectWithAllTranslations::class),
            $c->make(UpdateProjectTranslation::class),
            $c->make(ApproveProjectProposal::class),
            $c->make(RejectProjectProposal::class),
        ));
};
```

The bindings between the two `// ...` comments must be filled in by reading the actual source bootstrap/app.php Project bindings. Do not invent constructor signatures — copy them verbatim from current bootstrap, only swap the namespace prefix.

To extract them mechanically:
```bash
cd C:/laragon/www/daems-platform
sed -n '225,360p' bootstrap/app.php > /tmp/project_bindings.txt
cat /tmp/project_bindings.txt
```

Read the output, identify each `$container->bind(...)` block whose target class name contains "Project", copy each block, replace `\Daems\Application\Project\` → `\DaemsModule\Projects\Application\Project\` and `\Daems\Application\Backstage\` → `\DaemsModule\Projects\Application\Backstage\` for Project-named bindings.

- [ ] **Step 3: PHPStan verify the bindings file**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/bindings.php 2>&1 | tail -20
```

Expected: 0 errors.

- [ ] **Step 4: Production-container smoke test (Forum lesson 7)**

Create temp script `C:/laragon/www/daems-platform/_tmp_smoke.php` (NOT committed):
```php
<?php
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new ReflectionProperty($kernel, 'container');
$ref->setAccessible(true);
$c = $ref->getValue($kernel);

// instantiate each module-bound class
$classes = [
    \Daems\Domain\Project\ProjectRepositoryInterface::class,
    \Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class,
    \Daems\Domain\Project\ProjectProposalRepositoryInterface::class,
    \DaemsModule\Projects\Controller\ProjectController::class,
    \DaemsModule\Projects\Controller\ProjectsBackstageController::class,
];
foreach ($classes as $cls) {
    try {
        $obj = $c->make($cls);
        echo "OK: $cls\n";
    } catch (\Throwable $e) {
        echo "FAIL: $cls — " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "ALL OK\n";
```

Run:
```bash
cd C:/laragon/www/daems-platform
php _tmp_smoke.php
```

Expected: 5× "OK:" + "ALL OK". If any FAIL, fix the binding (likely constructor-arg-order bug) and re-run before commit.

- [ ] **Step 5: Delete temp script**

```bash
rm C:/laragon/www/daems-platform/_tmp_smoke.php
```

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/bindings.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Bind(production): 3 repos + 13 public + 12 admin use cases + 2 controllers"
```

---

## Task 14: bindings.test.php (test container)

**Repo for commits:** `dp-projects`

**Why:** KernelHarness test container needs InMemory fakes instead of SQL impls. Same use case + controller bindings as production; only the 3 repository bindings differ.

**Files:**
- Modify: `modules/projects/backend/bindings.test.php`

- [ ] **Step 1: Replicate `bindings.php` with InMemory fake substitution**

Path: `modules/projects/backend/bindings.test.php`

Same structure as `bindings.php` but for the 3 repository bindings, swap to InMemory fakes:
```php
$container->bind(ProjectRepositoryInterface::class,
    static fn() => new \DaemsModule\Projects\Tests\Support\InMemoryProjectRepository());

$container->bind(ProjectCommentModerationAuditRepositoryInterface::class,
    static fn() => new \DaemsModule\Projects\Tests\Support\InMemoryProjectCommentModerationAuditRepository());

$container->bind(ProjectProposalRepositoryInterface::class,
    static fn() => new \DaemsModule\Projects\Tests\Support\InMemoryProjectProposalRepository());
```

(Use cases + controllers bindings: identical to `bindings.php`.)

- [ ] **Step 2: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/bindings.test.php 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 3: KernelHarness smoke**

`tests/Support/KernelHarness.php` automatically loads each module's `bindings.test.php`. Run a quick KernelHarness boot smoke:
```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --filter=ModuleRegistry --testsuite=Unit 2>&1 | tail -20
vendor/bin/phpunit --filter=KernelHarness --testsuite=Unit 2>&1 | tail -20
```

Expected: existing harness/registry tests pass.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/bindings.test.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Bind(test): InMemory fake bindings for KernelHarness"
```

---

## Task 15: routes.php (25 routes)

**Repo for commits:** `dp-projects`

**Files:**
- Modify: `modules/projects/backend/routes.php`

- [ ] **Step 1: Read source routes**

```bash
cd C:/laragon/www/daems-platform
sed -n '63,115p' routes/api.php > /tmp/project_public_routes.txt
sed -n '263,296p' routes/api.php > /tmp/project_admin_routes.txt
sed -n '364,378p' routes/api.php > /tmp/project_admin_routes2.txt
cat /tmp/project_public_routes.txt /tmp/project_admin_routes.txt /tmp/project_admin_routes2.txt
```

Capture all 25 route registrations + their middleware lists.

- [ ] **Step 2: Write full `routes.php`**

Path: `modules/projects/backend/routes.php`

```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\LocaleMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use DaemsModule\Projects\Controller\ProjectController;
use DaemsModule\Projects\Controller\ProjectsBackstageController;

return static function (Router $router, Container $container): void {
    // ── Public (13 routes) ──
    $router->get('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->indexLocalized($req);
    }, [TenantContextMiddleware::class, LocaleMiddleware::class]);

    $router->get('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->showLocalized($req, $params);
    }, [TenantContextMiddleware::class, LocaleMiddleware::class]);

    $router->get('/api/v1/projects-legacy', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/projects-legacy/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->show($req, $params);
    }, [TenantContextMiddleware::class]);

    $router->post('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->create($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->archive($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/join', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->join($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/leave', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->leave($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/comments', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/project-comments/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->likeComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/updates', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addUpdate($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/project-proposals', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->propose($req);
    }, [TenantContextMiddleware::class, LocaleMiddleware::class, AuthMiddleware::class]);

    // ── Backstage (12 routes) ──
    $router->get('/api/v1/backstage/projects/stats', static function (Request $req) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->statsProjects($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->listProjectsAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->createProjectAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->updateProjectAdmin($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/status', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->changeProjectStatus($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/featured', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->setProjectFeatured($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/proposals/{id}/approve', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->approveProjectProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/proposals/{id}/reject', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->rejectProjectProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/projects/{id}/translations', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->getProjectWithTranslations($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/translations/{locale}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->updateProjectTranslation($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/comments/recent', static function (Request $req) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->listProjectComments($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/comments/{comment_id}/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectsBackstageController::class)->deleteProjectComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
};
```

**Verify against source:** middleware lists in each route MUST match exactly what `routes/api.php` has on the corresponding line. (E.g. `LocaleMiddleware` is on the 2 locale-aware GETs + on `propose`; `AuthMiddleware` is on all writes; both legacy GETs lack `LocaleMiddleware`. Read each line of source carefully.)

- [ ] **Step 2: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/projects/backend/routes.php 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 3: Smoke route count**

Count number of `$router->` calls in module's routes.php:
```bash
grep -cE "\\\$router->" modules/projects/backend/routes.php
```

Expected: 25.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/routes.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Routes: 13 public + 12 backstage = 25 total"
```

---

## Task 17: Move tests/Unit/Application/Project (2 files)

**Repo for commits:** `dp-projects`

**Files:**
- Move: `daems-platform/tests/Unit/Application/Project/CreateProjectTest.php` → `modules/projects/backend/tests/Unit/Application/Project/CreateProjectTest.php`
- Move: `UpdateProjectTest.php` → same name in module

- [ ] **Step 1: Copy 2 files**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/backend/tests/Unit/Application/Project
cp daems-platform/tests/Unit/Application/Project/CreateProjectTest.php modules/projects/backend/tests/Unit/Application/Project/CreateProjectTest.php
cp daems-platform/tests/Unit/Application/Project/UpdateProjectTest.php modules/projects/backend/tests/Unit/Application/Project/UpdateProjectTest.php
```

- [ ] **Step 2: Rewrite namespace + use statements**

In each file:
- `namespace Daems\Tests\Unit\Application\Project;` → `namespace DaemsModule\Projects\Tests\Unit\Application\Project;`
- `use Daems\Application\Project\` → `use DaemsModule\Projects\Application\Project\`
- `use Daems\Tests\Support\Fake\InMemoryProject` → `use DaemsModule\Projects\Tests\Support\InMemoryProject`

PowerShell:
```powershell
$dir = 'C:\laragon\www\modules\projects\backend\tests\Unit\Application\Project'
Get-ChildItem -Path $dir -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Tests\\Unit\\Application\\Project;', 'namespace DaemsModule\Projects\Tests\Unit\Application\Project;'
    $c = $c -replace 'use Daems\\Application\\Project\\', 'use DaemsModule\Projects\Application\Project\'
    $c = $c -replace 'use Daems\\Tests\\Support\\Fake\\InMemoryProject', 'use DaemsModule\Projects\Tests\Support\InMemoryProject'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Run tests against module copy**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Application/Project/ 2>&1 | tail -10
```

Expected: both tests pass. (Originals in `daems-platform/tests/Unit/Application/Project/` also still pass — coexistence is fine.)

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Unit/Application/Project/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests): Application/Project unit tests (2)"
```

---

## Task 18: Move 10 Backstage Project unit tests

**Repo for commits:** `dp-projects`

**Files:** 10 files in `daems-platform/tests/Unit/Application/Backstage/` matching `*Project*Test.php`:
- `AdminUpdateProjectTest`, `ApproveProjectProposalTest`, `ChangeProjectStatusTest`, `CreateProjectAsAdminTest`, `DeleteProjectCommentAsAdminTest`, `ListProjectCommentsForAdminTest`, `ListProjectsForAdminTest`, `ListProjectsStatsTest`, `RejectProjectProposalTest`, `SetProjectFeaturedTest`

Move to `modules/projects/backend/tests/Unit/Application/Backstage/<same names>.php`.

- [ ] **Step 1: Copy 10 files**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/backend/tests/Unit/Application/Backstage
for t in AdminUpdateProjectTest ApproveProjectProposalTest ChangeProjectStatusTest CreateProjectAsAdminTest DeleteProjectCommentAsAdminTest ListProjectCommentsForAdminTest ListProjectsForAdminTest ListProjectsStatsTest RejectProjectProposalTest SetProjectFeaturedTest; do
  cp "daems-platform/tests/Unit/Application/Backstage/${t}.php" "modules/projects/backend/tests/Unit/Application/Backstage/${t}.php"
done
echo "Files: $(ls modules/projects/backend/tests/Unit/Application/Backstage/*.php | wc -l)"
```

Expected: 10.

- [ ] **Step 2: Rewrite namespace + imports**

Per file:
- `namespace Daems\Tests\Unit\Application\Backstage;` → `namespace DaemsModule\Projects\Tests\Unit\Application\Backstage;`
- `use Daems\Application\Backstage\<UseCase>\` → `use DaemsModule\Projects\Application\Backstage\<UseCase>\` (for the 12 use case names)
- `use Daems\Tests\Support\Fake\InMemoryProject` → `use DaemsModule\Projects\Tests\Support\InMemoryProject`

PowerShell:
```powershell
$dir = 'C:\laragon\www\modules\projects\backend\tests\Unit\Application\Backstage'
$useCases = @('AdminUpdateProject','ApproveProjectProposal','ChangeProjectStatus','CreateProjectAsAdmin','DeleteProjectCommentAsAdmin','GetProjectWithAllTranslations','ListProjectCommentsForAdmin','ListProjectsForAdmin','RejectProjectProposal','SetProjectFeatured','UpdateProjectTranslation')
Get-ChildItem -Path $dir -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Tests\\Unit\\Application\\Backstage;', 'namespace DaemsModule\Projects\Tests\Unit\Application\Backstage;'
    foreach ($uc in $useCases) {
        $c = $c -replace ("use Daems\\Application\\Backstage\\$uc\\"), ("use DaemsModule\Projects\Application\Backstage\$uc\")
    }
    $c = $c -replace 'use Daems\\Application\\Backstage\\Projects\\ListProjectsStats\\', 'use DaemsModule\Projects\Application\Backstage\Projects\ListProjectsStats\'
    $c = $c -replace 'use Daems\\Tests\\Support\\Fake\\InMemoryProject', 'use DaemsModule\Projects\Tests\Support\InMemoryProject'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Run tests**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Unit/Application/Backstage/ 2>&1 | tail -10
```

Expected: all 10 pass.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Unit/Application/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests): 10 Backstage Project unit tests"
```

---

## Task 19: Move 5 integration tests

**Repo for commits:** `dp-projects`

**Files:**
- Move: `tests/Integration/Application/ProjectCommentModerationIntegrationTest.php` → `modules/projects/backend/tests/Integration/Application/ProjectCommentModerationIntegrationTest.php`
- Move: `tests/Integration/Application/ProjectsAdminIntegrationTest.php` → same path in module
- Move: `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php` → `modules/projects/backend/tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- Move: `tests/Integration/ProjectProposalStatsTest.php` → `modules/projects/backend/tests/Integration/ProjectProposalStatsTest.php`
- Move: `tests/Integration/ProjectStatsTest.php` → same in module

- [ ] **Step 1: Copy 5 files preserving sub-paths**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/backend/tests/Integration/Application
mkdir -p modules/projects/backend/tests/Integration/Infrastructure
cp daems-platform/tests/Integration/Application/ProjectCommentModerationIntegrationTest.php modules/projects/backend/tests/Integration/Application/ProjectCommentModerationIntegrationTest.php
cp daems-platform/tests/Integration/Application/ProjectsAdminIntegrationTest.php modules/projects/backend/tests/Integration/Application/ProjectsAdminIntegrationTest.php
cp daems-platform/tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php modules/projects/backend/tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php
cp daems-platform/tests/Integration/ProjectProposalStatsTest.php modules/projects/backend/tests/Integration/ProjectProposalStatsTest.php
cp daems-platform/tests/Integration/ProjectStatsTest.php modules/projects/backend/tests/Integration/ProjectStatsTest.php
```

- [ ] **Step 2: Rewrite namespace + imports**

For each file:
- `namespace Daems\Tests\Integration\Application;` → `namespace DaemsModule\Projects\Tests\Integration\Application;` (and respective `Infrastructure;` / `;` namespaces)
- `use Daems\Application\Project\` → `use DaemsModule\Projects\Application\Project\`
- `use Daems\Application\Backstage\<UseCase>\` (for project use cases) → `use DaemsModule\Projects\Application\Backstage\<UseCase>\`
- `use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProject` → `use DaemsModule\Projects\Infrastructure\SqlProject`
- `use Daems\Tests\Support\Fake\InMemoryProject` → `use DaemsModule\Projects\Tests\Support\InMemoryProject`

(May need a per-file approach — these tests likely also import core types, e.g. `Daems\Tests\Integration\MigrationTestCase`, which must REMAIN unchanged.)

- [ ] **Step 3: Run tests**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Integration/ 2>&1 | tail -20
```

Expected: 5 tests pass.

If any fails because of fixture / migration dependency, note it for Task 25 — moved migrations may need to be applied during integration test boot.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Integration/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests): 5 integration tests (CommentModeration, Admin, SqlI18n, ProposalStats, Stats)"
```

---

## Task 20: Move isolation (4) + E2E (2)

**Repo for commits:** `dp-projects`

**Files:**
- Move: `tests/Isolation/{ProjectTenantIsolationTest,ProjectsAdminTenantIsolationTest,ProjectsI18nTenantIsolationTest,ProjectsStatsTenantIsolationTest}.php` (4) → `modules/projects/backend/tests/Isolation/`
- Move: `tests/E2E/F004_UnauthProjectMutationTest.php` → `modules/projects/backend/tests/E2E/`
- Move: `tests/E2E/ProjectsLocaleE2ETest.php` → same in module

- [ ] **Step 1: Copy 6 files**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/backend/tests/Isolation
mkdir -p modules/projects/backend/tests/E2E
cp daems-platform/tests/Isolation/ProjectTenantIsolationTest.php modules/projects/backend/tests/Isolation/ProjectTenantIsolationTest.php
cp daems-platform/tests/Isolation/ProjectsAdminTenantIsolationTest.php modules/projects/backend/tests/Isolation/ProjectsAdminTenantIsolationTest.php
cp daems-platform/tests/Isolation/ProjectsI18nTenantIsolationTest.php modules/projects/backend/tests/Isolation/ProjectsI18nTenantIsolationTest.php
cp daems-platform/tests/Isolation/ProjectsStatsTenantIsolationTest.php modules/projects/backend/tests/Isolation/ProjectsStatsTenantIsolationTest.php
cp daems-platform/tests/E2E/F004_UnauthProjectMutationTest.php modules/projects/backend/tests/E2E/F004_UnauthProjectMutationTest.php
cp daems-platform/tests/E2E/ProjectsLocaleE2ETest.php modules/projects/backend/tests/E2E/ProjectsLocaleE2ETest.php
```

- [ ] **Step 2: Rewrite namespace + imports**

Same pattern as Task 19 — namespace changes from `Daems\Tests\Isolation\` to `DaemsModule\Projects\Tests\Isolation\`, similarly for E2E. Project-related imports rewrite to module namespace; core imports (e.g. `Daems\Tests\Integration\IsolationTestCase`) remain unchanged.

- [ ] **Step 3: Run tests**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/projects/backend/tests/Isolation/ 2>&1 | tail -10
vendor/bin/phpunit ../modules/projects/backend/tests/E2E/ 2>&1 | tail -10
```

Expected: 4 isolation + 2 E2E tests pass.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Isolation/ backend/tests/E2E/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests): 4 isolation + 2 E2E"
```

---

## Task 21: Remove Projects bindings from `bootstrap/app.php`

**Repo for commits:** `daems-platform`

**Why:** module's `bindings.php` (loaded by `ModuleRegistry`) now provides all 30 Project bindings. Core's bootstrap should not duplicate them.

**Files:**
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Identify exact line ranges**

```bash
cd C:/laragon/www/daems-platform
grep -n "Project" bootstrap/app.php
```

Expected: imports lines 23–35 (or thereabouts), bindings around 225–360. Capture each line range.

- [ ] **Step 2: Delete imports**

Remove lines:
```
use Daems\Application\Project\AddProjectComment\AddProjectComment;
use Daems\Application\Project\AddProjectUpdate\AddProjectUpdate;
use Daems\Application\Project\ArchiveProject\ArchiveProject;
use Daems\Application\Project\CreateProject\CreateProject;
use Daems\Application\Project\GetProject\GetProject;
use Daems\Application\Project\JoinProject\JoinProject;
use Daems\Application\Project\LeaveProject\LeaveProject;
use Daems\Application\Project\LikeProjectComment\LikeProjectComment;
use Daems\Application\Project\GetProjectBySlugForLocale\GetProjectBySlugForLocale;
use Daems\Application\Project\ListProjects\ListProjects;
use Daems\Application\Project\ListProjectsForLocale\ListProjectsForLocale;
use Daems\Application\Project\SubmitProjectProposal\SubmitProjectProposal;
use Daems\Application\Project\UpdateProject\UpdateProject;
use Daems\Infrastructure\Adapter\Api\Controller\ProjectController;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
```

(Plus `SqlProjectCommentModerationAuditRepository` import if present.)

**Keep** these imports:
- `use Daems\Domain\Project\ProjectProposalRepositoryInterface;` (cross-domain consumers like `ListProposalsForAdmin` still use)
- `use Daems\Domain\Project\ProjectRepositoryInterface;`
- `use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;`

- [ ] **Step 3: Delete binding blocks**

Delete every `$container->bind(...)` block where:
- Target is one of the 13 public + 12 admin Project use cases
- Target is one of the 3 SQL repositories
- Target is `ProjectController`

Some bindings may be cross-referenced from non-project use cases (e.g. `Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin` uses `ProjectProposalRepositoryInterface`). Those stay, but their `make(ProjectProposalRepositoryInterface::class)` calls now resolve via the module's binding (which is registered first by `ModuleRegistry`). No change needed there.

- [ ] **Step 4: PHPStan + tests**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
```

Expected: 0 errors. (If imports were not all removed, "unused use" warnings may surface — fix.)

```bash
vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -10
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -10
```

Expected: all green. (Module's `bindings.php` registers everything from `ModuleRegistry` before core bootstrap completes.)

- [ ] **Step 5: Production-container smoke**

Re-run the smoke from Task 13 step 4 (recreate temp script, run, delete):
```bash
cd C:/laragon/www/daems-platform
cat > _tmp_smoke.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new ReflectionProperty($kernel, 'container');
$ref->setAccessible(true);
$c = $ref->getValue($kernel);
foreach ([
    \DaemsModule\Projects\Controller\ProjectController::class,
    \DaemsModule\Projects\Controller\ProjectsBackstageController::class,
] as $cls) {
    $c->make($cls);
    echo "OK: $cls\n";
}
EOF
php _tmp_smoke.php
rm _tmp_smoke.php
```

Expected: 2× "OK".

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add bootstrap/app.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(bootstrap): Projects bindings — module owns them now"
```

---

## Task 22: Remove Projects routes from `routes/api.php`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Identify exact line ranges**

```bash
cd C:/laragon/www/daems-platform
grep -nE "ProjectController::class|->listProjectsAdmin|->createProjectAdmin|->updateProjectAdmin|->changeProjectStatus|->setProjectFeatured|->listProjectComments|->deleteProjectComment|->statsProjects|->getProjectWithTranslations|->updateProjectTranslation|->approveProjectProposal|->rejectProjectProposal" routes/api.php
```

Capture: 13 ProjectController routes (lines ~63–115) + 12 ProjectsBackstageController routes (lines ~263–296 + 364–378).

- [ ] **Step 2: Delete the 25 route blocks**

Each route is ~3–4 lines (`$router->...; }, [...]);`). Use Edit tool — if any 25-line ranges are contiguous, delete via large multi-line Edit.

The 5 out-of-prefix routes also delete:
- `/api/v1/project-comments/{id}/like`
- `/api/v1/project-proposals`
- `/api/v1/backstage/proposals/{id}/approve`
- `/api/v1/backstage/proposals/{id}/reject`
- `/api/v1/backstage/comments/recent`

**Do NOT delete** `/api/v1/backstage/proposals` (GET, list) — that's `ListProposalsForAdmin`, cross-domain, stays in core.

- [ ] **Step 3: Verify count**

```bash
grep -cE "/api/v1/projects|/api/v1/project-comments|/api/v1/project-proposals|/api/v1/backstage/projects|/api/v1/backstage/comments|/api/v1/backstage/proposals" routes/api.php
```

Expected: 1 (only `/api/v1/backstage/proposals` GET listing remains).

- [ ] **Step 4: Tests + smoke**

```bash
composer analyse 2>&1 | tail -5
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -5
```

Expected: 0 errors, all E2E green. Module's `routes.php` auto-registers all 25 deleted routes through `ModuleRegistry`.

Production-container smoke (Task 21 Step 5 pattern).

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(routes): 25 Projects routes — module owns them now"
```

---

## Task 23: Remove 12 Project methods from `BackstageController`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 1: Identify exact method line ranges**

```bash
cd C:/laragon/www/daems-platform
grep -nE "function (listProjectsAdmin|createProjectAdmin|updateProjectAdmin|changeProjectStatus|setProjectFeatured|listProjectComments|deleteProjectComment|statsProjects|getProjectWithTranslations|updateProjectTranslation|approveProjectProposal|rejectProjectProposal)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

Capture each method's start line. The body extends to its matching closing `}` — read each body via Read tool to determine end line.

- [ ] **Step 2: Delete each method body**

Use Edit tool per method — replace each `public function methodName(...): Response { ... }` block with empty (delete entirely).

Also remove from constructor any unused parameters that ONLY supported these 12 methods. Check via grep:
```bash
grep -nE "private (ListProjectsForAdmin|CreateProjectAsAdmin|AdminUpdateProject|ChangeProjectStatus|SetProjectFeatured|ListProjectCommentsForAdmin|DeleteProjectCommentAsAdmin|ListProjectsStats|GetProjectWithAllTranslations|UpdateProjectTranslation|ApproveProjectProposal|RejectProjectProposal) " src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

If any constructor parameter is no longer referenced anywhere in the remaining body, remove from constructor + remove its `use` import.

- [ ] **Step 3: Remove unused imports**

```bash
grep -E "^use Daems\\\\Application\\\\(Project|Backstage\\\\(AdminUpdateProject|ApproveProjectProposal|ChangeProjectStatus|CreateProjectAsAdmin|DeleteProjectCommentAsAdmin|GetProjectWithAllTranslations|ListProjectCommentsForAdmin|ListProjectsForAdmin|RejectProjectProposal|SetProjectFeatured|UpdateProjectTranslation)\\\\)" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
grep -E "^use Daems\\\\Application\\\\Backstage\\\\Projects\\\\" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

Remove every line that's no longer referenced.

- [ ] **Step 4: PHPStan + tests**

```bash
composer analyse 2>&1 | tail -10
vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -5
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -5
```

Expected: 0 errors, all green.

- [ ] **Step 5: Production-container smoke**

Same as Task 21 Step 5.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add src/Infrastructure/Adapter/Api/Controller/BackstageController.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(controller): 12 Project methods from BackstageController — module owns them now"
```

---

## Task 24: Remove Projects bindings from KernelHarness + update 6 cross-domain test imports

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `tests/Support/KernelHarness.php`
- Modify: `tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php`
- Modify: `tests/Unit/Application/Backstage/ListProposalsForAdminTest.php`
- Modify: `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php`
- Modify: `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php`
- Modify: `tests/E2E/F007_IdentitySpoofingTest.php`

- [ ] **Step 1: Remove KernelHarness Project bindings**

```bash
grep -nE "Project|InMemoryProject" tests/Support/KernelHarness.php
```

Identify all bindings + InMemory fake registrations for Projects. Delete them. Keep `use Daems\Domain\Project\*` interface imports if still needed (cross-domain consumers reference them).

- [ ] **Step 2: Update 5 cross-domain test imports**

For each of the 5 test files:
- Replace `use Daems\Tests\Support\Fake\InMemoryProjectRepository;` → `use DaemsModule\Projects\Tests\Support\InMemoryProjectRepository;`
- Replace `use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;` → `use DaemsModule\Projects\Tests\Support\InMemoryProjectProposalRepository;`
- Replace `use Daems\Tests\Support\Fake\InMemoryProjectCommentModerationAuditRepository;` → `use DaemsModule\Projects\Tests\Support\InMemoryProjectCommentModerationAuditRepository;`

PowerShell:
```powershell
$files = @(
    'C:\laragon\www\daems-platform\tests\Support\KernelHarness.php',
    'C:\laragon\www\daems-platform\tests\Unit\Application\Backstage\ListPendingApplicationsForAdminTest.php',
    'C:\laragon\www\daems-platform\tests\Unit\Application\Backstage\ListProposalsForAdminTest.php',
    'C:\laragon\www\daems-platform\tests\Unit\Application\Backstage\ListNotificationsStatsTest.php',
    'C:\laragon\www\daems-platform\tests\E2E\Backstage\AdminInboxIncludesProposalsTest.php',
    'C:\laragon\www\daems-platform\tests\E2E\F007_IdentitySpoofingTest.php'
)
foreach ($f in $files) {
    if (Test-Path $f) {
        $c = Get-Content $f -Raw
        $c = $c -replace 'use Daems\\Tests\\Support\\Fake\\InMemoryProject', 'use DaemsModule\Projects\Tests\Support\InMemoryProject'
        $c = $c -replace 'new \\Daems\\Tests\\Support\\Fake\\InMemoryProject', 'new \DaemsModule\Projects\Tests\Support\InMemoryProject'
        $c = $c -replace '\\Daems\\Tests\\Support\\Fake\\InMemoryProject', '\DaemsModule\Projects\Tests\Support\InMemoryProject'
        Set-Content -Path $f -Value $c -Encoding utf8
    }
}
```

- [ ] **Step 3: Verify no stale `Daems\Tests\Support\Fake\InMemoryProject` references**

```bash
grep -rn "Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryProject" src tests bootstrap
```

Expected: zero hits.

- [ ] **Step 4: PHPStan + tests**

```bash
composer analyse 2>&1 | tail -5
vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -5
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -5
```

Expected: 0 errors, all green.

- [ ] **Step 5: Production-container smoke**

Same as Task 21 Step 5.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add tests/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(harness): Projects bindings + update 5 cross-domain test imports to module fakes"
```

---

## Task 25: Apply 066_* data-fix migration; delete originals + 4 dangling Migration tests

**Repo for commits:** `daems-platform`

**Why:** module's migrations are now active via `ModuleRegistry`. Core's originals are obsolete and confusing. Apply the `066_*` data-fix to dev DB, then delete:
- 10 original Project migrations
- 38 + 29 + 3 + 1 = 71 backend source files (Domain stays — 14 retained)
- 2 + 10 = 12 unit tests (Application/Project + Backstage)
- 5 integration tests
- 4 isolation tests
- 2 E2E tests
- 3 InMemory fakes
- 4 dangling Migration tests (Migration027, 031, 044, 046)

**Files:** see "Deleted in `daems-platform/`" section of File map summary.

- [ ] **Step 1: Apply 066_* migration to dev DB**

```bash
cd C:/laragon/www/daems-platform
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db < database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql
```

Verify rows renamed in `daems_db.schema_migrations`:
```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db -e "SELECT migration FROM schema_migrations WHERE migration LIKE '%project%' ORDER BY migration"
```

Expected: 10 rows like `project_001_*` … `project_010_*`.

- [ ] **Step 2: Delete 10 original Project migrations**

```bash
rm database/migrations/003_create_projects_table.sql
rm database/migrations/009_create_project_extras.sql
rm database/migrations/010_create_project_proposals.sql
rm database/migrations/016_add_owner_id_to_projects.sql
rm database/migrations/027_add_tenant_id_to_projects.sql
rm database/migrations/031_add_tenant_id_to_project_extras.sql
rm database/migrations/044_add_featured_to_projects.sql
rm database/migrations/046_add_decision_metadata_to_project_proposals.sql
rm database/migrations/052_create_projects_i18n.sql
rm database/migrations/055_add_source_locale_to_project_proposals.sql
```

Verify migrations 053, 054, 059 retained:
```bash
ls database/migrations/053_backfill_events_projects_i18n.sql database/migrations/054_drop_translated_columns_from_events_projects.sql database/migrations/059_fulltext_events_projects_i18n.sql
```

Expected: 3 files exist.

- [ ] **Step 3: Delete 4 dangling Migration tests**

```bash
rm tests/Integration/Migration/Migration027Test.php
rm tests/Integration/Migration/Migration031Test.php
rm tests/Integration/Migration/Migration044Test.php
rm tests/Integration/Migration/Migration046Test.php
```

- [ ] **Step 4: Delete 71 backend source files + 21 test files + 3 fakes**

```bash
# 13 sub-dirs of Application/Project
rm -rf src/Application/Project

# 12 admin sibling dirs
for d in AdminUpdateProject ApproveProjectProposal ChangeProjectStatus CreateProjectAsAdmin DeleteProjectCommentAsAdmin GetProjectWithAllTranslations ListProjectCommentsForAdmin ListProjectsForAdmin Projects RejectProjectProposal SetProjectFeatured UpdateProjectTranslation; do
  rm -rf "src/Application/Backstage/$d"
done

# 3 SQL repos
rm src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php

# 1 controller
rm src/Infrastructure/Adapter/Api/Controller/ProjectController.php

# 2 unit tests in Application/Project
rm -rf tests/Unit/Application/Project

# 10 Backstage Project unit tests
for t in AdminUpdateProjectTest ApproveProjectProposalTest ChangeProjectStatusTest CreateProjectAsAdminTest DeleteProjectCommentAsAdminTest ListProjectCommentsForAdminTest ListProjectsForAdminTest ListProjectsStatsTest RejectProjectProposalTest SetProjectFeaturedTest; do
  rm "tests/Unit/Application/Backstage/${t}.php"
done

# 5 integration tests
rm tests/Integration/Application/ProjectCommentModerationIntegrationTest.php
rm tests/Integration/Application/ProjectsAdminIntegrationTest.php
rm tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php
rm tests/Integration/ProjectProposalStatsTest.php
rm tests/Integration/ProjectStatsTest.php

# 4 isolation tests
rm tests/Isolation/ProjectTenantIsolationTest.php
rm tests/Isolation/ProjectsAdminTenantIsolationTest.php
rm tests/Isolation/ProjectsI18nTenantIsolationTest.php
rm tests/Isolation/ProjectsStatsTenantIsolationTest.php

# 2 E2E tests
rm tests/E2E/F004_UnauthProjectMutationTest.php
rm tests/E2E/ProjectsLocaleE2ETest.php

# 3 InMemory fakes
rm tests/Support/Fake/InMemoryProjectRepository.php
rm tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php
rm tests/Support/Fake/InMemoryProjectProposalRepository.php
```

- [ ] **Step 5: Verify Domain retained**

```bash
ls src/Domain/Project/*.php | wc -l
```

Expected: 14 (Forum lesson 1).

- [ ] **Step 6: Final namespace grep — zero hits expected**

```bash
git grep -E "use Daems\\\\(Application\\\\Project\\\\|Application\\\\Backstage\\\\(AdminUpdateProject|ApproveProjectProposal|ChangeProjectStatus|CreateProjectAsAdmin|DeleteProjectCommentAsAdmin|GetProjectWithAllTranslations|ListProjectCommentsForAdmin|ListProjectsForAdmin|RejectProjectProposal|SetProjectFeatured|UpdateProjectTranslation)\\\\|Application\\\\Backstage\\\\Projects\\\\|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlProject|Infrastructure\\\\Adapter\\\\Api\\\\Controller\\\\ProjectController)" src tests bootstrap routes
```

Expected: zero hits in `daems-platform`. (Module-namespace `DaemsModule\Projects\*` imports in cross-domain core tests/harness are expected and correct — they're the cross-module import pattern from Task 24.)

- [ ] **Step 7: Full test suite**

```bash
vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -5
vendor/bin/phpunit --testsuite=Integration 2>&1 | tail -5
vendor/bin/phpunit --testsuite=Isolation 2>&1 | tail -5
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -5
```

Expected: all green. Combined count should match Forum's 943/943 minus any tests that were Project-specific Forum-derived now-deleted (verify by computing baseline pre-Task-25 vs post-Task-25 totals).

- [ ] **Step 8: PHPStan**

```bash
composer analyse 2>&1 | tail -5
```

Expected: 0 errors level 9.

- [ ] **Step 9: Production-container smoke**

Same as Task 21 Step 5.

- [ ] **Step 10: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -A
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(core): legacy Projects code — module owns it now (10 mig + 71 src + 21 test + 3 fake + 4 dangling Migration tests)"
```

---

## Task 26: Move 9 daem-society public pages

**Repo for commits:** `dp-projects`

**Files:**
- Move: 7 files from `daem-society/public/pages/projects/{cta,detail,edit,grid,hero,index,new}.php` → `modules/projects/frontend/public/`
- Move: 2 files from `daem-society/public/pages/projects/detail/{content,hero}.php` → `modules/projects/frontend/public/detail/`

- [ ] **Step 1: Copy 9 files preserving sub-paths**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/frontend/public/detail
cp sites/daem-society/public/pages/projects/cta.php modules/projects/frontend/public/cta.php
cp sites/daem-society/public/pages/projects/detail.php modules/projects/frontend/public/detail.php
cp sites/daem-society/public/pages/projects/edit.php modules/projects/frontend/public/edit.php
cp sites/daem-society/public/pages/projects/grid.php modules/projects/frontend/public/grid.php
cp sites/daem-society/public/pages/projects/hero.php modules/projects/frontend/public/hero.php
cp sites/daem-society/public/pages/projects/index.php modules/projects/frontend/public/index.php
cp sites/daem-society/public/pages/projects/new.php modules/projects/frontend/public/new.php
cp sites/daem-society/public/pages/projects/detail/content.php modules/projects/frontend/public/detail/content.php
cp sites/daem-society/public/pages/projects/detail/hero.php modules/projects/frontend/public/detail/hero.php
echo "Files copied: $(find modules/projects/frontend/public -name '*.php' | wc -l)"
```

Expected: 9.

- [ ] **Step 2: Rewrite `__DIR__`-relative includes to `DAEMS_SITE_PUBLIC` absolute paths**

For each file, identify includes that depend on the old location (e.g. `__DIR__ . '/../../top-nav.php'` → `DAEMS_SITE_PUBLIC . '/pages/top-nav.php'`). Pattern:

```bash
grep -nE "__DIR__|require_once|include" modules/projects/frontend/public/*.php modules/projects/frontend/public/detail/*.php
```

Replace each `__DIR__ . '/../../X'` with the canonical `DAEMS_SITE_PUBLIC . '/pages/X'` form (matching the path Forum's Wave F task 26 used).

For includes within the same dir (e.g. `index.php` includes `hero.php`), the relative path may still work since both files moved together. Use:
```php
require_once __DIR__ . '/hero.php';   // OK — both files in modules/projects/frontend/public/
```

For the `errors/404.php` reference — apply Forum's lesson learned: use `DAEMS_SITE_PUBLIC . '/pages/errors/404.php'` (NOT `__DIR__ . '/../../pages/404.php'`).

- [ ] **Step 3: Browser smoke**

In a local browser, navigate to:
- `http://daems.local/projects` (index list)
- `http://daems.local/projects/<slug>` (detail)
- `http://daems.local/projects/new` (proposal form, requires login)

Each must render identically to pre-move. JS console: 0 errors.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/public/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend-public): 9 Projects pages with __DIR__→DAEMS_SITE_PUBLIC rewrite"
```

Originals NOT yet deleted in daem-society — that's Task 28.

---

## Task 27: Move 1 backstage page + asset URL update

**Repo for commits:** `dp-projects`

**Files:**
- Move: `daem-society/public/pages/backstage/projects/index.php` → `modules/projects/frontend/backstage/index.php`

- [ ] **Step 1: Copy file**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/frontend/backstage
cp sites/daem-society/public/pages/backstage/projects/index.php modules/projects/frontend/backstage/index.php
```

- [ ] **Step 2: Rewrite asset URLs in moved page**

In `modules/projects/frontend/backstage/index.php`, replace:
```
/pages/backstage/projects/projects-stats.js  →  /modules/projects/assets/backstage/projects-stats.js
/pages/backstage/projects/project-modal.css  →  /modules/projects/assets/backstage/project-modal.css
/pages/backstage/projects/project-modal.js   →  /modules/projects/assets/backstage/project-modal.js
```

PowerShell:
```powershell
$file = 'C:\laragon\www\modules\projects\frontend\backstage\index.php'
$c = Get-Content $file -Raw
$c = $c -replace '/pages/backstage/projects/projects-stats\.js', '/modules/projects/assets/backstage/projects-stats.js'
$c = $c -replace '/pages/backstage/projects/project-modal\.css', '/modules/projects/assets/backstage/project-modal.css'
$c = $c -replace '/pages/backstage/projects/project-modal\.js', '/modules/projects/assets/backstage/project-modal.js'
Set-Content -Path $file -Value $c -Encoding utf8
```

- [ ] **Step 3: Rewrite `__DIR__` includes**

Same pattern as Task 26 Step 2 — chrome includes (`top-nav`, `footer`, etc.) → `DAEMS_SITE_PUBLIC`-rooted.

- [ ] **Step 4: Browser smoke**

Navigate to `http://daems.local/backstage/projects`. Must render. JS console: 0 errors. Network: assets at `/modules/projects/assets/backstage/*` should 404 at this point (assets not yet moved — Task 28).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend-backstage): index page + asset URL rewrite"
```

---

## Task 28: Move 3 assets (1 CSS + 2 JS) + delete daem-society originals

**Repo for commits:** `dp-projects` + `daem-society`

**Files:**
- Move: `daem-society/public/pages/backstage/projects/project-modal.css` → `modules/projects/frontend/assets/backstage/project-modal.css`
- Move: `project-modal.js` → same name in `assets/backstage/`
- Move: `projects-stats.js` → same name in `assets/backstage/`
- Delete: `daem-society/public/pages/projects/` (whole dir)
- Delete: `daem-society/public/pages/backstage/projects/` (whole dir)

- [ ] **Step 1: Copy 3 assets**

```bash
cd C:/laragon/www/
mkdir -p modules/projects/frontend/assets/backstage
cp sites/daem-society/public/pages/backstage/projects/project-modal.css modules/projects/frontend/assets/backstage/project-modal.css
cp sites/daem-society/public/pages/backstage/projects/project-modal.js modules/projects/frontend/assets/backstage/project-modal.js
cp sites/daem-society/public/pages/backstage/projects/projects-stats.js modules/projects/frontend/assets/backstage/projects-stats.js
```

- [ ] **Step 2: Asset HTTP smoke**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://daems.local/modules/projects/assets/backstage/project-modal.css
curl -s -o /dev/null -w "%{http_code}\n" http://daems.local/modules/projects/assets/backstage/project-modal.js
curl -s -o /dev/null -w "%{http_code}\n" http://daems.local/modules/projects/assets/backstage/projects-stats.js
```

Expected: 3× `200`.

- [ ] **Step 3: Commit asset move (in dp-projects)**

```bash
cd C:/laragon/www/modules/projects
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/assets/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend-assets): 3 backstage assets (project-modal css/js + projects-stats.js)"
```

- [ ] **Step 4: Delete daem-society originals**

```bash
rm -rf sites/daem-society/public/pages/projects
rm -rf sites/daem-society/public/pages/backstage/projects
```

Verify deletion:
```bash
ls sites/daem-society/public/pages/projects 2>&1
ls sites/daem-society/public/pages/backstage/projects 2>&1
```

Expected: "No such file or directory" twice.

- [ ] **Step 5: Browser smoke against new module-router-served URLs**

Navigate to:
- `http://daems.local/projects` — must render via module-router
- `http://daems.local/backstage/projects` — must render
- All assets in Network panel: HTTP 200 for `/modules/projects/assets/backstage/*`

JS console: 0 errors.

- [ ] **Step 6: Commit daem-society deletion**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -A
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(frontend): projects + backstage/projects dirs — module router serves them now"
```

---

## Task 29: Final verification gate

**Repo for commits:** none (verification only) — final commits already on dev branches.

- [ ] **Step 1: PHPStan level 9 = 0 errors**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 2: All test suites green**

Run separately to avoid 600s composer process timeout (Forum gotcha 3):

```bash
vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -5
vendor/bin/phpunit --testsuite=Integration 2>&1 | tail -5
vendor/bin/phpunit --testsuite=Isolation 2>&1 | tail -5
vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -5
```

Expected: each suite reports 0 failures, 0 errors.

Capture combined count: `Unit X + Integration Y + Isolation Z + E2E W = total/total green`.

- [ ] **Step 3: Production-container smoke (final)**

```bash
cd C:/laragon/www/daems-platform
cat > _tmp_smoke.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new ReflectionProperty($kernel, 'container');
$ref->setAccessible(true);
$c = $ref->getValue($kernel);
$classes = [
    \Daems\Domain\Project\ProjectRepositoryInterface::class,
    \Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class,
    \Daems\Domain\Project\ProjectProposalRepositoryInterface::class,
    \DaemsModule\Projects\Controller\ProjectController::class,
    \DaemsModule\Projects\Controller\ProjectsBackstageController::class,
    \DaemsModule\Projects\Application\Project\CreateProject\CreateProject::class,
    \DaemsModule\Projects\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin::class,
];
foreach ($classes as $cls) {
    try { $c->make($cls); echo "OK: $cls\n"; }
    catch (\Throwable $e) { echo "FAIL: $cls — " . $e->getMessage() . "\n"; exit(1); }
}
echo "ALL OK\n";
EOF
php _tmp_smoke.php
rm _tmp_smoke.php
```

Expected: 7× "OK:" + "ALL OK".

- [ ] **Step 4: Git grep — zero legacy references in core**

```bash
git grep -nE "use Daems\\\\(Application\\\\Project\\\\|Application\\\\Backstage\\\\(AdminUpdateProject|ApproveProjectProposal|ChangeProjectStatus|CreateProjectAsAdmin|DeleteProjectCommentAsAdmin|GetProjectWithAllTranslations|ListProjectCommentsForAdmin|ListProjectsForAdmin|RejectProjectProposal|SetProjectFeatured|UpdateProjectTranslation)\\\\|Application\\\\Backstage\\\\Projects\\\\|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlProject|Infrastructure\\\\Adapter\\\\Api\\\\Controller\\\\ProjectController)" src tests bootstrap routes
```

Expected: zero hits in `daems-platform`. Cross-module imports (`DaemsModule\Projects\Tests\Support\InMemoryProject*`) in 5 cross-domain test files are expected; ignore those.

- [ ] **Step 5: User browser smoke (mandatory — see spec §10)**

Navigate the following URLs in `http://daems.local`:

**Public:**
- `/projects` — list renders, project cards display
- `/projects/<slug>` — detail page renders, comments load, like/join/leave buttons functional
- `/projects/new` — proposal form (requires login) renders, submit creates a proposal

**Backstage:**
- `/backstage/projects` — admin dashboard renders, project list, stats KPIs display
- Click any project → modal opens with translations editor (English + Finnish + Swahili tabs)
- Save translation per-locale → toast confirms success
- Toggle featured flag → state persists
- Change status (draft/published/archived) → state persists

**Network panel checks:**
- `/modules/projects/assets/backstage/project-modal.css` → 200
- `/modules/projects/assets/backstage/project-modal.js` → 200
- `/modules/projects/assets/backstage/projects-stats.js` → 200

**JS console checks:**
- 0 errors
- 0 `payload is undefined`
- 0 unresolved import / 404 fetch errors

**Theme parity (CLAUDE.md backlog item I13):**
- Backstage projects page matches dark/light mode of other admin pages
- No theme-regression patches need adjusting

- [ ] **Step 6: User confirmation**

Wait for user "kaikki green" / "valmis" / "selain OK" before declaring milestone complete.

If smoke fails on any URL: report URL + console error / screenshot, fix in module, recommit, re-run gate.

- [ ] **Step 7: No commit needed**

Verification step is read-only. After user "pushaa":
```bash
cd C:/laragon/www/daems-platform   && git push origin dev
cd C:/laragon/www/modules/projects && git push origin dev
cd C:/laragon/www/sites/daem-society && git push origin dev
```

(One-way push only — three repos, three branches, all to `origin/dev`.)

---

## Self-review notes

This plan was generated by `superpowers:writing-plans` after the brainstorming spec was approved. Self-review checklist:

**1. Spec coverage:** every section in the spec maps to one or more tasks:
- §1 (locked decisions) → encoded into Tasks 1, 10, 13–15
- §2 (Forum lessons) → encoded as plan-level rules + Task 9.5 + production-smoke at Wave E gates
- §3 (inventory) → Task 3 verification
- §4 (manifest) → Task 1
- §5 (bindings + routes) → Tasks 13, 14, 15
- §6 (removals) → Wave E (Tasks 21–25)
- §7 (namespace rewrite map) → Tasks 5–9, 17–20 (per file step "rewrite namespace")
- §8 (frontend integration) → Tasks 26–28
- §9 (BOTH-wiring rule) → Tasks 13, 14, 24, plus production-smoke at Wave E
- §10 (verification gates) → Task 29
- §11 (risks + mitigations) → each mitigation cited inline (e.g. conditional ALTER guards in Task 12, production-smoke after each Wave E task)

**2. Placeholder scan:** zero TBD / TODO / "implement later". Every code block contains complete code. The only spots where Task 13 says "look at lines 225–360 of bootstrap/app.php — copy verbatim" are deliberate — the source of truth is large enough that pasting it inline would inflate the plan; the engineer reads the source, applies the namespace prefix swap rule, and copies. This is acceptable per Forum Task 13 precedent (which used the same approach and shipped).

**3. Type consistency:** controller property names in Task 10 Step 5 are explicitly aligned with the source `BackstageController` style in Step 7 (deliberate decision documented). `ListProjectsStats` (not `ListProjectsStat`) used consistently across all references. `ProjectsBackstageController` (plural) matches spec §1 F4.

**4. Spec scope check:** spec describes a single coherent extraction. No need to decompose into multiple plans. ~25 tasks, comparable to Forum's 29 (no Domain-move tasks, single controller). Estimated 27–30 commits.

---

**Plan complete. Saved to `docs/superpowers/plans/2026-04-27-modular-architecture-phase1-projects.md`.**
