# Modular Architecture — Phase 1 (Members extraction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the Members + Applications + Member Activation + Public Member Profile domain from `daems-platform` core into `modules/members/` (= `dp-members` repo), following the convention proven by the Insights pilot and the Forum/Projects/Events extractions. Zero user-visible change.

**Architecture:** All Members backend code (`src/Application/Membership/`, `src/Application/Member/`, `src/Application/Backstage/{Applications,Members,DecideApplication,DismissApplication,GetApplicationDetail,ListDecidedApplications,ListPendingApplications,ChangeMemberStatus,GetMemberAudit,ListMembers,ActivateMember,ActivateSupporter}/`, 8 SQL repos under `src/Infrastructure/Adapter/Persistence/Sql/`, `ApplicationController` + `MemberController`) moves under `modules/members/backend/src/` with namespace `DaemsModule\Members\*`. **`src/Domain/Membership/`, `src/Domain/Member/`, and the membership-related interfaces under `src/Domain/Backstage/` and `src/Domain/Tenant/` STAY in core** because the core consumer `Application/Backstage/Notifications/ListNotificationsStats` references `MemberApplicationRepositoryInterface`, `SupporterApplicationRepositoryInterface`, and `AdminApplicationDismissalRepositoryInterface` — Forum lesson 1. A new single `MembersBackstageController` extracts 11 Members methods from the monolith `BackstageController`. All Members-specific bindings + 14 routes + tests + 10 members-pure migrations move with the code. Migration `045_extend_dismissals_enum_and_comment_audit.sql` is mixed scope (touches both `admin_application_dismissals` AND `project_comment_moderation_audit`) and STAYS in core. Migration `057_add_member_number_prefix_to_tenants.sql` modifies the `tenants` table and STAYS in core. The `daem-society` frontend `public/pages/members/` (5 files) + `public/pages/backstage/{members,applications}/` directories move to `modules/members/frontend/{public,backstage,assets}/`. The Insights/Forum/Projects/Events-era `ModuleRegistry` + autoloader + module-router pick everything up automatically. The special `/members/{uuid}` UUID route handler in `daem-society/public/index.php` is updated to require the module's profile page instead of being deleted.

**Tech Stack:** PHP 8.3, Composer (runtime ClassLoader), MySQL 8.4, PHPUnit 10.5, PHPStan 2.x level 9. No new external dependencies.

**Spec:** This plan acts as both spec and execution document — derived directly from the four prior extraction plans (Insights/Forum/Projects/Events) and the recon completed 2026-04-28.

**Repos affected (3 commit streams):**

- `C:\laragon\www\daems-platform\` (branch `dev`) — new `068_*` data-fix migration + autoload-dev/phpstan paths commit + Wave E removals + KernelHarness cleanup + daem-society's `/members/{uuid}` route handler updated to require module path
- `C:\laragon\www\modules\members\` = `dp-members` repo (existing local `.git`, origin `https://github.com/Turtuure/dp-members.git`, branch `dev`, EMPTY working copy + zero commits) — manifest + all moved Members code
- `C:\laragon\www\sites\daem-society\` (branch `dev`) — frontend deletes only + one route-handler-path update (module-router already in place from prior extractions)

**Verification gates (must pass before final commit on any task touching moved code):**

- `composer analyse` → 0 errors at PHPStan level 9
- `composer test` (Unit + Integration) → all green
- `composer test:e2e` → all green
- `composer test:all` → all green
- `GET /members/{uuid}` renders identical public profile to pre-move
- `GET /api/v1/members/{uuid}` returns identical JSON to pre-move
- `POST /api/v1/applications/member` and `POST /api/v1/applications/supporter` accept submissions and produce same DB rows
- All admin endpoints under `/api/v1/backstage/applications/*` and `/api/v1/backstage/members/*` work — exercise pending-list / decide / dismiss / status-change / audit / stats flows
- Backstage UI `/backstage/members` (with `?view=applications`, `?view=decided` subviews) and `/backstage/applications` render and function identically
- Notifications page `/api/v1/backstage/notifications/stats` still returns counts that include pending member/supporter applications and dismissals (cross-module consumer)
- JS console: zero `payload is undefined` errors; Network: every `/modules/members/assets/backstage/*` returns 200

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa". `.claude/` never staged (`git reset HEAD .claude/` if needed).

**dp-members push cadence:** repo accumulates commits during plan execution. Push deferred until plan completion + user confirmation.

**Forbidden:** do NOT call `mcp__code-review-graph__*` tools — they hung subagent sessions during PR 3 Task 1.

---

## Task waves (dependency order)

```text
Wave A (parallel-safe)
├── Task 1: dp-members skeleton (manifest + README + .gitignore + phpunit + composer + STUB bindings/routes)
├── Task 2: Core data-fix migration 068_*
└── Task 3: Members file inventory verification (read-only)

Wave B (sequential, depends on Wave A)
├── Task 5: Move 8 SQL repos + namespace rewrite
├── Task 6: Move 7 InMemory fakes + namespace rewrite
├── Task 7: Move public-side Application code (Membership + Member, 3 use case dirs, ~9 files)
├── Task 8: Move 12 backstage Application use case dirs (~32 files including ActivationServices)
├── Task 9: Move ApplicationController + MemberController + namespace rewrite
├── Task 9.5: NEW infra commit on daems-platform — autoload-dev + phpstan paths
├── Task 10: Extract MembersBackstageController (TDD, 11 methods)
└── Task 12: Move 10 migrations with members_NNN_* rename

Wave C (wiring, depends on Wave B)
├── Task 13: bindings.php (production) — production-container smoke after
├── Task 14: bindings.test.php (test container)
└── Task 15: routes.php (14 routes)

Wave D (test moves, depends on Wave B + C)
├── Task 17: tests/Unit/Application/Member (1 file)
├── Task 18: 17 backstage Members + Applications unit tests
├── Task 19: 8 integration tests
└── Task 20: isolation (5) + E2E (5)

Wave E (core cleanup — production-smoke after EACH task)
├── Task 21: Remove Members bindings from bootstrap/app.php
├── Task 22: Remove Members routes from routes/api.php (-14 routes)
├── Task 23: Remove 11 Member methods from BackstageController + delete ApplicationController + MemberController
├── Task 24: Remove Members bindings from KernelHarness + update cross-domain test imports (Notifications consumers)
└── Task 25: Apply 068_* data-fix migration; delete originals + 7 dangling Migration tests

Wave F (frontend, can run parallel with Wave E)
├── Task 26: Move 5 daem-society public/members/ pages + __DIR__ rewrite + update /members/{uuid} require path
├── Task 27: Move 1 daem-society backstage/members/index.php + 1 backstage/applications/index.php + 2 JS asset moves
└── Task 28: Move CSS asset (public-member-page.css) + delete originals

Wave G (verification gate)
└── Task 29: Final verification — PHPStan + composer test:all + production-smoke + git grep + browser smoke
```

**Tasks 4 (Move Domain) + 11 (Second controller) + 16 (Move Domain unit tests) explicitly DROPPED** per Forum lesson 1 + F4=A decision + no Domain Membership unit tests exist.

---

## File map summary

**Created in `daems-platform/`:**

- `database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql`

**Modified in `daems-platform/`:**

- `composer.json` (autoload-dev `DaemsModule\\Members\\` + `DaemsModule\\Members\\Tests\\`)
- `phpstan.neon` (paths += `../modules/members/backend/src`)
- `bootstrap/app.php` — remove ~16 import lines + ~30 binding lines for Members
- `routes/api.php` — remove 14 Members routes
- `tests/Support/KernelHarness.php` — remove Members bindings + InMemory fake registrations
- `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php` — update `InMemoryMemberApplicationRepository`, `InMemorySupporterApplicationRepository`, `InMemoryAdminApplicationDismissalRepository` imports to module namespace
- (any other discovered cross-domain consumers — Task 3 inventory will surface them)
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 11 Members methods

**Deleted in `daems-platform/`:**

- `src/Application/Membership/` (entire dir, 2 sub-dirs, ~6 files)
- `src/Application/Member/` (entire dir, 1 sub-dir, ~3 files)
- 12 admin sibling dirs under `src/Application/Backstage/`: `Applications/` (contains `ListApplicationsStats/`), `DecideApplication/`, `DismissApplication/`, `GetApplicationDetail/`, `ListDecidedApplications/`, `ListPendingApplications/`, `Members/` (contains `ListMembersStats/`), `ListMembers/`, `ChangeMemberStatus/`, `GetMemberAudit/`, `ActivateMember/`, `ActivateSupporter/` (~32 files)
- `src/Infrastructure/Adapter/Persistence/Sql/Sql{MemberApplication,SupporterApplication,MemberStatusAudit,MemberDirectory,PublicMember,AdminApplicationDismissal,TenantMemberCounter,TenantSupporterCounter}Repository.php` (8 files)
- `src/Infrastructure/Adapter/Api/Controller/ApplicationController.php`
- `src/Infrastructure/Adapter/Api/Controller/MemberController.php`
- `database/migrations/{004,005,028,029,033,034,035,038,040,041}_*.sql` (10 files)
- `tests/Unit/Application/Member/GetPublicMemberProfileTest.php`
- `tests/Unit/Application/Backstage/{ChangeMemberStatusTest,DecideApplicationApproveMemberTest,DecideApplicationApproveSupporterTest,DecideApplicationTest,DismissApplicationTest,GetApplicationDetailTest,GetMemberAuditTest,ListApplicationsStatsTest,ListDecidedApplicationsTest,ListMembersStatsTest,ListMembersTest,ListPendingApplicationsForAdminTest,ListPendingApplicationsTest,MemberActivationServiceTest,SupporterActivationServiceTest}.php` (15)
- `tests/Integration/{MemberActivationIntegrationTest,AdminApplicationDismissalSliceTest,MemberApplicationStatsTest,MemberStatusAuditStatsTest,SupporterApplicationStatsTest,PublicMemberProfileIntegrationTest}.php` (6)
- `tests/Integration/Persistence/Sql/{SqlMemberApplicationRepositoryTest,SqlMemberDirectoryRepositoryTest}.php` (2)
- `tests/Isolation/{ApplicationApprovalTenantIsolationTest,ApplicationsStatsTenantIsolationTest,MemberApplicationTenantIsolationTest,MembersStatsTenantIsolationTest,SupporterApplicationTenantIsolationTest}.php` (5)
- `tests/E2E/{F011_BackstageApplicationsAccessTest,F012_BackstageDecideApplicationTest,F013_BackstageMembersGsaOnlyStatusTest}.php` + `tests/E2E/Backstage/{ApplicationsStatsEndpointTest,MembersStatsEndpointTest}.php` (5)
- `tests/Support/Fake/InMemory{MemberApplication,SupporterApplication,MemberStatusAudit,MemberDirectory,AdminApplicationDismissal,TenantMemberCounter,TenantSupporterCounter}Repository.php` (7 files)
- `tests/Integration/Migration/{Migration028Test,Migration029Test,Migration033Test,Migration034Test,Migration035Test,Migration038Test,Migration040Test}.php` (7 files dangling after migration moves)

**Retained in `daems-platform/` (NOT deleted):**

- `src/Domain/Membership/` (Forum lesson 1: Domain stays in core because `ListNotificationsStats` consumes the repository interfaces)
- `src/Domain/Member/` (same reason)
- `src/Domain/Backstage/{MemberDirectoryEntry,MemberDirectoryRepositoryInterface,MemberStatusAuditEntry}.php` (DTOs/interfaces consumed by core)
- `src/Domain/Tenant/{TenantMemberCounterRepositoryInterface,TenantSupporterCounterRepositoryInterface}.php` (Tenant namespace, may be used by Tenant module later)
- `src/Application/Backstage/Notifications/*` (cross-domain consumer)
- Migrations `database/migrations/{045_extend_dismissals_enum_and_comment_audit,057_add_member_number_prefix_to_tenants}.sql` — mixed scope / tenant-table scope

**Created in `modules/members/` (dp-members):**

- `module.json`, `README.md`, `.gitignore`, `phpunit.xml.dist`, `composer.json`
- `backend/bindings.php`, `backend/bindings.test.php`, `backend/routes.php`
- `backend/migrations/members_001..010_*.sql` (10 files)
- `backend/src/Application/Membership/{SubmitMemberApplication,SubmitSupporterApplication}/*` (~6 files)
- `backend/src/Application/Member/GetPublicMemberProfile/*` (~3 files)
- `backend/src/Application/Backstage/*` (~32 files in 12 dirs)
- `backend/src/Infrastructure/Sql{...}Repository.php` (8 files)
- `backend/src/Controller/ApplicationController.php`
- `backend/src/Controller/MemberController.php`
- `backend/src/Controller/MembersBackstageController.php`
- `backend/tests/Support/InMemory{...}Repository.php` (7 files)
- `backend/tests/Unit/Application/Member/GetPublicMemberProfileTest.php` (1)
- `backend/tests/Unit/Application/Backstage/*Test.php` (15)
- `backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php` (1, TDD-created)
- `backend/tests/Integration/*Test.php` (6 + 2 = 8)
- `backend/tests/Isolation/*Test.php` (5)
- `backend/tests/E2E/*Test.php` (5)
- `frontend/public/{_layout,benefits,board-minutes,guides,profile}.php` (5)
- `frontend/backstage/index.php` (members admin) + `frontend/backstage/applications/index.php` (1 + 1 = 2)
- `frontend/assets/backstage/{applications-stats.js,members-stats.js}` (2)
- `frontend/assets/public/public-member-page.css` (1)

**Modified in `daem-society/`:**

- `public/index.php` — update line ~453-458 (UUID route handler) to require `modules/members/frontend/public/profile.php` instead of `pages/members/profile.php`

**Deleted in `daem-society/`:**

- `public/pages/members/` (entire dir, 5 files)
- `public/pages/backstage/members/` (entire dir, 3 files)
- `public/pages/backstage/applications/` (entire dir, 1 file)
- `public/assets/css/public-member-page.css`

---

## Task 1: dp-members repo skeleton

**Repo for commits:** `dp-members` (the new module). Local `.git` already initialised at `C:\laragon\www\modules\members\` with `origin = https://github.com/Turtuure/dp-members.git`, HEAD → `refs/heads/dev`. Working copy is empty, zero commits.

**Files:**

- Create: `C:/laragon/www/modules/members/module.json`
- Create: `C:/laragon/www/modules/members/README.md`
- Create: `C:/laragon/www/modules/members/.gitignore`
- Create: `C:/laragon/www/modules/members/phpunit.xml.dist`
- Create: `C:/laragon/www/modules/members/composer.json`
- Create: `C:/laragon/www/modules/members/backend/bindings.php` (STUB)
- Create: `C:/laragon/www/modules/members/backend/bindings.test.php` (STUB)
- Create: `C:/laragon/www/modules/members/backend/routes.php` (STUB)
- Create: `C:/laragon/www/modules/members/backend/migrations/.gitkeep`

- [ ] **Step 1: Verify GitHub repo exists**

Run:

```bash
gh repo view Turtuure/dp-members 2>&1 | head -3
```

If "GraphQL: Could not resolve to a Repository" appears, create:

```bash
gh repo create Turtuure/dp-members --public --description "Members module for daems-platform — extracted Phase 1" --homepage "https://daems.fi"
```

Otherwise skip.

- [ ] **Step 2: Create `module.json`**

Path: `C:/laragon/www/modules/members/module.json`

```json
{
  "name": "members",
  "version": "1.0.0",
  "description": "Members + member applications + supporter applications + member status audit + public member profile",
  "namespace": "DaemsModule\\Members\\",
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

Path: `C:/laragon/www/modules/members/README.md`

```markdown
# dp-members — Members module

Extracted from `daems-platform` Phase 1, 2026-04-28. Pattern proven by Insights pilot + Forum/Projects/Events extractions.

## Scope

- Member application submission (public form)
- Supporter application submission (public form)
- Public member profile (verified card via `/members/{uuid}`)
- Backstage applications list (pending + decided)
- Backstage application decide/dismiss workflow
- Backstage members list + status changes (suspend/reinstate/anonymise)
- Backstage member audit trail
- Application + member sparkline stats (KPI strips)
- Member activation service (called when an application is approved)

## Structure

- `module.json` — manifest read by core's `ModuleRegistry`
- `backend/src/` — PHP code under namespace `DaemsModule\Members\`
- `backend/bindings.php` — production DI bindings
- `backend/bindings.test.php` — test container bindings (InMemory fakes)
- `backend/routes.php` — public + backstage HTTP route registrations
- `backend/migrations/` — `members_NNN_*.sql` and `.php` migrations
- `frontend/public/` — `/members/{uuid}` public verification page + members section pages
- `frontend/backstage/` — `/backstage/members` + `/backstage/applications` admin pages
- `frontend/assets/` — KPI stats JS, public member page CSS

## Conventions

- PHPStan level 9 = 0 errors
- Tests use core's `KernelHarness`; per-module `bindings.test.php` swaps InMemory fakes
- Domain interfaces (`Daems\Domain\Membership\*`, `Daems\Domain\Member\*`, `Daems\Domain\Backstage\MemberDirectoryRepositoryInterface`, `Daems\Domain\Tenant\Tenant{Member,Supporter}CounterRepositoryInterface`) live in core; module binds them to module-owned implementations
- No `Co-Authored-By` in commits; identity is `Dev Team <dev@daems.fi>`

## Cross-module dependencies

- Core's `Daems\Application\Backstage\Notifications\ListNotificationsStats` injects `MemberApplicationRepositoryInterface` + `SupporterApplicationRepositoryInterface` + `AdminApplicationDismissalRepositoryInterface`. The bindings.php in this module satisfies those interfaces at runtime; core's notifications use case continues to work because Domain interfaces stay in core.
- The `daem-society` site has a special UUID route matcher at `public/index.php` that maps `/members/{uuid}` to this module's `frontend/public/profile.php`.
```

- [ ] **Step 4: Create `.gitignore`**

Path: `C:/laragon/www/modules/members/.gitignore`

```text
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

Path: `C:/laragon/www/modules/members/phpunit.xml.dist`

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

Path: `C:/laragon/www/modules/members/composer.json`

```json
{
  "name": "daems/dp-members",
  "description": "Members module for daems-platform",
  "type": "project",
  "license": "proprietary",
  "autoload": {
    "psr-4": {
      "DaemsModule\\Members\\": "backend/src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "DaemsModule\\Members\\Tests\\": "backend/tests/"
    }
  },
  "require": {
    "php": "^8.3"
  }
}
```

- [ ] **Step 7: Create STUB `backend/bindings.php`**

Path: `C:/laragon/www/modules/members/backend/bindings.php`

```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Bindings will be added in Task 13.
};
```

- [ ] **Step 8: Create STUB `backend/bindings.test.php`**

Path: `C:/laragon/www/modules/members/backend/bindings.test.php`

```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Test bindings will be added in Task 14.
};
```

- [ ] **Step 9: Create STUB `backend/routes.php`**

Path: `C:/laragon/www/modules/members/backend/routes.php`

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

Path: `C:/laragon/www/modules/members/backend/migrations/.gitkeep` (empty file)

- [ ] **Step 11: Run core tests to verify ModuleRegistry boots cleanly with empty stubs**

Run from `C:/laragon/www/daems-platform/`:

```bash
vendor/bin/phpunit --testsuite=E2E --filter=ModuleRegistry 2>&1 | tail -20
```

Expected: zero failures (it should detect `module.json` for `members` and load empty stubs without error).

- [ ] **Step 12: Commit in `dp-members`**

Run from `C:/laragon/www/modules/members/`:

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add module.json README.md .gitignore phpunit.xml.dist composer.json backend/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Skeleton: module manifest + stub bindings/routes + composer/phpunit/README"
```

Expected: one commit on local `dev` branch (becomes the first commit since repo had zero commits). Do NOT push.

---

## Task 2: Core data-fix migration 068_*

**Repo for commits:** `daems-platform`

**Why:** when 10 members migrations move to `modules/members/backend/migrations/` with new filenames (`members_001..010`), the existing `schema_migrations` table on dev/test DBs still references the OLD filenames (`004_create_member_applications_table.sql` etc.). Without a rename data-fix, the migration runner re-applies the moved migrations under their new names → duplicate tables / "already exists" errors.

**Files:**

- Create: `database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql`

- [ ] **Step 1: Create `068_*` migration with conditional, idempotent renames**

Path: `database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql`

```sql
-- 068_rename_members_migrations_in_schema_migrations_table.sql
-- Rename schema_migrations rows for members migrations that moved to modules/members/.
-- Idempotent: each UPDATE is gated by a SELECT that checks the schema_migrations table exists.
-- Safe to re-run on dev DBs that already saw the rename.

SET @smt := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_001_create_member_applications_table.sql' WHERE filename = '004_create_member_applications_table.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_002_create_supporter_applications_table.sql' WHERE filename = '005_create_supporter_applications_table.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_003_add_tenant_id_to_member_applications.sql' WHERE filename = '028_add_tenant_id_to_member_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_004_add_tenant_id_to_supporter_applications.sql' WHERE filename = '029_add_tenant_id_to_supporter_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_005_add_tenant_id_to_member_register_audit.sql' WHERE filename = '033_add_tenant_id_to_member_register_audit.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_006_add_decision_metadata_to_applications.sql' WHERE filename = '034_add_decision_metadata_to_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_007_create_member_status_audit.sql' WHERE filename = '035_create_member_status_audit.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_008_create_tenant_member_counters.sql' WHERE filename = '038_create_tenant_member_counters.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_009_create_admin_application_dismissals.sql' WHERE filename = '040_create_admin_application_dismissals.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_010_create_tenant_supporter_counters.sql' WHERE filename = '041_create_tenant_supporter_counters.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 2: Verify migration parses**

Run from `C:/laragon/www/daems-platform/`:

```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db_test < database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql
echo "Exit: $?"
```

Expected: Exit 0.

- [ ] **Step 3: Verify idempotency**

Run again — same command. Expected: Exit 0 (no-op).

- [ ] **Step 4: Commit**

Run from `C:/laragon/www/daems-platform/`:

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Migration(068): rename members migrations in schema_migrations for module move"
```

Expected: one commit on `dev`. Do NOT push.

---

## Task 3: Members file inventory verification (read-only)

**Repo for commits:** none — read-only confirmation step.

**Why:** before moving anything, freeze the exact file list this plan refers to. If any file count differs from the plan, surface it and pause.

**Files:** none modified.

- [ ] **Step 1: Verify backend file counts**

Run from `C:/laragon/www/daems-platform/`:

```bash
echo "=== Application/Membership (expect 2 dirs, ~6 files) ==="
ls -d src/Application/Membership/*/ | wc -l
find src/Application/Membership -name "*.php" | wc -l

echo "=== Application/Member (expect 1 dir, ~3 files) ==="
ls -d src/Application/Member/*/ | wc -l
find src/Application/Member -name "*.php" | wc -l

echo "=== Application/Backstage 12 admin dirs (expect ~32 files total) ==="
find src/Application/Backstage/Applications src/Application/Backstage/DecideApplication src/Application/Backstage/DismissApplication src/Application/Backstage/GetApplicationDetail src/Application/Backstage/ListDecidedApplications src/Application/Backstage/ListPendingApplications src/Application/Backstage/Members src/Application/Backstage/ListMembers src/Application/Backstage/ChangeMemberStatus src/Application/Backstage/GetMemberAudit src/Application/Backstage/ActivateMember src/Application/Backstage/ActivateSupporter -name "*.php" | wc -l

echo "=== SQL repos (expect 8) ==="
ls src/Infrastructure/Adapter/Persistence/Sql/Sql{MemberApplication,SupporterApplication,MemberStatusAudit,MemberDirectory,PublicMember,AdminApplicationDismissal,TenantMemberCounter,TenantSupporterCounter}Repository.php 2>&1 | grep -c '\.php$'

echo "=== ApplicationController + MemberController (expect 2) ==="
ls src/Infrastructure/Adapter/Api/Controller/ApplicationController.php src/Infrastructure/Adapter/Api/Controller/MemberController.php | wc -l

echo "=== Members methods in BackstageController (expect 11) ==="
grep -cE "function (pendingApplications|decidedApplications|applicationDetail|decideApplication|dismissApplication|members|changeMemberStatus|memberAudit|statsMembers|statsApplications|listPendingForAdmin)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php

echo "=== Member unit test (expect 1 in Application/Member) ==="
find tests/Unit/Application/Member -name "*.php" 2>/dev/null | wc -l

echo "=== Backstage Members + Applications unit tests (expect 15) ==="
ls tests/Unit/Application/Backstage/{ChangeMemberStatusTest,DecideApplicationApproveMemberTest,DecideApplicationApproveSupporterTest,DecideApplicationTest,DismissApplicationTest,GetApplicationDetailTest,GetMemberAuditTest,ListApplicationsStatsTest,ListDecidedApplicationsTest,ListMembersStatsTest,ListMembersTest,ListPendingApplicationsForAdminTest,ListPendingApplicationsTest,MemberActivationServiceTest,SupporterActivationServiceTest}.php 2>/dev/null | wc -l

echo "=== Members integration tests (expect 6 + 2 = 8) ==="
ls tests/Integration/{MemberActivationIntegrationTest,AdminApplicationDismissalSliceTest,MemberApplicationStatsTest,MemberStatusAuditStatsTest,SupporterApplicationStatsTest,PublicMemberProfileIntegrationTest}.php tests/Integration/Persistence/Sql/{SqlMemberApplicationRepositoryTest,SqlMemberDirectoryRepositoryTest}.php 2>/dev/null | wc -l

echo "=== Members isolation tests (expect 5) ==="
ls tests/Isolation/{ApplicationApprovalTenantIsolationTest,ApplicationsStatsTenantIsolationTest,MemberApplicationTenantIsolationTest,MembersStatsTenantIsolationTest,SupporterApplicationTenantIsolationTest}.php | wc -l

echo "=== Members E2E tests (expect 5) ==="
ls tests/E2E/F011_BackstageApplicationsAccessTest.php tests/E2E/F012_BackstageDecideApplicationTest.php tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php tests/E2E/Backstage/ApplicationsStatsEndpointTest.php tests/E2E/Backstage/MembersStatsEndpointTest.php | wc -l

echo "=== InMemory fakes (expect 7) ==="
ls tests/Support/Fake/InMemory{MemberApplication,SupporterApplication,MemberStatusAudit,MemberDirectory,AdminApplicationDismissal,TenantMemberCounter,TenantSupporterCounter}Repository.php 2>&1 | grep -c '\.php$'

echo "=== Migration tests dangling after extract (expect 7) ==="
ls tests/Integration/Migration/Migration028Test.php tests/Integration/Migration/Migration029Test.php tests/Integration/Migration/Migration033Test.php tests/Integration/Migration/Migration034Test.php tests/Integration/Migration/Migration035Test.php tests/Integration/Migration/Migration038Test.php tests/Integration/Migration/Migration040Test.php | wc -l

echo "=== Members pure migrations (expect 10) ==="
ls database/migrations/004_create_member_applications_table.sql database/migrations/005_create_supporter_applications_table.sql database/migrations/028_add_tenant_id_to_member_applications.sql database/migrations/029_add_tenant_id_to_supporter_applications.sql database/migrations/033_add_tenant_id_to_member_register_audit.sql database/migrations/034_add_decision_metadata_to_applications.sql database/migrations/035_create_member_status_audit.sql database/migrations/038_create_tenant_member_counters.sql database/migrations/040_create_admin_application_dismissals.sql database/migrations/041_create_tenant_supporter_counters.sql | wc -l

echo "=== Mixed/tenant migrations stay in core (expect 2) ==="
ls database/migrations/045_extend_dismissals_enum_and_comment_audit.sql database/migrations/057_add_member_number_prefix_to_tenants.sql | wc -l
```

Expected counts: 2 / 6 / 1 / 3 / 32 / 8 / 2 / 11 / 1 / 15 / 8 / 5 / 5 / 7 / 7 / 10 / 2.

If ANY count differs (e.g. recon found extra Backstage admin dirs not on the list, or expected 6 in Membership but ls reports 7), STOP and surface to user. Do not continue.

- [ ] **Step 2: Verify frontend file counts**

Run from `C:/laragon/www/sites/daem-society/`:

```bash
echo "=== Public members/ (expect 5 PHP) ==="
ls public/pages/members/*.php | wc -l

echo "=== Backstage members/ (expect 1 PHP + 2 JS) ==="
ls public/pages/backstage/members/*.php | wc -l
ls public/pages/backstage/members/*.js | wc -l

echo "=== Backstage applications/ (expect 1 PHP) ==="
ls public/pages/backstage/applications/*.php | wc -l

echo "=== Public member CSS (expect 1) ==="
ls public/assets/css/public-member-page.css | wc -l

echo "=== /members/{uuid} route in index.php (expect 1) ==="
grep -cE "preg_match\([^)]+/members/.*\\\\{8}" public/index.php
```

Expected: 5 / 1 / 2 / 1 / 1 / 1.

- [ ] **Step 3: Verify cross-domain core consumers (will need import updates in Wave E)**

Run from `C:/laragon/www/daems-platform/`:

```bash
grep -rln "Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryMemberApplication\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemorySupporterApplication\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryMemberStatusAudit\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryAdminApplicationDismissal\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryMemberDirectory\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryTenantMemberCounter\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemoryTenantSupporterCounter" src tests | sort -u
```

Expected: at minimum `tests/Support/KernelHarness.php` + `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php`. Note any additional files for Task 24.

- [ ] **Step 4: Inspect Backstage method line ranges**

Run from `C:/laragon/www/daems-platform/`:

```bash
grep -nE "function (pendingApplications|decidedApplications|applicationDetail|decideApplication|dismissApplication|members|changeMemberStatus|memberAudit|statsMembers|statsApplications|listPendingForAdmin)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

Capture exact line numbers — needed for Task 10 (extract) and Task 23 (delete).

- [ ] **Step 5: No commit**

Inventory step is read-only. Capture counts in session memory; proceed to Task 5 (or surface mismatch to user).

---

## Task 5: Move 8 SQL repositories

**Repo for commits:** `dp-members`

**Files:**

- Move (with namespace rewrite): `daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php` → `modules/members/backend/src/Infrastructure/SqlMemberApplicationRepository.php`
- Move: `SqlSupporterApplicationRepository.php` → same name in module
- Move: `SqlMemberStatusAuditRepository.php` → same
- Move: `SqlMemberDirectoryRepository.php` → same
- Move: `SqlPublicMemberRepository.php` → same
- Move: `SqlAdminApplicationDismissalRepository.php` → same
- Move: `SqlTenantMemberCounterRepository.php` → same
- Move: `SqlTenantSupporterCounterRepository.php` → same

- [ ] **Step 1: Copy 8 files into module**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/src/Infrastructure
for f in SqlMemberApplicationRepository SqlSupporterApplicationRepository SqlMemberStatusAuditRepository SqlMemberDirectoryRepository SqlPublicMemberRepository SqlAdminApplicationDismissalRepository SqlTenantMemberCounterRepository SqlTenantSupporterCounterRepository; do
  cp "daems-platform/src/Infrastructure/Adapter/Persistence/Sql/${f}.php" "modules/members/backend/src/Infrastructure/${f}.php"
done
echo "Files copied: $(ls modules/members/backend/src/Infrastructure/*.php | wc -l)"
```

Expected: 8.

- [ ] **Step 2: Rewrite `namespace` declaration in each**

For each of the 8 files, replace:

```text
namespace Daems\Infrastructure\Adapter\Persistence\Sql;
```

with:

```text
namespace DaemsModule\Members\Infrastructure;
```

PowerShell (run in `C:\laragon\www\modules\members\backend\src\Infrastructure\`):

```powershell
Get-ChildItem -Filter Sql*.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Infrastructure\\Adapter\\Persistence\\Sql;', 'namespace DaemsModule\Members\Infrastructure;'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Verify imports**

For each moved file, scan its `use` statements. Allowed imports (must remain `Daems\` core namespace):

- `Daems\Domain\Membership\*` (Forum lesson 1, Domain stays in core)
- `Daems\Domain\Member\*`, `Daems\Domain\Backstage\MemberDirectory*`, `Daems\Domain\Tenant\*`, `Daems\Domain\User\*`, `Daems\Domain\Locale\*`, `Daems\Domain\Shared\*`
- `Daems\Infrastructure\Framework\*` (Connection, etc.)

Run:

```bash
grep -hE "^use " modules/members/backend/src/Infrastructure/Sql*.php | sort -u
```

Expected: only `Daems\` core imports + PDO/builtin classes. NO `DaemsModule\` self-imports. NO Forum/Insights/Projects/Events imports.

- [ ] **Step 4: PHP syntax check**

```bash
for f in modules/members/backend/src/Infrastructure/Sql*.php; do
  php -l "$f"
done
```

Expected: each prints "No syntax errors detected".

- [ ] **Step 5: Commit in dp-members**

Run from `C:/laragon/www/modules/members/`:

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Infrastructure/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(infra): 8 SQL repositories with namespace rewrite"
```

**Note:** the originals in `daems-platform/src/Infrastructure/Adapter/Persistence/Sql/` are NOT yet deleted. They remain valid until Wave E (Task 21–25). Until then, both core and module copies coexist (only the module copy is autoloaded after Task 9.5 + 13).

---

## Task 6: Move 7 InMemory fakes

**Repo for commits:** `dp-members`

**Files:**

- Move: `daems-platform/tests/Support/Fake/InMemoryMemberApplicationRepository.php` → `modules/members/backend/tests/Support/InMemoryMemberApplicationRepository.php`
- Move: `InMemorySupporterApplicationRepository.php` → same name in module
- Move: `InMemoryMemberStatusAuditRepository.php` → same
- Move: `InMemoryMemberDirectoryRepository.php` → same
- Move: `InMemoryAdminApplicationDismissalRepository.php` → same
- Move: `InMemoryTenantMemberCounterRepository.php` → same
- Move: `InMemoryTenantSupporterCounterRepository.php` → same

(Note: there's no `InMemoryPublicMemberRepository.php` — `PublicMember` is read-only and has no fake; integration test `PublicMemberProfileIntegrationTest` uses real DB.)

- [ ] **Step 1: Copy 7 files**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/tests/Support
for f in InMemoryMemberApplicationRepository InMemorySupporterApplicationRepository InMemoryMemberStatusAuditRepository InMemoryMemberDirectoryRepository InMemoryAdminApplicationDismissalRepository InMemoryTenantMemberCounterRepository InMemoryTenantSupporterCounterRepository; do
  cp "daems-platform/tests/Support/Fake/${f}.php" "modules/members/backend/tests/Support/${f}.php"
done
echo "Files copied: $(ls modules/members/backend/tests/Support/InMemory*.php | wc -l)"
```

Expected: 7.

- [ ] **Step 2: Rewrite namespace per file**

In each, replace:

```text
namespace Daems\Tests\Support\Fake;
```

with:

```text
namespace DaemsModule\Members\Tests\Support;
```

PowerShell (in `C:\laragon\www\modules\members\backend\tests\Support\`):

```powershell
Get-ChildItem -Filter InMemory*.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Tests\\Support\\Fake;', 'namespace DaemsModule\Members\Tests\Support;'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Syntax check**

```bash
for f in modules/members/backend/tests/Support/InMemory*.php; do
  php -l "$f"
done
```

Expected: each "No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Support/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests-support): 7 InMemory fakes with namespace rewrite"
```

Originals stay in `daems-platform/tests/Support/Fake/` until Task 24.

---

## Task 7: Move public-side Application code (Membership + Member)

**Repo for commits:** `dp-members`

**Files (3 use case dirs, ~9 files):**

- Move: `daems-platform/src/Application/Membership/SubmitMemberApplication/` (3 files) → `modules/members/backend/src/Application/Membership/SubmitMemberApplication/`
- Move: `daems-platform/src/Application/Membership/SubmitSupporterApplication/` (3 files) → same
- Move: `daems-platform/src/Application/Member/GetPublicMemberProfile/` (3 files) → `modules/members/backend/src/Application/Member/GetPublicMemberProfile/`

- [ ] **Step 1: Copy directory trees**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/src/Application
cp -r daems-platform/src/Application/Membership modules/members/backend/src/Application/Membership
cp -r daems-platform/src/Application/Member modules/members/backend/src/Application/Member
echo "Files copied: $(find modules/members/backend/src/Application/Membership modules/members/backend/src/Application/Member -name '*.php' | wc -l)"
```

Expected: 9 files.

- [ ] **Step 2: Mass namespace rewrite — Membership**

PowerShell (in `C:\laragon\www\modules\members\backend\src\Application\Membership\`):

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Application\\Membership\\', 'namespace DaemsModule\Members\Application\Membership\'
    $c = $c -replace 'use Daems\\Application\\Membership\\', 'use DaemsModule\Members\Application\Membership\'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Mass namespace rewrite — Member**

PowerShell (in `C:\laragon\www\modules\members\backend\src\Application\Member\`):

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Application\\Member\\', 'namespace DaemsModule\Members\Application\Member\'
    $c = $c -replace 'use Daems\\Application\\Member\\', 'use DaemsModule\Members\Application\Member\'
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 4: Verify allowed imports remain (Forum lesson 1)**

`Daems\Domain\Membership\*`, `Daems\Domain\Member\*`, `Daems\Domain\Tenant\*`, `Daems\Domain\User\*`, `Daems\Domain\Locale\*`, `Daems\Domain\Shared\*`, `Daems\Application\Shared\*` are all permitted (Domain stays in core).

Run:

```bash
grep -rhE "^use Daems\\\\" modules/members/backend/src/Application/Membership modules/members/backend/src/Application/Member | sort -u
```

Inspect: all should be in {Domain, Application/Shared, Infrastructure/Framework}. NO `Daems\Application\Forum\`, `Daems\Application\Project\`, `Daems\Application\Event\`, `Daems\Application\Insight\`, etc.

- [ ] **Step 5: Syntax + PHPStan checks**

```bash
for f in $(find modules/members/backend/src/Application/Membership modules/members/backend/src/Application/Member -name '*.php'); do
  php -l "$f"
done

cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/members/backend/src/Application/Membership ../modules/members/backend/src/Application/Member 2>&1 | tail -20
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

Run from `C:/laragon/www/modules/members/`:

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Application/Membership/ backend/src/Application/Member/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(app): public-side Membership + Member use cases (9 files in 3 dirs)"
```

Originals remain in `daems-platform/src/Application/{Membership,Member}/` until Task 25.

---

## Task 8: Move 12 backstage Application use case dirs

**Repo for commits:** `dp-members`

**Files (12 dirs, ~32 files total):**

- Move: 12 directories under `daems-platform/src/Application/Backstage/` → `modules/members/backend/src/Application/Backstage/`:
  - `Applications/` (contains `ListApplicationsStats/` with 3 files)
  - `DecideApplication/` (3)
  - `DismissApplication/` (3)
  - `GetApplicationDetail/` (3)
  - `ListDecidedApplications/` (3)
  - `ListPendingApplications/` (contains `ListPendingApplications.php` + Input/Output + `ListPendingApplicationsForAdmin.php` + Input/Output = 6)
  - `Members/` (contains `ListMembersStats/` with 3 files)
  - `ListMembers/` (3)
  - `ChangeMemberStatus/` (3)
  - `GetMemberAudit/` (3)
  - `ActivateMember/` (1 file: `MemberActivationService.php`)
  - `ActivateSupporter/` (1 file: `SupporterActivationService.php`)

- [ ] **Step 1: Copy each sibling dir**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/src/Application/Backstage
for d in Applications DecideApplication DismissApplication GetApplicationDetail ListDecidedApplications ListPendingApplications Members ListMembers ChangeMemberStatus GetMemberAudit ActivateMember ActivateSupporter; do
  cp -r "daems-platform/src/Application/Backstage/$d" "modules/members/backend/src/Application/Backstage/$d"
done
echo "Files copied: $(find modules/members/backend/src/Application/Backstage -name '*.php' | wc -l)"
```

Expected: ~32 files (verify against Task 3 inventory count).

- [ ] **Step 2: Mass namespace rewrite**

PowerShell (in `C:\laragon\www\modules\members\backend\src\Application\Backstage\`):

```powershell
$useCases = @(
    'Applications\ListApplicationsStats',
    'DecideApplication',
    'DismissApplication',
    'GetApplicationDetail',
    'ListDecidedApplications',
    'ListPendingApplications',
    'Members\ListMembersStats',
    'ListMembers',
    'ChangeMemberStatus',
    'GetMemberAudit',
    'ActivateMember',
    'ActivateSupporter'
)
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $content = Get-Content $_.FullName -Raw
    foreach ($uc in $useCases) {
        $escaped = $uc -replace '\\', '\\'
        $content = $content -replace ("namespace Daems\\Application\\Backstage\\$escaped"), ("namespace DaemsModule\Members\Application\Backstage\$uc")
        $content = $content -replace ("use Daems\\Application\\Backstage\\$escaped"), ("use DaemsModule\Members\Application\Backstage\$uc")
    }
    Set-Content -Path $_.FullName -Value $content -Encoding utf8
}
```

- [ ] **Step 3: Verify imports**

```bash
grep -rhE "^(namespace|use Daems\\\\)" modules/members/backend/src/Application/Backstage/ | sort -u | head -80
```

Inspect:

- All `namespace` lines start with `DaemsModule\Members\Application\Backstage\`
- All `use Daems\` imports point to {Domain, Application/Shared, Infrastructure/Framework, Application/Membership (intra-module after Task 7)}
- NO leftover `use Daems\Application\Backstage\<MovedUseCase>\` — those should now be `DaemsModule\Members\Application\Backstage\<MovedUseCase>\`
- Cross-references between moved use cases (e.g. `DecideApplication` calling `MemberActivationService`) resolve to module namespace

- [ ] **Step 4: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/members/backend/src/Application/Backstage 2>&1 | tail -20
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Application/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(app): 12 backstage Members + Applications use-case dirs (~32 files) with namespace rewrite"
```

---

## Task 9: Move ApplicationController + MemberController

**Repo for commits:** `dp-members`

**Files:**

- Move: `daems-platform/src/Infrastructure/Adapter/Api/Controller/ApplicationController.php` → `modules/members/backend/src/Controller/ApplicationController.php`
- Move: `daems-platform/src/Infrastructure/Adapter/Api/Controller/MemberController.php` → `modules/members/backend/src/Controller/MemberController.php`

- [ ] **Step 1: Copy files**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/src/Controller
cp daems-platform/src/Infrastructure/Adapter/Api/Controller/ApplicationController.php modules/members/backend/src/Controller/ApplicationController.php
cp daems-platform/src/Infrastructure/Adapter/Api/Controller/MemberController.php modules/members/backend/src/Controller/MemberController.php
```

- [ ] **Step 2: Rewrite namespace + use statements — ApplicationController**

PowerShell:

```powershell
$file = 'C:\laragon\www\modules\members\backend\src\Controller\ApplicationController.php'
$c = Get-Content $file -Raw
$c = $c -replace 'namespace Daems\\Infrastructure\\Adapter\\Api\\Controller;', 'namespace DaemsModule\Members\Controller;'
$c = $c -replace 'use Daems\\Application\\Membership\\', 'use DaemsModule\Members\Application\Membership\'
Set-Content -Path $file -Value $c -Encoding utf8
```

- [ ] **Step 3: Rewrite namespace + use statements — MemberController**

PowerShell:

```powershell
$file = 'C:\laragon\www\modules\members\backend\src\Controller\MemberController.php'
$c = Get-Content $file -Raw
$c = $c -replace 'namespace Daems\\Infrastructure\\Adapter\\Api\\Controller;', 'namespace DaemsModule\Members\Controller;'
$c = $c -replace 'use Daems\\Application\\Member\\', 'use DaemsModule\Members\Application\Member\'
Set-Content -Path $file -Value $c -Encoding utf8
```

- [ ] **Step 4: Verify imports + syntax**

```bash
php -l modules/members/backend/src/Controller/ApplicationController.php
php -l modules/members/backend/src/Controller/MemberController.php
grep -E "^(namespace|use )" modules/members/backend/src/Controller/ApplicationController.php
grep -E "^(namespace|use )" modules/members/backend/src/Controller/MemberController.php
```

Expected: namespace `DaemsModule\Members\Controller`, all `use Daems\` imports point to Domain or Framework, all `use DaemsModule\Members\` point to module use cases.

- [ ] **Step 5: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/members/backend/src/Controller/ 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Controller/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(controller): ApplicationController + MemberController to module"
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

Capture the existing `psr-4` map structure. Insights/Forum/Projects/Events entries are the model.

- [ ] **Step 2: Add Members entries to `composer.json` autoload-dev**

Edit `C:/laragon/www/daems-platform/composer.json`. Inside `autoload-dev.psr-4`, add (alphabetical order, after `DaemsModule\\Insights` block, before `DaemsModule\\Projects`):

```json
"DaemsModule\\Members\\": "../modules/members/backend/src/",
"DaemsModule\\Members\\Tests\\": "../modules/members/backend/tests/",
```

Verify the JSON parses:

```bash
php -r "json_decode(file_get_contents('composer.json'), false, 512, JSON_THROW_ON_ERROR); echo 'OK';"
```

- [ ] **Step 3: Add module path to `phpstan.neon`**

Read current paths block:

```bash
grep -B 1 -A 15 "^\s*paths:" phpstan.neon
```

Identify the existing module path entries (Forum, Projects, Events, Insights). Add an equivalent line for Members in alphabetical order.

Edit `C:/laragon/www/daems-platform/phpstan.neon`, in the `paths:` list under `parameters:`, add:

```text
        - ../modules/members/backend/src/
```

Maintain identical indentation as the existing path entries.

- [ ] **Step 4: Run `composer dump-autoload`**

```bash
composer dump-autoload 2>&1 | tail -10
```

Expected: "Generated optimized autoload files"; no errors.

- [ ] **Step 5: Verify autoload picks up module classes**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('DaemsModule\\\\Members\\\\Infrastructure\\\\SqlMemberApplicationRepository') ? 'OK' : 'NO'; echo PHP_EOL;"
```

Expected: `OK`.

- [ ] **Step 6: PHPStan baseline still passes**

```bash
composer analyse 2>&1 | tail -10
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add composer.json composer.lock phpstan.neon
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(modules): Members autoload-dev + phpstan path"
```

---

## Task 10: Extract MembersBackstageController (TDD, 11 methods)

**Repo for commits:** `dp-members`

**Why:** the 11 Members methods currently live as methods on the monolith `BackstageController` in core. Move them into a single new module-owned `MembersBackstageController`. F4=A locked (one consolidated controller, not two).

**Files:**

- Source (read-only this task): `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`
- Create: `modules/members/backend/src/Controller/MembersBackstageController.php`
- Create: `modules/members/backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php`

### Method inventory (11 methods to extract)

| # | Method | Calls use case |
|---|---|---|
| 1 | `pendingApplications` | `ListPendingApplications` |
| 2 | `decidedApplications` | `ListDecidedApplications` |
| 3 | `applicationDetail` | `GetApplicationDetail` |
| 4 | `decideApplication` | `DecideApplication` |
| 5 | `dismissApplication` | `DismissApplication` |
| 6 | `members` | `ListMembers` |
| 7 | `changeMemberStatus` | `ChangeMemberStatus` |
| 8 | `memberAudit` | `GetMemberAudit` |
| 9 | `statsMembers` | `Backstage\Members\ListMembersStats\ListMembersStats` |
| 10 | `statsApplications` | `Backstage\Applications\ListApplicationsStats\ListApplicationsStats` |
| 11 | `listPendingForAdmin` | `ListPendingApplicationsForAdmin` |

- [ ] **Step 1: Locate source method line ranges**

Run from `C:/laragon/www/daems-platform/`:

```bash
grep -nE "function (pendingApplications|decidedApplications|applicationDetail|decideApplication|dismissApplication|members|changeMemberStatus|memberAudit|statsMembers|statsApplications|listPendingForAdmin)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

Capture line numbers. For each method, identify the closing brace by reading from the opening function line.

- [ ] **Step 2: Read each method's body in full**

Use Read tool on `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` for each method's line range. Capture:

- Full method signature (parameters, return type, visibility)
- Full method body
- Use cases referenced (these become constructor dependencies)
- Helper methods called (e.g. `requireAdminUser()`, `requireTenant()`, `acting()`) — these helpers must either be replicated in the new controller or kept as static helpers on a shared base class. Check the existing extracted controllers (`modules/projects/backend/src/Controller/ProjectsBackstageController.php` is the closest peer) for the convention.

- [ ] **Step 3: Write the failing reflection signature test FIRST**

Path: `modules/members/backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php`

```php
<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Controller;

use DaemsModule\Members\Controller\MembersBackstageController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MembersBackstageControllerSignatureTest extends TestCase
{
    private const EXPECTED_METHODS = [
        'pendingApplications',
        'decidedApplications',
        'applicationDetail',
        'decideApplication',
        'dismissApplication',
        'members',
        'changeMemberStatus',
        'memberAudit',
        'statsMembers',
        'statsApplications',
        'listPendingForAdmin',
    ];

    public function test_class_exists(): void
    {
        self::assertTrue(class_exists(MembersBackstageController::class));
    }

    public function test_has_all_expected_public_methods(): void
    {
        $rc = new ReflectionClass(MembersBackstageController::class);
        $publicMethodNames = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $rc->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        $publicMethodNames = array_filter(
            $publicMethodNames,
            static fn (string $n): bool => $n !== '__construct'
        );

        sort($publicMethodNames);
        $expected = self::EXPECTED_METHODS;
        sort($expected);

        self::assertSame($expected, array_values($publicMethodNames));
    }
}
```

- [ ] **Step 4: Run the test — expect FAIL**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php 2>&1 | tail -20
```

Expected: FAIL with "Class DaemsModule\Members\Controller\MembersBackstageController not found".

- [ ] **Step 5: Create skeleton MembersBackstageController**

Path: `modules/members/backend/src/Controller/MembersBackstageController.php`

```php
<?php

declare(strict_types=1);

namespace DaemsModule\Members\Controller;

use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetail;
use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAudit;
use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use DaemsModule\Members\Application\Backstage\ListMembers\ListMembers;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplications;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStats;

final class MembersBackstageController
{
    public function __construct(
        private readonly ListPendingApplications $listPending,
        private readonly ListDecidedApplications $listDecided,
        private readonly GetApplicationDetail $applicationDetailUseCase,
        private readonly DecideApplication $decideUseCase,
        private readonly DismissApplication $dismissUseCase,
        private readonly ListMembers $listMembers,
        private readonly ChangeMemberStatus $changeStatus,
        private readonly GetMemberAudit $auditUseCase,
        private readonly ListMembersStats $membersStats,
        private readonly ListApplicationsStats $applicationsStats,
        private readonly ListPendingApplicationsForAdmin $listPendingForAdminUseCase,
    ) {
    }

    public function pendingApplications(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function decidedApplications(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function applicationDetail(Request $request, array $params): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function decideApplication(Request $request, array $params): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function dismissApplication(Request $request, array $params): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function members(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function changeMemberStatus(Request $request, array $params): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function memberAudit(Request $request, array $params): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function statsMembers(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function statsApplications(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }

    public function listPendingForAdmin(Request $request): Response
    {
        throw new \RuntimeException('Not implemented yet — Step 7');
    }
}
```

- [ ] **Step 6: Re-run reflection test — expect PASS**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php 2>&1 | tail -20
```

Expected: 2 tests, 2 assertions, OK.

- [ ] **Step 7: Port each method body from BackstageController**

For EACH of the 11 methods, copy the body verbatim from `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php` into the corresponding stub in `MembersBackstageController.php`. Then:

(a) Replace any `$this->use_case_property` references with the renamed constructor properties (see Step 5 list).

(b) Replace any `$this->requireAdminUser()`, `$this->requireTenant()`, `$this->acting()` helper calls — read `modules/projects/backend/src/Controller/ProjectsBackstageController.php` to see how it replicates or imports these helpers. Mirror that approach exactly.

(c) Update any `use` imports — every `Daems\Application\Backstage\<MovedUseCase>` use must become `DaemsModule\Members\Application\Backstage\<MovedUseCase>`.

After porting, syntax-check:

```bash
php -l modules/members/backend/src/Controller/MembersBackstageController.php
```

- [ ] **Step 8: PHPStan check**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --level=9 --memory-limit=1G ../modules/members/backend/src/Controller/MembersBackstageController.php 2>&1 | tail -20
```

Expected: 0 errors. If `Request`/`Response` argument types differ from what the original method bodies use, fix the imports/types in MembersBackstageController.

- [ ] **Step 9: Run reflection test again**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php 2>&1 | tail -20
```

Expected: still PASS.

- [ ] **Step 10: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/src/Controller/MembersBackstageController.php backend/tests/Unit/Controller/MembersBackstageControllerSignatureTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(controller): MembersBackstageController extracted from core (TDD, 11 methods)"
```

**Note:** the 11 methods in core's `BackstageController.php` are NOT yet deleted. They remain in core until Wave E Task 23.

---

## Task 12: Move 10 migrations with members_NNN_* rename

**Repo for commits:** `dp-members`

**Files (10 migrations renamed):**

| From | To |
|---|---|
| `004_create_member_applications_table.sql` | `members_001_create_member_applications_table.sql` |
| `005_create_supporter_applications_table.sql` | `members_002_create_supporter_applications_table.sql` |
| `028_add_tenant_id_to_member_applications.sql` | `members_003_add_tenant_id_to_member_applications.sql` |
| `029_add_tenant_id_to_supporter_applications.sql` | `members_004_add_tenant_id_to_supporter_applications.sql` |
| `033_add_tenant_id_to_member_register_audit.sql` | `members_005_add_tenant_id_to_member_register_audit.sql` |
| `034_add_decision_metadata_to_applications.sql` | `members_006_add_decision_metadata_to_applications.sql` |
| `035_create_member_status_audit.sql` | `members_007_create_member_status_audit.sql` |
| `038_create_tenant_member_counters.sql` | `members_008_create_tenant_member_counters.sql` |
| `040_create_admin_application_dismissals.sql` | `members_009_create_admin_application_dismissals.sql` |
| `041_create_tenant_supporter_counters.sql` | `members_010_create_tenant_supporter_counters.sql` |

- [ ] **Step 1: Copy + rename**

Run from `C:/laragon/www/`:

```bash
mkdir -p modules/members/backend/migrations
cp daems-platform/database/migrations/004_create_member_applications_table.sql modules/members/backend/migrations/members_001_create_member_applications_table.sql
cp daems-platform/database/migrations/005_create_supporter_applications_table.sql modules/members/backend/migrations/members_002_create_supporter_applications_table.sql
cp daems-platform/database/migrations/028_add_tenant_id_to_member_applications.sql modules/members/backend/migrations/members_003_add_tenant_id_to_member_applications.sql
cp daems-platform/database/migrations/029_add_tenant_id_to_supporter_applications.sql modules/members/backend/migrations/members_004_add_tenant_id_to_supporter_applications.sql
cp daems-platform/database/migrations/033_add_tenant_id_to_member_register_audit.sql modules/members/backend/migrations/members_005_add_tenant_id_to_member_register_audit.sql
cp daems-platform/database/migrations/034_add_decision_metadata_to_applications.sql modules/members/backend/migrations/members_006_add_decision_metadata_to_applications.sql
cp daems-platform/database/migrations/035_create_member_status_audit.sql modules/members/backend/migrations/members_007_create_member_status_audit.sql
cp daems-platform/database/migrations/038_create_tenant_member_counters.sql modules/members/backend/migrations/members_008_create_tenant_member_counters.sql
cp daems-platform/database/migrations/040_create_admin_application_dismissals.sql modules/members/backend/migrations/members_009_create_admin_application_dismissals.sql
cp daems-platform/database/migrations/041_create_tenant_supporter_counters.sql modules/members/backend/migrations/members_010_create_tenant_supporter_counters.sql
```

- [ ] **Step 2: Verify file count**

```bash
ls modules/members/backend/migrations/members_*.sql | wc -l
```

Expected: 10.

- [ ] **Step 3: Verify ModuleRegistry's `migrationPaths()` picks them up**

Run from `C:/laragon/www/daems-platform/`:

```bash
php -r "
require 'vendor/autoload.php';
\$registry = new \\Daems\\Infrastructure\\Module\\ModuleRegistry();
\$registry->discover(__DIR__ . '/../modules');
foreach (\$registry->migrationPaths() as \$p) {
    echo \$p . PHP_EOL;
}
" 2>&1
```

Expected: includes `C:/laragon/www/modules/members/backend/migrations`.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/migrations/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(migrations): 10 members migrations renamed members_001..010"
```

Originals stay in `daems-platform/database/migrations/{004,005,028,029,033,034,035,038,040,041}_*.sql` until Task 25.

---

## Task 13: bindings.php (production)

**Repo for commits:** `dp-members`

**Why:** wire the moved SQL repositories + use cases + controllers into the platform's DI container so the production Kernel can resolve `MemberApplicationRepositoryInterface` etc. After this task, production endpoints use the module's classes (autoloaded since Task 9.5). The original core bindings still exist in `bootstrap/app.php` but are overridden by the module's later registration (Forum lesson 6 confirmed: module bindings registered AFTER core bindings, last-write-wins).

**Files:**

- Modify: `modules/members/backend/bindings.php`

- [ ] **Step 1: Read existing module bindings.php files for reference**

```bash
cat C:/laragon/www/modules/projects/backend/bindings.php
echo "---"
cat C:/laragon/www/modules/insights/backend/bindings.php
```

Identify the convention: `singleton` for repositories, `bind` for use cases + controllers; constructor injection wired via container.

- [ ] **Step 2: Replace stub bindings.php with full bindings**

Path: `modules/members/backend/bindings.php`

```php
<?php

declare(strict_types=1);

use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Membership\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetail;
use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAudit;
use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use DaemsModule\Members\Application\Backstage\ListMembers\ListMembers;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplications;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use DaemsModule\Members\Controller\ApplicationController;
use DaemsModule\Members\Controller\MemberController;
use DaemsModule\Members\Controller\MembersBackstageController;
use DaemsModule\Members\Infrastructure\SqlAdminApplicationDismissalRepository;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlMemberDirectoryRepository;
use DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository;
use DaemsModule\Members\Infrastructure\SqlPublicMemberRepository;
use DaemsModule\Members\Infrastructure\SqlSupporterApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlTenantMemberCounterRepository;
use DaemsModule\Members\Infrastructure\SqlTenantSupporterCounterRepository;

return static function (Container $container): void {
    // Repositories — singletons (PDO connection injected via core)
    $container->singleton(
        MemberApplicationRepositoryInterface::class,
        static fn (Container $c): SqlMemberApplicationRepository
            => new SqlMemberApplicationRepository($c->make(Connection::class))
    );
    $container->singleton(
        SupporterApplicationRepositoryInterface::class,
        static fn (Container $c): SqlSupporterApplicationRepository
            => new SqlSupporterApplicationRepository($c->make(Connection::class))
    );
    $container->singleton(
        MemberStatusAuditRepositoryInterface::class,
        static fn (Container $c): SqlMemberStatusAuditRepository
            => new SqlMemberStatusAuditRepository($c->make(Connection::class))
    );
    $container->singleton(
        MemberDirectoryRepositoryInterface::class,
        static fn (Container $c): SqlMemberDirectoryRepository
            => new SqlMemberDirectoryRepository($c->make(Connection::class))
    );
    $container->singleton(
        PublicMemberRepositoryInterface::class,
        static fn (Container $c): SqlPublicMemberRepository
            => new SqlPublicMemberRepository($c->make(Connection::class))
    );
    $container->singleton(
        AdminApplicationDismissalRepositoryInterface::class,
        static fn (Container $c): SqlAdminApplicationDismissalRepository
            => new SqlAdminApplicationDismissalRepository($c->make(Connection::class))
    );
    $container->singleton(
        TenantMemberCounterRepositoryInterface::class,
        static fn (Container $c): SqlTenantMemberCounterRepository
            => new SqlTenantMemberCounterRepository($c->make(Connection::class))
    );
    $container->singleton(
        TenantSupporterCounterRepositoryInterface::class,
        static fn (Container $c): SqlTenantSupporterCounterRepository
            => new SqlTenantSupporterCounterRepository($c->make(Connection::class))
    );

    // Activation services
    $container->bind(MemberActivationService::class);
    $container->bind(SupporterActivationService::class);

    // Use cases
    $container->bind(SubmitMemberApplication::class);
    $container->bind(SubmitSupporterApplication::class);
    $container->bind(GetPublicMemberProfile::class);
    $container->bind(ListPendingApplications::class);
    $container->bind(ListPendingApplicationsForAdmin::class);
    $container->bind(ListDecidedApplications::class);
    $container->bind(GetApplicationDetail::class);
    $container->bind(DecideApplication::class);
    $container->bind(DismissApplication::class);
    $container->bind(ListMembers::class);
    $container->bind(ChangeMemberStatus::class);
    $container->bind(GetMemberAudit::class);
    $container->bind(ListMembersStats::class);
    $container->bind(ListApplicationsStats::class);

    // Controllers
    $container->bind(ApplicationController::class);
    $container->bind(MemberController::class);
    $container->bind(MembersBackstageController::class);
};
```

**Note:** verify constructor parameter signatures by reading `MemberActivationService.php`, `DecideApplication.php`, etc. If any use case has additional non-DI-resolvable arguments (e.g. `Clock`, `IdGenerator`, `EventBus`), add explicit factory closures matching the existing core bindings. Read `daems-platform/bootstrap/app.php` Members section for reference signatures.

- [ ] **Step 3: Adjust signatures based on reading**

For each `bind(...)` line, check if a factory closure is needed (e.g. activation services may need `Clock` injected). Follow the closure pattern from `modules/projects/backend/bindings.php` for any non-trivial bindings.

- [ ] **Step 4: Container resolution smoke test**

```bash
cd C:/laragon/www/daems-platform
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$container = \$app->container();
foreach ([
    'DaemsModule\\\\Members\\\\Controller\\\\ApplicationController',
    'DaemsModule\\\\Members\\\\Controller\\\\MemberController',
    'DaemsModule\\\\Members\\\\Controller\\\\MembersBackstageController',
    'DaemsModule\\\\Members\\\\Application\\\\Membership\\\\SubmitMemberApplication\\\\SubmitMemberApplication',
    'DaemsModule\\\\Members\\\\Application\\\\Backstage\\\\DecideApplication\\\\DecideApplication',
] as \$class) {
    try { \$container->make(\$class); echo \"OK \$class\\n\"; }
    catch (\\Throwable \$e) { echo \"FAIL \$class — \" . \$e->getMessage() . \"\\n\"; }
}
"
```

Expected: every line prints `OK <class>`. If any FAIL, fix the binding (check Container's bind/singleton method signatures for parameter mismatch).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/bindings.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(bindings): production DI for 8 repos + 14 use cases + 3 controllers"
```

---

## Task 14: bindings.test.php (test container)

**Repo for commits:** `dp-members`

**Why:** the test KernelHarness constructs a separate container that swaps SQL repositories for InMemory fakes. The module's `bindings.test.php` must mirror Task 13's bindings but point repositories at the moved fakes (`DaemsModule\Members\Tests\Support\InMemory*`).

**Files:**

- Modify: `modules/members/backend/bindings.test.php`

- [ ] **Step 1: Read existing module bindings.test.php for pattern**

```bash
cat C:/laragon/www/modules/projects/backend/bindings.test.php
```

- [ ] **Step 2: Write full test bindings**

Path: `modules/members/backend/bindings.test.php`

```php
<?php

declare(strict_types=1);

use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Membership\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface;
use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetail;
use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAudit;
use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use DaemsModule\Members\Application\Backstage\ListMembers\ListMembers;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplications;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use DaemsModule\Members\Controller\ApplicationController;
use DaemsModule\Members\Controller\MemberController;
use DaemsModule\Members\Controller\MembersBackstageController;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberDirectoryRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantMemberCounterRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantSupporterCounterRepository;

return static function (Container $container): void {
    // InMemory fakes for membership repositories
    $container->singleton(
        MemberApplicationRepositoryInterface::class,
        static fn (): InMemoryMemberApplicationRepository => new InMemoryMemberApplicationRepository()
    );
    $container->singleton(
        SupporterApplicationRepositoryInterface::class,
        static fn (): InMemorySupporterApplicationRepository => new InMemorySupporterApplicationRepository()
    );
    $container->singleton(
        MemberStatusAuditRepositoryInterface::class,
        static fn (): InMemoryMemberStatusAuditRepository => new InMemoryMemberStatusAuditRepository()
    );
    $container->singleton(
        MemberDirectoryRepositoryInterface::class,
        static fn (): InMemoryMemberDirectoryRepository => new InMemoryMemberDirectoryRepository()
    );
    $container->singleton(
        AdminApplicationDismissalRepositoryInterface::class,
        static fn (): InMemoryAdminApplicationDismissalRepository => new InMemoryAdminApplicationDismissalRepository()
    );
    $container->singleton(
        TenantMemberCounterRepositoryInterface::class,
        static fn (): InMemoryTenantMemberCounterRepository => new InMemoryTenantMemberCounterRepository()
    );
    $container->singleton(
        TenantSupporterCounterRepositoryInterface::class,
        static fn (): InMemoryTenantSupporterCounterRepository => new InMemoryTenantSupporterCounterRepository()
    );

    // PublicMemberRepository has no InMemory fake — bind a no-op test stub if any test needs it,
    // otherwise let core's bindings.test.php (or a future fake added in this module) handle it.
    // For now, leave unbound; tests that need it must use IntegrationTestCase with real DB.

    // Activation services + use cases + controllers — same as production bindings
    $container->bind(MemberActivationService::class);
    $container->bind(SupporterActivationService::class);
    $container->bind(SubmitMemberApplication::class);
    $container->bind(SubmitSupporterApplication::class);
    $container->bind(GetPublicMemberProfile::class);
    $container->bind(ListPendingApplications::class);
    $container->bind(ListPendingApplicationsForAdmin::class);
    $container->bind(ListDecidedApplications::class);
    $container->bind(GetApplicationDetail::class);
    $container->bind(DecideApplication::class);
    $container->bind(DismissApplication::class);
    $container->bind(ListMembers::class);
    $container->bind(ChangeMemberStatus::class);
    $container->bind(GetMemberAudit::class);
    $container->bind(ListMembersStats::class);
    $container->bind(ListApplicationsStats::class);
    $container->bind(ApplicationController::class);
    $container->bind(MemberController::class);
    $container->bind(MembersBackstageController::class);
};
```

- [ ] **Step 3: Verify the file parses**

```bash
php -l modules/members/backend/bindings.test.php
```

Expected: "No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/bindings.test.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(bindings-test): InMemory fakes + use cases + controllers for KernelHarness"
```

---

## Task 15: routes.php (14 routes)

**Repo for commits:** `dp-members`

**Why:** wire the moved controllers into the platform Router. After this task, requests to `/api/v1/applications/member`, `/api/v1/applications/supporter`, `/api/v1/members/{id}`, and the 11 backstage Members endpoints will be served by the module's controllers — overriding the legacy core registrations from `daems-platform/routes/api.php` (last-write-wins). The legacy core routes remain in place until Wave E Task 22.

**Files:**

- Modify: `modules/members/backend/routes.php`

- [ ] **Step 1: Read existing module routes.php files for pattern**

```bash
cat C:/laragon/www/modules/projects/backend/routes.php
```

Identify middleware imports + verbs + path conventions.

- [ ] **Step 2: Read existing core routes for Members in `daems-platform/routes/api.php`**

```bash
cd C:/laragon/www/daems-platform
grep -nE "/applications/member|/applications/supporter|/members/|/backstage/applications|/backstage/members" routes/api.php
```

Capture: HTTP verb, exact path with placeholders, middleware list, controller class + method.

- [ ] **Step 3: Write the routes.php**

Path: `modules/members/backend/routes.php`

```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Router;
use DaemsModule\Members\Controller\ApplicationController;
use DaemsModule\Members\Controller\MemberController;
use DaemsModule\Members\Controller\MembersBackstageController;

return static function (Router $router, Container $container): void {
    $tenant = [TenantContextMiddleware::class];
    $admin = [TenantContextMiddleware::class, AuthMiddleware::class];

    // Public application submission
    $router->post('/api/v1/applications/member', [ApplicationController::class, 'member'], $tenant);
    $router->post('/api/v1/applications/supporter', [ApplicationController::class, 'supporter'], $tenant);

    // Public member profile (UUID → card)
    $router->get('/api/v1/members/{id}', [MemberController::class, 'getPublicProfile'], $tenant);

    // Backstage applications
    $router->get('/api/v1/backstage/applications/pending', [MembersBackstageController::class, 'pendingApplications'], $admin);
    $router->get('/api/v1/backstage/applications/decided', [MembersBackstageController::class, 'decidedApplications'], $admin);
    $router->get('/api/v1/backstage/applications/pending-count', [MembersBackstageController::class, 'listPendingForAdmin'], $admin);
    $router->get('/api/v1/backstage/applications/stats', [MembersBackstageController::class, 'statsApplications'], $admin);
    $router->get('/api/v1/backstage/applications/{type}/{id}', [MembersBackstageController::class, 'applicationDetail'], $admin);
    $router->post('/api/v1/backstage/applications/{type}/{id}/decision', [MembersBackstageController::class, 'decideApplication'], $admin);
    $router->post('/api/v1/backstage/applications/{type}/{id}/dismiss', [MembersBackstageController::class, 'dismissApplication'], $admin);

    // Backstage members
    $router->get('/api/v1/backstage/members', [MembersBackstageController::class, 'members'], $admin);
    $router->get('/api/v1/backstage/members/stats', [MembersBackstageController::class, 'statsMembers'], $admin);
    $router->post('/api/v1/backstage/members/{id}/status', [MembersBackstageController::class, 'changeMemberStatus'], $admin);
    $router->get('/api/v1/backstage/members/{id}/audit', [MembersBackstageController::class, 'memberAudit'], $admin);
};
```

**Note:** if existing core routes use a different pattern (e.g. `addRoute('GET', ...)` instead of `get(...)`), adapt accordingly. Verify by reading `modules/projects/backend/routes.php` and matching.

- [ ] **Step 4: Verify the file parses**

```bash
php -l modules/members/backend/routes.php
```

- [ ] **Step 5: Smoke test — list registered routes**

```bash
cd C:/laragon/www/daems-platform
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$router = \$app->container()->make(\\Daems\\Infrastructure\\Framework\\Http\\Router::class);
foreach (\$router->routes() as \$route) {
    if (preg_match('#/applications|/members|/backstage/applications|/backstage/members#', \$route->path())) {
        printf(\"%-6s %s -> %s::%s\\n\", \$route->method(), \$route->path(), \$route->handlerClass(), \$route->handlerMethod());
    }
}
"
```

Expected: 14 module routes printed PLUS the legacy core routes (which remain until Task 22). Both are present at this point — verify both sets exist. The router's last-write-wins ensures module routes take effect.

- [ ] **Step 6: Run test suite to verify no regressions**

```bash
cd C:/laragon/www/daems-platform
composer test:e2e 2>&1 | tail -30
```

Expected: existing E2E tests still pass (they exercise the same endpoints, now served by module controllers).

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/routes.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(routes): 14 routes — public applications/members + backstage members/applications"
```

---

## Task 17: Move Member unit test (1 file)

**Repo for commits:** `dp-members`

**Files:**

- Move: `daems-platform/tests/Unit/Application/Member/GetPublicMemberProfileTest.php` → `modules/members/backend/tests/Unit/Application/Member/GetPublicMemberProfileTest.php`

- [ ] **Step 1: Copy file**

```bash
mkdir -p modules/members/backend/tests/Unit/Application/Member
cp daems-platform/tests/Unit/Application/Member/GetPublicMemberProfileTest.php modules/members/backend/tests/Unit/Application/Member/GetPublicMemberProfileTest.php
```

- [ ] **Step 2: Rewrite namespace + imports**

PowerShell:

```powershell
$file = 'C:\laragon\www\modules\members\backend\tests\Unit\Application\Member\GetPublicMemberProfileTest.php'
$c = Get-Content $file -Raw
$c = $c -replace 'namespace Daems\\Tests\\Unit\\Application\\Member;', 'namespace DaemsModule\Members\Tests\Unit\Application\Member;'
$c = $c -replace 'use Daems\\Application\\Member\\', 'use DaemsModule\Members\Application\Member\'
$c = $c -replace 'use Daems\\Tests\\Support\\Fake\\InMemoryPublicMember', 'use DaemsModule\Members\Tests\Support\InMemoryPublicMember'
Set-Content -Path $file -Value $c -Encoding utf8
```

(Note: there's no `InMemoryPublicMemberRepository` per Task 6 — if this test uses some other test double, leave that import alone.)

- [ ] **Step 3: Run the test from module**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Unit/Application/Member/GetPublicMemberProfileTest.php 2>&1 | tail -20
```

Expected: tests pass.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Unit/Application/Member/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests-unit): GetPublicMemberProfileTest with namespace rewrite"
```

Original stays in `daems-platform/tests/Unit/Application/Member/` until Task 25.

---

## Task 18: Move 15 backstage Members + Applications unit tests

**Repo for commits:** `dp-members`

**Files (15 tests under `tests/Unit/Application/Backstage/`):**

- `ChangeMemberStatusTest.php`
- `DecideApplicationApproveMemberTest.php`
- `DecideApplicationApproveSupporterTest.php`
- `DecideApplicationTest.php`
- `DismissApplicationTest.php`
- `GetApplicationDetailTest.php`
- `GetMemberAuditTest.php`
- `ListApplicationsStatsTest.php`
- `ListDecidedApplicationsTest.php`
- `ListMembersStatsTest.php`
- `ListMembersTest.php`
- `ListPendingApplicationsForAdminTest.php`
- `ListPendingApplicationsTest.php`
- `MemberActivationServiceTest.php`
- `SupporterActivationServiceTest.php`

- [ ] **Step 1: Copy 15 files**

```bash
mkdir -p modules/members/backend/tests/Unit/Application/Backstage
for f in ChangeMemberStatusTest DecideApplicationApproveMemberTest DecideApplicationApproveSupporterTest DecideApplicationTest DismissApplicationTest GetApplicationDetailTest GetMemberAuditTest ListApplicationsStatsTest ListDecidedApplicationsTest ListMembersStatsTest ListMembersTest ListPendingApplicationsForAdminTest ListPendingApplicationsTest MemberActivationServiceTest SupporterActivationServiceTest; do
  cp "daems-platform/tests/Unit/Application/Backstage/${f}.php" "modules/members/backend/tests/Unit/Application/Backstage/${f}.php"
done
echo "Copied: $(ls modules/members/backend/tests/Unit/Application/Backstage/*.php | wc -l)"
```

Expected: 15.

- [ ] **Step 2: Mass rewrite — namespace + use statements**

PowerShell (in `C:\laragon\www\modules\members\backend\tests\Unit\Application\Backstage\`):

```powershell
$useCases = @(
    'Applications\ListApplicationsStats',
    'DecideApplication',
    'DismissApplication',
    'GetApplicationDetail',
    'ListDecidedApplications',
    'ListPendingApplications',
    'Members\ListMembersStats',
    'ListMembers',
    'ChangeMemberStatus',
    'GetMemberAudit',
    'ActivateMember',
    'ActivateSupporter'
)
$inMemoryFakes = @(
    'InMemoryMemberApplicationRepository',
    'InMemorySupporterApplicationRepository',
    'InMemoryMemberStatusAuditRepository',
    'InMemoryMemberDirectoryRepository',
    'InMemoryAdminApplicationDismissalRepository',
    'InMemoryTenantMemberCounterRepository',
    'InMemoryTenantSupporterCounterRepository'
)

Get-ChildItem -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw

    $c = $c -replace 'namespace Daems\\Tests\\Unit\\Application\\Backstage;', 'namespace DaemsModule\Members\Tests\Unit\Application\Backstage;'

    foreach ($uc in $useCases) {
        $c = $c -replace ("use Daems\\Application\\Backstage\\$uc"), ("use DaemsModule\Members\Application\Backstage\$uc")
    }

    foreach ($fake in $inMemoryFakes) {
        $c = $c -replace ("use Daems\\Tests\\Support\\Fake\\$fake"), ("use DaemsModule\Members\Tests\Support\$fake")
    }

    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Verify imports + syntax**

```bash
for f in modules/members/backend/tests/Unit/Application/Backstage/*.php; do
  php -l "$f"
done
grep -hE "^use Daems\\\\Tests" modules/members/backend/tests/Unit/Application/Backstage/*.php | sort -u
```

The second grep should return EMPTY or only show core namespaces that are still legitimately used (e.g. `Daems\Tests\Support\TenantSeed` if such a helper exists for shared test seeding — leave those alone).

- [ ] **Step 4: Run all 15 tests**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Unit/Application/Backstage/ 2>&1 | tail -30
```

Expected: all 15 test classes green.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Unit/Application/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests-unit): 15 backstage Members + Applications unit tests"
```

Originals stay in `daems-platform/tests/Unit/Application/Backstage/` until Task 25.

---

## Task 19: Move 8 integration tests

**Repo for commits:** `dp-members`

**Files (8 tests):**

From `tests/Integration/`:

- `MemberActivationIntegrationTest.php`
- `AdminApplicationDismissalSliceTest.php`
- `MemberApplicationStatsTest.php`
- `MemberStatusAuditStatsTest.php`
- `SupporterApplicationStatsTest.php`
- `PublicMemberProfileIntegrationTest.php`

From `tests/Integration/Persistence/Sql/`:

- `SqlMemberApplicationRepositoryTest.php`
- `SqlMemberDirectoryRepositoryTest.php`

- [ ] **Step 1: Copy 8 files preserving directory structure**

```bash
mkdir -p modules/members/backend/tests/Integration
mkdir -p modules/members/backend/tests/Integration/Infrastructure
for f in MemberActivationIntegrationTest AdminApplicationDismissalSliceTest MemberApplicationStatsTest MemberStatusAuditStatsTest SupporterApplicationStatsTest PublicMemberProfileIntegrationTest; do
  cp "daems-platform/tests/Integration/${f}.php" "modules/members/backend/tests/Integration/${f}.php"
done
cp daems-platform/tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php modules/members/backend/tests/Integration/Infrastructure/SqlMemberApplicationRepositoryTest.php
cp daems-platform/tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php modules/members/backend/tests/Integration/Infrastructure/SqlMemberDirectoryRepositoryTest.php
```

- [ ] **Step 2: Rewrite namespaces + imports**

PowerShell (in `C:\laragon\www\modules\members\backend\tests\Integration\`):

```powershell
$useCases = @(
    'Applications\ListApplicationsStats',
    'DecideApplication',
    'DismissApplication',
    'GetApplicationDetail',
    'ListDecidedApplications',
    'ListPendingApplications',
    'Members\ListMembersStats',
    'ListMembers',
    'ChangeMemberStatus',
    'GetMemberAudit',
    'ActivateMember',
    'ActivateSupporter'
)
$sqlRepos = @(
    'SqlMemberApplicationRepository',
    'SqlSupporterApplicationRepository',
    'SqlMemberStatusAuditRepository',
    'SqlMemberDirectoryRepository',
    'SqlPublicMemberRepository',
    'SqlAdminApplicationDismissalRepository',
    'SqlTenantMemberCounterRepository',
    'SqlTenantSupporterCounterRepository'
)

Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw

    # Top-level Integration namespace
    $c = $c -replace 'namespace Daems\\Tests\\Integration;', 'namespace DaemsModule\Members\Tests\Integration;'
    # Sub-namespaces (Persistence/Sql -> Infrastructure)
    $c = $c -replace 'namespace Daems\\Tests\\Integration\\Persistence\\Sql;', 'namespace DaemsModule\Members\Tests\Integration\Infrastructure;'

    foreach ($uc in $useCases) {
        $c = $c -replace ("use Daems\\Application\\Backstage\\$uc"), ("use DaemsModule\Members\Application\Backstage\$uc")
    }
    $c = $c -replace 'use Daems\\Application\\Membership\\', 'use DaemsModule\Members\Application\Membership\'
    $c = $c -replace 'use Daems\\Application\\Member\\', 'use DaemsModule\Members\Application\Member\'

    foreach ($repo in $sqlRepos) {
        $c = $c -replace ("use Daems\\Infrastructure\\Adapter\\Persistence\\Sql\\$repo"), ("use DaemsModule\Members\Infrastructure\$repo")
    }

    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 3: Run integration suite**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php --testsuite=Integration --filter="DaemsModule\\\\Members\\\\Tests" 2>&1 | tail -30
```

Expected: all 8 integration tests green. (Note: `MigrationTestCase` runs migrations fresh per test — slow but deterministic. Members migrations are now in `modules/members/backend/migrations/`, picked up via `ModuleRegistry::migrationPaths()`.)

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Integration/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests-integration): 8 integration tests for members"
```

---

## Task 20: Move isolation (5) + E2E (5) tests

**Repo for commits:** `dp-members`

**Files (10 tests):**

Isolation (`tests/Isolation/`):

- `ApplicationApprovalTenantIsolationTest.php`
- `ApplicationsStatsTenantIsolationTest.php`
- `MemberApplicationTenantIsolationTest.php`
- `MembersStatsTenantIsolationTest.php`
- `SupporterApplicationTenantIsolationTest.php`

E2E (`tests/E2E/`):

- `F011_BackstageApplicationsAccessTest.php`
- `F012_BackstageDecideApplicationTest.php`
- `F013_BackstageMembersGsaOnlyStatusTest.php`
- `Backstage/ApplicationsStatsEndpointTest.php`
- `Backstage/MembersStatsEndpointTest.php`

- [ ] **Step 1: Copy 5 isolation tests**

```bash
mkdir -p modules/members/backend/tests/Isolation
for f in ApplicationApprovalTenantIsolationTest ApplicationsStatsTenantIsolationTest MemberApplicationTenantIsolationTest MembersStatsTenantIsolationTest SupporterApplicationTenantIsolationTest; do
  cp "daems-platform/tests/Isolation/${f}.php" "modules/members/backend/tests/Isolation/${f}.php"
done
```

- [ ] **Step 2: Copy 5 E2E tests**

```bash
mkdir -p modules/members/backend/tests/E2E
mkdir -p modules/members/backend/tests/E2E/Backstage
cp daems-platform/tests/E2E/F011_BackstageApplicationsAccessTest.php modules/members/backend/tests/E2E/F011_BackstageApplicationsAccessTest.php
cp daems-platform/tests/E2E/F012_BackstageDecideApplicationTest.php modules/members/backend/tests/E2E/F012_BackstageDecideApplicationTest.php
cp daems-platform/tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php modules/members/backend/tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php
cp daems-platform/tests/E2E/Backstage/ApplicationsStatsEndpointTest.php modules/members/backend/tests/E2E/Backstage/ApplicationsStatsEndpointTest.php
cp daems-platform/tests/E2E/Backstage/MembersStatsEndpointTest.php modules/members/backend/tests/E2E/Backstage/MembersStatsEndpointTest.php
```

- [ ] **Step 3: Rewrite namespaces + imports for isolation**

PowerShell (in `C:\laragon\www\modules\members\backend\tests\Isolation\`):

```powershell
$useCases = @(
    'Applications\ListApplicationsStats',
    'DecideApplication',
    'DismissApplication',
    'GetApplicationDetail',
    'ListDecidedApplications',
    'ListPendingApplications',
    'Members\ListMembersStats',
    'ListMembers',
    'ChangeMemberStatus',
    'GetMemberAudit',
    'ActivateMember',
    'ActivateSupporter'
)
$inMemoryFakes = @(
    'InMemoryMemberApplicationRepository',
    'InMemorySupporterApplicationRepository',
    'InMemoryMemberStatusAuditRepository',
    'InMemoryMemberDirectoryRepository',
    'InMemoryAdminApplicationDismissalRepository',
    'InMemoryTenantMemberCounterRepository',
    'InMemoryTenantSupporterCounterRepository'
)
$sqlRepos = @(
    'SqlMemberApplicationRepository',
    'SqlSupporterApplicationRepository',
    'SqlMemberStatusAuditRepository',
    'SqlMemberDirectoryRepository',
    'SqlPublicMemberRepository',
    'SqlAdminApplicationDismissalRepository',
    'SqlTenantMemberCounterRepository',
    'SqlTenantSupporterCounterRepository'
)

Get-ChildItem -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Tests\\Isolation;', 'namespace DaemsModule\Members\Tests\Isolation;'
    foreach ($uc in $useCases) {
        $c = $c -replace ("use Daems\\Application\\Backstage\\$uc"), ("use DaemsModule\Members\Application\Backstage\$uc")
    }
    foreach ($fake in $inMemoryFakes) {
        $c = $c -replace ("use Daems\\Tests\\Support\\Fake\\$fake"), ("use DaemsModule\Members\Tests\Support\$fake")
    }
    foreach ($repo in $sqlRepos) {
        $c = $c -replace ("use Daems\\Infrastructure\\Adapter\\Persistence\\Sql\\$repo"), ("use DaemsModule\Members\Infrastructure\$repo")
    }
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 4: Rewrite namespaces + imports for E2E**

PowerShell (in `C:\laragon\www\modules\members\backend\tests\E2E\`):

```powershell
$useCases = @(
    'Applications\ListApplicationsStats',
    'DecideApplication',
    'DismissApplication',
    'GetApplicationDetail',
    'ListDecidedApplications',
    'ListPendingApplications',
    'Members\ListMembersStats',
    'ListMembers',
    'ChangeMemberStatus',
    'GetMemberAudit',
    'ActivateMember',
    'ActivateSupporter'
)
$inMemoryFakes = @(
    'InMemoryMemberApplicationRepository',
    'InMemorySupporterApplicationRepository',
    'InMemoryMemberStatusAuditRepository',
    'InMemoryMemberDirectoryRepository',
    'InMemoryAdminApplicationDismissalRepository',
    'InMemoryTenantMemberCounterRepository',
    'InMemoryTenantSupporterCounterRepository'
)

Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    $c = Get-Content $_.FullName -Raw
    $c = $c -replace 'namespace Daems\\Tests\\E2E;', 'namespace DaemsModule\Members\Tests\E2E;'
    $c = $c -replace 'namespace Daems\\Tests\\E2E\\Backstage;', 'namespace DaemsModule\Members\Tests\E2E\Backstage;'
    foreach ($uc in $useCases) {
        $c = $c -replace ("use Daems\\Application\\Backstage\\$uc"), ("use DaemsModule\Members\Application\Backstage\$uc")
    }
    foreach ($fake in $inMemoryFakes) {
        $c = $c -replace ("use Daems\\Tests\\Support\\Fake\\$fake"), ("use DaemsModule\Members\Tests\Support\$fake")
    }
    Set-Content -Path $_.FullName -Value $c -Encoding utf8
}
```

- [ ] **Step 5: Run isolation + E2E suites**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/Isolation/ 2>&1 | tail -20
vendor/bin/phpunit --bootstrap vendor/autoload.php ../modules/members/backend/tests/E2E/ 2>&1 | tail -20
```

Expected: all 5 isolation + 5 E2E tests green.

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add backend/tests/Isolation/ backend/tests/E2E/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(tests): 5 isolation + 5 E2E tests for members"
```

---

## Task 21: Remove Members bindings from bootstrap/app.php

**Repo for commits:** `daems-platform`

**Why:** core's `bootstrap/app.php` still binds the Members repositories + use cases + controllers. After Tasks 13–15, the module re-binds the same interfaces to module classes, taking effect via last-write-wins. But the duplicate bindings are dead code — they reference classes that will be deleted in Task 23/25. Remove them now to make the cleanup atomic.

**Files:**

- Modify: `bootstrap/app.php` — remove ~16 import lines + ~30 binding lines

**Production smoke gate after this task:** `php -r "require 'bootstrap/app.php'; echo 'OK';"` must print `OK`. Any unresolvable class → STOP and revert.

- [ ] **Step 1: Identify exact lines to delete**

Run from `C:/laragon/www/daems-platform/`:

```bash
grep -nE "use Daems\\\\(Application\\\\(Membership|Member|Backstage\\\\(Applications|Members|DecideApplication|DismissApplication|GetApplicationDetail|ListDecidedApplications|ListPendingApplications|ListMembers|ChangeMemberStatus|GetMemberAudit|ActivateMember|ActivateSupporter))|Domain\\\\Membership|Domain\\\\Member|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\Sql(Member|Supporter|MemberStatusAudit|MemberDirectory|PublicMember|AdminApplicationDismissal|TenantMemberCounter|TenantSupporterCounter)|Infrastructure\\\\Adapter\\\\Api\\\\Controller\\\\(Application|Member)Controller)" bootstrap/app.php
```

Capture line numbers for all matched `use` lines.

- [ ] **Step 2: Identify binding registration lines**

```bash
grep -nE "(MemberApplicationRepository|SupporterApplicationRepository|MemberStatusAuditRepository|MemberDirectoryRepository|PublicMemberRepository|AdminApplicationDismissalRepository|TenantMemberCounterRepository|TenantSupporterCounterRepository|MemberActivationService|SupporterActivationService|SubmitMemberApplication|SubmitSupporterApplication|GetPublicMemberProfile|ListPendingApplications(ForAdmin)?|ListDecidedApplications|GetApplicationDetail|DecideApplication|DismissApplication|ListMembers|ChangeMemberStatus|GetMemberAudit|ListMembersStats|ListApplicationsStats|ApplicationController|MemberController)" bootstrap/app.php | grep -vE "Notifications" | head -60
```

(The grep filters out `Notifications` so the cross-domain consumer `ListNotificationsStats` binding stays intact.)

Capture all matched lines.

- [ ] **Step 3: Delete lines using Edit tool**

Use Edit tool with `replace_all=false` to delete each binding block one at a time. For each binding, the `old_string` is the full multi-line block (e.g. the `singleton(MemberApplicationRepositoryInterface::class, ...)` chained to its closure). Replace with empty string `""`.

Critical: do NOT delete:

- Bindings for `MemberApplicationRepositoryInterface` etc. that are referenced by `Notifications\ListNotificationsStats` — wait, those ARE in the module now. So we DO delete them; the module will re-bind them (Task 13 already done).
- The `ListNotificationsStats` binding itself (it's a core use case that consumes module interfaces).

- [ ] **Step 4: Verify file syntax**

```bash
php -l bootstrap/app.php
```

Expected: "No syntax errors detected".

- [ ] **Step 5: Production smoke**

```bash
php -r "
\$app = require 'bootstrap/app.php';
\$container = \$app->container();
foreach ([
    'Daems\\\\Application\\\\Backstage\\\\Notifications\\\\ListNotificationsStats\\\\ListNotificationsStats',
    'DaemsModule\\\\Members\\\\Controller\\\\ApplicationController',
    'DaemsModule\\\\Members\\\\Controller\\\\MemberController',
    'DaemsModule\\\\Members\\\\Controller\\\\MembersBackstageController',
] as \$class) {
    try { \$container->make(\$class); echo \"OK \$class\\n\"; }
    catch (\\Throwable \$e) { echo \"FAIL \$class — \" . \$e->getMessage() . \"\\n\"; }
}
"
```

Expected: all four print `OK`.

If `ListNotificationsStats` fails with "interface MemberApplicationRepositoryInterface not bound" — the module's `bindings.php` did not run before resolution. Investigate ModuleRegistry boot order in `bootstrap/app.php`. Module bindings MUST register after core bindings but before the request is handled.

- [ ] **Step 6: Run full test suite**

```bash
composer test:all 2>&1 | tail -30
```

Expected: 0 failures.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add bootstrap/app.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(core): Members bindings from bootstrap/app.php — module owns them"
```

---

## Task 22: Remove Members routes from routes/api.php

**Repo for commits:** `daems-platform`

**Why:** core's `routes/api.php` still registers the 14 Members routes against core controllers. The module's `routes.php` overrode these via last-write-wins (Task 15), but the duplicate registrations point to controllers that will be deleted in Task 23. Remove now.

**Files:**

- Modify: `routes/api.php` — remove ~14 route registrations

- [ ] **Step 1: Identify route lines**

```bash
cd C:/laragon/www/daems-platform
grep -nE "/applications/member|/applications/supporter|/api/v1/members/\\{|/backstage/applications|/backstage/members" routes/api.php
```

Capture line numbers for all matches. Verify they correspond to the 14 routes the module took over (Task 15 list).

- [ ] **Step 2: Delete route lines using Edit tool**

For each route registration block (typically 1–3 lines per route in `routes/api.php`), use Edit to remove it. Maintain surrounding `// comment` lines or empty lines as appropriate to keep the file readable.

- [ ] **Step 3: Verify file syntax**

```bash
php -l routes/api.php
```

- [ ] **Step 4: Verify routes still resolve**

```bash
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$router = \$app->container()->make(\\Daems\\Infrastructure\\Framework\\Http\\Router::class);
\$count = 0;
foreach (\$router->routes() as \$route) {
    if (preg_match('#/applications/(member|supporter)\$|/api/v1/members/\\{|/backstage/applications|/backstage/members#', \$route->path())) {
        printf(\"%-6s %s -> %s\\n\", \$route->method(), \$route->path(), \$route->handlerClass());
        \$count++;
    }
}
echo \"Total members routes: \$count\\n\";
"
```

Expected: 14 routes, all pointing to `DaemsModule\Members\Controller\*` classes.

- [ ] **Step 5: Run full test suite**

```bash
composer test:all 2>&1 | tail -30
```

Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(core): 14 Members routes from routes/api.php — module owns them"
```

---

## Task 23: Remove 11 Member methods from BackstageController + delete ApplicationController + MemberController

**Repo for commits:** `daems-platform`

**Why:** the 11 Members methods on `BackstageController` are no longer reachable via routes (Task 22). Delete them. Also delete the now-unused `ApplicationController` and `MemberController` (the module owns the new copies). After this task, core has no Members controller code.

**Files:**

- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 11 methods
- Delete: `src/Infrastructure/Adapter/Api/Controller/ApplicationController.php`
- Delete: `src/Infrastructure/Adapter/Api/Controller/MemberController.php`

- [ ] **Step 1: Locate the 11 methods**

```bash
cd C:/laragon/www/daems-platform
grep -nE "function (pendingApplications|decidedApplications|applicationDetail|decideApplication|dismissApplication|members|changeMemberStatus|memberAudit|statsMembers|statsApplications|listPendingForAdmin)\(" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```

For each, identify the closing brace line (read the file to find the matching `}` at the right indentation level).

- [ ] **Step 2: Delete each method via Edit tool**

For each of the 11 methods, use Edit with `replace_all=false`:

- `old_string` = the complete method (signature + body + closing brace), including the blank line before it if present
- `new_string` = empty `""`

After all 11 deletions, verify `BackstageController.php` still has matching `{` and `}` counts.

- [ ] **Step 3: Identify constructor dependencies that became unused**

After removing the 11 methods, the BackstageController constructor may inject use cases that are no longer referenced. Read the constructor; for any property that's no longer used in any remaining method, remove it from the constructor signature. Remove the corresponding `use` import at the top of the file.

```bash
grep -E "private readonly.*\\\$" src/Infrastructure/Adapter/Api/Controller/BackstageController.php | head -30
```

Cross-check: does the property name appear anywhere else in the file?

```bash
grep -c "this->listPending" src/Infrastructure/Adapter/Api/Controller/BackstageController.php
# zero hits → safe to remove that property
```

- [ ] **Step 4: Delete ApplicationController.php and MemberController.php**

```bash
rm src/Infrastructure/Adapter/Api/Controller/ApplicationController.php
rm src/Infrastructure/Adapter/Api/Controller/MemberController.php
```

- [ ] **Step 5: Syntax + PHPStan**

```bash
php -l src/Infrastructure/Adapter/Api/Controller/BackstageController.php
composer analyse 2>&1 | tail -10
```

Expected: 0 syntax errors, 0 PHPStan errors.

- [ ] **Step 6: Run full test suite**

```bash
composer test:all 2>&1 | tail -30
```

Expected: 0 failures.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -u src/Infrastructure/Adapter/Api/Controller/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(core): 11 members methods from BackstageController + delete ApplicationController + MemberController"
```

---

## Task 24: Remove Members bindings from KernelHarness + update cross-domain test imports

**Repo for commits:** `daems-platform`

**Why:** `tests/Support/KernelHarness.php` (the test container) still binds `MemberApplicationRepositoryInterface` etc. to core's InMemory fakes. The fakes are now in the module (`DaemsModule\Members\Tests\Support\InMemory*`). The cross-domain consumer test `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php` imports the OLD fake namespace — update its imports to the module namespace.

**Files:**

- Modify: `tests/Support/KernelHarness.php` — remove Members bindings
- Modify: `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php` — update fake imports
- (any other files surfaced in Task 3 Step 3 — apply same import updates)

- [ ] **Step 1: Identify Members bindings in KernelHarness**

```bash
cd C:/laragon/www/daems-platform
grep -nE "(InMemoryMember|InMemorySupporter|InMemoryAdminApplicationDismissal|InMemoryTenantMember|InMemoryTenantSupporter)" tests/Support/KernelHarness.php
```

Capture line ranges of binding closures + property declarations + use imports.

- [ ] **Step 2: Identify property accessors used by cross-domain tests**

```bash
grep -E "(memberApps|supporterApps|memberStatusAudit|memberDirectory|dismissals|memberCounters|supporterCounters)" tests/Support/KernelHarness.php | head -20
```

If any KernelHarness public property like `$harness->memberApps` is referenced by tests OUTSIDE of `tests/Unit/Application/Backstage/Notifications`, leave that property AS A PROPERTY but change its type hint to the new module namespace. The property itself must stay accessible to whichever core test still uses it.

For Members-specific tests, the property usages have already been migrated to module tests (Tasks 18–20). Verify no core tests still reference them:

```bash
grep -rln "harness->memberApps\|harness->supporterApps\|harness->memberStatusAudit\|harness->memberDirectory\|harness->dismissals\|harness->memberCounters\|harness->supporterCounters" tests
```

Expected: only `Notifications/ListNotificationsStatsTest.php` if any.

- [ ] **Step 3: Update cross-domain ListNotificationsStatsTest imports**

Edit `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php`:

Replace:

```text
use Daems\Tests\Support\Fake\InMemoryMemberApplicationRepository;
use Daems\Tests\Support\Fake\InMemorySupporterApplicationRepository;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
```

with:

```text
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
```

PowerShell:

```powershell
$file = 'C:\laragon\www\daems-platform\tests\Unit\Application\Backstage\ListNotificationsStatsTest.php'
$c = Get-Content $file -Raw
foreach ($f in @('InMemoryMemberApplicationRepository','InMemorySupporterApplicationRepository','InMemoryAdminApplicationDismissalRepository')) {
    $c = $c -replace ("use Daems\\Tests\\Support\\Fake\\$f"), ("use DaemsModule\Members\Tests\Support\$f")
}
Set-Content -Path $file -Value $c -Encoding utf8
```

- [ ] **Step 4: Update KernelHarness imports + bindings**

Edit `tests/Support/KernelHarness.php`:

(a) Replace the `use Daems\Tests\Support\Fake\InMemory{...}` imports for the 7 Members fakes with their module-namespace equivalents:

```text
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberDirectoryRepository;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantMemberCounterRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantSupporterCounterRepository;
```

(b) Remove the binding closures that registered these repositories with the test container — the module's `bindings.test.php` (Task 14) will register them when ModuleRegistry runs in test mode.

(c) For any `$harness->memberApps` etc. properties that core tests still access, KEEP them but change their property type to the module namespace. Override the assignment to fetch from the container:

```php
$this->memberApps = $container->make(MemberApplicationRepositoryInterface::class);
```

If unsure how KernelHarness builds the test container, read it end-to-end first.

- [ ] **Step 5: Verify KernelHarness still resolves**

```bash
php -r "
require 'vendor/autoload.php';
\$harness = new \\Daems\\Tests\\Support\\KernelHarness();
\$container = \$harness->container();
foreach ([
    'Daems\\\\Domain\\\\Membership\\\\MemberApplicationRepositoryInterface',
    'Daems\\\\Domain\\\\Membership\\\\SupporterApplicationRepositoryInterface',
    'Daems\\\\Domain\\\\Membership\\\\AdminApplicationDismissalRepositoryInterface',
    'Daems\\\\Application\\\\Backstage\\\\Notifications\\\\ListNotificationsStats\\\\ListNotificationsStats',
] as \$class) {
    try { \$obj = \$container->make(\$class); echo \"OK \$class — \" . get_class(\$obj) . \"\\n\"; }
    catch (\\Throwable \$e) { echo \"FAIL \$class — \" . \$e->getMessage() . \"\\n\"; }
}
"
```

Expected: 4 lines, each starting `OK`, with the InMemory fake class names being `DaemsModule\Members\Tests\Support\InMemory*` (NOT `Daems\Tests\Support\Fake\InMemory*`).

- [ ] **Step 6: Run full test suite**

```bash
composer test:all 2>&1 | tail -30
```

Expected: 0 failures. Particularly: `ListNotificationsStatsTest` continues to pass with module-namespace fakes.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add tests/Support/KernelHarness.php tests/Unit/Application/Backstage/ListNotificationsStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(tests-harness): Members bindings from KernelHarness; update Notifications cross-domain imports"
```

---

## Task 25: Apply 068_* data-fix; delete originals + 7 dangling Migration tests

**Repo for commits:** `daems-platform`

**Why:** the 10 members migrations now live in `modules/members/backend/migrations/` with new names. The originals in `database/migrations/` are still on disk; the migration runner would re-apply them OR (worse) the moved copies would re-apply because `schema_migrations` rows still hold old filenames. Apply the 068 data-fix to rename `schema_migrations` rows on dev/test DBs; then delete originals + the 7 Migration tests that exercise migrations now living in the module.

**Files:**

- Run: `database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql` against `daems_db` and `daems_db_test`
- Delete: 10 migration originals
- Delete: 7 Migration test files

- [ ] **Step 1: Apply 068 to both dev DBs**

```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db < database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe --user=root --password=salasana --host=127.0.0.1 daems_db_test < database/migrations/068_rename_members_migrations_in_schema_migrations_table.sql
```

- [ ] **Step 2: Delete 10 migration originals**

```bash
rm database/migrations/004_create_member_applications_table.sql
rm database/migrations/005_create_supporter_applications_table.sql
rm database/migrations/028_add_tenant_id_to_member_applications.sql
rm database/migrations/029_add_tenant_id_to_supporter_applications.sql
rm database/migrations/033_add_tenant_id_to_member_register_audit.sql
rm database/migrations/034_add_decision_metadata_to_applications.sql
rm database/migrations/035_create_member_status_audit.sql
rm database/migrations/038_create_tenant_member_counters.sql
rm database/migrations/040_create_admin_application_dismissals.sql
rm database/migrations/041_create_tenant_supporter_counters.sql
```

- [ ] **Step 3: Delete 7 dangling Migration tests**

```bash
rm tests/Integration/Migration/Migration028Test.php
rm tests/Integration/Migration/Migration029Test.php
rm tests/Integration/Migration/Migration033Test.php
rm tests/Integration/Migration/Migration034Test.php
rm tests/Integration/Migration/Migration035Test.php
rm tests/Integration/Migration/Migration038Test.php
rm tests/Integration/Migration/Migration040Test.php
```

- [ ] **Step 4: Delete remaining core Members source code (the originals from Tasks 5–9)**

```bash
# SQL repos
rm src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlSupporterApplicationRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlMemberStatusAuditRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlMemberDirectoryRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlPublicMemberRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlAdminApplicationDismissalRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlTenantMemberCounterRepository.php
rm src/Infrastructure/Adapter/Persistence/Sql/SqlTenantSupporterCounterRepository.php

# Application use cases (Membership + Member + 12 Backstage dirs)
rm -rf src/Application/Membership
rm -rf src/Application/Member
rm -rf src/Application/Backstage/Applications
rm -rf src/Application/Backstage/DecideApplication
rm -rf src/Application/Backstage/DismissApplication
rm -rf src/Application/Backstage/GetApplicationDetail
rm -rf src/Application/Backstage/ListDecidedApplications
rm -rf src/Application/Backstage/ListPendingApplications
rm -rf src/Application/Backstage/Members
rm -rf src/Application/Backstage/ListMembers
rm -rf src/Application/Backstage/ChangeMemberStatus
rm -rf src/Application/Backstage/GetMemberAudit
rm -rf src/Application/Backstage/ActivateMember
rm -rf src/Application/Backstage/ActivateSupporter

# InMemory fakes
rm tests/Support/Fake/InMemoryMemberApplicationRepository.php
rm tests/Support/Fake/InMemorySupporterApplicationRepository.php
rm tests/Support/Fake/InMemoryMemberStatusAuditRepository.php
rm tests/Support/Fake/InMemoryMemberDirectoryRepository.php
rm tests/Support/Fake/InMemoryAdminApplicationDismissalRepository.php
rm tests/Support/Fake/InMemoryTenantMemberCounterRepository.php
rm tests/Support/Fake/InMemoryTenantSupporterCounterRepository.php

# Test files (15 unit + 1 Member unit + 8 integration + 5 isolation + 5 E2E = 34)
rm tests/Unit/Application/Member/GetPublicMemberProfileTest.php
rmdir tests/Unit/Application/Member 2>/dev/null
rm tests/Unit/Application/Backstage/ChangeMemberStatusTest.php
rm tests/Unit/Application/Backstage/DecideApplicationApproveMemberTest.php
rm tests/Unit/Application/Backstage/DecideApplicationApproveSupporterTest.php
rm tests/Unit/Application/Backstage/DecideApplicationTest.php
rm tests/Unit/Application/Backstage/DismissApplicationTest.php
rm tests/Unit/Application/Backstage/GetApplicationDetailTest.php
rm tests/Unit/Application/Backstage/GetMemberAuditTest.php
rm tests/Unit/Application/Backstage/ListApplicationsStatsTest.php
rm tests/Unit/Application/Backstage/ListDecidedApplicationsTest.php
rm tests/Unit/Application/Backstage/ListMembersStatsTest.php
rm tests/Unit/Application/Backstage/ListMembersTest.php
rm tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php
rm tests/Unit/Application/Backstage/ListPendingApplicationsTest.php
rm tests/Unit/Application/Backstage/MemberActivationServiceTest.php
rm tests/Unit/Application/Backstage/SupporterActivationServiceTest.php
rm tests/Integration/MemberActivationIntegrationTest.php
rm tests/Integration/AdminApplicationDismissalSliceTest.php
rm tests/Integration/MemberApplicationStatsTest.php
rm tests/Integration/MemberStatusAuditStatsTest.php
rm tests/Integration/SupporterApplicationStatsTest.php
rm tests/Integration/PublicMemberProfileIntegrationTest.php
rm tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php
rm tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php
rm tests/Isolation/ApplicationApprovalTenantIsolationTest.php
rm tests/Isolation/ApplicationsStatsTenantIsolationTest.php
rm tests/Isolation/MemberApplicationTenantIsolationTest.php
rm tests/Isolation/MembersStatsTenantIsolationTest.php
rm tests/Isolation/SupporterApplicationTenantIsolationTest.php
rm tests/E2E/F011_BackstageApplicationsAccessTest.php
rm tests/E2E/F012_BackstageDecideApplicationTest.php
rm tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php
rm tests/E2E/Backstage/ApplicationsStatsEndpointTest.php
rm tests/E2E/Backstage/MembersStatsEndpointTest.php
```

- [ ] **Step 5: Verify no broken imports remain in core**

```bash
grep -rln "Daems\\\\Application\\\\Membership\|Daems\\\\Application\\\\Member\\\\GetPublicMemberProfile\|Daems\\\\Application\\\\Backstage\\\\\\(DecideApplication\|DismissApplication\|GetApplicationDetail\|ListDecidedApplications\|ListPendingApplications\|ListMembers\|ChangeMemberStatus\|GetMemberAudit\|ActivateMember\|ActivateSupporter\|Applications\|Members\\\\)\|Daems\\\\Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\Sql\\(MemberApplication\|SupporterApplication\|MemberStatusAudit\|MemberDirectory\|PublicMember\|AdminApplicationDismissal\|TenantMemberCounter\|TenantSupporterCounter\\)Repository\|Daems\\\\Tests\\\\Support\\\\Fake\\\\InMemory\\(MemberApplication\|SupporterApplication\|MemberStatusAudit\|MemberDirectory\|AdminApplicationDismissal\|TenantMemberCounter\|TenantSupporterCounter\\)Repository" src tests
```

Expected: NO matches in `src/`. In `tests/` only `Daems\Domain\` references should remain (interfaces stay in core). If any `Daems\Application\Backstage\<MovedUseCase>` reference remains, fix or delete the offending file.

- [ ] **Step 6: PHPStan + tests**

```bash
composer analyse 2>&1 | tail -10
composer test:all 2>&1 | tail -30
```

Expected: 0 PHPStan errors, all tests green.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -A src tests database
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(core): legacy Members code — module owns it now"
```

---

## Task 26: Move 5 daem-society public/members/ pages + update /members/{uuid} require path

**Repo for commits:** `daem-society` (1 commit) + `dp-members` (1 commit)

**Why:** the public-facing members section (5 PHP files: `_layout.php`, `benefits.php`, `board-minutes.php`, `guides.php`, `profile.php`) currently lives under `sites/daem-society/public/pages/members/`. Move into the module's `frontend/public/`. The module-router already maps `/members/*` to `modules/members/frontend/public/*` for non-UUID paths. The special `/members/{uuid}` regex matcher in `daem-society/public/index.php` (currently requires `pages/members/profile.php`) needs its require path updated to the module's location.

**Files:**

- Move (with `__DIR__` rewrite): 5 files from `daem-society/public/pages/members/` to `modules/members/frontend/public/`
- Modify: `daem-society/public/index.php` — update the `/members/{uuid}` route handler's require path

- [ ] **Step 1: Copy 5 files**

```bash
mkdir -p C:/laragon/www/modules/members/frontend/public
for f in _layout.php benefits.php board-minutes.php guides.php profile.php; do
  cp "C:/laragon/www/sites/daem-society/public/pages/members/$f" "C:/laragon/www/modules/members/frontend/public/$f"
done
```

- [ ] **Step 2: Inspect each file for `__DIR__` and `require_once` paths**

For each of the 5 files, the original used `require_once __DIR__ . '/../../../src/...'` style paths to reach daem-society's helpers. After the move, the new `__DIR__` is `C:/laragon/www/modules/members/frontend/public/`. Original `__DIR__` was `C:/laragon/www/sites/daem-society/public/pages/members/`.

The relative-path delta is:

- From `sites/daem-society/public/pages/members/` to `sites/daem-society/public/` was `../../`.
- From `modules/members/frontend/public/` to `sites/daem-society/public/` is `../../../sites/daem-society/public/`.
- So `../../../src/Foo` (which meant `sites/daem-society/src/Foo`) becomes `../../../sites/daem-society/src/Foo`.

Run:

```bash
grep -n "__DIR__\|require\|include" C:/laragon/www/modules/members/frontend/public/*.php
```

For each match, update the path so it points to the same target file. Use Edit tool per file.

Example: if `profile.php` had `require_once __DIR__ . '/../../../src/MemberNumberFormatter.php';`, change to `require_once __DIR__ . '/../../../sites/daem-society/src/MemberNumberFormatter.php';`.

If a helper is referenced only by Members module pages and lives in `daem-society/src/`, consider whether to also move it into `modules/members/backend/src/` — but for now, leave such helpers in daem-society and update the path. Document the dependency in the module's README.

- [ ] **Step 3: Update daem-society's `/members/{uuid}` route handler**

In `C:/laragon/www/sites/daem-society/public/index.php`, find the special UUID matcher (around line 453–458 per recon):

```php
if (preg_match('#^/members/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#', $uri, $m)) {
    $__memberId = $m[1];
    require __DIR__ . '/pages/members/profile.php';
    exit;
}
```

Edit to require the module's profile page:

```php
if (preg_match('#^/members/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#', $uri, $m)) {
    $__memberId = $m[1];
    require __DIR__ . '/../../../modules/members/frontend/public/profile.php';
    exit;
}
```

- [ ] **Step 4: Delete originals from daem-society**

```bash
rm -r C:/laragon/www/sites/daem-society/public/pages/members
```

- [ ] **Step 5: Manual smoke (browser)**

Open `http://daem-society.local/members/{uuid-of-existing-member}` — should render the public profile card identical to pre-move.

Open `http://daem-society.local/members` — should resolve via the module-router fallback (it will look for `modules/members/frontend/public/index.php`. If `_layout.php` is the index, the module-router may not find it; verify current behavior with the existing layout/file structure).

If `/members` 404s after the move, options:
(a) Rename `_layout.php` → `index.php` in module
(b) Add a thin `index.php` in module that requires `_layout.php`
(c) If `/members` was never a real public landing URL (only used as include from other pages), leave it 404.

Decide based on whether `/members` is reachable currently.

- [ ] **Step 6: Commit in dp-members**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/public/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend-public): 5 members section pages from daem-society"
```

- [ ] **Step 7: Commit in daem-society**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add public/index.php public/pages/members/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(members): 5 pages to modules/members; update /members/{uuid} require path"
```

---

## Task 27: Move backstage members + applications pages + 2 JS assets

**Repo for commits:** `daem-society` (1 commit) + `dp-members` (1 commit)

**Files:**

- Move: `daem-society/public/pages/backstage/members/index.php` → `modules/members/frontend/backstage/index.php`
- Move: `daem-society/public/pages/backstage/members/applications-stats.js` → `modules/members/frontend/assets/backstage/applications-stats.js`
- Move: `daem-society/public/pages/backstage/members/members-stats.js` → `modules/members/frontend/assets/backstage/members-stats.js`
- Move: `daem-society/public/pages/backstage/applications/index.php` → `modules/members/frontend/backstage/applications/index.php`

- [ ] **Step 1: Copy files**

```bash
mkdir -p C:/laragon/www/modules/members/frontend/backstage
mkdir -p C:/laragon/www/modules/members/frontend/backstage/applications
mkdir -p C:/laragon/www/modules/members/frontend/assets/backstage
cp C:/laragon/www/sites/daem-society/public/pages/backstage/members/index.php C:/laragon/www/modules/members/frontend/backstage/index.php
cp C:/laragon/www/sites/daem-society/public/pages/backstage/members/applications-stats.js C:/laragon/www/modules/members/frontend/assets/backstage/applications-stats.js
cp C:/laragon/www/sites/daem-society/public/pages/backstage/members/members-stats.js C:/laragon/www/modules/members/frontend/assets/backstage/members-stats.js
cp C:/laragon/www/sites/daem-society/public/pages/backstage/applications/index.php C:/laragon/www/modules/members/frontend/backstage/applications/index.php
```

- [ ] **Step 2: Update `__DIR__` and asset URLs in PHP files**

For both `frontend/backstage/index.php` and `frontend/backstage/applications/index.php`:

(a) `__DIR__` paths to daem-society chrome (e.g. headers, footers, auth includes) — original was `daem-society/public/pages/backstage/{members,applications}/`, now `modules/members/frontend/backstage[/applications]/`. The relative path back to `daem-society/public/` changes from `../../` to `../../../../sites/daem-society/public/`. Update all `require_once __DIR__ . '/../../partials/...'` style includes accordingly.

(b) `<script>` and `<link>` tags pointing to `applications-stats.js` and `members-stats.js`: change from `/pages/backstage/members/applications-stats.js` to `/modules/members/assets/backstage/applications-stats.js`. Same for members-stats.js.

Run:

```bash
grep -nE "applications-stats\\.js|members-stats\\.js|require_once|require_once" C:/laragon/www/modules/members/frontend/backstage/index.php C:/laragon/www/modules/members/frontend/backstage/applications/index.php
```

For each match, edit the path. Use Edit tool per file.

- [ ] **Step 3: Delete originals from daem-society**

```bash
rm -r C:/laragon/www/sites/daem-society/public/pages/backstage/members
rm -r C:/laragon/www/sites/daem-society/public/pages/backstage/applications
```

- [ ] **Step 4: Browser smoke**

Open `http://daem-society.local/backstage/members` — should render identical to pre-move (members list, KPI strip, applications subview if `?view=applications`). KPI sparklines must populate (means `members-stats.js` is being served from `/modules/members/assets/backstage/members-stats.js` and the API endpoint works).

Open `http://daem-society.local/backstage/applications` — should render identical applications page.

Open browser DevTools Network tab. Verify:

- `GET /modules/members/assets/backstage/applications-stats.js` → 200
- `GET /modules/members/assets/backstage/members-stats.js` → 200
- `GET /api/v1/backstage/applications/stats` → 200 with sparkline data
- `GET /api/v1/backstage/members/stats` → 200 with sparkline data

- [ ] **Step 5: Commit in dp-members**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/backstage/ frontend/assets/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(frontend-backstage): members + applications admin pages + 2 stats JS assets"
```

- [ ] **Step 6: Commit in daem-society**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -u public/pages/backstage
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(backstage): members + applications pages — module owns them"
```

---

## Task 28: Move public-member-page.css + delete original

**Repo for commits:** `daem-society` (1 commit) + `dp-members` (1 commit)

**Files:**

- Move: `daem-society/public/assets/css/public-member-page.css` → `modules/members/frontend/assets/public/public-member-page.css`

- [ ] **Step 1: Copy CSS file**

```bash
mkdir -p C:/laragon/www/modules/members/frontend/assets/public
cp C:/laragon/www/sites/daem-society/public/assets/css/public-member-page.css C:/laragon/www/modules/members/frontend/assets/public/public-member-page.css
```

- [ ] **Step 2: Update CSS reference in profile.php**

Open `C:/laragon/www/modules/members/frontend/public/profile.php`. Find:

```html
<link rel="stylesheet" href="/assets/css/public-member-page.css">
```

Replace with:

```html
<link rel="stylesheet" href="/modules/members/assets/public/public-member-page.css">
```

(The module-router serves `/modules/<name>/assets/<rel>` from `modules/<name>/frontend/assets/<rel>` — verify this matches the daem-society routing already in place by reading `daem-society/public/index.php` lines ~124–141.)

- [ ] **Step 3: Delete original CSS from daem-society**

```bash
rm C:/laragon/www/sites/daem-society/public/assets/css/public-member-page.css
```

- [ ] **Step 4: Browser smoke**

Open `http://daem-society.local/members/{uuid}` — verify the public profile card renders with full styling (avatar, role badge, joined date). Open DevTools Network: `GET /modules/members/assets/public/public-member-page.css` → 200.

- [ ] **Step 5: Commit in dp-members**

```bash
cd C:/laragon/www/modules/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add frontend/assets/public/ frontend/public/profile.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Move(assets-public): public-member-page.css + update profile.php reference"
```

- [ ] **Step 6: Commit in daem-society**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add -u public/assets/css/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(assets): public-member-page.css — module owns it"
```

---

## Task 29: Final verification gate

**Repo for commits:** none — verification only.

**Why:** before declaring the extraction complete, exhaustively verify that no Members code remains in core, all tests pass, the module's public surface matches, and browser smoke succeeds across every member-related URL.

**Files:** none modified.

- [ ] **Step 1: PHPStan baseline**

```bash
cd C:/laragon/www/daems-platform
composer analyse 2>&1 | tail -10
```

Expected: 0 errors at level 9. Module's `backend/src/` is included via `phpstan.neon` paths (Task 9.5).

- [ ] **Step 2: Full test suite**

```bash
composer test:all 2>&1 | tail -50
```

Expected: 0 failures. Run as 4 separate suites if total > 600s.

- [ ] **Step 3: git grep for any remaining Members references in core src**

```bash
git -C C:/laragon/www/daems-platform grep -lE "(Daems\\\\Application\\\\Membership|Daems\\\\Application\\\\Member\\\\GetPublicMemberProfile|Daems\\\\Application\\\\Backstage\\\\(DecideApplication|DismissApplication|GetApplicationDetail|ListDecidedApplications|ListPendingApplications|ListMembers|ChangeMemberStatus|GetMemberAudit|ActivateMember|ActivateSupporter|Applications|Members\\\\)|SqlMemberApplicationRepository|SqlSupporterApplicationRepository|SqlMemberStatusAuditRepository|SqlMemberDirectoryRepository|SqlPublicMemberRepository|SqlAdminApplicationDismissalRepository|SqlTenantMemberCounterRepository|SqlTenantSupporterCounterRepository|InMemoryMemberApplicationRepository|InMemorySupporterApplicationRepository|InMemoryMemberStatusAuditRepository|InMemoryMemberDirectoryRepository|InMemoryAdminApplicationDismissalRepository|InMemoryTenantMemberCounterRepository|InMemoryTenantSupporterCounterRepository|class ApplicationController|class MemberController)" -- 'src/**' 'tests/**' ':!tests/Support/KernelHarness.php' ':!tests/Unit/Application/Backstage/ListNotificationsStatsTest.php'
```

Expected: NO matches. (The two excluded files are documented cross-domain consumers — KernelHarness rebinds module fakes; ListNotificationsStatsTest imports module fakes for the cross-cut.)

- [ ] **Step 4: git grep daem-society for stale paths**

```bash
git -C C:/laragon/www/sites/daem-society grep -lE "(public/pages/members/|public/pages/backstage/members/|public/pages/backstage/applications/|public/assets/css/public-member-page\\.css)"
```

Expected: NO matches except possibly comments referencing past structure. If the `index.php` UUID matcher is the only remaining reference, that's expected (it now points to `modules/members/frontend/public/profile.php`).

- [ ] **Step 5: Production-mode container smoke**

```bash
cd C:/laragon/www/daems-platform
php -r "
\$app = require 'bootstrap/app.php';
\$container = \$app->container();
\$mustResolve = [
    'DaemsModule\\\\Members\\\\Controller\\\\ApplicationController',
    'DaemsModule\\\\Members\\\\Controller\\\\MemberController',
    'DaemsModule\\\\Members\\\\Controller\\\\MembersBackstageController',
    'DaemsModule\\\\Members\\\\Application\\\\Membership\\\\SubmitMemberApplication\\\\SubmitMemberApplication',
    'DaemsModule\\\\Members\\\\Application\\\\Membership\\\\SubmitSupporterApplication\\\\SubmitSupporterApplication',
    'DaemsModule\\\\Members\\\\Application\\\\Member\\\\GetPublicMemberProfile\\\\GetPublicMemberProfile',
    'DaemsModule\\\\Members\\\\Application\\\\Backstage\\\\DecideApplication\\\\DecideApplication',
    'DaemsModule\\\\Members\\\\Application\\\\Backstage\\\\DismissApplication\\\\DismissApplication',
    'DaemsModule\\\\Members\\\\Application\\\\Backstage\\\\ChangeMemberStatus\\\\ChangeMemberStatus',
    'Daems\\\\Application\\\\Backstage\\\\Notifications\\\\ListNotificationsStats\\\\ListNotificationsStats',
];
\$failed = [];
foreach (\$mustResolve as \$class) {
    try { \$container->make(\$class); }
    catch (\\Throwable \$e) { \$failed[] = \$class . ' — ' . \$e->getMessage(); }
}
echo (\$failed === []) ? 'OK all resolved' : 'FAIL: ' . PHP_EOL . implode(PHP_EOL, \$failed);
echo PHP_EOL;
"
```

Expected: `OK all resolved`.

- [ ] **Step 6: Browser smoke matrix**

Open each URL and confirm behavior:

| URL | Expected | Verify |
|---|---|---|
| `http://daem-society.local/members/{uuid}` | Public profile card | name, role, type, joined date render |
| `http://daems-platform.local/api/v1/members/{uuid}` | JSON | non-empty payload |
| `http://daem-society.local/join` | Join landing | hero + forms.php (NOT broken — these stayed in daem-society) |
| `http://daem-society.local/backstage/members` | Members admin list | KPI strip with sparklines, table populated |
| `http://daem-society.local/backstage/members?view=applications` | Pending apps subview | list of pending member/supporter applications |
| `http://daem-society.local/backstage/members?view=decided` | Decided apps subview | history of approved/rejected applications |
| `http://daem-society.local/backstage/applications` | Applications admin (legacy URL) | redirects or renders identical content |
| `http://daems-platform.local/api/v1/backstage/notifications/stats` | JSON | counts include pending member/supporter applications + dismissals |

DevTools network tab: every `/modules/members/assets/*` returns 200. Zero JS console errors.

- [ ] **Step 7: Commit count summary**

```bash
echo "=== daems-platform commits since plan start ==="
git -C C:/laragon/www/daems-platform log --oneline dev ^HEAD~30 2>/dev/null | head -20

echo "=== modules/members commits ==="
git -C C:/laragon/www/modules/members log --oneline dev 2>/dev/null

echo "=== daem-society commits since plan start ==="
git -C C:/laragon/www/sites/daem-society log --oneline dev ^HEAD~10 2>/dev/null | head -10
```

Expected: ~6 commits in daems-platform (068 + autoload + 5 cleanup), ~16 commits in dp-members (skeleton + ~10 moves + ~4 wiring + 1 controller TDD), ~3 commits in daem-society (frontend moves).

- [ ] **Step 8: Surface to user — DO NOT push**

Report SHAs for each repo. Wait for explicit "pushaa" before any `git push`.

```bash
echo "=== Latest SHAs ==="
git -C C:/laragon/www/daems-platform rev-parse HEAD
git -C C:/laragon/www/modules/members rev-parse HEAD
git -C C:/laragon/www/sites/daem-society rev-parse HEAD
```

---

## Self-review

After plan execution, run this sanity checklist:

1. **Spec coverage**: every Members-related capability (public application submit, public profile, backstage list/decide/dismiss/status/audit/stats, notifications cross-cut) is verifiable via Task 29's URL matrix. ✓
2. **Placeholder scan**: search this plan for "TBD", "TODO", "implement later" — none present. ✓
3. **Type consistency**: method names referenced across tasks match (`pendingApplications` everywhere, not `listPendingApplications`). ✓
4. **Cross-repo coherence**: every commit lists a specific repo; commit messages follow the pattern `Verb(scope): description` matching existing daems-platform style. ✓
5. **Forum lessons applied**:
   - L1 (Domain stays in core when cross-consumed) → ✓ Domain\Membership\* + Domain\Member\* + relevant Domain\Backstage + Domain\Tenant interfaces stay
   - L2 (cross-domain test imports updated) → ✓ Task 24 explicitly handles ListNotificationsStatsTest
   - L3 (autoload-dev + phpstan paths added before test moves) → ✓ Task 9.5
   - L6 (module bindings register after core; last-write-wins) → ✓ Task 13/15 commits before Task 21/22 cleanup
6. **Idempotency**: 068 data-fix is gated by `IF (smt > 0)` per row — safe to re-run on dev/test DBs that already saw the rename. ✓

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-28-modular-architecture-phase1-members.md`.

Two execution options:

**1. Subagent-Driven (recommended)** — fresh subagent per task with two-stage review between tasks. Parallel-safe waves (A, F) dispatched concurrently; sequential waves (B, C, D, E, G) dispatched in order. Best for the 27-task scale + 3-repo coordination.

**2. Inline Execution** — execute tasks in this session using executing-plans skill, batched with checkpoints for review. More context-efficient but slower iteration.

Recommendation: Subagent-Driven.
