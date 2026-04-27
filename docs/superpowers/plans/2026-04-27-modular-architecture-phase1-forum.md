# Modular Architecture — Phase 1 (Forum extraction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the Forum domain from `daems-platform` core into the `modules/forum/` (= `dp-forum` repo) module, following the manifest convention proven by the Insights pilot. Zero user-visible change after the move.

**Architecture:** All Forum backend code (`src/Domain/Forum/`, `src/Application/Forum/`, `src/Application/Backstage/Forum/`, 4 SQL repos, public + backstage controllers) moves under `modules/forum/backend/src/` with namespace `DaemsModule\Forum\*`. Two new controllers extract Forum methods from the monolith `BackstageController`: `ForumModerationBackstageController` (11 methods) + `ForumContentBackstageController` (9 methods). All Forum-specific bindings + routes + tests + 9 migrations move with the code. The `daem-society` frontend `public/pages/{forum,backstage/forum}/` directories move to `modules/forum/frontend/{public,backstage,assets}/`. The Insights pilot's `ModuleRegistry` + autoloader + module-router already pick everything up automatically.

**Tech Stack:** PHP 8.3, Composer (runtime ClassLoader), MySQL 8.4, PHPUnit 10.5, PHPStan 1.12 level 9. No new external dependencies.

**Spec:** `docs/superpowers/specs/2026-04-27-modular-architecture-phase1-forum-design.md` (commit `001104d`)

**REVISION 2026-04-27 (during Wave A execution):** Cross-domain consumers in core (`ListNotificationsStats`, `ListPendingApplicationsForAdmin`, `GetUserActivity`, `AddProjectComment`) import Forum types. To preserve strict-A-isolation while keeping these working, **the entire `src/Domain/Forum/` directory (all 18 files) STAYS in core**. Only implementations (`SqlForum*Repository`), application use cases, controllers, tests, migrations, and frontend move to the module. The module's `bindings.php` binds **core-namespaced interfaces** (e.g. `Daems\Domain\Forum\ForumRepositoryInterface`) to **module-namespaced implementations** (`DaemsModule\Forum\Infrastructure\SqlForumRepository`). Additionally, `ForumIdentityDeriver::initials()` is lifted to `Daems\Application\Shared\IdentityFormatter::initials()` so `AddProjectComment` can stop importing from Forum. **Tasks affected:**
- Task 4 (Move Domain) — **REMOVED**
- Task 16 (Move Domain unit tests) — **REMOVED** (tests stay alongside their entities in core)
- Task 3.5 (NEW) — Lift `IdentityFormatter::initials()` to core, update `AddProjectComment`, before any module moves
- Task 7/8/9/13/14/19/20 namespace rewrites — only `Daems\Application\Forum`, `Daems\Application\Backstage\Forum`, `Daems\Infrastructure\Adapter\{Persistence\Sql,Api\Controller}\*Forum*` rewrite; **leave `Daems\Domain\Forum\*` references unchanged**.

**Repos affected (3 commit streams):**
- `C:\laragon\www\daems-platform\` — removals + `KernelHarness` cleanup + new data-fix migration `065_*` + extraction commits
- `C:\laragon\www\modules\forum\` = `dp-forum` repo (new — `Turtuure/dp-forum`, branch `dev`) — manifest + all moved Forum code
- `C:\laragon\www\sites\daem-society\` — frontend deletes only (module-router already in place)

**Verification gates (must pass before final commit on any task touching moved code):**
- `composer analyse` → 0 errors at PHPStan level 9
- `composer test` (Unit + Integration) → all green
- `composer test:e2e` → all green
- `composer test:all` → all green
- `GET /forum`, `/forum/category`, `/forum/thread`, `/backstage/forum/{,reports,topics,categories,audit}` render identically to pre-move
- `GET /api/v1/forum/categories`, `GET /api/v1/forum/topics/{slug}` return identical JSON to pre-move
- `POST /api/v1/forum/topics/{slug}/posts` and `POST /api/v1/forum/posts/{id}/like` work
- All admin endpoints under `/api/v1/backstage/forum/*` work — exercise pin/unpin/lock/unlock/resolve/dismiss/warn flows
- JS console: zero `payload is undefined` errors; Network: every `/modules/forum/assets/backstage/*` returns 200

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa". `.claude/` never staged (`git reset HEAD .claude/` if needed).

**dp-forum push cadence:** repo accumulates commits during plan execution. Push deferred until plan completion + user confirmation.

**Forbidden:** do NOT call `mcp__code-review-graph__*` tools — they hung subagent sessions during PR 3 Task 1.

---

## Task waves (dependency order)

```
Wave A (parallel-safe, no cross-deps)
├── Task 1: dp-forum skeleton (modules/forum + manifest + README + .gitignore + phpunit + composer)
├── Task 2: Core data-fix migration 065_*
└── Task 3: Forum file inventory verification (read-only — produces commit-time checklist)

Wave B (sequential, depends on Wave A)
├── Task 4: Move Domain (18 files) + namespace rewrite
├── Task 5: Move SQL repos (4 files) + namespace rewrite
├── Task 6: Move InMemory fakes (4) + ForumSeed + namespace rewrite
├── Task 7: Move Application/Forum (23 files) + namespace rewrite
├── Task 8: Move Application/Backstage/Forum (51 files) + namespace rewrite
├── Task 9: Move ForumController + namespace rewrite
├── Task 10: Extract ForumModerationBackstageController (TDD)
├── Task 11: Extract ForumContentBackstageController (TDD)
└── Task 12: Move 9 migrations with forum_NNN_* rename

Wave C (wiring, depends on Wave B)
├── Task 13: bindings.php (production)
├── Task 14: bindings.test.php (test container)
└── Task 15: routes.php (26 routes)

Wave D (test moves, depends on Wave B + C)
├── Task 16: tests/Unit/Domain/Forum (4 files)
├── Task 17: tests/Unit/Application/Forum (3 files)
├── Task 18: tests/Unit/Application/Backstage/Forum (24 files)
├── Task 19: integration tests (2 files)
└── Task 20: isolation (2) + E2E (1)

Wave E (core cleanup, depends on Wave C + D — module is self-contained)
├── Task 21: Remove Forum bindings from bootstrap/app.php
├── Task 22: Remove Forum routes from routes/api.php
├── Task 23: Remove Forum methods from BackstageController
├── Task 24: Remove Forum bindings from KernelHarness
└── Task 25: Apply data-fix migration; delete original migration files

Wave F (frontend moves, can run parallel with Wave E)
├── Task 26: Move daem-society public pages (8) + __DIR__ rewrite
├── Task 27: Move daem-society backstage pages (5 PHP + 4 sub-paths) + asset URL update
└── Task 28: Move assets (6 JS + 1 CSS + 2 SVG) + delete original directories

Wave G (verification gate)
└── Task 29: Final verification — composer analyse + test:all + production-container controller smoke + browser smoke-test
```

---

## File map summary

**Created in `daems-platform/`:**
- `database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql`

**Modified in `daems-platform/`:**
- `bootstrap/app.php` — remove Forum bindings
- `routes/api.php` — remove Forum routes (lines 179–208 + 411–491 + 492–494)
- `tests/Support/KernelHarness.php` — remove Forum bindings + InMemory fake registrations
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 20 Forum methods (lines 680–1232)

**Deleted in `daems-platform/`:**
- `src/Domain/Forum/` (entire dir, 18 files)
- `src/Application/Forum/` (entire dir, 23 files)
- `src/Application/Backstage/Forum/` (entire dir, 51 files)
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository.php` (4 files)
- `src/Infrastructure/Adapter/Api/Controller/ForumController.php`
- `database/migrations/{007,013,030,047,048,049,050,061,061}_*forum*.{sql,php}` (9 files)
- `tests/Unit/Domain/Forum/` (4 files)
- `tests/Unit/Application/Forum/` (3 files)
- `tests/Unit/Application/Backstage/Forum/` (24 files)
- `tests/Integration/Persistence/SqlForumStatsTest.php`
- `tests/Integration/Http/BackstageForumStatsTest.php`
- `tests/Isolation/Forum*TenantIsolationTest.php` (2 files)
- `tests/E2E/F005_ForumRoleImpersonationTest.php`
- `tests/Support/Fake/InMemoryForum*.php` (4 files)
- `tests/Support/ForumSeed.php`

**Created in `modules/forum/` (dp-forum):**
- `module.json`
- `README.md`
- `.gitignore`
- `phpunit.xml.dist`
- `composer.json` (PSR-4 autoload only)
- `backend/bindings.php`
- `backend/bindings.test.php`
- `backend/routes.php`
- `backend/migrations/forum_001_*.sql` through `forum_009_*.php` (9 files)
- `backend/src/Domain/*` (18 files)
- `backend/src/Application/Forum/*` (23 files)
- `backend/src/Application/Backstage/Forum/*` (51 files)
- `backend/src/Infrastructure/SqlForum*Repository.php` (4 files)
- `backend/src/Controller/ForumController.php`
- `backend/src/Controller/ForumModerationBackstageController.php`
- `backend/src/Controller/ForumContentBackstageController.php`
- `backend/tests/Support/InMemoryForum*.php` (4 files) + `ForumSeed.php`
- `backend/tests/Unit/Domain/Forum/*.php` (4 files)
- `backend/tests/Unit/Application/Forum/*.php` (3 files)
- `backend/tests/Unit/Application/Backstage/Forum/*.php` (24 files)
- `backend/tests/Integration/Persistence/SqlForumStatsTest.php`
- `backend/tests/Integration/Http/BackstageForumStatsTest.php`
- `backend/tests/Isolation/Forum*TenantIsolationTest.php` (2 files)
- `backend/tests/E2E/F005_ForumRoleImpersonationTest.php`
- `frontend/public/{index,categories,category,thread,new-topic,hero,cta,stats}.php` (8 files)
- `frontend/backstage/{index,reports/index,topics/index,categories/index,audit/index,forum-kpi-strip}.php` (6 files)
- `frontend/assets/backstage/{forum.css,forum-dashboard.js,forum-kpi-strip.js,forum-reports-page.js,forum-topics-page.js,forum-categories-page.js,forum-audit-page.js,empty-state-topics.svg,empty-state-reports.svg}` (9 files)

**Modified in `daem-society/`:** none (module-router already handles Forum after manifest discovery)

**Deleted in `daem-society/`:**
- `public/pages/forum/` (entire dir, 8 files)
- `public/pages/backstage/forum/` (entire dir, ~16 files)

---

## Task 1: dp-forum repo skeleton

**Repo for commits:** `dp-forum` (the new module)

**Files (created in `C:\laragon\www\modules\forum\`):**
- `module.json`
- `README.md`
- `.gitignore`
- `phpunit.xml.dist`
- `composer.json`
- `backend/migrations/.gitkeep`
- `backend/src/.gitkeep`
- `backend/tests/.gitkeep`
- `frontend/public/.gitkeep`
- `frontend/backstage/.gitkeep`
- `frontend/assets/.gitkeep`

- [ ] **Step 1.1 — Create directory + git init**

```bash
mkdir -p C:/laragon/www/modules/forum && cd C:/laragon/www/modules/forum && \
  git init -b dev && git remote add origin https://github.com/Turtuure/dp-forum.git
```

- [ ] **Step 1.2 — Write `module.json`**

```json
{
  "name": "forum",
  "version": "1.0.0",
  "description": "Categories, topics, posts, reports, moderation audit, user warnings",
  "namespace": "DaemsModule\\Forum\\",
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

- [ ] **Step 1.3 — Write `README.md`** (model on `modules/insights/README.md` — title, brief, link to spec, install/test commands)

- [ ] **Step 1.4 — Write `.gitignore`** (model on `modules/insights/.gitignore` — `/vendor`, `/.phpunit.cache`, OS junk)

- [ ] **Step 1.5 — Write `phpunit.xml.dist`** (model on `modules/insights/phpunit.xml.dist`; testsuites `Unit`, `Integration`, `Isolation`, `E2E` matching `tests/{Unit,Integration,Isolation,E2E}/` — case matters per CLAUDE.md `feedback_phpunit_testsuite_names.md`)

- [ ] **Step 1.6 — Write `composer.json`**

```json
{
    "name": "turtuure/dp-forum",
    "description": "Forum module for Daems platform",
    "type": "project",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "DaemsModule\\Forum\\": "backend/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "DaemsModule\\Forum\\Tests\\": "backend/tests/"
        }
    }
}
```

- [ ] **Step 1.7 — Create empty placeholder files (.gitkeep) in each subdir** so `git add` picks up the structure.

- [ ] **Step 1.8 — Verify `ModuleRegistry` discovers the manifest**

From `daems-platform/` run:
```bash
php -r 'require "vendor/autoload.php"; require "bootstrap/app.php"; $r = $container->make(\Daems\Infrastructure\Module\ModuleRegistry::class); var_dump(array_keys($r->all()));'
```
Expected: array contains `"forum"` and `"insights"` (both modules).

- [ ] **Step 1.9 — Commit (in `modules/forum/`)**

```bash
cd C:/laragon/www/modules/forum && \
  git add module.json README.md .gitignore phpunit.xml.dist composer.json \
          backend/migrations/.gitkeep backend/src/.gitkeep backend/tests/.gitkeep \
          frontend/public/.gitkeep frontend/backstage/.gitkeep frontend/assets/.gitkeep && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Init(dp-forum): module skeleton + manifest + composer + phpunit"
```

---

## Task 2: Core data-fix migration 065_*

**Repo for commits:** `daems-platform`

**Files:**
- Create: `daems-platform/database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql`

- [ ] **Step 2.1 — Verify the rename map matches what's recorded in dev DB**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db \
  -e "SELECT migration FROM schema_migrations WHERE migration LIKE '%forum%' ORDER BY migration"
```
Expected: 9 rows for the migrations listed in spec section 2.

- [ ] **Step 2.2 — Write migration SQL**

```sql
-- 065_rename_forum_migrations_in_schema_migrations_table.sql
-- Rewrites schema_migrations rows so existing dev DBs don't try to re-run
-- forum migrations after they're moved to modules/forum/backend/migrations/
-- with the forum_NNN_* prefix. Idempotent.

UPDATE schema_migrations SET migration = 'forum_001_create_forum_tables.sql'
  WHERE migration = '007_create_forum_tables.sql';
UPDATE schema_migrations SET migration = 'forum_002_add_user_id_to_forum.sql'
  WHERE migration = '013_add_user_id_to_forum.sql';
UPDATE schema_migrations SET migration = 'forum_003_add_tenant_id_to_forum_tables.sql'
  WHERE migration = '030_add_tenant_id_to_forum_tables.sql';
UPDATE schema_migrations SET migration = 'forum_004_add_locked_to_forum_topics.sql'
  WHERE migration = '047_add_locked_to_forum_topics.sql';
UPDATE schema_migrations SET migration = 'forum_005_create_forum_reports_audit_warnings_and_edited_at.sql'
  WHERE migration = '048_create_forum_reports_audit_warnings_and_edited_at.sql';
UPDATE schema_migrations SET migration = 'forum_006_extend_dismissals_enum_forum_report.sql'
  WHERE migration = '049_extend_dismissals_enum_forum_report.sql';
UPDATE schema_migrations SET migration = 'forum_007_widen_app_id_for_compound_forum_report.sql'
  WHERE migration = '050_widen_app_id_for_compound_forum_report.sql';
UPDATE schema_migrations SET migration = 'forum_008_search_text_forum_topics.sql'
  WHERE migration = '061_search_text_forum_topics.sql';
UPDATE schema_migrations SET migration = 'forum_009_search_text_forum_topics_backfill.php'
  WHERE migration = '061_search_text_forum_topics_backfill.php';
```

- [ ] **Step 2.3 — Smoke-test migration is idempotent**

Apply migration to a snapshot DB:
```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db \
  < database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql
# Run a second time — must succeed (no-op).
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db \
  < database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql
```
Expected: both runs succeed, no errors.

- [ ] **Step 2.4 — Verify final state**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db \
  -e "SELECT migration FROM schema_migrations WHERE migration LIKE '%forum%' ORDER BY migration"
```
Expected: 9 rows, all starting with `forum_`.

- [ ] **Step 2.5 — Commit (in `daems-platform/`)**

```bash
cd C:/laragon/www/daems-platform && \
  git add database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Migration(065): rename forum_* rows in schema_migrations for module move"
```

---

## Task 3: Forum file inventory verification (read-only)

**Repo for commits:** none (produces a checklist used by later tasks)

**Files:** none modified — generates a verification artifact

- [ ] **Step 3.1 — Capture exhaustive Forum file list to `/tmp/forum-files.txt`**

```bash
cd C:/laragon/www/daems-platform && \
  (find src/Domain/Forum src/Application/Forum src/Application/Backstage/Forum \
        tests/Unit/Domain/Forum tests/Unit/Application/Forum \
        tests/Unit/Application/Backstage/Forum -type f -name '*.php'; \
   ls src/Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository.php; \
   echo src/Infrastructure/Adapter/Api/Controller/ForumController.php; \
   ls tests/Support/Fake/InMemoryForum*.php; \
   echo tests/Support/ForumSeed.php; \
   ls tests/Integration/{Persistence/SqlForumStatsTest.php,Http/BackstageForumStatsTest.php}; \
   ls tests/Isolation/Forum*Test.php; \
   echo tests/E2E/F005_ForumRoleImpersonationTest.php; \
   ls database/migrations/{007,013,030,047,048,049,050,061}*forum*.{sql,php} 2>/dev/null) \
   | sort -u > /tmp/forum-files.txt && wc -l /tmp/forum-files.txt
```
Expected: ~125 lines (18 + 23 + 51 + 4 + 3 + 24 + 4 + 1 + 2 + 9 + 1 + 4 + 1 + 1 + 1).

- [ ] **Step 3.2 — Verify no other `Forum` files exist outside this list**

```bash
cd C:/laragon/www/daems-platform && \
  git ls-files | grep -i forum | sort > /tmp/forum-git-files.txt && \
  diff /tmp/forum-files.txt /tmp/forum-git-files.txt
```
Expected: only diff is the data-fix migration `065_*` and any documentation references — both are expected. If actual code files differ, investigate before proceeding.

- [ ] **Step 3.3 — Document `ForumIdentityDeriver` imports**

```bash
cd C:/laragon/www/daems-platform && \
  grep -E "^use " src/Application/Forum/Shared/ForumIdentityDeriver.php
```
Expected: imports only `Daems\Domain\{Tenant,User,Auth,Membership}` + `Daems\Infrastructure\Framework\*`. If it imports anything outside core, flag it for the brainstorming author before continuing — strict-A-isolation may require additional handling.

---

## Task 4: Move Domain layer (18 files)

**Repo for commits:** `dp-forum` (move-in) + `daems-platform` (move-out is part of Wave E)

**Files:**
- Move from `daems-platform/src/Domain/Forum/` → `modules/forum/backend/src/Domain/`
- Files: 18 (per `Glob` in spec section 2)

- [ ] **Step 4.1 — Copy + rewrite namespace** (the originals are deleted in Task 24, so use `cp` here, not `git mv` — this preserves the originals until KernelHarness + bootstrap are switched over)

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/src/Domain && \
  cp -r src/Domain/Forum/. ../modules/forum/backend/src/Domain/
```

- [ ] **Step 4.2 — Rewrite `namespace` declarations**

```bash
cd C:/laragon/www/modules/forum/backend/src/Domain && \
  find . -name '*.php' -print0 | xargs -0 \
  sed -i 's/^namespace Daems\\Domain\\Forum;/namespace DaemsModule\\Forum\\Domain;/'
```

- [ ] **Step 4.3 — Rewrite `use` statements pointing at the OLD Forum domain inside the moved files**

```bash
cd C:/laragon/www/modules/forum/backend/src/Domain && \
  find . -name '*.php' -print0 | xargs -0 \
  sed -i 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g'
```

- [ ] **Step 4.4 — Verify namespace consistency**

```bash
cd C:/laragon/www/modules/forum/backend/src/Domain && \
  grep -rE '^namespace ' --include='*.php' | grep -v 'DaemsModule\\Forum\\Domain'
```
Expected: zero results.

```bash
grep -r 'namespace Daems\\Domain\\Forum' --include='*.php' .
```
Expected: zero results.

- [ ] **Step 4.5 — Commit (in `modules/forum/`)**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Domain && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(domain): Forum domain layer with DaemsModule namespace"
```

---

## Task 5: Move SQL repositories (4 files)

**Repo for commits:** `dp-forum`

**Files:**
- Copy `daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php` → `modules/forum/backend/src/Infrastructure/SqlForumRepository.php`
- Same for `SqlForumModerationAuditRepository`, `SqlForumReportRepository`, `SqlForumUserWarningRepository`

- [ ] **Step 5.1 — Copy files**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/src/Infrastructure && \
  cp src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php \
     src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php \
     src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php \
     src/Infrastructure/Adapter/Persistence/Sql/SqlForumUserWarningRepository.php \
     ../modules/forum/backend/src/Infrastructure/
```

- [ ] **Step 5.2 — Rewrite namespace + Forum-domain use statements**

```bash
cd C:/laragon/www/modules/forum/backend/src/Infrastructure && \
  find . -name 'SqlForum*.php' -print0 | xargs -0 \
  sed -i \
    -e 's|^namespace Daems\\Infrastructure\\Adapter\\Persistence\\Sql;|namespace DaemsModule\\Forum\\Infrastructure;|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g'
```

- [ ] **Step 5.3 — Verify**

```bash
cd C:/laragon/www/modules/forum/backend/src/Infrastructure && \
  grep -rE '^namespace |^use Daems\\\\Domain\\\\Forum' --include='*.php' .
```
Expected: only `namespace DaemsModule\Forum\Infrastructure;` lines, zero `use Daems\Domain\Forum\` lines.

- [ ] **Step 5.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Infrastructure && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(infrastructure): 4 SqlForum*Repository classes with namespace rewrite"
```

---

## Task 6: Move InMemory fakes (4) + ForumSeed

**Repo for commits:** `dp-forum`

**Files:**
- Copy `daems-platform/tests/Support/Fake/InMemoryForumRepository.php` → `modules/forum/backend/tests/Support/InMemoryForumRepository.php`
- Same for `InMemoryForumModerationAuditRepository`, `InMemoryForumReportRepository`, `InMemoryForumUserWarningRepository`
- Copy `daems-platform/tests/Support/ForumSeed.php` → `modules/forum/backend/tests/Support/ForumSeed.php`

- [ ] **Step 6.1 — Copy files**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/tests/Support && \
  cp tests/Support/Fake/InMemoryForumRepository.php \
     tests/Support/Fake/InMemoryForumModerationAuditRepository.php \
     tests/Support/Fake/InMemoryForumReportRepository.php \
     tests/Support/Fake/InMemoryForumUserWarningRepository.php \
     tests/Support/ForumSeed.php \
     ../modules/forum/backend/tests/Support/
```

- [ ] **Step 6.2 — Rewrite namespaces**

```bash
cd C:/laragon/www/modules/forum/backend/tests/Support && \
  find . -name '*.php' -print0 | xargs -0 \
  sed -i \
    -e 's|^namespace Daems\\Tests\\Support\\Fake;|namespace DaemsModule\\Forum\\Tests\\Support;|' \
    -e 's|^namespace Daems\\Tests\\Support;|namespace DaemsModule\\Forum\\Tests\\Support;|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Tests\\Support\\\\Fake\\\\InMemoryForum|use DaemsModule\\Forum\\Tests\\Support\\InMemoryForum|g'
```

- [ ] **Step 6.3 — Verify**

```bash
cd C:/laragon/www/modules/forum/backend/tests/Support && \
  grep -rE '^namespace ' --include='*.php' . | grep -v 'DaemsModule\\Forum\\Tests\\Support'
```
Expected: zero results.

- [ ] **Step 6.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/tests/Support && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): 4 InMemoryForum* fakes + ForumSeed with namespace rewrite"
```

---

## Task 7: Move Application/Forum (23 files)

**Repo for commits:** `dp-forum`

**Files:** Copy `daems-platform/src/Application/Forum/` → `modules/forum/backend/src/Application/Forum/`

- [ ] **Step 7.1 — Copy directory tree**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/src/Application/Forum && \
  cp -r src/Application/Forum/. ../modules/forum/backend/src/Application/Forum/
```

- [ ] **Step 7.2 — Rewrite namespaces in copied files**

```bash
cd C:/laragon/www/modules/forum/backend/src/Application/Forum && \
  find . -name '*.php' -print0 | xargs -0 \
  sed -i \
    -e 's|^namespace Daems\\Application\\Forum|namespace DaemsModule\\Forum\\Application\\Forum|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Application\\Forum\\\\|use DaemsModule\\Forum\\Application\\Forum\\\\|g'
```

- [ ] **Step 7.3 — Verify**

```bash
cd C:/laragon/www/modules/forum/backend/src/Application/Forum && \
  grep -rE '^(namespace |use ) Daems\\\\(Domain|Application)\\\\Forum' --include='*.php' .
```
Expected: zero results.

- [ ] **Step 7.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Application/Forum && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(application): public Forum use cases (23 files) with namespace rewrite"
```

---

## Task 8: Move Application/Backstage/Forum (51 files)

**Repo for commits:** `dp-forum`

**Files:** Copy `daems-platform/src/Application/Backstage/Forum/` → `modules/forum/backend/src/Application/Backstage/Forum/`

- [ ] **Step 8.1 — Copy directory tree**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/src/Application/Backstage/Forum && \
  cp -r src/Application/Backstage/Forum/. ../modules/forum/backend/src/Application/Backstage/Forum/
```

- [ ] **Step 8.2 — Rewrite namespaces in copied files**

```bash
cd C:/laragon/www/modules/forum/backend/src/Application/Backstage/Forum && \
  find . -name '*.php' -print0 | xargs -0 \
  sed -i \
    -e 's|^namespace Daems\\Application\\Backstage\\Forum|namespace DaemsModule\\Forum\\Application\\Backstage\\Forum|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Application\\Forum\\\\|use DaemsModule\\Forum\\Application\\Forum\\\\|g' \
    -e 's|use Daems\\Application\\Backstage\\Forum\\\\|use DaemsModule\\Forum\\Application\\Backstage\\Forum\\\\|g'
```

- [ ] **Step 8.3 — Verify**

```bash
cd C:/laragon/www/modules/forum/backend/src/Application/Backstage/Forum && \
  grep -rE '^(namespace |use ) Daems\\\\(Domain\\\\Forum|Application\\\\(Forum|Backstage\\\\Forum))' --include='*.php' .
```
Expected: zero results.

- [ ] **Step 8.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Application/Backstage/Forum && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(application): admin Backstage Forum use cases (51 files) with namespace rewrite"
```

---

## Task 9: Move ForumController + namespace rewrite

**Repo for commits:** `dp-forum`

**Files:**
- Copy `daems-platform/src/Infrastructure/Adapter/Api/Controller/ForumController.php` → `modules/forum/backend/src/Controller/ForumController.php`

- [ ] **Step 9.1 — Copy file**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/src/Controller && \
  cp src/Infrastructure/Adapter/Api/Controller/ForumController.php \
     ../modules/forum/backend/src/Controller/ForumController.php
```

- [ ] **Step 9.2 — Rewrite namespace + use statements**

```bash
cd C:/laragon/www/modules/forum/backend/src/Controller && \
  sed -i \
    -e 's|^namespace Daems\\Infrastructure\\Adapter\\Api\\Controller;|namespace DaemsModule\\Forum\\Controller;|' \
    -e 's|use Daems\\Application\\Forum\\\\|use DaemsModule\\Forum\\Application\\Forum\\\\|g' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    ForumController.php
```

- [ ] **Step 9.3 — Verify**

```bash
grep -E '^(namespace |use ) Daems\\\\(Application\\\\Forum|Domain\\\\Forum|Infrastructure\\\\Adapter\\\\Api\\\\Controller)' \
  C:/laragon/www/modules/forum/backend/src/Controller/ForumController.php
```
Expected: zero results.

- [ ] **Step 9.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Controller/ForumController.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(controller): ForumController with namespace rewrite"
```

---

## Task 10: Extract ForumModerationBackstageController (TDD, 11 methods)

**Repo for commits:** `dp-forum`

**Files:**
- Create: `modules/forum/backend/src/Controller/ForumModerationBackstageController.php`
- Test: `modules/forum/backend/tests/Unit/Controller/ForumModerationBackstageControllerTest.php`

**Source methods (in `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`):**
- `listForumReports` (line 680)
- `getForumReport` (718)
- `resolveForumReport` (763)
- `dismissForumReport` (817)
- `lockForumTopic` (920)
- `unlockForumTopic` (939)
- `deleteForumTopicAdmin` (958)
- `editForumPostAdmin` (1010)
- `deleteForumPostAdmin` (1033)
- `warnForumUser` (1052)
- `listForumAudit` (1160)

- [ ] **Step 10.1 — Read each source method's body to understand its dependencies**

```bash
cd C:/laragon/www/daems-platform && \
  sed -n '680,1232p' src/Infrastructure/Adapter/Api/Controller/BackstageController.php > /tmp/backstage-forum-methods.php
```
Read `/tmp/backstage-forum-methods.php` to inventory which use cases each method uses.

- [ ] **Step 10.2 — Read BackstageController constructor (line 141) to identify required services**

Note which `Daems\Application\Backstage\Forum\*` use cases are injected. Plan-author has confirmed: 11 moderation-flow use cases plus `ForumIdentityDeriver`, `ForumRepositoryInterface`, `ForumReportRepositoryInterface`, `ForumModerationAuditRepositoryInterface`, `ForumUserWarningRepositoryInterface`.

- [ ] **Step 10.3 — Write the failing test**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Forum\Tests\Unit\Controller;

use DaemsModule\Forum\Controller\ForumModerationBackstageController;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit;
use DaemsModule\Forum\Application\Backstage\Forum\DismissForumReport\DismissForumReport;
use DaemsModule\Forum\Application\Backstage\Forum\LockForumTopic\LockForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\WarnForumUser\WarnForumUser;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin;
use DaemsModule\Forum\Application\Forum\Shared\ForumIdentityDeriver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ForumModerationBackstageControllerTest extends TestCase
{
    public function test_constructor_signature_is_stable(): void
    {
        $reflection = new ReflectionClass(ForumModerationBackstageController::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $paramTypes = array_map(
            fn(\ReflectionParameter $p) => $p->getType()?->getName(),
            $constructor->getParameters()
        );

        // Constructor MUST accept exactly these classes in this order.
        // Adding/reordering arguments here is a breaking change — verify against bootstrap/app.php
        // production binding before changing.
        self::assertSame([
            ListForumReportsForAdmin::class,
            GetForumReportDetail::class,
            ResolveForumReportByLock::class,
            ResolveForumReportByWarn::class,
            ResolveForumReportByDelete::class,
            ResolveForumReportByEdit::class,
            DismissForumReport::class,
            LockForumTopic::class,
            UnlockForumTopic::class,
            DeleteForumTopicAsAdmin::class,
            EditForumPostAsAdmin::class,
            DeleteForumPostAsAdmin::class,
            WarnForumUser::class,
            ListForumModerationAuditForAdmin::class,
            ForumIdentityDeriver::class,
        ], $paramTypes);
    }

    public function test_has_all_11_moderation_methods(): void
    {
        $reflection = new ReflectionClass(ForumModerationBackstageController::class);
        $methods = array_map(
            fn(\ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $methods = array_values(array_filter($methods, fn(string $m) => $m !== '__construct'));
        sort($methods);

        self::assertSame([
            'deleteForumPostAdmin',
            'deleteForumTopicAdmin',
            'dismissForumReport',
            'editForumPostAdmin',
            'getForumReport',
            'listForumAudit',
            'listForumReports',
            'lockForumTopic',
            'resolveForumReport',
            'unlockForumTopic',
            'warnForumUser',
        ], $methods);
    }
}
```

- [ ] **Step 10.4 — Verify test fails (class doesn't exist yet)**

```bash
cd C:/laragon/www/modules/forum && composer install --no-interaction && \
  vendor/bin/phpunit --filter ForumModerationBackstageControllerTest \
                     backend/tests/Unit/Controller/ForumModerationBackstageControllerTest.php
```
Expected: ERROR — class not found.

- [ ] **Step 10.5 — Write `ForumModerationBackstageController.php`**

Copy each method body **verbatim** from `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php` lines for the 11 methods listed in section "Source methods" above. Constructor takes the 15 services from the test's `paramTypes` assertion. **Do not edit method bodies** — verbatim copy keeps risk minimal. Only update `use` statements + namespace.

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Forum\Controller;

use Daems\Domain\Auth\AuthenticatedUser;
use Daems\Domain\Membership\MembershipUserId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\User\ForbiddenException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdminInput;
use DaemsModule\Forum\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use DaemsModule\Forum\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetailInput;
// ... (all 11 input/output companions)
use DaemsModule\Forum\Application\Forum\Shared\ForumIdentityDeriver;

final class ForumModerationBackstageController
{
    public function __construct(
        private readonly ListForumReportsForAdmin $listReports,
        private readonly GetForumReportDetail $getReport,
        private readonly ResolveForumReportByLock $resolveByLock,
        private readonly ResolveForumReportByWarn $resolveByWarn,
        private readonly ResolveForumReportByDelete $resolveByDelete,
        private readonly ResolveForumReportByEdit $resolveByEdit,
        private readonly DismissForumReport $dismissReport,
        private readonly LockForumTopic $lockTopic,
        private readonly UnlockForumTopic $unlockTopic,
        private readonly DeleteForumTopicAsAdmin $deleteTopic,
        private readonly EditForumPostAsAdmin $editPost,
        private readonly DeleteForumPostAsAdmin $deletePost,
        private readonly WarnForumUser $warnUser,
        private readonly ListForumModerationAuditForAdmin $listAudit,
        private readonly ForumIdentityDeriver $identityDeriver,
    ) {}

    // Method bodies copied verbatim from BackstageController.php lines 680, 718, 763, 817,
    // 920, 939, 958, 1010, 1033, 1052, 1160. Adjust internal references that called
    // $this->createForum* etc. to use $this->resolveByLock / $this->lockTopic / etc.
    // (the rename map):
    //   $this->listForumReportsUseCase → $this->listReports
    //   $this->getForumReportDetail    → $this->getReport
    //   ... (use the constructor property names above)
}
```

(Reading original BackstageController bodies in step 10.1 produces the verbatim method content. The rename map collapses lengthy field names per the constructor.)

- [ ] **Step 10.6 — Run tests, expect green**

```bash
cd C:/laragon/www/modules/forum && \
  vendor/bin/phpunit --filter ForumModerationBackstageControllerTest \
                     backend/tests/Unit/Controller/ForumModerationBackstageControllerTest.php
```
Expected: PASS.

- [ ] **Step 10.7 — Run PHPStan**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```
Expected: 0 errors. (Module's controller is now under `paths: [src/, ../modules/*/backend/src/]`.)

- [ ] **Step 10.8 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Controller/ForumModerationBackstageController.php \
          backend/tests/Unit/Controller/ForumModerationBackstageControllerTest.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Extract(controller): ForumModerationBackstageController (11 methods + signature test)"
```

---

## Task 11: Extract ForumContentBackstageController (TDD, 9 methods)

**Repo for commits:** `dp-forum`

**Files:**
- Create: `modules/forum/backend/src/Controller/ForumContentBackstageController.php`
- Test: `modules/forum/backend/tests/Unit/Controller/ForumContentBackstageControllerTest.php`

**Source methods (in `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`):**
- `listForumTopicsAdmin` (line 843)
- `pinForumTopic` (882)
- `unpinForumTopic` (901)
- `listForumPostsAdmin` (974)
- `listForumCategoriesAdmin` (1071)
- `createForumCategoryAdmin` (1082)
- `updateForumCategoryAdmin` (1110)
- `deleteForumCategoryAdmin` (1142)
- `statsForum` (1201)

- [ ] **Step 11.1 — Write the failing test (mirroring Task 10's signature + method-list assertions)**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Forum\Tests\Unit\Controller;

use DaemsModule\Forum\Controller\ForumContentBackstageController;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\PinForumTopic\PinForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumStats\ListForumStats;
use DaemsModule\Forum\Application\Forum\ListForumCategories\ListForumCategories;
use DaemsModule\Forum\Application\Forum\Shared\ForumIdentityDeriver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ForumContentBackstageControllerTest extends TestCase
{
    public function test_constructor_signature_is_stable(): void
    {
        $reflection = new ReflectionClass(ForumContentBackstageController::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $paramTypes = array_map(
            fn(\ReflectionParameter $p) => $p->getType()?->getName(),
            $constructor->getParameters()
        );
        self::assertSame([
            ListForumTopicsForAdmin::class,
            PinForumTopic::class,
            UnpinForumTopic::class,
            ListForumPostsForAdmin::class,
            ListForumCategories::class,
            CreateForumCategoryAsAdmin::class,
            UpdateForumCategoryAsAdmin::class,
            DeleteForumCategoryAsAdmin::class,
            ListForumStats::class,
            ForumIdentityDeriver::class,
        ], $paramTypes);
    }

    public function test_has_all_9_content_methods(): void
    {
        $reflection = new ReflectionClass(ForumContentBackstageController::class);
        $methods = array_map(
            fn(\ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $methods = array_values(array_filter($methods, fn(string $m) => $m !== '__construct'));
        sort($methods);
        self::assertSame([
            'createForumCategoryAdmin',
            'deleteForumCategoryAdmin',
            'listForumCategoriesAdmin',
            'listForumPostsAdmin',
            'listForumTopicsAdmin',
            'pinForumTopic',
            'statsForum',
            'unpinForumTopic',
            'updateForumCategoryAdmin',
        ], $methods);
    }
}
```

- [ ] **Step 11.2 — Verify test fails (class missing)**

```bash
cd C:/laragon/www/modules/forum && \
  vendor/bin/phpunit --filter ForumContentBackstageControllerTest \
                     backend/tests/Unit/Controller/ForumContentBackstageControllerTest.php
```
Expected: ERROR — class not found.

- [ ] **Step 11.3 — Write `ForumContentBackstageController.php`** with the same verbatim-copy approach as Task 10. Constructor params match the 10 services in the test's `paramTypes` assertion.

- [ ] **Step 11.4 — Run tests, expect green**

- [ ] **Step 11.5 — Run PHPStan**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```
Expected: 0 errors.

- [ ] **Step 11.6 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/src/Controller/ForumContentBackstageController.php \
          backend/tests/Unit/Controller/ForumContentBackstageControllerTest.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Extract(controller): ForumContentBackstageController (9 methods + signature test)"
```

---

## Task 12: Move 9 migrations with `forum_NNN_*` rename

**Repo for commits:** `dp-forum` (originals deleted later in Task 25)

**Files:** Copy 9 migrations into `modules/forum/backend/migrations/` per the rename map in spec section 2.

- [ ] **Step 12.1 — Copy + rename migrations**

```bash
cd C:/laragon/www/daems-platform && \
  M=../modules/forum/backend/migrations && \
  cp database/migrations/007_create_forum_tables.sql               $M/forum_001_create_forum_tables.sql && \
  cp database/migrations/013_add_user_id_to_forum.sql              $M/forum_002_add_user_id_to_forum.sql && \
  cp database/migrations/030_add_tenant_id_to_forum_tables.sql     $M/forum_003_add_tenant_id_to_forum_tables.sql && \
  cp database/migrations/047_add_locked_to_forum_topics.sql        $M/forum_004_add_locked_to_forum_topics.sql && \
  cp database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql \
     $M/forum_005_create_forum_reports_audit_warnings_and_edited_at.sql && \
  cp database/migrations/049_extend_dismissals_enum_forum_report.sql \
     $M/forum_006_extend_dismissals_enum_forum_report.sql && \
  cp database/migrations/050_widen_app_id_for_compound_forum_report.sql \
     $M/forum_007_widen_app_id_for_compound_forum_report.sql && \
  cp database/migrations/061_search_text_forum_topics.sql          $M/forum_008_search_text_forum_topics.sql && \
  cp database/migrations/061_search_text_forum_topics_backfill.php $M/forum_009_search_text_forum_topics_backfill.php
```

- [ ] **Step 12.2 — Verify count + smoke that file contents are identical**

```bash
ls C:/laragon/www/modules/forum/backend/migrations/forum_*.{sql,php} 2>/dev/null | wc -l
```
Expected: `9`.

```bash
diff C:/laragon/www/daems-platform/database/migrations/007_create_forum_tables.sql \
     C:/laragon/www/modules/forum/backend/migrations/forum_001_create_forum_tables.sql
```
Expected: zero output.

- [ ] **Step 12.3 — `MigrationTestCase` smoke (verifies module migrations loadable)**

```bash
cd C:/laragon/www/daems-platform && composer test -- --filter MigrationTestCase 2>&1 | tail -10
```
Expected: PASS — runner picks up `../modules/forum/backend/migrations/forum_*` per commit `649806c`.

- [ ] **Step 12.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/migrations && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(migrations): 9 Forum migrations renamed to forum_001..forum_009"
```

---

## Task 13: bindings.php (production)

**Repo for commits:** `dp-forum`

**Files:**
- Create: `modules/forum/backend/bindings.php`

- [ ] **Step 13.1 — Read the original Forum bindings in `bootstrap/app.php`**

```bash
grep -n -A 3 'Forum' C:/laragon/www/daems-platform/bootstrap/app.php | head -100
```
Inventory: every `bind()`/`singleton()` call referencing `Daems\Application\{Forum,Backstage\Forum}` or `Daems\Domain\Forum` or `Daems\Infrastructure\Adapter\Persistence\Sql\SqlForum*`.

- [ ] **Step 13.2 — Write `modules/forum/backend/bindings.php`** following `modules/insights/backend/bindings.php`'s structure. Bindings register:

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Forum\Domain\ForumRepositoryInterface;
use DaemsModule\Forum\Domain\ForumModerationAuditRepositoryInterface;
use DaemsModule\Forum\Domain\ForumReportRepositoryInterface;
use DaemsModule\Forum\Domain\ForumUserWarningRepositoryInterface;
use DaemsModule\Forum\Infrastructure\SqlForumRepository;
use DaemsModule\Forum\Infrastructure\SqlForumModerationAuditRepository;
use DaemsModule\Forum\Infrastructure\SqlForumReportRepository;
use DaemsModule\Forum\Infrastructure\SqlForumUserWarningRepository;
use DaemsModule\Forum\Application\Forum\Shared\ForumIdentityDeriver;
// public use cases
use DaemsModule\Forum\Application\Forum\ListForumCategories\ListForumCategories;
use DaemsModule\Forum\Application\Forum\GetForumCategory\GetForumCategory;
use DaemsModule\Forum\Application\Forum\GetForumThread\GetForumThread;
use DaemsModule\Forum\Application\Forum\CreateForumTopic\CreateForumTopic;
use DaemsModule\Forum\Application\Forum\CreateForumPost\CreateForumPost;
use DaemsModule\Forum\Application\Forum\LikeForumPost\LikeForumPost;
use DaemsModule\Forum\Application\Forum\IncrementTopicView\IncrementTopicView;
use DaemsModule\Forum\Application\Forum\ReportForumTarget\ReportForumTarget;
// admin use cases (24 total)
use DaemsModule\Forum\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use DaemsModule\Forum\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit;
use DaemsModule\Forum\Application\Backstage\Forum\DismissForumReport\DismissForumReport;
use DaemsModule\Forum\Application\Backstage\Forum\PinForumTopic\PinForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\UnpinForumTopic\UnpinForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\LockForumTopic\LockForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\UnlockForumTopic\UnlockForumTopic;
use DaemsModule\Forum\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumTopicAsAdmin\DeleteForumTopicAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\WarnForumUser\WarnForumUser;
use DaemsModule\Forum\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin;
use DaemsModule\Forum\Application\Backstage\Forum\ListForumStats\ListForumStats;
// controllers
use DaemsModule\Forum\Controller\ForumController;
use DaemsModule\Forum\Controller\ForumModerationBackstageController;
use DaemsModule\Forum\Controller\ForumContentBackstageController;

return function (Container $container): void {
    // 4× repositories
    $container->singleton(ForumRepositoryInterface::class,
        static fn(Container $c) => new SqlForumRepository($c->make(Connection::class)));
    $container->singleton(ForumModerationAuditRepositoryInterface::class,
        static fn(Container $c) => new SqlForumModerationAuditRepository($c->make(Connection::class)));
    $container->singleton(ForumReportRepositoryInterface::class,
        static fn(Container $c) => new SqlForumReportRepository($c->make(Connection::class)));
    $container->singleton(ForumUserWarningRepositoryInterface::class,
        static fn(Container $c) => new SqlForumUserWarningRepository($c->make(Connection::class)));

    // shared service — copy ForumIdentityDeriver constructor signature from
    // modules/forum/backend/src/Application/Forum/Shared/ForumIdentityDeriver.php
    $container->bind(ForumIdentityDeriver::class,
        static fn(Container $c) => new ForumIdentityDeriver(
            $c->make(\Daems\Domain\Membership\MembershipRepositoryInterface::class),
            $c->make(\Daems\Domain\User\UserRepositoryInterface::class),
        ));

    // ... bind every use case (8 public + 24 admin) — copy ctor signatures verbatim
    // from each use case class file. Each use case typically takes 1-3 repos.

    // Controllers — see Tasks 9, 10, 11 for exact constructor parameter order.
    $container->bind(ForumController::class,
        static fn(Container $c) => new ForumController(
            $c->make(ListForumCategories::class),
            $c->make(GetForumCategory::class),
            $c->make(GetForumThread::class),
            $c->make(CreateForumTopic::class),
            $c->make(CreateForumPost::class),
            $c->make(LikeForumPost::class),
            $c->make(IncrementTopicView::class),
            $c->make(ReportForumTarget::class),
        ));
    $container->bind(ForumModerationBackstageController::class,
        static fn(Container $c) => new ForumModerationBackstageController(
            $c->make(ListForumReportsForAdmin::class),
            $c->make(GetForumReportDetail::class),
            $c->make(ResolveForumReportByLock::class),
            $c->make(ResolveForumReportByWarn::class),
            $c->make(ResolveForumReportByDelete::class),
            $c->make(ResolveForumReportByEdit::class),
            $c->make(DismissForumReport::class),
            $c->make(LockForumTopic::class),
            $c->make(UnlockForumTopic::class),
            $c->make(DeleteForumTopicAsAdmin::class),
            $c->make(EditForumPostAsAdmin::class),
            $c->make(DeleteForumPostAsAdmin::class),
            $c->make(WarnForumUser::class),
            $c->make(ListForumModerationAuditForAdmin::class),
            $c->make(ForumIdentityDeriver::class),
        ));
    $container->bind(ForumContentBackstageController::class,
        static fn(Container $c) => new ForumContentBackstageController(
            $c->make(ListForumTopicsForAdmin::class),
            $c->make(PinForumTopic::class),
            $c->make(UnpinForumTopic::class),
            $c->make(ListForumPostsForAdmin::class),
            $c->make(ListForumCategories::class),
            $c->make(CreateForumCategoryAsAdmin::class),
            $c->make(UpdateForumCategoryAsAdmin::class),
            $c->make(DeleteForumCategoryAsAdmin::class),
            $c->make(ListForumStats::class),
            $c->make(ForumIdentityDeriver::class),
        ));
};
```

For every use case binding, **read the use case's constructor signature** from its file in `modules/forum/backend/src/Application/...` and inject the matching dependencies (repositories, identity deriver, etc).

- [ ] **Step 13.3 — PHPStan check**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```
Expected: 0 errors.

- [ ] **Step 13.4 — Smoke: instantiate every controller via production container**

Save this as `/tmp/smoke-forum-controllers.php`:
```php
<?php
require __DIR__ . '/../../laragon/www/daems-platform/vendor/autoload.php';
$container = require __DIR__ . '/../../laragon/www/daems-platform/bootstrap/app.php';
$c = $container->make(\DaemsModule\Forum\Controller\ForumController::class);
$m = $container->make(\DaemsModule\Forum\Controller\ForumModerationBackstageController::class);
$g = $container->make(\DaemsModule\Forum\Controller\ForumContentBackstageController::class);
echo "OK: ", get_class($c), " | ", get_class($m), " | ", get_class($g), PHP_EOL;
```

```bash
cd C:/laragon/www/daems-platform && php /tmp/smoke-forum-controllers.php
```
Expected: `OK: DaemsModule\Forum\Controller\ForumController | ... | ...`. **If TypeError, constructor parameter order in `bindings.php` is wrong** — fix before commit (this is the bug class from CLAUDE.md feedback file `feedback_bootstrap_and_harness_must_both_wire.md`).

- [ ] **Step 13.5 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/bindings.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(bindings): production DI for Forum module (4 repos + 32 use cases + 3 controllers)"
```

---

## Task 14: bindings.test.php (test container)

**Repo for commits:** `dp-forum`

**Files:**
- Create: `modules/forum/backend/bindings.test.php`

- [ ] **Step 14.1 — Copy `bindings.php` → `bindings.test.php`**

- [ ] **Step 14.2 — Replace 4 SQL repo bindings with InMemory equivalents**

Replace the singleton fns:
```php
$container->singleton(ForumRepositoryInterface::class,
    static fn() => new \DaemsModule\Forum\Tests\Support\InMemoryForumRepository());
$container->singleton(ForumModerationAuditRepositoryInterface::class,
    static fn() => new \DaemsModule\Forum\Tests\Support\InMemoryForumModerationAuditRepository());
$container->singleton(ForumReportRepositoryInterface::class,
    static fn() => new \DaemsModule\Forum\Tests\Support\InMemoryForumReportRepository());
$container->singleton(ForumUserWarningRepositoryInterface::class,
    static fn() => new \DaemsModule\Forum\Tests\Support\InMemoryForumUserWarningRepository());
```

All other bindings (use cases + controllers + identity deriver) stay identical.

- [ ] **Step 14.3 — Verify**

```bash
cd C:/laragon/www/daems-platform && \
  php -r 'require "vendor/autoload.php"; \
          $registry = (new \Daems\Infrastructure\Module\ModuleRegistry()); \
          $registry->discover(__DIR__ . "/../modules"); \
          $registry->registerAutoloader(); \
          $container = new \Daems\Infrastructure\Framework\Container\Container(); \
          $registry->registerBindings($container, testMode: true); \
          $c = $container->make(\DaemsModule\Forum\Controller\ForumController::class); \
          echo "OK\n";'
```
Expected: `OK`.

- [ ] **Step 14.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/bindings.test.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(bindings.test): KernelHarness DI with InMemory fakes"
```

---

## Task 15: routes.php (26 routes)

**Repo for commits:** `dp-forum`

**Files:**
- Create: `modules/forum/backend/routes.php`

- [ ] **Step 15.1 — Read original Forum routes**

```bash
sed -n '179,208p;411,494p' C:/laragon/www/daems-platform/routes/api.php > /tmp/forum-routes-original.php
```

- [ ] **Step 15.2 — Write `modules/forum/backend/routes.php`** following `modules/insights/backend/routes.php` pattern. Map every route to the correct controller per spec section 4:

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use DaemsModule\Forum\Controller\ForumController;
use DaemsModule\Forum\Controller\ForumModerationBackstageController;
use DaemsModule\Forum\Controller\ForumContentBackstageController;

return function (Router $router, Container $container): void {
    // ===== Public Forum (8 routes) =====
    $router->get('/api/v1/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumController::class)->index($req);
    }, [TenantContextMiddleware::class]);
    $router->get('/api/v1/forum/categories/{slug}', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->category($req, $p);
    }, [TenantContextMiddleware::class]);
    $router->get('/api/v1/forum/topics/{slug}', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->thread($req, $p);
    }, [TenantContextMiddleware::class]);
    $router->post('/api/v1/forum/categories/{slug}/topics', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->createTopic($req, $p);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
    $router->post('/api/v1/forum/topics/{slug}/posts', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->createPost($req, $p);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
    $router->post('/api/v1/forum/posts/{id}/like', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->likePost($req, $p);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
    $router->post('/api/v1/forum/topics/{slug}/view', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumController::class)->incrementView($req, $p);
    }, [TenantContextMiddleware::class]);
    $router->post('/api/v1/forum/reports', static function (Request $req) use ($container): Response {
        return $container->make(ForumController::class)->createReport($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // ===== Backstage Moderation (11 routes) → ForumModerationBackstageController =====
    $auth = [TenantContextMiddleware::class, AuthMiddleware::class];
    $router->get('/api/v1/backstage/forum/reports', static function (Request $req) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->listForumReports($req);
    }, $auth);
    $router->get('/api/v1/backstage/forum/reports/{id}', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->getForumReport($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/reports/{id}/resolve', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->resolveForumReport($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/reports/{id}/dismiss', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->dismissForumReport($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/topics/{id}/lock', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->lockForumTopic($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/topics/{id}/unlock', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->unlockForumTopic($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/topics/{id}/delete', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->deleteForumTopicAdmin($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/posts/{id}/edit', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->editForumPostAdmin($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/posts/{id}/delete', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->deleteForumPostAdmin($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/users/{id}/warn', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->warnForumUser($req, $p);
    }, $auth);
    $router->get('/api/v1/backstage/forum/audit', static function (Request $req) use ($container): Response {
        return $container->make(ForumModerationBackstageController::class)->listForumAudit($req);
    }, $auth);

    // ===== Backstage Content (9 routes) → ForumContentBackstageController =====
    $router->get('/api/v1/backstage/forum/stats', static function (Request $req) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->statsForum($req);
    }, $auth);
    $router->get('/api/v1/backstage/forum/topics', static function (Request $req) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->listForumTopicsAdmin($req);
    }, $auth);
    $router->post('/api/v1/backstage/forum/topics/{id}/pin', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->pinForumTopic($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/topics/{id}/unpin', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->unpinForumTopic($req, $p);
    }, $auth);
    $router->get('/api/v1/backstage/forum/posts', static function (Request $req) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->listForumPostsAdmin($req);
    }, $auth);
    $router->get('/api/v1/backstage/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->listForumCategoriesAdmin($req);
    }, $auth);
    $router->post('/api/v1/backstage/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->createForumCategoryAdmin($req);
    }, $auth);
    $router->post('/api/v1/backstage/forum/categories/{id}', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->updateForumCategoryAdmin($req, $p);
    }, $auth);
    $router->post('/api/v1/backstage/forum/categories/{id}/delete', static function (Request $req, array $p) use ($container): Response {
        return $container->make(ForumContentBackstageController::class)->deleteForumCategoryAdmin($req, $p);
    }, $auth);
};
```

**Verify count:** 28 `$router->` calls (7 public reads + 1 reports POST + 11 moderation + 9 content = 28; but spec says 26 — recount). Forum public reads + writes = 8. Backstage = 20. Total = **28** routes. Spec section 4 says 26 — discrepancy is the 2 view-incrementing + reports POST routes. **Cross-check the count by grep:** `grep -c '$router->' modules/forum/backend/routes.php` → expected 28.

- [ ] **Step 15.3 — Verify the route file is loaded**

```bash
cd C:/laragon/www/daems-platform && \
  php -r 'require "vendor/autoload.php"; \
          $container = require "bootstrap/app.php"; \
          $router = $container->make(\Daems\Infrastructure\Framework\Http\Router::class); \
          $count = count($router->routes()); \
          echo "Total routes: $count\n";'
```
Expected: count is the original total (which already included all Forum routes via `routes/api.php`) — should be unchanged because Tasks 22 hasn't yet removed the originals. **Routes are temporarily double-registered.** This is fine — the next-occurrence wins per Router contract.

- [ ] **Step 15.4 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/routes.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(routes): Forum HTTP routes (28 routes — 8 public + 11 moderation + 9 content)"
```

---

## Task 16–18: Move Unit tests (4 + 3 + 24 = 31 files)

**Repo for commits:** `dp-forum`

For each task: copy directory, rewrite namespaces, run tests inside the module, commit.

### Task 16: Domain unit tests (4)

- [ ] **Step 16.1 — Copy + rewrite**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/tests/Unit/Domain/Forum && \
  cp tests/Unit/Domain/Forum/*.php ../modules/forum/backend/tests/Unit/Domain/Forum/ && \
  cd ../modules/forum/backend/tests/Unit/Domain/Forum && \
  find . -name '*.php' -print0 | xargs -0 sed -i \
    -e 's|^namespace Daems\\Tests\\Unit\\Domain\\Forum;|namespace DaemsModule\\Forum\\Tests\\Unit\\Domain\\Forum;|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Tests\\Support\\\\Fake\\\\InMemoryForum|use DaemsModule\\Forum\\Tests\\Support\\InMemoryForum|g' \
    -e 's|use Daems\\Tests\\Support\\\\ForumSeed|use DaemsModule\\Forum\\Tests\\Support\\ForumSeed|g'
```

- [ ] **Step 16.2 — Run tests**

```bash
cd C:/laragon/www/modules/forum && vendor/bin/phpunit backend/tests/Unit/Domain
```
Expected: 4 test classes, all green.

- [ ] **Step 16.3 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/tests/Unit/Domain && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Domain Forum unit tests (4 files)"
```

### Task 17: Application/Forum unit tests (3)

Repeat Task 16 pattern for `tests/Unit/Application/Forum/*.php` (CreateForumTopicTest, CreateForumPostTest, ReportForumTargetTest). Namespace: `DaemsModule\Forum\Tests\Unit\Application\Forum`. Use rewrite includes both `Daems\Application\Forum\\` → `DaemsModule\Forum\Application\Forum\\` and `Daems\Domain\Forum\\` → `DaemsModule\Forum\Domain\\`.

- [ ] **Step 17.1 — Copy + rewrite**
- [ ] **Step 17.2 — Run tests** (expect 3 classes green)
- [ ] **Step 17.3 — Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
    -m "Move(tests): Application/Forum unit tests (3 files)"
```

### Task 18: Application/Backstage/Forum unit tests (24)

Same pattern. Namespace: `DaemsModule\Forum\Tests\Unit\Application\Backstage\Forum`. Use rewrite expands to also handle `Daems\Application\Backstage\Forum\\` → `DaemsModule\Forum\Application\Backstage\Forum\\`.

- [ ] **Step 18.1 — Copy + rewrite**
- [ ] **Step 18.2 — Run tests** (expect 24 classes green)
- [ ] **Step 18.3 — Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
    -m "Move(tests): Application/Backstage/Forum unit tests (24 files)"
```

---

## Task 19: Move Integration tests (2)

**Repo for commits:** `dp-forum`

**Files:** `tests/Integration/Persistence/SqlForumStatsTest.php`, `tests/Integration/Http/BackstageForumStatsTest.php`

- [ ] **Step 19.1 — Copy + rewrite**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/tests/Integration/Persistence \
           ../modules/forum/backend/tests/Integration/Http && \
  cp tests/Integration/Persistence/SqlForumStatsTest.php \
     ../modules/forum/backend/tests/Integration/Persistence/ && \
  cp tests/Integration/Http/BackstageForumStatsTest.php \
     ../modules/forum/backend/tests/Integration/Http/ && \
  cd ../modules/forum/backend/tests/Integration && \
  find . -name '*.php' -print0 | xargs -0 sed -i \
    -e 's|^namespace Daems\\Tests\\Integration\\\\|namespace DaemsModule\\Forum\\Tests\\Integration\\\\|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Application\\Forum\\\\|use DaemsModule\\Forum\\Application\\Forum\\\\|g' \
    -e 's|use Daems\\Application\\Backstage\\Forum\\\\|use DaemsModule\\Forum\\Application\\Backstage\\Forum\\\\|g' \
    -e 's|use Daems\\Infrastructure\\Adapter\\Persistence\\Sql\\SqlForum|use DaemsModule\\Forum\\Infrastructure\\SqlForum|g' \
    -e 's|use Daems\\Tests\\Support\\\\ForumSeed|use DaemsModule\\Forum\\Tests\\Support\\ForumSeed|g'
```

- [ ] **Step 19.2 — Run tests** (DB must be reachable)

```bash
cd C:/laragon/www/daems-platform && \
  composer test -- --filter 'SqlForumStatsTest|BackstageForumStatsTest'
```
Expected: 2 test classes green.

- [ ] **Step 19.3 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/tests/Integration && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Integration tests (SqlForumStats + BackstageForumStats)"
```

---

## Task 20: Move Isolation (2) + E2E (1)

**Repo for commits:** `dp-forum`

**Files:** `tests/Isolation/Forum*Test.php` (2), `tests/E2E/F005_ForumRoleImpersonationTest.php`

- [ ] **Step 20.1 — Copy + rewrite**

```bash
cd C:/laragon/www/daems-platform && \
  mkdir -p ../modules/forum/backend/tests/Isolation \
           ../modules/forum/backend/tests/E2E && \
  cp tests/Isolation/ForumTenantIsolationTest.php \
     tests/Isolation/ForumStatsTenantIsolationTest.php \
     ../modules/forum/backend/tests/Isolation/ && \
  cp tests/E2E/F005_ForumRoleImpersonationTest.php \
     ../modules/forum/backend/tests/E2E/ && \
  cd ../modules/forum/backend/tests && \
  find Isolation E2E -name '*.php' -print0 | xargs -0 sed -i \
    -e 's|^namespace Daems\\Tests\\\\\(Isolation\|E2E\);|namespace DaemsModule\\Forum\\Tests\\\1;|' \
    -e 's|use Daems\\Domain\\Forum\\\\|use DaemsModule\\Forum\\Domain\\\\|g' \
    -e 's|use Daems\\Application\\Forum\\\\|use DaemsModule\\Forum\\Application\\Forum\\\\|g' \
    -e 's|use Daems\\Application\\Backstage\\Forum\\\\|use DaemsModule\\Forum\\Application\\Backstage\\Forum\\\\|g' \
    -e 's|use Daems\\Infrastructure\\Adapter\\Persistence\\Sql\\SqlForum|use DaemsModule\\Forum\\Infrastructure\\SqlForum|g' \
    -e 's|use Daems\\Tests\\Support\\\\ForumSeed|use DaemsModule\\Forum\\Tests\\Support\\ForumSeed|g'
```

- [ ] **Step 20.2 — Run tests**

```bash
cd C:/laragon/www/daems-platform && \
  composer test -- --filter 'ForumTenantIsolationTest|ForumStatsTenantIsolationTest' && \
  composer test:e2e -- --filter 'F005_ForumRoleImpersonationTest'
```
Expected: 2 isolation + 1 E2E green.

- [ ] **Step 20.3 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add backend/tests/Isolation backend/tests/E2E && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Forum isolation (2) + F005 E2E"
```

---

## Task 21: Remove Forum bindings from `bootstrap/app.php`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `daems-platform/bootstrap/app.php`

- [ ] **Step 21.1 — Identify exact line ranges**

```bash
grep -n -E 'Forum|SqlForum' C:/laragon/www/daems-platform/bootstrap/app.php
```
Expected: a contiguous block of bindings (~30+ lines) plus `use` statements at top of file.

- [ ] **Step 21.2 — Delete those lines + use statements**

Use `Edit` tool repeatedly: each binding closure becomes a single `Edit` deletion. `use` lines at top of file become individual deletions. Do not use sed for this — `Edit` tool's exact-match safety is preferable for `bootstrap/app.php`.

- [ ] **Step 21.3 — Verify no Forum references remain**

```bash
grep -n -E 'Forum|SqlForum' C:/laragon/www/daems-platform/bootstrap/app.php
```
Expected: zero results.

- [ ] **Step 21.4 — Run controller smoke test**

```bash
cd C:/laragon/www/daems-platform && php /tmp/smoke-forum-controllers.php
```
Expected: `OK` — controllers are now resolved entirely through the module's `bindings.php`. Any TypeError here indicates a constructor mismatch — go back and fix `modules/forum/backend/bindings.php`.

- [ ] **Step 21.5 — PHPStan + tests**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```
Expected: 0 errors.

- [ ] **Step 21.6 — Commit (in `daems-platform/`)**

```bash
cd C:/laragon/www/daems-platform && \
  git add bootstrap/app.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(bootstrap): Forum bindings — module owns them now"
```

---

## Task 22: Remove Forum routes from `routes/api.php`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `daems-platform/routes/api.php` lines 179–208 + 411–491 + 492–494
- Also remove unused `use Daems\Infrastructure\Adapter\Api\Controller\ForumController;` at line 9

- [ ] **Step 22.1 — Use `Edit` tool to delete each route block**

Three blocks total (per spec section 5). Delete corresponding `use ForumController;` import too.

- [ ] **Step 22.2 — Verify**

```bash
grep -n -E 'Forum|forum' C:/laragon/www/daems-platform/routes/api.php
```
Expected: zero results.

- [ ] **Step 22.3 — Smoke browser-side**

```bash
curl -i http://daem-society.local/api/v1/forum/categories
```
Expected: `200 OK` with the same JSON as before — module's `routes.php` is registered, double-registration is gone.

```bash
curl -i http://daem-society.local/api/v1/backstage/forum/stats -H "Cookie: <auth>"
```
Expected: `200 OK` (or `401`/`403` depending on auth state — but NOT `404`).

- [ ] **Step 22.4 — Run all tests**

```bash
cd C:/laragon/www/daems-platform && composer test:all 2>&1 | tail -20
```
Expected: all green.

- [ ] **Step 22.5 — Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add routes/api.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(routes): Forum routes — module owns them now"
```

---

## Task 23: Remove Forum methods from `BackstageController.php`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 23.1 — Identify ranges to delete**

20 methods spanning lines 680–1232. Constructor (line 141) also takes Forum-related services that are now dead — those constructor params + corresponding `use` statements must come out too.

- [ ] **Step 23.2 — Delete methods**

Use `Edit` tool with whole-method exact strings. Each method body becomes one `Edit` call.

- [ ] **Step 23.3 — Update constructor**

Remove every Forum-related constructor parameter + property. Cross-reference the ones removed against `bootstrap/app.php` `BackstageController` binding (which Task 21 also pruned).

- [ ] **Step 23.4 — Update `use` statements at file top**

Remove `use Daems\Application\Backstage\Forum\*;` and `use Daems\Application\Forum\Shared\ForumIdentityDeriver;` and `use Daems\Domain\Forum\*;` lines.

- [ ] **Step 23.5 — Verify**

```bash
grep -nE 'Forum|forum' C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```
Expected: zero results.

- [ ] **Step 23.6 — Run all tests**

```bash
cd C:/laragon/www/daems-platform && composer test:all && composer analyse 2>&1 | tail -5
```
Expected: all green, 0 PHPStan errors.

- [ ] **Step 23.7 — Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(controller): 20 Forum methods from BackstageController — module owns them"
```

---

## Task 24: Remove Forum bindings from `KernelHarness.php`

**Repo for commits:** `daems-platform`

**Files:**
- Modify: `daems-platform/tests/Support/KernelHarness.php`

- [ ] **Step 24.1 — Identify ranges**

```bash
grep -nE 'Forum|InMemoryForum' C:/laragon/www/daems-platform/tests/Support/KernelHarness.php
```
Expected: ~20+ references — repository singletons, use case binds, controller binds, InMemory imports.

- [ ] **Step 24.2 — Delete them**

Use `Edit` tool. Module's `bindings.test.php` covers everything now (Tasks 14 + module registry already wired in Insights pilot).

- [ ] **Step 24.3 — Verify**

```bash
grep -nE 'Forum|InMemoryForum' C:/laragon/www/daems-platform/tests/Support/KernelHarness.php
```
Expected: zero.

- [ ] **Step 24.4 — Run all tests**

```bash
cd C:/laragon/www/daems-platform && composer test:all 2>&1 | tail -20
```
Expected: all green. Module's `bindings.test.php` carries the load.

- [ ] **Step 24.5 — Commit**

```bash
git add tests/Support/KernelHarness.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(harness): KernelHarness Forum bindings — module owns them now"
```

---

## Task 25: Apply data-fix migration; delete originals

**Repo for commits:** `daems-platform`

- [ ] **Step 25.1 — Apply `065_*` to dev DB**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db \
  < database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql
```
Expected: 9 rows updated; subsequent run is idempotent.

- [ ] **Step 25.2 — Delete original Forum source dirs**

```bash
cd C:/laragon/www/daems-platform && \
  rm -rf src/Domain/Forum src/Application/Forum src/Application/Backstage/Forum && \
  rm src/Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository.php && \
  rm src/Infrastructure/Adapter/Api/Controller/ForumController.php
```

- [ ] **Step 25.3 — Delete original Forum test dirs + InMemory fakes**

```bash
rm -rf tests/Unit/Domain/Forum tests/Unit/Application/Forum tests/Unit/Application/Backstage/Forum && \
  rm tests/Integration/Persistence/SqlForumStatsTest.php \
     tests/Integration/Http/BackstageForumStatsTest.php \
     tests/Isolation/ForumTenantIsolationTest.php \
     tests/Isolation/ForumStatsTenantIsolationTest.php \
     tests/E2E/F005_ForumRoleImpersonationTest.php \
     tests/Support/Fake/InMemoryForumRepository.php \
     tests/Support/Fake/InMemoryForumModerationAuditRepository.php \
     tests/Support/Fake/InMemoryForumReportRepository.php \
     tests/Support/Fake/InMemoryForumUserWarningRepository.php \
     tests/Support/ForumSeed.php
```

- [ ] **Step 25.4 — Delete original 9 migration files**

```bash
rm database/migrations/007_create_forum_tables.sql \
   database/migrations/013_add_user_id_to_forum.sql \
   database/migrations/030_add_tenant_id_to_forum_tables.sql \
   database/migrations/047_add_locked_to_forum_topics.sql \
   database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql \
   database/migrations/049_extend_dismissals_enum_forum_report.sql \
   database/migrations/050_widen_app_id_for_compound_forum_report.sql \
   database/migrations/061_search_text_forum_topics.sql \
   database/migrations/061_search_text_forum_topics_backfill.php
```

- [ ] **Step 25.5 — Verify nothing Forum left in core**

```bash
cd C:/laragon/www/daems-platform && \
  git ls-files | grep -i forum
```
Expected: only `database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql` and the spec/plan markdown files in `docs/superpowers/`.

- [ ] **Step 25.6 — Run all tests**

```bash
composer test:all 2>&1 | tail -30
composer analyse 2>&1 | tail -5
```
Expected: all green, 0 PHPStan errors.

- [ ] **Step 25.7 — Commit**

```bash
git add -u && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(forum): legacy Forum code — moved to modules/forum/"
```

---

## Task 26: Move daem-society public pages (8 files)

**Repo for commits:** `dp-forum` (move-in) + `daem-society` (move-out at end)

**Files:** Copy `daem-society/public/pages/forum/{index,categories,category,thread,new-topic,hero,cta,stats}.php` → `modules/forum/frontend/public/`

- [ ] **Step 26.1 — Copy files**

```bash
cd C:/laragon/www/sites/daem-society && \
  cp -r public/pages/forum/. C:/laragon/www/modules/forum/frontend/public/
```

- [ ] **Step 26.2 — Identify `__DIR__` includes that need rewriting**

```bash
cd C:/laragon/www/modules/forum/frontend/public && \
  grep -rn '__DIR__' --include='*.php' .
```
Inventory: every `include __DIR__ . '/../...'` must convert to `include DAEMS_SITE_PUBLIC . '/...'`.

- [ ] **Step 26.3 — Apply rewrites**

For each match, work out the absolute target path, verify the file lives at `daem-society/public/<that-path>`, and replace with `DAEMS_SITE_PUBLIC . '/<path>'`. Check chrome includes: `top-nav.php`, `footer.php`, `layout.php`, `empty-state.php`, `ApiClient.php`, `errors/404.php`, `errors/500.php`.

- [ ] **Step 26.4 — Identify asset URLs in moved files**

```bash
grep -rn 'pages/forum/\|pages/backstage/forum/' --include='*.php' .
```
Expected: zero — these files do not reference each other through `/pages/forum/` URLs but may reference `/modules/forum/...` already (after Task 28).

- [ ] **Step 26.5 — Smoke-test page loads**

Browser: visit `http://daem-society.local/forum`, verify 200 + same render. Check JS console + Network tab.

- [ ] **Step 26.6 — Commit (in `modules/forum/`)**

```bash
cd C:/laragon/www/modules/forum && \
  git add frontend/public && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 8 public Forum pages with DAEMS_SITE_PUBLIC includes"
```

---

## Task 27: Move daem-society backstage pages (5 PHP + 4 sub-paths) + asset URL update

**Repo for commits:** `dp-forum`

**Files:** Copy `daem-society/public/pages/backstage/forum/` (PHP files only) → `modules/forum/frontend/backstage/`. JS/CSS/SVG handled in Task 28.

- [ ] **Step 27.1 — Copy PHP files only**

```bash
cd C:/laragon/www/sites/daem-society/public/pages/backstage/forum && \
  cp index.php forum-kpi-strip.php C:/laragon/www/modules/forum/frontend/backstage/ && \
  mkdir -p C:/laragon/www/modules/forum/frontend/backstage/{reports,topics,categories,audit} && \
  cp reports/index.php     C:/laragon/www/modules/forum/frontend/backstage/reports/ && \
  cp topics/index.php      C:/laragon/www/modules/forum/frontend/backstage/topics/ && \
  cp categories/index.php  C:/laragon/www/modules/forum/frontend/backstage/categories/ && \
  cp audit/index.php       C:/laragon/www/modules/forum/frontend/backstage/audit/
```

- [ ] **Step 27.2 — Rewrite `__DIR__` chrome includes** (same pattern as Task 26 step 2-3)

- [ ] **Step 27.3 — Update `<script src=>` and `<link href=>` URLs to point at new asset paths**

```bash
cd C:/laragon/www/modules/forum/frontend/backstage && \
  grep -rn 'pages/backstage/forum/' --include='*.php' .
```

For each match, replace:
- `/pages/backstage/forum/forum.css` → `/modules/forum/assets/backstage/forum.css`
- `/pages/backstage/forum/forum-dashboard.js` → `/modules/forum/assets/backstage/forum-dashboard.js`
- `/pages/backstage/forum/forum-kpi-strip.js` → `/modules/forum/assets/backstage/forum-kpi-strip.js`
- `/pages/backstage/forum/forum-reports-page.js` → `/modules/forum/assets/backstage/forum-reports-page.js`
- `/pages/backstage/forum/forum-topics-page.js` → `/modules/forum/assets/backstage/forum-topics-page.js`
- `/pages/backstage/forum/forum-categories-page.js` → `/modules/forum/assets/backstage/forum-categories-page.js`
- `/pages/backstage/forum/forum-audit-page.js` → `/modules/forum/assets/backstage/forum-audit-page.js`
- `/pages/backstage/forum/empty-state-topics.svg` → `/modules/forum/assets/backstage/empty-state-topics.svg`
- `/pages/backstage/forum/empty-state-reports.svg` → `/modules/forum/assets/backstage/empty-state-reports.svg`

- [ ] **Step 27.4 — Update `forum-kpi-strip.php` includers**

```bash
grep -rn 'forum-kpi-strip.php' --include='*.php' .
```
Verify the `include`/`require` paths are still correct (e.g. `__DIR__ . '/forum-kpi-strip.php'` if same directory). Adjust if needed.

- [ ] **Step 27.5 — Smoke-test backstage pages load**

Browser: visit `/backstage/forum`, `/backstage/forum/{reports,topics,categories,audit}`. Verify 200 + same render. JS console: zero errors.

- [ ] **Step 27.6 — Commit**

```bash
cd C:/laragon/www/modules/forum && \
  git add frontend/backstage && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 5 backstage Forum pages + asset URL rewrite to /modules/forum/assets/"
```

---

## Task 28: Move assets (6 JS + 1 CSS + 2 SVG); delete originals

**Repo for commits:** `dp-forum` (move-in) + `daem-society` (move-out)

- [ ] **Step 28.1 — Copy assets**

```bash
cd C:/laragon/www/sites/daem-society/public/pages/backstage/forum && \
  mkdir -p C:/laragon/www/modules/forum/frontend/assets/backstage && \
  cp forum.css \
     forum-dashboard.js forum-kpi-strip.js forum-reports-page.js \
     forum-topics-page.js forum-categories-page.js forum-audit-page.js \
     empty-state-topics.svg empty-state-reports.svg \
     C:/laragon/www/modules/forum/frontend/assets/backstage/
```

- [ ] **Step 28.2 — Smoke-test asset URLs**

Browser: visit `/modules/forum/assets/backstage/forum.css`. Expected: `200 OK`, valid CSS. Same for each JS + SVG.

```bash
for f in forum.css forum-dashboard.js forum-kpi-strip.js forum-reports-page.js \
         forum-topics-page.js forum-categories-page.js forum-audit-page.js \
         empty-state-topics.svg empty-state-reports.svg; do
  curl -s -o /dev/null -w "%{http_code} /modules/forum/assets/backstage/$f\n" \
       "http://daem-society.local/modules/forum/assets/backstage/$f"
done
```
Expected: 9× `200`.

- [ ] **Step 28.3 — Commit assets to dp-forum**

```bash
cd C:/laragon/www/modules/forum && \
  git add frontend/assets && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 6 JS + 1 CSS + 2 SVG to assets/backstage/"
```

- [ ] **Step 28.4 — Delete originals from daem-society**

```bash
cd C:/laragon/www/sites/daem-society && \
  rm -rf public/pages/forum public/pages/backstage/forum
```

- [ ] **Step 28.5 — Smoke-test pages still render via module router**

Browser: visit `/forum`, `/forum/category?slug=X`, `/forum/thread?slug=Y`, `/backstage/forum/{,reports,topics,categories,audit}`. All 200, same render, zero JS console errors.

- [ ] **Step 28.6 — Commit (in `daem-society/`)**

```bash
cd C:/laragon/www/sites/daem-society && \
  git add -u && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(frontend): forum + backstage/forum dirs — module router serves them now"
```

---

## Task 29: Final verification gate

**Repo for commits:** none (verification only — no new commits if green; new fix commits if red)

**Goal:** Prove the refactor is zero-regression before declaring "valmis".

- [ ] **Step 29.1 — `composer analyse`**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -20
```
Expected: 0 errors at PHPStan level 9.

- [ ] **Step 29.2 — `composer test:all`**

```bash
cd C:/laragon/www/daems-platform && composer test:all 2>&1 | tail -50
```
Expected: all green (Unit, Integration, Isolation, E2E suites all in `daems-platform` AND in module via `phpunit.xml.dist` autoloaded testsuites).

- [ ] **Step 29.3 — Production-container controller smoke**

```bash
php /tmp/smoke-forum-controllers.php
```
Expected: `OK: DaemsModule\Forum\Controller\ForumController | ... | ...`. No `TypeError`.

- [ ] **Step 29.4 — `git grep` cleanup verification**

```bash
cd C:/laragon/www/daems-platform && \
  git grep -E 'Daems\\\\(Domain\\\\Forum|Application\\\\Forum|Application\\\\Backstage\\\\Forum|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlForum|Infrastructure\\\\Adapter\\\\Api\\\\Controller\\\\ForumController)' \
    -- src/ tests/ bootstrap/ routes/ 2>/dev/null
```
Expected: zero results.

```bash
cd C:/laragon/www/daems-platform && \
  git grep -E 'InMemoryForum' -- tests/ 2>/dev/null
```
Expected: zero results in `daems-platform/tests/`.

- [ ] **Step 29.5 — Browser smoke-test (mandatory, Insights lesson #6)**

User runs through this checklist manually:

| URL | Expected |
|---|---|
| `GET http://daem-society.local/forum` | Public listing renders, identical to pre-move |
| `GET http://daem-society.local/forum/category?slug=<existing>` | Category renders |
| `GET http://daem-society.local/forum/thread?slug=<existing>` | Thread renders, posts visible |
| `POST /api/v1/forum/topics/<slug>/posts` (via UI) | New post appears |
| `POST /api/v1/forum/posts/<id>/like` (via UI) | Like count updates |
| `GET http://daem-society.local/backstage/forum` | Dashboard + KPI strip + 4 quick-link tiles render |
| `GET .../backstage/forum/reports` | Reports table renders |
| `GET .../backstage/forum/topics` | Topics table renders |
| `GET .../backstage/forum/categories` | Categories form + list renders |
| `GET .../backstage/forum/audit` | Audit log renders |
| Network tab | Every `/modules/forum/assets/backstage/*` returns 200 |
| JS console | Zero errors |
| Theme | Backstage parity with other admin pages — light/dark variants equivalent |

**Block on any failure** — fix, recommit, re-run gate.

- [ ] **Step 29.6 — Final report to user**

Provide:
- All commit SHAs in `daems-platform`, `dp-forum`, `daem-society`
- `composer test:all` summary
- PHPStan summary
- Browser smoke-test screenshot/note from user
- Awaiting "pushaa" to push all 3 repos.

---

## Self-review notes

**Spec coverage check:** every section of the spec maps to one or more tasks:
- Spec §2 inventory → Tasks 4–9, 12, 16–20
- Spec §3 manifest → Task 1
- Spec §4 bindings + routes → Tasks 13–15
- Spec §5 removals → Tasks 21–25
- Spec §6 namespace rewrite map → applied in every move task
- Spec §7 frontend integration → Tasks 26–28
- Spec §8 BOTH-wiring rule → Tasks 13 + 14 + 21 + 24 + 29 (smoke)
- Spec §9 verification gates → Task 29
- Spec §10 risks → mitigations distributed across tasks (controller-instantiation in Task 13.4 + 21.4 + 29.3, namespace verify in every Wave B task, etc.)
- Spec §12 success criteria → enforced in Task 29
