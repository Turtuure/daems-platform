# Modular Architecture — Phase 1 (Events extraction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the Events domain (Event + EventProposal + EventRegistration) from `daems-platform` core into `modules/events/` (= `dp-events` repo), following the convention proven by the Insights pilot, the Forum extraction (943/943 tests green, 2026-04-27, including the Wave F regression-fix lesson), and the Projects extraction design (in flight). Zero user-visible change.

**Architecture:** All Events backend code (`src/Domain/Event/` — MOVES this time, unlike Forum/Projects — `src/Application/Event/`, 15 sibling dirs of `src/Application/Backstage/<UseCase>/`, 2 SQL repos, public + backstage controllers) moves under `modules/events/backend/src/` with namespace `DaemsModule\Events\*`. **`src/Domain/Event/` (7 files) MOVES to module** because zero cross-domain consumers were verified by grep — Insights pattern, NOT Forum pattern. A new single `EventBackstageController` extracts 13 Event methods from `BackstageController` + 2 image methods (`uploadEventImage` / `deleteEventImage`) relocated from `MediaController` (preserves Q3-A strict isolation by removing core MediaController's reliance on Event types). All Event-specific bindings + 22 routes + tests + 7 event-pure migrations move with the code. Migrations 053/054/059 (mixed events+projects) STAY in core — same as Projects extraction kept them. The `daem-society` frontend `public/pages/{events,backstage/events,backstage/event-proposals}/` directories move to `modules/events/frontend/{public,backstage,assets}/`. **Wave F task ORDER explicit:** front-controller routes update FIRST + curl-smoke gate, THEN delete originals — prevents the `/forums`-style HTTP 500 regression we just fixed in Forum.

**Tech Stack:** PHP 8.3, Composer (runtime ClassLoader), MySQL 8.4, PHPUnit 10.5, PHPStan 2.x level 9 (with `phpstan-baseline.neon` from PHPStan-2.x upgrade — 33 baselined errors, none in Event code). No new external dependencies.

**Spec:** `docs/superpowers/specs/2026-04-27-modular-architecture-phase1-events-design.md` (commit `8dbf733`)

**Repos affected (3 commit streams):**
- `C:\laragon\www\daems-platform\` (branch `dev`) — new data-fix migration + autoload-dev/phpstan paths commit + Wave E removals + KernelHarness cleanup
- `C:\laragon\www\modules\events\` = `dp-events` repo (existing local `.git`, origin `Turtuure/dp-events`, branch `dev`, no commits yet) — manifest + all moved Events code
- `C:\laragon\www\sites\daem-society\` (branch `dev`) — Wave F front-controller routes update commit + Wave F delete-originals commit (module-router already in place)

**Verification gates (must pass before final commit on any task touching moved code):**
- `composer analyse` → 0 errors at PHPStan level 9 (with baseline)
- `composer test` (Unit + Integration) → all green
- `composer test:e2e` → all green
- `composer test:all` → all green (run as 4 separate suites if total exceeds 600s — Forum gotcha 3)
- `GET /events`, `/events/{slug}`, `/events/propose`, `/backstage/events`, `/backstage/event-proposals` render identically to pre-move
- `GET /api/v1/events` returns identical JSON to pre-move
- `POST /api/v1/event-proposals`, `/events/{slug}/register`, `/events/{slug}/unregister` work
- All admin endpoints under `/api/v1/backstage/events/*` and `/backstage/event-proposals/*` work — exercise create/update/publish/archive/translation/registration/image-upload/approve/reject flows
- JS console: zero errors; Network: every `/modules/events/assets/backstage/*` returns 200

**Commit identity:** EVERY commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. No `Co-Authored-By` trailer. Never push without explicit "pushaa". `.claude/` never staged (`git reset HEAD .claude/` if needed).

**dp-events push cadence:** repo accumulates commits during plan execution. First push (initial branch publication) happens after Task 1 completes. Subsequent pushes deferred until plan completion + user confirmation.

**Forbidden:** do NOT call `mcp__code-review-graph__*` tools — they hung subagent sessions during PR 3 Task 1.

---

## Task waves (dependency order)

```
Wave A (parallel-safe)
├── Task 1: dp-events skeleton (manifest + README + .gitignore + phpunit + composer + STUB bindings/routes)
├── Task 2: Core data-fix migration 0NN_rename_event_migrations_in_schema_migrations_table.sql
└── Task 3: Events file inventory verification (read-only)

Wave B (sequential, depends on Wave A)
├── Task 4: Move Domain layer (7 files) + namespace rewrite
├── Task 5: Move 2 SQL repos + namespace rewrite
├── Task 6: Move 2 InMemory fakes + namespace rewrite
├── Task 7: Move Application/Event (~21 files) + namespace rewrite
├── Task 8: Move 15 admin use case dirs (~41 files) + namespace rewrite
├── Task 9: Move EventController + namespace rewrite
├── Task 9.5: NEW infra commit on daems-platform — autoload-dev + phpstan paths
├── Task 10: Extract EventBackstageController (TDD, 15 methods: 13 from BackstageController + 2 from MediaController)
└── Task 12: Move 7 migrations with event_NNN_* rename

Wave C (wiring, depends on Wave B)
├── Task 13: bindings.php (production) — production-container smoke after
├── Task 14: bindings.test.php (test container)
└── Task 15: routes.php (22 routes)

Wave D (test moves, depends on Wave B + C)
├── Task 16: Move Domain unit test (1 file: EventTest.php)
├── Task 17: Move tests/Unit/Application/Event (1 file: RegisterForEventTest.php)
├── Task 18: Move 10 Backstage Event unit tests
├── Task 19: Move 5 integration tests
└── Task 20: Move isolation (5) + E2E (5)

Wave E (core cleanup — production-smoke after EACH task)
├── Task 21: Remove Events bindings from bootstrap/app.php
├── Task 22: Remove Events routes from routes/api.php (-22 routes, including 2 image inline closures)
├── Task 23: Remove 13 Event methods from BackstageController + 2 Event image methods from MediaController
├── Task 24: Remove Events bindings from KernelHarness
└── Task 25: Apply data-fix migration; delete originals + dangling Migration tests

Wave F (frontend — REGRESSION-PREVENTION ORDER, depends on Wave E)
├── Task 26: FIRST update daem-society/public/index.php front-controller routes (5 paths) to point at $daemsKnownModules['events'] + curl-smoke gate
├── Task 27: Move 8 daem-society public pages + __DIR__ rewrite
├── Task 28: Move 1 backstage events PHP + 1 event-proposals PHP + asset URL update
└── Task 29: Move 6 assets (CSS + JS) + delete daem-society originals

Wave G (verification gate)
└── Task 30: Final verification — PHPStan + composer test:all + production-smoke + git grep + browser smoke
```

**Tasks 11 (Second controller) explicitly DROPPED** per E4=A decision (single controller of 15 methods is below 20-method threshold).

---

## File map summary

**Created in `daems-platform/`:**
- `database/migrations/0NN_rename_event_migrations_in_schema_migrations_table.sql` (NN to be assigned during Task 2 = highest existing + 1)

**Modified in `daems-platform/`:**
- `composer.json` (autoload-dev `DaemsModule\\Events\\` + `DaemsModule\\Events\\Tests\\`)
- `phpstan.neon` (paths += `../modules/events/backend/src`)
- `bootstrap/app.php` — remove ~9 import lines + ~30 binding lines for Events
- `routes/api.php` — remove 22 Events routes (7 public + 12 backstage events + 3 backstage event-proposals)
- `tests/Support/KernelHarness.php` — remove Events bindings + InMemory fake registrations
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 13 Event methods (lines 293–401, 639–740, 812–855)
- `src/Infrastructure/Adapter/Api/Controller/MediaController.php` — remove 2 Event image methods (`uploadEventImage` + `deleteEventImage`)

**Deleted in `daems-platform/`:**
- `src/Domain/Event/` (entire dir, 7 files)
- `src/Application/Event/` (entire dir, 8 sub-dirs, ~21 files)
- 15 admin sibling dirs under `src/Application/Backstage/`: `ApproveEventProposal`, `ArchiveEvent`, `CreateEvent`, `DeleteEventImage`, `Events`, `GetEventWithAllTranslations`, `ListEventProposalsForAdmin`, `ListEventRegistrations`, `ListEventsForAdmin`, `PublishEvent`, `RejectEventProposal`, `UnregisterUserFromEvent`, `UpdateEvent`, `UpdateEventTranslation`, `UploadEventImage` (~41 files)
- `src/Infrastructure/Adapter/Persistence/Sql/Sql{Event,EventProposal}Repository.php` (2 files)
- `src/Infrastructure/Adapter/Api/Controller/EventController.php`
- `database/migrations/{001,012,025,032,043,051,056}_*.sql` (7 files — only event-pure ones)
- `tests/Unit/Domain/Event/EventTest.php` (1)
- `tests/Unit/Application/Event/RegisterForEventTest.php` (1)
- `tests/Unit/Application/Backstage/{ArchiveEvent,CreateEvent,DeleteEventImage,ListEventRegistrations,ListEventsForAdmin,ListEventsStats,PublishEvent,UnregisterUserFromEvent,UpdateEvent,UploadEventImage}Test.php` (10)
- `tests/Integration/Application/EventsAdminIntegrationTest.php`
- `tests/Integration/{EventProposalStatsTest,EventStatsTest}.php` (2)
- `tests/Integration/Infrastructure/{SqlEventProposalRepositoryTest,SqlEventRepositoryI18nTest}.php` (2)
- `tests/Isolation/{EventProposalTenantIsolationTest,EventsAdminTenantIsolationTest,EventsI18nTenantIsolationTest,EventsStatsTenantIsolationTest,EventTenantIsolationTest}.php` (5)
- `tests/E2E/Backstage/{EventAdminEndpointsTest,EventsStatsEndpointTest,EventUploadTest}.php` (3)
- `tests/E2E/{EventProposalFlowE2ETest,EventsLocaleE2ETest}.php` (2)
- `tests/Support/Fake/InMemoryEvent{,Proposal}Repository.php` (2)
- Any dangling `tests/Integration/Migration/Migration{025,032,043,051,056}Test.php` files (verify during Task 25)

**Retained in `daems-platform/` (NOT deleted):**
- Migrations `database/migrations/{053_backfill_events_projects_i18n,054_drop_translated_columns_from_events_projects,059_fulltext_events_projects_i18n}.sql` — mixed scope (touch both events + projects). Same retention as Projects extraction.

**Created in `modules/events/` (dp-events):**
- `module.json`, `README.md`, `.gitignore`, `phpunit.xml.dist`, `composer.json`
- `backend/bindings.php`, `backend/bindings.test.php`, `backend/routes.php`
- `backend/migrations/event_001..007_*.sql` (7 files)
- `backend/src/Domain/{Event,EventId,EventProposal,EventProposalId,EventProposalRepositoryInterface,EventRegistration,EventRepositoryInterface}.php` (7 files)
- `backend/src/Application/{GetEvent,GetEventBySlugForLocale,ListEvents,ListEventsForLocale,RegisterForEvent,SubmitEventProposal,UnregisterFromEvent,UpdateEvent}/*` (~21 files)
- `backend/src/Application/Backstage/*` (~41 files in 15 dirs)
- `backend/src/Infrastructure/Sql{Event,EventProposal}Repository.php` (2 files)
- `backend/src/Controller/EventController.php`
- `backend/src/Controller/EventBackstageController.php` (NEW — 15 methods extracted via TDD)
- `backend/tests/Support/InMemoryEvent{,Proposal}Repository.php` (2 files)
- `backend/tests/Unit/Domain/EventTest.php` (1)
- `backend/tests/Unit/Application/RegisterForEventTest.php` (1)
- `backend/tests/Unit/Application/Backstage/*Test.php` (10)
- `backend/tests/Integration/Application/EventsAdminIntegrationTest.php`
- `backend/tests/Integration/{EventProposalStatsTest,EventStatsTest}.php` (2)
- `backend/tests/Integration/Infrastructure/{SqlEventProposalRepositoryTest,SqlEventRepositoryI18nTest}.php` (2)
- `backend/tests/Isolation/Event*Test.php` (5)
- `backend/tests/E2E/Backstage/{EventAdminEndpointsTest,EventsStatsEndpointTest,EventUploadTest}.php` (3)
- `backend/tests/E2E/{EventProposalFlowE2ETest,EventsLocaleE2ETest}.php` (2)
- `frontend/public/{cta,detail,grid,hero,index,propose}.php` (6) + `frontend/public/detail/*.php` + `frontend/public/data/*` (final inventory in Task 27) ≈ 8
- `frontend/backstage/index.php` (events admin) + `frontend/backstage/event-proposals/index.php` (proposals admin) = 2
- `frontend/assets/backstage/{event-modal.css,event-modal.js,events-stats.js,upload-widget.js,proposal-modal.css,proposal-modal.js}` (6)

**Modified in `daem-society/`:**
- `public/index.php` — front-controller routes update: 5 paths `__DIR__ . '/pages/events/...'` → `$daemsKnownModules['events']['public']` / `['backstage']` (Task 26)

**Deleted in `daem-society/`:**
- `public/pages/events/` (entire dir, 8 files + sub-dirs)
- `public/pages/backstage/events/` (entire dir, 5 files)
- `public/pages/backstage/event-proposals/` (entire dir, 3 files)

---

## Task 1: dp-events repo skeleton

**Repo for commits:** `dp-events` (the new module). Local `.git` already initialised at `C:\laragon\www\modules\events\` with `origin = https://github.com/Turtuure/dp-events.git`, HEAD → `refs/heads/dev`. Working copy is empty (no commits).

**Files:**
- Create: `C:/laragon/www/modules/events/module.json`
- Create: `C:/laragon/www/modules/events/README.md`
- Create: `C:/laragon/www/modules/events/.gitignore`
- Create: `C:/laragon/www/modules/events/phpunit.xml.dist`
- Create: `C:/laragon/www/modules/events/composer.json`
- Create: `C:/laragon/www/modules/events/backend/bindings.php` (STUB)
- Create: `C:/laragon/www/modules/events/backend/bindings.test.php` (STUB)
- Create: `C:/laragon/www/modules/events/backend/routes.php` (STUB)
- Create: `C:/laragon/www/modules/events/backend/migrations/.gitkeep`
- Create: `C:/laragon/www/modules/events/backend/src/.gitkeep`
- Create: `C:/laragon/www/modules/events/backend/tests/.gitkeep`
- Create: `C:/laragon/www/modules/events/frontend/public/.gitkeep`
- Create: `C:/laragon/www/modules/events/frontend/backstage/.gitkeep`
- Create: `C:/laragon/www/modules/events/frontend/assets/.gitkeep`

- [ ] **Step 1: Verify GitHub repo exists**

```bash
gh repo view Turtuure/dp-events 2>&1 | head -3
```

If "GraphQL: Could not resolve to a Repository" appears, create:
```bash
gh repo create Turtuure/dp-events --public --description "Events module for daems-platform — extracted Phase 1" --homepage "https://daems.fi"
```

Otherwise skip.

- [ ] **Step 2: Create `module.json`**

Path: `C:/laragon/www/modules/events/module.json`
```json
{
  "name": "events",
  "version": "1.0.0",
  "description": "Events + event registrations + event proposals — public listing/detail/registration + admin CRUD + proposals workflow",
  "namespace": "DaemsModule\\Events\\",
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

Path: `C:/laragon/www/modules/events/README.md`
```markdown
# dp-events — Events module

Extracted from `daems-platform` Phase 1, 2026-04-27. Pattern proven by Insights pilot + Forum extraction + Projects extraction.

## Structure

- `module.json` — manifest read by core's `ModuleRegistry`
- `backend/src/` — PHP code under namespace `DaemsModule\Events\`
- `backend/bindings.php` — production DI bindings
- `backend/bindings.test.php` — test container bindings (InMemory fakes)
- `backend/routes.php` — public + backstage HTTP route registrations
- `backend/migrations/` — `event_NNN_*.sql` migrations
- `frontend/public/`, `frontend/backstage/`, `frontend/assets/` — daem-society UI

## Conventions

- PHPStan level 9 = 0 errors
- Tests use core's `KernelHarness`; per-module `bindings.test.php` swaps InMemory fakes
- Domain `Daems\Domain\Event\*` MOVED into module (no cross-domain consumers in core)
- No `Co-Authored-By` in commits; identity is `Dev Team <dev@daems.fi>`
```

- [ ] **Step 4: Create `.gitignore`**

Path: `C:/laragon/www/modules/events/.gitignore`
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

Path: `C:/laragon/www/modules/events/phpunit.xml.dist`
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

Path: `C:/laragon/www/modules/events/composer.json`
```json
{
  "name": "daems/dp-events",
  "description": "Events module for daems-platform",
  "type": "project",
  "license": "proprietary",
  "autoload": {
    "psr-4": {
      "DaemsModule\\Events\\": "backend/src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "DaemsModule\\Events\\Tests\\": "backend/tests/"
    }
  },
  "require": {
    "php": "^8.3"
  }
}
```

- [ ] **Step 7: Create STUB `backend/bindings.php`**

Path: `C:/laragon/www/modules/events/backend/bindings.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Bindings will be added in Task 13.
};
```

- [ ] **Step 8: Create STUB `backend/bindings.test.php`**

Path: `C:/laragon/www/modules/events/backend/bindings.test.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Test bindings will be added in Task 14.
};
```

- [ ] **Step 9: Create STUB `backend/routes.php`**

Path: `C:/laragon/www/modules/events/backend/routes.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;

return static function (Router $router, Container $container): void {
    // Routes will be added in Task 15.
};
```

- [ ] **Step 10: Create directory placeholders**

```bash
mkdir -p C:/laragon/www/modules/events/backend/{migrations,src,tests} \
         C:/laragon/www/modules/events/frontend/{public,backstage,assets}
touch C:/laragon/www/modules/events/backend/migrations/.gitkeep \
      C:/laragon/www/modules/events/backend/src/.gitkeep \
      C:/laragon/www/modules/events/backend/tests/.gitkeep \
      C:/laragon/www/modules/events/frontend/public/.gitkeep \
      C:/laragon/www/modules/events/frontend/backstage/.gitkeep \
      C:/laragon/www/modules/events/frontend/assets/.gitkeep
```

- [ ] **Step 11: Verify ModuleRegistry can boot the empty module**

Run from `C:/laragon/www/daems-platform`:
```bash
vendor/bin/phpunit --testsuite=E2E --filter=KernelHarness 2>&1 | tail -10
```
Expected: tests pass (or skipped). Stub closures prevent the "missing bindings.php" crash that hit Forum extraction.

- [ ] **Step 12: Commit + push initial branch**

```bash
cd C:/laragon/www/modules/events && \
  git add -A && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Skeleton: module.json + manifest + STUB bindings + routes" && \
  git push -u origin dev
```

(First push initialises remote `dev` branch.)

---

## Task 2: Core data-fix migration to rename event migrations in schema_migrations table

**Repo for commits:** `daems-platform`

**Why:** During Task 12 we'll move 7 core migrations into the module's `backend/migrations/` and rename them `event_001..007_*.sql`. Production + dev DBs already have rows like `('001_create_events_table.sql', ...)` in `schema_migrations`. If we just rename the files, dev DBs would re-run the migrations (different filename = unseen). The data-fix migration UPDATEs those rows in place, idempotent, conditional, runs once per DB.

**Files:**
- Create: `C:/laragon/www/daems-platform/database/migrations/0NN_rename_event_migrations_in_schema_migrations_table.sql` (NN = highest existing + 1; verify in Step 1)

- [ ] **Step 1: Determine next migration number**

```bash
cd C:/laragon/www/daems-platform && ls database/migrations/ | sort | tail -3
```
Use the highest NNN seen + 1. (Forum used `065`, Projects will use `066`. If Projects has shipped, Events uses `067`. If Projects hasn't shipped yet, Events uses `066`. **For the rest of this plan, refer to it as `NN`.** Verify before continuing.)

- [ ] **Step 2: Create the migration file**

Path: `C:/laragon/www/daems-platform/database/migrations/NN_rename_event_migrations_in_schema_migrations_table.sql`
```sql
-- Idempotent rename of event-related migration filenames in schema_migrations
-- so dev/prod DBs don't re-run them after Task 12 moves them into the module.
-- Pairs with Task 12 of the modular-architecture-phase1-events plan.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_001_create_events_table.sql'' WHERE migration = ''001_create_events_table.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_002_create_event_registrations.sql'' WHERE migration = ''012_create_event_registrations.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_003_add_tenant_id_to_events.sql'' WHERE migration = ''025_add_tenant_id_to_events.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_004_add_tenant_id_to_event_registrations.sql'' WHERE migration = ''032_add_tenant_id_to_event_registrations.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_005_add_status_to_events.sql'' WHERE migration = ''043_add_status_to_events.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_006_create_events_i18n.sql'' WHERE migration = ''051_create_events_i18n.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_007_create_event_proposals.sql'' WHERE migration = ''056_create_event_proposals.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 3: Lint syntax check (don't apply yet)**

The migration is applied to dev DB in Task 25 (after legacy files are deleted in same step). Until then it's just a file in the migrations dir. Verify it parses:

```bash
php -r "echo file_exists('C:/laragon/www/daems-platform/database/migrations/NN_rename_event_migrations_in_schema_migrations_table.sql') ? 'OK' : 'MISSING';"
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add database/migrations/NN_rename_event_migrations_in_schema_migrations_table.sql && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Migration: rename event_NNN_* in schema_migrations (data-fix for plan Task 12 + 25)"
```

---

## Task 3: Events file inventory verification (read-only)

**Repo for commits:** none (audit only).

**Why:** Confirm the file lists in §3 of the spec match reality before starting moves. Catches drift since the spec was written.

- [ ] **Step 1: Domain count**

```bash
cd C:/laragon/www/daems-platform && find src/Domain/Event -name '*.php' | sort
```
Expected: 7 files (`Event.php`, `EventId.php`, `EventProposal.php`, `EventProposalId.php`, `EventProposalRepositoryInterface.php`, `EventRegistration.php`, `EventRepositoryInterface.php`). If different, update Task 4 list inline.

- [ ] **Step 2: Application/Event count**

```bash
cd C:/laragon/www/daems-platform && find src/Application/Event -name '*.php' | sort
```
Record the count. Expected: ~21. Each sub-directory is a use-case bundle (UseCase.php + Input.php + Output.php). Update Task 7 list if different.

- [ ] **Step 3: Application/Backstage event use cases**

```bash
cd C:/laragon/www/daems-platform && ls -d src/Application/Backstage/*Event* src/Application/Backstage/{Approve,Reject}EventProposal src/Application/Backstage/Events 2>&1 | sort
find src/Application/Backstage -path '*Event*' -name '*.php' 2>/dev/null | wc -l
```
Expected: 15 directories, ~41 files. Update Task 8 list if different.

- [ ] **Step 4: SQL repos**

```bash
ls C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/Sql{Event,EventProposal}Repository.php 2>&1
```
Expected: 2 files. If only 1 or 3, update Task 5 list.

- [ ] **Step 5: Public controller**

```bash
ls C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/EventController.php 2>&1
```
Expected: 1 file.

- [ ] **Step 6: BackstageController Event methods**

```bash
grep -nE "function (listEvents|createEvent|updateEvent|publishEvent|archiveEvent|listEventRegistrations|removeEventRegistration|statsEvents|getEventWithTranslations|updateEventTranslation|listEventProposals|approveEventProposal|rejectEventProposal)" C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php
```
Expected: 13 lines. Record line numbers for Task 10.

- [ ] **Step 7: MediaController Event image methods**

```bash
grep -nE "function (uploadEventImage|deleteEventImage)" C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/MediaController.php
```
Expected: 2 lines. Record line numbers for Task 10 + Task 23.

- [ ] **Step 8: Routes count**

```bash
grep -cE "/api/v1/(events|event-proposals|backstage/events|backstage/event-proposals)" C:/laragon/www/daems-platform/routes/api.php
```
Expected: 22 lines.

- [ ] **Step 9: Test file count**

```bash
find C:/laragon/www/daems-platform/tests -name '*Event*' -name '*.php' | wc -l
```
Expected: ~30 files (29 tests + 2 fakes − 1 if any unexpected drop).

- [ ] **Step 10: Migrations check**

```bash
ls C:/laragon/www/daems-platform/database/migrations/ | grep -iE "event"
```
Expected: 10 files (7 event-pure → move; 3 mixed events+projects → stay: 053, 054, 059).

- [ ] **Step 11: Cross-domain consumer scan (NULL test — must return 0 for E2 to hold)**

```bash
cd C:/laragon/www/daems-platform && grep -rE "use Daems\\\\(Domain|Application)\\\\Event\\\\" src/ tests/ --include='*.php' -l 2>/dev/null | grep -vE "/Event/|/Backstage/(Approve|Archive|Create|DeleteEvent|Events|GetEventWith|ListEvent|Publish|Reject|UnregisterUserFromEvent|UpdateEvent|UploadEvent)" | head -10
```
Expected: 0 results (zero non-Event-folder files import Event types). If results appear → STOP, this contradicts E2 = MOVE Domain. Promote to user before proceeding.

- [ ] **Step 12: No commit (audit task)**

If counts deviate ≥10% from the numbers in the spec, update the spec (commit a follow-up to spec) and notify before continuing. Otherwise proceed to Task 4.

---

## Task 4: Move Domain layer (7 files) + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):**
- `src/Domain/Event/Event.php` → `backend/src/Domain/Event.php`
- `src/Domain/Event/EventId.php` → `backend/src/Domain/EventId.php`
- `src/Domain/Event/EventProposal.php` → `backend/src/Domain/EventProposal.php`
- `src/Domain/Event/EventProposalId.php` → `backend/src/Domain/EventProposalId.php`
- `src/Domain/Event/EventProposalRepositoryInterface.php` → `backend/src/Domain/EventProposalRepositoryInterface.php`
- `src/Domain/Event/EventRegistration.php` → `backend/src/Domain/EventRegistration.php`
- `src/Domain/Event/EventRepositoryInterface.php` → `backend/src/Domain/EventRepositoryInterface.php`

- [ ] **Step 1: Copy files**

```bash
mkdir -p C:/laragon/www/modules/events/backend/src/Domain
cp C:/laragon/www/daems-platform/src/Domain/Event/*.php \
   C:/laragon/www/modules/events/backend/src/Domain/
ls C:/laragon/www/modules/events/backend/src/Domain/
```
Expected: 7 PHP files.

- [ ] **Step 2: Rewrite namespace in copies (Edit each file)**

For each of the 7 files, change:
- Old: `namespace Daems\Domain\Event;`
- New: `namespace DaemsModule\Events\Domain;`

Use sed for bulk:
```bash
cd C:/laragon/www/modules/events/backend/src/Domain && \
  sed -i 's|^namespace Daems\\Domain\\Event;|namespace DaemsModule\\Events\\Domain;|' *.php
grep -h '^namespace ' *.php | sort -u
```
Expected: only `namespace DaemsModule\Events\Domain;` printed.

- [ ] **Step 3: Verify each file PHP-lints**

```bash
for f in C:/laragon/www/modules/events/backend/src/Domain/*.php; do php -l "$f" || exit 1; done
```
Expected: all 7 "No syntax errors detected".

- [ ] **Step 4: Verify no internal cross-imports broken**

```bash
grep -rh "use Daems\\\\Domain\\\\Event\\\\" C:/laragon/www/modules/events/backend/src/Domain/ | sort -u
```
Expected: zero results — Domain files reference each other via same-namespace, no `use` needed.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Domain && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(domain): 7 Event/EventProposal/EventRegistration files with DaemsModule\\\\Events\\\\Domain namespace"
```

(Originals in core stay until Task 25 — namespace rewrite of core's references happens in Tasks 5-9 + Wave E removals.)

---

## Task 5: Move 2 SQL repositories + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php` → `backend/src/Infrastructure/SqlEventRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php` → `backend/src/Infrastructure/SqlEventProposalRepository.php`

- [ ] **Step 1: Copy files**

```bash
mkdir -p C:/laragon/www/modules/events/backend/src/Infrastructure
cp C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php \
   C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php \
   C:/laragon/www/modules/events/backend/src/Infrastructure/
```

- [ ] **Step 2: Rewrite namespace + import statements**

For each of the 2 files in `modules/events/backend/src/Infrastructure/`:

Change namespace declaration:
- Old: `namespace Daems\Infrastructure\Adapter\Persistence\Sql;`
- New: `namespace DaemsModule\Events\Infrastructure;`

Update Domain imports (Domain moved in Task 4):
- Old: `use Daems\Domain\Event\Event;` etc.
- New: `use DaemsModule\Events\Domain\Event;` etc.

(Other imports — `Daems\Infrastructure\Framework\Database\Database`, `Daems\Domain\Tenant\TenantId`, etc. — stay unchanged.)

Use sed:
```bash
cd C:/laragon/www/modules/events/backend/src/Infrastructure && \
  sed -i 's|^namespace Daems\\Infrastructure\\Adapter\\Persistence\\Sql;|namespace DaemsModule\\Events\\Infrastructure;|' Sql*Repository.php && \
  sed -i 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' Sql*Repository.php
grep -h '^namespace \|^use Daems\\Domain\\Event\|^use DaemsModule\\Events\\Domain' Sql*Repository.php | sort -u
```
Expected: namespace lines show `DaemsModule\Events\Infrastructure;`. No `use Daems\Domain\Event\` lines remain. All Domain imports rewritten to `use DaemsModule\Events\Domain\`.

- [ ] **Step 3: PHP lint**

```bash
for f in C:/laragon/www/modules/events/backend/src/Infrastructure/Sql*Repository.php; do php -l "$f" || exit 1; done
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Infrastructure && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(infrastructure): 2 SQL repos to DaemsModule\\\\Events\\\\Infrastructure"
```

---

## Task 6: Move 2 InMemory fakes + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):**
- `tests/Support/Fake/InMemoryEventRepository.php` → `backend/tests/Support/InMemoryEventRepository.php`
- `tests/Support/Fake/InMemoryEventProposalRepository.php` → `backend/tests/Support/InMemoryEventProposalRepository.php`

- [ ] **Step 1: Copy files**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Support
cp C:/laragon/www/daems-platform/tests/Support/Fake/InMemoryEventRepository.php \
   C:/laragon/www/daems-platform/tests/Support/Fake/InMemoryEventProposalRepository.php \
   C:/laragon/www/modules/events/backend/tests/Support/
```

- [ ] **Step 2: Rewrite namespaces + Domain imports**

```bash
cd C:/laragon/www/modules/events/backend/tests/Support && \
  sed -i 's|^namespace Daems\\Tests\\Support\\Fake;|namespace DaemsModule\\Events\\Tests\\Support;|' InMemoryEvent*.php && \
  sed -i 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' InMemoryEvent*.php
grep -h '^namespace ' InMemoryEvent*.php | sort -u
```
Expected: `namespace DaemsModule\Events\Tests\Support;`.

- [ ] **Step 3: PHP lint**

```bash
for f in C:/laragon/www/modules/events/backend/tests/Support/InMemoryEvent*.php; do php -l "$f" || exit 1; done
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Support && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): 2 InMemory event fakes to DaemsModule\\\\Events\\\\Tests\\\\Support"
```

---

## Task 7: Move Application/Event (8 use case dirs, ~21 files) + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):**
- `src/Application/Event/{GetEvent,GetEventBySlugForLocale,ListEvents,ListEventsForLocale,RegisterForEvent,SubmitEventProposal,UnregisterFromEvent,UpdateEvent}/*.php` → `backend/src/Application/{same-dir-names}/*.php`

- [ ] **Step 1: Copy directory tree**

```bash
mkdir -p C:/laragon/www/modules/events/backend/src/Application
cp -r C:/laragon/www/daems-platform/src/Application/Event/* \
      C:/laragon/www/modules/events/backend/src/Application/
ls C:/laragon/www/modules/events/backend/src/Application/
find C:/laragon/www/modules/events/backend/src/Application -name '*.php' | wc -l
```
Expected: 8 sub-dirs, ~21 PHP files.

- [ ] **Step 2: Rewrite namespaces + Domain imports across all files**

```bash
cd C:/laragon/www/modules/events/backend/src/Application && \
  find . -name '*.php' -print0 | xargs -0 sed -i \
    -e 's|^namespace Daems\\Application\\Event\\|namespace DaemsModule\\Events\\Application\\|' \
    -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
    -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g'
grep -rh '^namespace ' . | sort -u
```
Expected: every namespace line is `namespace DaemsModule\Events\Application\<UseCaseDir>;`. No leftover `Daems\Application\Event` or `Daems\Domain\Event` imports.

- [ ] **Step 3: PHP lint each file**

```bash
find C:/laragon/www/modules/events/backend/src/Application -name '*.php' -exec php -l {} \;
```
Expected: all "No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Application && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(application): 8 Application/Event use case dirs (~21 files) to DaemsModule\\\\Events\\\\Application"
```

---

## Task 8: Move 15 admin use case sibling dirs (~41 files) + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):** these 15 sibling dirs from `src/Application/Backstage/`:

| # | Dir name | Reason |
|---|---|---|
| 1 | `ApproveEventProposal/` | Approve a proposal → spawns Event |
| 2 | `ArchiveEvent/` | Archive event |
| 3 | `CreateEvent/` | Create event as admin |
| 4 | `DeleteEventImage/` | Delete event image |
| 5 | `Events/` | Stats helper (`ListEventsStats`) |
| 6 | `GetEventWithAllTranslations/` | Read with all locales |
| 7 | `ListEventProposalsForAdmin/` | List proposals |
| 8 | `ListEventRegistrations/` | List registrations |
| 9 | `ListEventsForAdmin/` | List events |
| 10 | `PublishEvent/` | Publish status transition |
| 11 | `RejectEventProposal/` | Reject proposal |
| 12 | `UnregisterUserFromEvent/` | Admin removes user from event |
| 13 | `UpdateEvent/` | Update event chrome |
| 14 | `UpdateEventTranslation/` | Save per-locale translation |
| 15 | `UploadEventImage/` | Upload image |

→ `backend/src/Application/Backstage/<same-dir-names>/*.php`

- [ ] **Step 1: Copy 15 directory trees**

```bash
mkdir -p C:/laragon/www/modules/events/backend/src/Application/Backstage
for d in ApproveEventProposal ArchiveEvent CreateEvent DeleteEventImage Events \
         GetEventWithAllTranslations ListEventProposalsForAdmin \
         ListEventRegistrations ListEventsForAdmin PublishEvent \
         RejectEventProposal UnregisterUserFromEvent UpdateEvent \
         UpdateEventTranslation UploadEventImage; do
  cp -r "C:/laragon/www/daems-platform/src/Application/Backstage/$d" \
        "C:/laragon/www/modules/events/backend/src/Application/Backstage/"
done
ls C:/laragon/www/modules/events/backend/src/Application/Backstage/ | wc -l
find C:/laragon/www/modules/events/backend/src/Application/Backstage -name '*.php' | wc -l
```
Expected: 15 dirs, ~41 PHP files.

- [ ] **Step 2: Rewrite namespaces + imports**

```bash
cd C:/laragon/www/modules/events/backend/src/Application/Backstage && \
  find . -name '*.php' -print0 | xargs -0 sed -i \
    -e 's|^namespace Daems\\Application\\Backstage\\|namespace DaemsModule\\Events\\Application\\Backstage\\|' \
    -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
    -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
    -e 's|use Daems\\Application\\Backstage\\\(ApproveEventProposal\|ArchiveEvent\|CreateEvent\|DeleteEventImage\|Events\|GetEventWithAllTranslations\|ListEventProposalsForAdmin\|ListEventRegistrations\|ListEventsForAdmin\|PublishEvent\|RejectEventProposal\|UnregisterUserFromEvent\|UpdateEvent\|UpdateEventTranslation\|UploadEventImage\)\\|use DaemsModule\\Events\\Application\\Backstage\\\1\\|g'
grep -rh '^namespace ' . | sort -u
```
Expected: namespaces like `namespace DaemsModule\Events\Application\Backstage\<UseCase>;`.

- [ ] **Step 3: Verify no leftover legacy imports**

```bash
cd C:/laragon/www/modules/events/backend/src/Application/Backstage && \
  grep -rE "use Daems\\\\(Domain\\\\Event|Application\\\\Event|Application\\\\Backstage\\\\(ApproveEventProposal|ArchiveEvent|CreateEvent|DeleteEventImage|Events|GetEventWithAllTranslations|ListEventProposalsForAdmin|ListEventRegistrations|ListEventsForAdmin|PublishEvent|RejectEventProposal|UnregisterUserFromEvent|UpdateEvent|UpdateEventTranslation|UploadEventImage))" .
```
Expected: zero results.

- [ ] **Step 4: PHP lint each file**

```bash
find C:/laragon/www/modules/events/backend/src/Application/Backstage -name '*.php' -exec php -l {} \; | grep -v "No syntax errors"
```
Expected: empty (no errors).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Application/Backstage && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(application/backstage): 15 admin use case dirs (~41 files) to DaemsModule\\\\Events\\\\Application\\\\Backstage"
```

---

## Task 9: Move EventController (public) + namespace rewrite

**Repo for commits:** `dp-events`

**Files (move):**
- `src/Infrastructure/Adapter/Api/Controller/EventController.php` → `backend/src/Controller/EventController.php`

- [ ] **Step 1: Copy file**

```bash
mkdir -p C:/laragon/www/modules/events/backend/src/Controller
cp C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/EventController.php \
   C:/laragon/www/modules/events/backend/src/Controller/
```

- [ ] **Step 2: Rewrite namespace + imports**

```bash
cd C:/laragon/www/modules/events/backend/src/Controller && \
  sed -i \
    -e 's|^namespace Daems\\Infrastructure\\Adapter\\Api\\Controller;|namespace DaemsModule\\Events\\Controller;|' \
    -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
    -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
    EventController.php
grep '^namespace \|^use Daems\\\(Domain\|Application\)\\Event' EventController.php
```
Expected: only `namespace DaemsModule\Events\Controller;` shows; no leftover legacy imports.

- [ ] **Step 3: PHP lint**

```bash
php -l C:/laragon/www/modules/events/backend/src/Controller/EventController.php
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Controller/EventController.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(controller): EventController (public) to DaemsModule\\\\Events\\\\Controller"
```

---

## Task 9.5: NEW infra commit on daems-platform — autoload-dev + phpstan paths

**Repo for commits:** `daems-platform`

**Why:** Without this, PHPStan's level-9 analysis can't see module classes and the autoloader can't resolve `DaemsModule\Events\*`. Forum lesson 3.

**Files modified:**
- `composer.json` — add 2 `autoload-dev` PSR-4 entries
- `phpstan.neon` — add 1 path

- [ ] **Step 1: Edit `composer.json`**

In `autoload-dev.psr-4`, add (next to existing `DaemsModule\\Insights\\`, `DaemsModule\\Forum\\`, `DaemsModule\\Projects\\`):
```json
"DaemsModule\\Events\\": "../modules/events/backend/src/",
"DaemsModule\\Events\\Tests\\": "../modules/events/backend/tests/"
```

- [ ] **Step 2: Edit `phpstan.neon`**

In the `paths:` block, add at the end:
```yaml
        - ../modules/events/backend/src
```

- [ ] **Step 3: Regenerate autoloader**

```bash
cd C:/laragon/www/daems-platform && composer dump-autoload 2>&1 | tail -3
```
Expected: "Generated optimized autoload files" (or similar success message).

- [ ] **Step 4: Verify PHPStan finds the module's existing files**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```
Expected: `[OK] No errors` (with baseline). Already-moved Domain + SQL + Application files now lint successfully because the autoloader can resolve their classes.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add composer.json composer.lock phpstan.neon && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(modules): autoload-dev + phpstan path for events module"
```

---

## Task 10: Extract EventBackstageController (TDD, 15 methods)

**Repo for commits:** `dp-events`

**Why:** Spec E4 = single controller. 13 Event methods live in core's `BackstageController.php` + 2 image methods (`uploadEventImage`, `deleteEventImage`) in core's `MediaController.php`. Extract via TDD: write Reflection-based signature tests for the new class first, fail, implement minimal class to pass, then port method bodies.

**Files (create):**
- `backend/src/Controller/EventBackstageController.php` (NEW class, 15 public methods)
- `backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php` (NEW Reflection signature tests)

**Reference (read-only):**
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` lines 293, 308, 331, 359, 373, 387, 401, 639, 721, 740, 812, 833, 855
- `src/Infrastructure/Adapter/Api/Controller/MediaController.php` (locate `uploadEventImage` + `deleteEventImage` via Step 1 grep)

- [ ] **Step 1: Locate exact source line ranges**

```bash
cd C:/laragon/www/daems-platform && \
grep -nE "function (listEvents|createEvent|updateEvent|publishEvent|archiveEvent|listEventRegistrations|removeEventRegistration|statsEvents|getEventWithTranslations|updateEventTranslation|listEventProposals|approveEventProposal|rejectEventProposal)" src/Infrastructure/Adapter/Api/Controller/BackstageController.php

grep -nE "function (uploadEventImage|deleteEventImage)" src/Infrastructure/Adapter/Api/Controller/MediaController.php
```
Record line numbers. (Spec lists lines 293, 308, 331, 359, 373, 387, 401, 639, 721, 740, 812, 833, 855 for BackstageController; verify MediaController numbers in this step.)

- [ ] **Step 2: Write Reflection signature test (failing)**

Path: `C:/laragon/www/modules/events/backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php`
```php
<?php

declare(strict_types=1);

namespace DaemsModule\Events\Tests\Unit\Controller;

use DaemsModule\Events\Controller\EventBackstageController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class EventBackstageControllerSignatureTest extends TestCase
{
    public function test_class_exists_and_is_final(): void
    {
        self::assertTrue(class_exists(EventBackstageController::class));
        $rc = new ReflectionClass(EventBackstageController::class);
        self::assertTrue($rc->isFinal());
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function methodNames(): array
    {
        return [
            // From BackstageController
            ['listEvents'], ['createEvent'], ['updateEvent'], ['publishEvent'],
            ['archiveEvent'], ['listEventRegistrations'], ['removeEventRegistration'],
            ['statsEvents'], ['getEventWithTranslations'], ['updateEventTranslation'],
            ['listEventProposals'], ['approveEventProposal'], ['rejectEventProposal'],
            // From MediaController
            ['uploadEventImage'], ['deleteEventImage'],
        ];
    }

    /**
     * @dataProvider methodNames
     */
    public function test_method_exists_and_returns_response(string $methodName): void
    {
        $rc = new ReflectionClass(EventBackstageController::class);
        self::assertTrue($rc->hasMethod($methodName), "Method $methodName missing");
        $method = $rc->getMethod($methodName);
        self::assertTrue($method->isPublic(), "Method $methodName not public");
        $returnType = $method->getReturnType();
        self::assertNotNull($returnType, "Method $methodName has no return type");
        self::assertSame(\Daems\Infrastructure\Framework\Http\Response::class, (string) $returnType);
    }
}
```

- [ ] **Step 3: Run test — must FAIL**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php 2>&1 | tail -15
```
Expected: FAIL with "class does not exist". This proves test runs and is wired.

- [ ] **Step 4: Create minimal `EventBackstageController` skeleton**

Path: `C:/laragon/www/modules/events/backend/src/Controller/EventBackstageController.php`
```php
<?php

declare(strict_types=1);

namespace DaemsModule\Events\Controller;

use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class EventBackstageController
{
    public function __construct() {}

    public function listEvents(Request $request): Response { return new Response(); }
    public function createEvent(Request $request): Response { return new Response(); }
    public function updateEvent(Request $request, array $params): Response { return new Response(); }
    public function publishEvent(Request $request, array $params): Response { return new Response(); }
    public function archiveEvent(Request $request, array $params): Response { return new Response(); }
    public function listEventRegistrations(Request $request, array $params): Response { return new Response(); }
    public function removeEventRegistration(Request $request, array $params): Response { return new Response(); }
    public function statsEvents(Request $request): Response { return new Response(); }
    public function getEventWithTranslations(Request $request, array $params): Response { return new Response(); }
    public function updateEventTranslation(Request $request, array $params): Response { return new Response(); }
    public function listEventProposals(Request $request): Response { return new Response(); }
    public function approveEventProposal(Request $request, array $params): Response { return new Response(); }
    public function rejectEventProposal(Request $request, array $params): Response { return new Response(); }
    public function uploadEventImage(Request $request, array $params): Response { return new Response(); }
    public function deleteEventImage(Request $request, array $params): Response { return new Response(); }
}
```

- [ ] **Step 5: Run signature test — must PASS**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php 2>&1 | tail -5
```
Expected: PASS (16 tests — class-exists + 15 method tests).

- [ ] **Step 6: Port method bodies + constructor wiring (port from BackstageController.php + MediaController.php)**

Open `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`. Read each of the 13 methods at the line numbers from Step 1. Identify use cases each method invokes (visible in `$container->make(...)` calls or property access to constructor-injected instances).

The current BackstageController takes ALL use cases via constructor (huge constructor). For the module's `EventBackstageController`, take ONLY the use cases that the 15 Event methods actually invoke. Inventory will look approximately like this (verify by reading method bodies):

```php
public function __construct(
    private readonly \DaemsModule\Events\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin $listEventsForAdmin,
    private readonly \DaemsModule\Events\Application\Backstage\CreateEvent\CreateEvent $createEvent,
    private readonly \DaemsModule\Events\Application\Backstage\UpdateEvent\UpdateEvent $updateEvent,
    private readonly \DaemsModule\Events\Application\Backstage\PublishEvent\PublishEvent $publishEvent,
    private readonly \DaemsModule\Events\Application\Backstage\ArchiveEvent\ArchiveEvent $archiveEvent,
    private readonly \DaemsModule\Events\Application\Backstage\ListEventRegistrations\ListEventRegistrations $listEventRegistrations,
    private readonly \DaemsModule\Events\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent $unregisterUserFromEvent,
    private readonly \DaemsModule\Events\Application\Backstage\Events\ListEventsStats $listEventsStats,
    private readonly \DaemsModule\Events\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations $getEventWithAllTranslations,
    private readonly \DaemsModule\Events\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation $updateEventTranslation,
    private readonly \DaemsModule\Events\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin $listEventProposalsForAdmin,
    private readonly \DaemsModule\Events\Application\Backstage\ApproveEventProposal\ApproveEventProposal $approveEventProposal,
    private readonly \DaemsModule\Events\Application\Backstage\RejectEventProposal\RejectEventProposal $rejectEventProposal,
    private readonly \DaemsModule\Events\Application\Backstage\UploadEventImage\UploadEventImage $uploadEventImage,
    private readonly \DaemsModule\Events\Application\Backstage\DeleteEventImage\DeleteEventImage $deleteEventImageUseCase,
    private readonly \Daems\Infrastructure\Storage\LocalImageStorage $imageStorage,  // for upload/delete (verify need by reading MediaController)
) {}
```

Port each method body verbatim from the source file, replacing parent class's `$this->approveEventProposal` (use case property) with the new local `$this->approveEventProposal` (constructor-injected). Method bodies often:
- Read inputs from `$request` (Authorization, JSON body, multipart form)
- Build `Input` value object
- Call `$this-><useCase>->execute($input)`
- Map exceptions to Response status codes (catch `ForbiddenException`, `NotFoundException`, `ValidationException`)
- Return `Response::json($output->toArray())` or similar

**For the 2 image methods (uploadEventImage, deleteEventImage):** read MediaController's bodies. They likely reference `$this->localImageStorage` plus the upload/delete use case. The new EventBackstageController gets `$this->imageStorage` via constructor (note: type may differ — verify against MediaController's actual constructor types).

- [ ] **Step 7: Run signature test again — still PASS**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php 2>&1 | tail -3
```
Expected: PASS.

- [ ] **Step 8: PHPStan check on the new file**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpstan analyse C:/laragon/www/modules/events/backend/src/Controller/EventBackstageController.php 2>&1 | tail -10
```
Expected: 0 errors. If list<>/array<> issues surface (likely from the route's `array $params` parameter), follow the precedent set in Forum + PHPStan-2.x upgrade: prefer real `list<string,string>` typing OR baseline if structural.

- [ ] **Step 9: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/src/Controller/EventBackstageController.php \
          backend/tests/Unit/Controller/EventBackstageControllerSignatureTest.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Extract(controller): EventBackstageController (15 methods, TDD'd from BackstageController + MediaController)"
```

---

## Task 12: Move 7 migrations with event_NNN_* rename

**Repo for commits:** `dp-events`

**Files (move from `daems-platform/database/migrations/` to `modules/events/backend/migrations/`):**

| Old name | New name |
|---|---|
| `001_create_events_table.sql` | `event_001_create_events_table.sql` |
| `012_create_event_registrations.sql` | `event_002_create_event_registrations.sql` |
| `025_add_tenant_id_to_events.sql` | `event_003_add_tenant_id_to_events.sql` |
| `032_add_tenant_id_to_event_registrations.sql` | `event_004_add_tenant_id_to_event_registrations.sql` |
| `043_add_status_to_events.sql` | `event_005_add_status_to_events.sql` |
| `051_create_events_i18n.sql` | `event_006_create_events_i18n.sql` |
| `056_create_event_proposals.sql` | `event_007_create_event_proposals.sql` |

(Migrations 053, 054, 059 STAY in core — mixed events+projects scope.)

- [ ] **Step 1: Copy + rename**

```bash
SRC=C:/laragon/www/daems-platform/database/migrations
DST=C:/laragon/www/modules/events/backend/migrations
cp "$SRC/001_create_events_table.sql"                 "$DST/event_001_create_events_table.sql"
cp "$SRC/012_create_event_registrations.sql"          "$DST/event_002_create_event_registrations.sql"
cp "$SRC/025_add_tenant_id_to_events.sql"             "$DST/event_003_add_tenant_id_to_events.sql"
cp "$SRC/032_add_tenant_id_to_event_registrations.sql" "$DST/event_004_add_tenant_id_to_event_registrations.sql"
cp "$SRC/043_add_status_to_events.sql"                "$DST/event_005_add_status_to_events.sql"
cp "$SRC/051_create_events_i18n.sql"                  "$DST/event_006_create_events_i18n.sql"
cp "$SRC/056_create_event_proposals.sql"              "$DST/event_007_create_event_proposals.sql"
ls "$DST"
```
Expected: 7 files.

- [ ] **Step 2: Verify ALTER-on-core-tables migrations have conditional guards**

```bash
grep -lE "ALTER TABLE (admin|users|tenants)" C:/laragon/www/modules/events/backend/migrations/*.sql 2>/dev/null
```
Events migrations only ALTER tables they own (events, event_registrations, events_i18n, event_proposals). Likely no cross-table ALTERs need guards. If any are flagged, verify the table is created in core; if so, wrap in conditional `IF (table_count > 0) THEN ALTER ELSE DO 0` per Forum lesson 4. Otherwise no guard needed (the table is created by an earlier event migration in the same module).

- [ ] **Step 3: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/migrations/event_*.sql && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(migrations): 7 event migrations renamed event_001..007"
```

(Originals in core are deleted in Task 25, where the data-fix migration `NN_*` from Task 2 also runs.)

---

## Task 13: bindings.php (production)

**Repo for commits:** `dp-events`

**Files (modify):**
- `backend/bindings.php` — replace stub with full production bindings

- [ ] **Step 1: List all use cases that need bindings (read from moved code)**

```bash
cd C:/laragon/www/modules/events && \
  find backend/src/Application -name '*.php' | xargs -I{} grep -lE "^final class .* implements" {} 2>/dev/null
```
Expected: each use case class. Note them.

- [ ] **Step 2: Replace `bindings.php` stub with full bindings**

Path: `C:/laragon/www/modules/events/backend/bindings.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Database;

return static function (Container $container): void {
    // Repositories — module owns the interfaces (Domain MOVED to module per E2)
    $container->singleton(\DaemsModule\Events\Domain\EventRepositoryInterface::class,
        static fn(Container $c) => new \DaemsModule\Events\Infrastructure\SqlEventRepository(
            $c->make(Database::class),
        ),
    );
    $container->singleton(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class,
        static fn(Container $c) => new \DaemsModule\Events\Infrastructure\SqlEventProposalRepository(
            $c->make(Database::class),
        ),
    );

    // Application/Event public use cases (8 dirs)
    $container->bind(\DaemsModule\Events\Application\GetEvent\GetEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\GetEvent\GetEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\GetEventBySlugForLocale\GetEventBySlugForLocale::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\GetEventBySlugForLocale\GetEventBySlugForLocale(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\ListEvents\ListEvents::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\ListEvents\ListEvents(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\ListEventsForLocale\ListEventsForLocale::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\ListEventsForLocale\ListEventsForLocale(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\RegisterForEvent\RegisterForEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\RegisterForEvent\RegisterForEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\SubmitEventProposal\SubmitEventProposal::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\SubmitEventProposal\SubmitEventProposal(
            $c->make(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\UnregisterFromEvent\UnregisterFromEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\UnregisterFromEvent\UnregisterFromEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\UpdateEvent\UpdateEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\UpdateEvent\UpdateEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );

    // Application/Backstage admin use cases (15 dirs) — bind each
    $container->bind(\DaemsModule\Events\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\ApproveEventProposal\ApproveEventProposal(
            $c->make(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class),
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\ArchiveEvent\ArchiveEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\ArchiveEvent\ArchiveEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\CreateEvent\CreateEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\CreateEvent\CreateEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\DeleteEventImage\DeleteEventImage::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\DeleteEventImage\DeleteEventImage(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
            $c->make(\Daems\Infrastructure\Storage\LocalImageStorage::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\Events\ListEventsStats::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\Events\ListEventsStats(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin(
            $c->make(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\ListEventRegistrations\ListEventRegistrations(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\PublishEvent\PublishEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\PublishEvent\PublishEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\RejectEventProposal\RejectEventProposal::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\RejectEventProposal\RejectEventProposal(
            $c->make(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\UpdateEvent\UpdateEvent::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\UpdateEvent\UpdateEvent(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Application\Backstage\UploadEventImage\UploadEventImage::class,
        static fn(Container $c) => new \DaemsModule\Events\Application\Backstage\UploadEventImage\UploadEventImage(
            $c->make(\DaemsModule\Events\Domain\EventRepositoryInterface::class),
            $c->make(\Daems\Infrastructure\Storage\LocalImageStorage::class),
        ),
    );

    // Controllers
    $container->bind(\DaemsModule\Events\Controller\EventController::class,
        static fn(Container $c) => new \DaemsModule\Events\Controller\EventController(
            $c->make(\DaemsModule\Events\Application\GetEvent\GetEvent::class),
            $c->make(\DaemsModule\Events\Application\GetEventBySlugForLocale\GetEventBySlugForLocale::class),
            $c->make(\DaemsModule\Events\Application\ListEvents\ListEvents::class),
            $c->make(\DaemsModule\Events\Application\ListEventsForLocale\ListEventsForLocale::class),
            $c->make(\DaemsModule\Events\Application\RegisterForEvent\RegisterForEvent::class),
            $c->make(\DaemsModule\Events\Application\SubmitEventProposal\SubmitEventProposal::class),
            $c->make(\DaemsModule\Events\Application\UnregisterFromEvent\UnregisterFromEvent::class),
        ),
    );
    $container->bind(\DaemsModule\Events\Controller\EventBackstageController::class,
        static fn(Container $c) => new \DaemsModule\Events\Controller\EventBackstageController(
            $c->make(\DaemsModule\Events\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class),
            $c->make(\DaemsModule\Events\Application\Backstage\CreateEvent\CreateEvent::class),
            $c->make(\DaemsModule\Events\Application\Backstage\UpdateEvent\UpdateEvent::class),
            $c->make(\DaemsModule\Events\Application\Backstage\PublishEvent\PublishEvent::class),
            $c->make(\DaemsModule\Events\Application\Backstage\ArchiveEvent\ArchiveEvent::class),
            $c->make(\DaemsModule\Events\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class),
            $c->make(\DaemsModule\Events\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class),
            $c->make(\DaemsModule\Events\Application\Backstage\Events\ListEventsStats::class),
            $c->make(\DaemsModule\Events\Application\Backstage\GetEventWithAllTranslations\GetEventWithAllTranslations::class),
            $c->make(\DaemsModule\Events\Application\Backstage\UpdateEventTranslation\UpdateEventTranslation::class),
            $c->make(\DaemsModule\Events\Application\Backstage\ListEventProposalsForAdmin\ListEventProposalsForAdmin::class),
            $c->make(\DaemsModule\Events\Application\Backstage\ApproveEventProposal\ApproveEventProposal::class),
            $c->make(\DaemsModule\Events\Application\Backstage\RejectEventProposal\RejectEventProposal::class),
            $c->make(\DaemsModule\Events\Application\Backstage\UploadEventImage\UploadEventImage::class),
            $c->make(\DaemsModule\Events\Application\Backstage\DeleteEventImage\DeleteEventImage::class),
            $c->make(\Daems\Infrastructure\Storage\LocalImageStorage::class),
        ),
    );
};
```

**Note:** The exact constructor argument lists for use cases (Step 2) are best-effort based on Forum/Insights patterns. Verify each use case's actual constructor parameters by reading the moved files; adjust the binding closure args if any use case takes additional dependencies (e.g., `Clock`, `Authorizer`, `LocaleNegotiator`).

- [ ] **Step 3: Production-container smoke**

Create `C:/laragon/www/daems-platform/smoke-events-controllers.php`:
```php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new \ReflectionObject($kernel);
$prop = $ref->getProperty('container'); $prop->setAccessible(true);
$container = $prop->getValue($kernel);

foreach ([
    \DaemsModule\Events\Controller\EventController::class,
    \DaemsModule\Events\Controller\EventBackstageController::class,
] as $fqcn) {
    try {
        $c = $container->make($fqcn);
        echo $c instanceof $fqcn ? "OK: $fqcn\n" : "FAIL: $fqcn — wrong instance\n";
    } catch (\Throwable $e) {
        echo "FAIL: $fqcn — " . $e::class . ": " . $e->getMessage() . "\n";
        exit(1);
    }
}
```

Run:
```bash
cd C:/laragon/www/daems-platform && php smoke-events-controllers.php
```
Expected: `OK: DaemsModule\Events\Controller\EventController` and `OK: DaemsModule\Events\Controller\EventBackstageController`. **Block on any FAIL** — investigate constructor mismatch, fix bindings.php, re-run.

Delete the smoke script after success:
```bash
rm C:/laragon/www/daems-platform/smoke-events-controllers.php
```

- [ ] **Step 4: PHPStan check**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -3
```
Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/bindings.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(production): bindings.php — 2 repos + ~24 use cases + 2 controllers"
```

---

## Task 14: bindings.test.php (test container)

**Repo for commits:** `dp-events`

**Files (modify):**
- `backend/bindings.test.php` — replace stub with full test bindings (InMemory fakes for repositories)

- [ ] **Step 1: Replace stub**

Path: `C:/laragon/www/modules/events/backend/bindings.test.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Repositories — bind module interfaces to module InMemory fakes
    $container->singleton(\DaemsModule\Events\Domain\EventRepositoryInterface::class,
        static fn() => new \DaemsModule\Events\Tests\Support\InMemoryEventRepository(),
    );
    $container->singleton(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class,
        static fn() => new \DaemsModule\Events\Tests\Support\InMemoryEventProposalRepository(),
    );

    // Application/Event public + Backstage admin use cases — same shape as production but
    // resolve repositories via the rebindings above (InMemory fakes).
    // Reuse production bindings for use cases + controllers (only repos differ in test mode).
    require __DIR__ . '/_bindings_use_cases_and_controllers.php';
};
```

- [ ] **Step 2: Extract shared use case + controller bindings into `_bindings_use_cases_and_controllers.php`**

To DRY this up (Forum extracted a shared file), copy the use case + controller binding closures from `bindings.php` into a new file `backend/_bindings_use_cases_and_controllers.php`. Both `bindings.php` AND `bindings.test.php` `require` it after their repository bindings. **Update `bindings.php` accordingly.**

(Skip this step if it adds complexity — Forum did NOT use this pattern. Forum's `bindings.php` and `bindings.test.php` each duplicate the use case + controller bindings, with only the repository closures differing. Match Forum's pattern: duplicate the bindings in `bindings.test.php`.)

If choosing duplication (recommended for parity with Forum):

Path: `C:/laragon/www/modules/events/backend/bindings.test.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;

return static function (Container $container): void {
    // Repositories — InMemory fakes
    $container->singleton(\DaemsModule\Events\Domain\EventRepositoryInterface::class,
        static fn() => new \DaemsModule\Events\Tests\Support\InMemoryEventRepository(),
    );
    $container->singleton(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class,
        static fn() => new \DaemsModule\Events\Tests\Support\InMemoryEventProposalRepository(),
    );

    // [Copy ALL use case + controller `$container->bind(...)` blocks from bindings.php here verbatim.]
};
```

Verify the bindings match production by diff:
```bash
diff <(grep "container->bind\|container->singleton" C:/laragon/www/modules/events/backend/bindings.php) \
     <(grep "container->bind\|container->singleton" C:/laragon/www/modules/events/backend/bindings.test.php)
```
Expected: only the 2 repository singleton lines differ (production = SqlEventRepository / SqlEventProposalRepository; test = InMemoryEventRepository / InMemoryEventProposalRepository). All other binds identical.

- [ ] **Step 3: Run a small E2E test to verify KernelHarness loads test bindings**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=E2E --filter=KernelHarness 2>&1 | tail -5
```
Expected: tests pass (or N/A skipped). No "Cannot resolve EventRepositoryInterface" errors.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/bindings.test.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(test): bindings.test.php — InMemory fakes + same use cases as production"
```

---

## Task 15: routes.php (22 routes)

**Repo for commits:** `dp-events`

**Files (modify):**
- `backend/routes.php` — replace stub with all 22 Event routes

- [ ] **Step 1: Replace stub**

Path: `C:/laragon/www/modules/events/backend/routes.php`
```php
<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Http\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\OptionalAuthMiddleware;

return static function (Router $router, Container $container): void {
    // Public routes (7) → EventController
    $router->get('/api/v1/events', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->listEvents($req);
    }, [TenantContextMiddleware::class, OptionalAuthMiddleware::class]);

    $router->get('/api/v1/events/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->getEvent($req, $params);
    }, [TenantContextMiddleware::class, OptionalAuthMiddleware::class]);

    $router->get('/api/v1/events-legacy', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->listEventsLegacy($req);
    }, [TenantContextMiddleware::class, OptionalAuthMiddleware::class]);

    $router->get('/api/v1/events-legacy/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->getEventLegacy($req, $params);
    }, [TenantContextMiddleware::class, OptionalAuthMiddleware::class]);

    $router->post('/api/v1/event-proposals', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->submitEventProposal($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/events/{slug}/register', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->registerForEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/events/{slug}/unregister', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventController::class)->unregisterFromEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage events routes (12) → EventBackstageController
    $router->get('/api/v1/backstage/events/stats', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->statsEvents($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->listEvents($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->createEvent($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->updateEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/publish', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->publishEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->archiveEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/events/{id}/registrations', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->listEventRegistrations($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/registrations/{user_id}/remove', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->removeEventRegistration($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/images', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->uploadEventImage($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/images/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->deleteEventImage($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/events/{id}/translations', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->getEventWithTranslations($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/translations/{locale}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->updateEventTranslation($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage event-proposals routes (3) → EventBackstageController
    $router->get('/api/v1/backstage/event-proposals', static function (Request $req) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->listEventProposals($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/event-proposals/{id}/approve', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->approveEventProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/event-proposals/{id}/reject', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\DaemsModule\Events\Controller\EventBackstageController::class)->rejectEventProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
};
```

**Verify against core's `routes/api.php`:** the middleware lists (`OptionalAuthMiddleware` vs `AuthMiddleware` for `/api/v1/events`) must match exactly what core had. Also verify `events-legacy` aliases are still wired in frontend before keeping them (per spec note).

- [ ] **Step 2: Verify route count = 22**

```bash
grep -c "router->" C:/laragon/www/modules/events/backend/routes.php
```
Expected: 22.

- [ ] **Step 3: Live API smoke**

After Wave E removes Event routes from core's `routes/api.php`, the module's routes are the only ones serving Event endpoints. For now, the routes are duplicated with core but only one will execute (whichever the Router resolves first). Verify the module routes work alongside core:

```bash
curl -s -o /dev/null -w "GET /api/v1/events: %{http_code}\n" "http://daems-platform.local/api/v1/events"
curl -s -o /dev/null -w "GET /api/v1/backstage/events/stats: %{http_code}\n" "http://daems-platform.local/api/v1/backstage/events/stats"
```
Expected: 200 (events) and 401 (backstage stats — needs auth). **Both non-500.** (The auth fail on backstage is expected, not a regression.)

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/routes.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(routes): routes.php — 22 routes (7 public + 12 backstage events + 3 backstage event-proposals)"
```

---

## Task 16: Move Domain unit test (1 file: EventTest.php)

**Repo for commits:** `dp-events`

**Files (move):**
- `tests/Unit/Domain/Event/EventTest.php` → `backend/tests/Unit/Domain/EventTest.php`

- [ ] **Step 1: Copy file**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Unit/Domain
cp C:/laragon/www/daems-platform/tests/Unit/Domain/Event/EventTest.php \
   C:/laragon/www/modules/events/backend/tests/Unit/Domain/
```

- [ ] **Step 2: Rewrite namespace + imports**

```bash
cd C:/laragon/www/modules/events/backend/tests/Unit/Domain && \
  sed -i \
    -e 's|^namespace Daems\\Tests\\Unit\\Domain\\Event;|namespace DaemsModule\\Events\\Tests\\Unit\\Domain;|' \
    -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
    EventTest.php
```

- [ ] **Step 3: Run test to verify it passes**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Domain/EventTest.php 2>&1 | tail -5
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Unit/Domain && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Domain Event unit test"
```

---

## Task 17: Move tests/Unit/Application/Event (1 file: RegisterForEventTest.php)

**Repo for commits:** `dp-events`

**Files (move):**
- `tests/Unit/Application/Event/RegisterForEventTest.php` → `backend/tests/Unit/Application/RegisterForEventTest.php`

- [ ] **Step 1: Copy + rewrite**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Unit/Application
cp C:/laragon/www/daems-platform/tests/Unit/Application/Event/RegisterForEventTest.php \
   C:/laragon/www/modules/events/backend/tests/Unit/Application/

cd C:/laragon/www/modules/events/backend/tests/Unit/Application && \
  sed -i \
    -e 's|^namespace Daems\\Tests\\Unit\\Application\\Event;|namespace DaemsModule\\Events\\Tests\\Unit\\Application;|' \
    -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
    -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
    -e 's|use Daems\\Tests\\Support\\Fake\\InMemoryEvent|use DaemsModule\\Events\\Tests\\Support\\InMemoryEvent|g' \
    RegisterForEventTest.php
```

- [ ] **Step 2: Run test**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Application/RegisterForEventTest.php 2>&1 | tail -5
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Unit/Application && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Application Event unit test (RegisterForEventTest)"
```

---

## Task 18: Move 10 Backstage Event unit tests

**Repo for commits:** `dp-events`

**Files (move):**

Each of these from `tests/Unit/Application/Backstage/` to `backend/tests/Unit/Application/Backstage/`:
- `ArchiveEventTest.php`
- `CreateEventTest.php`
- `DeleteEventImageTest.php`
- `ListEventRegistrationsTest.php`
- `ListEventsForAdminTest.php`
- `ListEventsStatsTest.php`
- `PublishEventTest.php`
- `UnregisterUserFromEventTest.php`
- `UpdateEventTest.php`
- `UploadEventImageTest.php`

- [ ] **Step 1: Copy all 10**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Unit/Application/Backstage
for f in ArchiveEvent CreateEvent DeleteEventImage ListEventRegistrations ListEventsForAdmin \
         ListEventsStats PublishEvent UnregisterUserFromEvent UpdateEvent UploadEventImage; do
  cp "C:/laragon/www/daems-platform/tests/Unit/Application/Backstage/${f}Test.php" \
     "C:/laragon/www/modules/events/backend/tests/Unit/Application/Backstage/"
done
ls C:/laragon/www/modules/events/backend/tests/Unit/Application/Backstage/ | wc -l
```
Expected: 10 files.

- [ ] **Step 2: Rewrite namespaces + imports across all 10**

```bash
cd C:/laragon/www/modules/events/backend/tests/Unit/Application/Backstage && \
  for f in *.php; do
    sed -i \
      -e 's|^namespace Daems\\Tests\\Unit\\Application\\Backstage;|namespace DaemsModule\\Events\\Tests\\Unit\\Application\\Backstage;|' \
      -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
      -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
      -e 's|use Daems\\Application\\Backstage\\\(ApproveEventProposal\|ArchiveEvent\|CreateEvent\|DeleteEventImage\|Events\|GetEventWithAllTranslations\|ListEventProposalsForAdmin\|ListEventRegistrations\|ListEventsForAdmin\|PublishEvent\|RejectEventProposal\|UnregisterUserFromEvent\|UpdateEvent\|UpdateEventTranslation\|UploadEventImage\)\\|use DaemsModule\\Events\\Application\\Backstage\\\1\\|g' \
      -e 's|use Daems\\Tests\\Support\\Fake\\InMemoryEvent|use DaemsModule\\Events\\Tests\\Support\\InMemoryEvent|g' \
      "$f"
  done
```

- [ ] **Step 3: Run all 10 tests**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Unit/Application/Backstage/ 2>&1 | tail -5
```
Expected: 10 tests passing.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Unit/Application/Backstage && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): 10 Backstage Event unit tests"
```

---

## Task 19: Move 5 integration tests

**Repo for commits:** `dp-events`

**Files (move):**
- `tests/Integration/Application/EventsAdminIntegrationTest.php` → `backend/tests/Integration/Application/EventsAdminIntegrationTest.php`
- `tests/Integration/EventProposalStatsTest.php` → `backend/tests/Integration/EventProposalStatsTest.php`
- `tests/Integration/EventStatsTest.php` → `backend/tests/Integration/EventStatsTest.php`
- `tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php` → `backend/tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php`
- `tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php` → `backend/tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php`

- [ ] **Step 1: Copy**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Integration/{Application,Infrastructure}
cp C:/laragon/www/daems-platform/tests/Integration/Application/EventsAdminIntegrationTest.php \
   C:/laragon/www/modules/events/backend/tests/Integration/Application/
cp C:/laragon/www/daems-platform/tests/Integration/EventProposalStatsTest.php \
   C:/laragon/www/daems-platform/tests/Integration/EventStatsTest.php \
   C:/laragon/www/modules/events/backend/tests/Integration/
cp C:/laragon/www/daems-platform/tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php \
   C:/laragon/www/daems-platform/tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php \
   C:/laragon/www/modules/events/backend/tests/Integration/Infrastructure/
```

- [ ] **Step 2: Rewrite namespaces + imports**

```bash
find C:/laragon/www/modules/events/backend/tests/Integration -name '*.php' -print0 | xargs -0 sed -i \
  -e 's|^namespace Daems\\Tests\\Integration\\Application;|namespace DaemsModule\\Events\\Tests\\Integration\\Application;|' \
  -e 's|^namespace Daems\\Tests\\Integration\\Infrastructure;|namespace DaemsModule\\Events\\Tests\\Integration\\Infrastructure;|' \
  -e 's|^namespace Daems\\Tests\\Integration;|namespace DaemsModule\\Events\\Tests\\Integration;|' \
  -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
  -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
  -e 's|use Daems\\Application\\Backstage\\\(ApproveEventProposal\|ArchiveEvent\|CreateEvent\|DeleteEventImage\|Events\|GetEventWithAllTranslations\|ListEventProposalsForAdmin\|ListEventRegistrations\|ListEventsForAdmin\|PublishEvent\|RejectEventProposal\|UnregisterUserFromEvent\|UpdateEvent\|UpdateEventTranslation\|UploadEventImage\)\\|use DaemsModule\\Events\\Application\\Backstage\\\1\\|g' \
  -e 's|use Daems\\Infrastructure\\Adapter\\Persistence\\Sql\\Sql\(Event\|EventProposal\)Repository|use DaemsModule\\Events\\Infrastructure\\Sql\1Repository|g' \
  -e 's|use Daems\\Tests\\Support\\Fake\\InMemoryEvent|use DaemsModule\\Events\\Tests\\Support\\InMemoryEvent|g'
```

- [ ] **Step 3: Run integration suite for events tests only**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Integration/ 2>&1 | tail -10
```
Expected: 5 tests passing. (Note: Integration suite is slow; ~5-10 min for these 5 tests.)

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Integration && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): 5 Event integration tests"
```

---

## Task 20: Move isolation (5) + E2E (5) tests

**Repo for commits:** `dp-events`

**Files (move):**

Isolation:
- `tests/Isolation/EventProposalTenantIsolationTest.php`
- `tests/Isolation/EventsAdminTenantIsolationTest.php`
- `tests/Isolation/EventsI18nTenantIsolationTest.php`
- `tests/Isolation/EventsStatsTenantIsolationTest.php`
- `tests/Isolation/EventTenantIsolationTest.php`

E2E:
- `tests/E2E/Backstage/EventAdminEndpointsTest.php`
- `tests/E2E/Backstage/EventsStatsEndpointTest.php`
- `tests/E2E/Backstage/EventUploadTest.php`
- `tests/E2E/EventProposalFlowE2ETest.php`
- `tests/E2E/EventsLocaleE2ETest.php`

- [ ] **Step 1: Copy**

```bash
mkdir -p C:/laragon/www/modules/events/backend/tests/Isolation \
         C:/laragon/www/modules/events/backend/tests/E2E/Backstage

for f in EventProposalTenantIsolation EventsAdminTenantIsolation EventsI18nTenantIsolation \
         EventsStatsTenantIsolation EventTenantIsolation; do
  cp "C:/laragon/www/daems-platform/tests/Isolation/${f}Test.php" \
     "C:/laragon/www/modules/events/backend/tests/Isolation/"
done

for f in EventAdminEndpoints EventsStatsEndpoint EventUpload; do
  cp "C:/laragon/www/daems-platform/tests/E2E/Backstage/${f}Test.php" \
     "C:/laragon/www/modules/events/backend/tests/E2E/Backstage/"
done

cp C:/laragon/www/daems-platform/tests/E2E/EventProposalFlowE2ETest.php \
   C:/laragon/www/daems-platform/tests/E2E/EventsLocaleE2ETest.php \
   C:/laragon/www/modules/events/backend/tests/E2E/
```

- [ ] **Step 2: Rewrite namespaces + imports**

```bash
find C:/laragon/www/modules/events/backend/tests/Isolation -name '*.php' -print0 | xargs -0 sed -i \
  -e 's|^namespace Daems\\Tests\\Isolation;|namespace DaemsModule\\Events\\Tests\\Isolation;|' \
  -e 's|use Daems\\Tests\\Isolation\\IsolationTestCase|use Daems\\Tests\\Isolation\\IsolationTestCase|g' \
  -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
  -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g' \
  -e 's|use Daems\\Application\\Backstage\\\(ApproveEventProposal\|ArchiveEvent\|CreateEvent\|DeleteEventImage\|Events\|GetEventWithAllTranslations\|ListEventProposalsForAdmin\|ListEventRegistrations\|ListEventsForAdmin\|PublishEvent\|RejectEventProposal\|UnregisterUserFromEvent\|UpdateEvent\|UpdateEventTranslation\|UploadEventImage\)\\|use DaemsModule\\Events\\Application\\Backstage\\\1\\|g'

find C:/laragon/www/modules/events/backend/tests/E2E -name '*.php' -print0 | xargs -0 sed -i \
  -e 's|^namespace Daems\\Tests\\E2E\\Backstage;|namespace DaemsModule\\Events\\Tests\\E2E\\Backstage;|' \
  -e 's|^namespace Daems\\Tests\\E2E;|namespace DaemsModule\\Events\\Tests\\E2E;|' \
  -e 's|use Daems\\Domain\\Event\\|use DaemsModule\\Events\\Domain\\|g' \
  -e 's|use Daems\\Application\\Event\\|use DaemsModule\\Events\\Application\\|g'
```

**Note on IsolationTestCase:** the base class `Daems\Tests\Isolation\IsolationTestCase` STAYS in core (provides shared MigrationTestCase setup). Moved tests use `use Daems\Tests\Isolation\IsolationTestCase;` — that line should NOT be rewritten. Verify with grep:
```bash
grep -h "use Daems\\\\Tests\\\\Isolation\\\\IsolationTestCase" C:/laragon/www/modules/events/backend/tests/Isolation/*.php
```
Expected: 5 lines (one per test).

- [ ] **Step 3: Run isolation suite for these tests**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/Isolation/ 2>&1 | tail -10
```
Expected: 5 tests passing. (Slow — ~5 min.)

- [ ] **Step 4: Run E2E suite for these tests**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit C:/laragon/www/modules/events/backend/tests/E2E/ 2>&1 | tail -10
```
Expected: 5 tests passing.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add backend/tests/Isolation backend/tests/E2E && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(tests): Event isolation (5) + E2E (5)"
```

---

## Task 21: Remove Events bindings from `bootstrap/app.php`

**Repo for commits:** `daems-platform`

**Files (modify):**
- `bootstrap/app.php` — remove ~9 Event-import lines + ~30 Event-binding lines

- [ ] **Step 1: Locate Event bindings**

```bash
cd C:/laragon/www/daems-platform && \
  grep -nE "use Daems\\\\(Domain|Application|Infrastructure)\\\\(Event|Adapter\\\\Api\\\\Controller\\\\EventController|Adapter\\\\Persistence\\\\Sql\\\\SqlEvent)" bootstrap/app.php
grep -nE "GetEvent|ListEvents|RegisterForEvent|SubmitEventProposal|UnregisterFromEvent|EventRepositoryInterface|EventProposalRepositoryInterface|SqlEvent|EventController" bootstrap/app.php
```
Record the line numbers. (Mix of `use` lines + `$container->bind` blocks.)

- [ ] **Step 2: Delete the lines**

Use Edit tool on `bootstrap/app.php`:
- Remove every `use Daems\(Domain|Application)\Event\` import (8-9 lines, top of file)
- Remove every `$container->singleton(EventRepositoryInterface::class, ...)` and `EventProposalRepositoryInterface` block (~10 lines each)
- Remove every `$container->bind(\Daems\Application\Event\<X>::class, ...)` block (~5 lines each × 8 use cases)
- Remove the `$container->bind(EventController::class, ...)` block

- [ ] **Step 3: Verify no leftover Event refs in bootstrap**

```bash
cd C:/laragon/www/daems-platform && \
  grep -E "Event(?!s2030|sLog|ementInfo)" bootstrap/app.php | grep -vE "//.*Event"
```
Expected: zero results (no remaining Event-related lines outside comments).

- [ ] **Step 4: Production-container smoke (module controllers still work via module bindings)**

Recreate the smoke script from Task 13 + run:
```bash
cd C:/laragon/www/daems-platform && cat > smoke-events-controllers.php <<'EOF'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new \ReflectionObject($kernel);
$prop = $ref->getProperty('container'); $prop->setAccessible(true);
$container = $prop->getValue($kernel);
foreach ([
    \DaemsModule\Events\Controller\EventController::class,
    \DaemsModule\Events\Controller\EventBackstageController::class,
] as $fqcn) {
    $c = $container->make($fqcn);
    echo $c instanceof $fqcn ? "OK: $fqcn\n" : "FAIL\n";
}
EOF
php smoke-events-controllers.php
rm smoke-events-controllers.php
```
Expected: 2× OK.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add bootstrap/app.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(bootstrap): Event bindings from bootstrap/app.php — module owns them now"
```

---

## Task 22: Remove Events routes from `routes/api.php`

**Repo for commits:** `daems-platform`

**Files (modify):**
- `routes/api.php` — remove all 22 Event routes (7 public + 15 backstage)

- [ ] **Step 1: Locate route blocks**

```bash
cd C:/laragon/www/daems-platform && \
  grep -nE "/api/v1/(events|event-proposals|backstage/events|backstage/event-proposals)" routes/api.php
```
Record line numbers (each route is ~3 lines).

- [ ] **Step 2: Delete each route block + its surrounding comments**

For each line range, delete the `$router->...({...}, [...]);` block. Total: 22 routes × ~3 lines = ~66 lines removed.

- [ ] **Step 3: Verify**

```bash
cd C:/laragon/www/daems-platform && \
  grep -cE "/api/v1/(events|event-proposals|backstage/events|backstage/event-proposals)" routes/api.php
```
Expected: 0.

- [ ] **Step 4: Live API smoke (module routes still serve)**

```bash
curl -s -o /dev/null -w "GET /api/v1/events: %{http_code}\n" "http://daems-platform.local/api/v1/events"
```
Expected: 200 (module routes from `routes.php` serve the request).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add routes/api.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(routes): 22 Event routes — module owns them now"
```

---

## Task 23: Remove 13 Event methods from BackstageController + 2 from MediaController

**Repo for commits:** `daems-platform`

**Files (modify):**
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — remove 13 methods + their `use` imports
- `src/Infrastructure/Adapter/Api/Controller/MediaController.php` — remove 2 methods + their `use` imports

- [ ] **Step 1: Remove 13 methods from BackstageController.php**

Use the line numbers recorded in Task 3 Step 6 + Task 10 Step 1. For each of these 13 methods, delete the entire method body (`public function <name>(...): Response { ... }`):
- `listEvents` (line 293)
- `createEvent` (line 308)
- `updateEvent` (line 331)
- `publishEvent` (line 359)
- `archiveEvent` (line 373)
- `listEventRegistrations` (line 387)
- `removeEventRegistration` (line 401)
- `statsEvents` (line 639)
- `getEventWithTranslations` (line 721)
- `updateEventTranslation` (line 740)
- `listEventProposals` (line 812)
- `approveEventProposal` (line 833)
- `rejectEventProposal` (line 855)

Also remove from the constructor:
- All Event-related use case parameters (`ListEventsForAdmin $listEventsForAdmin`, `CreateEvent $createEvent`, etc.)
- Their `use` import statements at the top of the file

- [ ] **Step 2: Remove 2 methods from MediaController.php**

Delete:
- `uploadEventImage`
- `deleteEventImage`

And their constructor parameters + `use` imports.

- [ ] **Step 3: Verify no Event references remain**

```bash
cd C:/laragon/www/daems-platform && \
  grep -nE "(Event|EventProposal|EventRegistration|EventController)" src/Infrastructure/Adapter/Api/Controller/BackstageController.php | grep -vE "^[0-9]+:\s*//"
```
Expected: zero non-comment Event references.

```bash
grep -nE "(uploadEventImage|deleteEventImage|UploadEventImage|DeleteEventImage)" src/Infrastructure/Adapter/Api/Controller/MediaController.php
```
Expected: zero results.

- [ ] **Step 4: PHPStan + smoke**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -3
```
Expected: 0 errors.

```bash
cd C:/laragon/www/daems-platform && cat > smoke.php <<'EOF'
<?php
require 'vendor/autoload.php';
$kernel = require 'bootstrap/app.php';
$ref = new ReflectionObject($kernel); $prop = $ref->getProperty('container'); $prop->setAccessible(true);
$c = $prop->getValue($kernel);
echo $c->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class) ? "BackstageController OK\n" : "FAIL\n";
echo $c->make(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class) ? "MediaController OK\n" : "FAIL\n";
echo $c->make(\DaemsModule\Events\Controller\EventBackstageController::class) ? "EventBackstageController OK\n" : "FAIL\n";
EOF
php smoke.php
rm smoke.php
```
Expected: 3× OK.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php \
          src/Infrastructure/Adapter/Api/Controller/MediaController.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(controllers): 13 Event methods from BackstageController + 2 from MediaController"
```

---

## Task 24: Remove Events bindings from `KernelHarness`

**Repo for commits:** `daems-platform`

**Files (modify):**
- `tests/Support/KernelHarness.php` — remove all Event-related bindings (mirror of bootstrap/app.php cleanup)

- [ ] **Step 1: Locate Event bindings in KernelHarness**

```bash
cd C:/laragon/www/daems-platform && \
  grep -nE "Event|EventProposal|InMemoryEvent" tests/Support/KernelHarness.php
```

- [ ] **Step 2: Delete those lines** (use Edit tool, mirror of Task 21 cleanup logic).

- [ ] **Step 3: Run E2E + Unit suites — all green**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -3
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -3
```
Expected: both pass.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add tests/Support/KernelHarness.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(harness): KernelHarness Event bindings — module owns them now"
```

---

## Task 25: Apply data-fix migration; delete legacy Event src/* + tests/* + dangling Migration tests + 7 original migration files

**Repo for commits:** `daems-platform`

**Files (delete):**
- `src/Domain/Event/` (entire dir)
- `src/Application/Event/` (entire dir)
- 15 admin sibling dirs under `src/Application/Backstage/`
- `src/Infrastructure/Adapter/Persistence/Sql/Sql{Event,EventProposal}Repository.php`
- `src/Infrastructure/Adapter/Api/Controller/EventController.php`
- `database/migrations/{001,012,025,032,043,051,056}_*.sql`
- 30 test files listed in §3 of plan
- 2 InMemory fakes in `tests/Support/Fake/`
- Any dangling `tests/Integration/Migration/Migration{025,032,043,051,056}Test.php`

**Files (apply):**
- Migration `database/migrations/NN_rename_event_migrations_in_schema_migrations_table.sql` (created in Task 2) — apply to dev DB.

- [ ] **Step 1: Apply data-fix migration to dev DB**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" \
  -h 127.0.0.1 -u root -psalasana daems_db \
  < C:/laragon/www/daems-platform/database/migrations/NN_rename_event_migrations_in_schema_migrations_table.sql
```
Expected: silent success. Verify rows updated:
```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" \
  -h 127.0.0.1 -u root -psalasana daems_db \
  -e "SELECT COUNT(*) FROM schema_migrations WHERE migration LIKE 'event_%';"
```
Expected: 7.

- [ ] **Step 2: Delete legacy src/ directories**

```bash
cd C:/laragon/www/daems-platform && \
  rm -rf src/Domain/Event \
         src/Application/Event \
         src/Application/Backstage/{ApproveEventProposal,ArchiveEvent,CreateEvent,DeleteEventImage,Events,GetEventWithAllTranslations,ListEventProposalsForAdmin,ListEventRegistrations,ListEventsForAdmin,PublishEvent,RejectEventProposal,UnregisterUserFromEvent,UpdateEvent,UpdateEventTranslation,UploadEventImage} \
         src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php \
         src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php \
         src/Infrastructure/Adapter/Api/Controller/EventController.php
```

- [ ] **Step 3: Delete 7 legacy migration files**

```bash
cd C:/laragon/www/daems-platform && \
  rm database/migrations/001_create_events_table.sql \
     database/migrations/012_create_event_registrations.sql \
     database/migrations/025_add_tenant_id_to_events.sql \
     database/migrations/032_add_tenant_id_to_event_registrations.sql \
     database/migrations/043_add_status_to_events.sql \
     database/migrations/051_create_events_i18n.sql \
     database/migrations/056_create_event_proposals.sql
```

- [ ] **Step 4: Delete 30 test files + 2 fakes**

```bash
cd C:/laragon/www/daems-platform && \
  rm tests/Unit/Domain/Event/EventTest.php \
     tests/Unit/Application/Event/RegisterForEventTest.php \
     tests/Unit/Application/Backstage/{ArchiveEvent,CreateEvent,DeleteEventImage,ListEventRegistrations,ListEventsForAdmin,ListEventsStats,PublishEvent,UnregisterUserFromEvent,UpdateEvent,UploadEventImage}Test.php \
     tests/Integration/Application/EventsAdminIntegrationTest.php \
     tests/Integration/EventProposalStatsTest.php \
     tests/Integration/EventStatsTest.php \
     tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php \
     tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php \
     tests/Isolation/EventProposalTenantIsolationTest.php \
     tests/Isolation/EventsAdminTenantIsolationTest.php \
     tests/Isolation/EventsI18nTenantIsolationTest.php \
     tests/Isolation/EventsStatsTenantIsolationTest.php \
     tests/Isolation/EventTenantIsolationTest.php \
     tests/E2E/Backstage/EventAdminEndpointsTest.php \
     tests/E2E/Backstage/EventsStatsEndpointTest.php \
     tests/E2E/Backstage/EventUploadTest.php \
     tests/E2E/EventProposalFlowE2ETest.php \
     tests/E2E/EventsLocaleE2ETest.php \
     tests/Support/Fake/InMemoryEventRepository.php \
     tests/Support/Fake/InMemoryEventProposalRepository.php

# Empty dirs cleanup
rmdir tests/Unit/Domain/Event tests/Unit/Application/Event 2>/dev/null
```

- [ ] **Step 5: Delete dangling Migration tests**

```bash
cd C:/laragon/www/daems-platform && \
  ls tests/Integration/Migration/Migration{025,032,043,051,056}Test.php 2>/dev/null
```
For each that exists, delete:
```bash
rm tests/Integration/Migration/Migration025Test.php tests/Integration/Migration/Migration032Test.php tests/Integration/Migration/Migration043Test.php tests/Integration/Migration/Migration051Test.php tests/Integration/Migration/Migration056Test.php 2>/dev/null
```

- [ ] **Step 6: Verify no Event references remain in core**

```bash
cd C:/laragon/www/daems-platform && \
  git grep -E 'Daems\\(Domain\\Event|Application\\Event|Application\\Backstage\\(ApproveEventProposal|ArchiveEvent|CreateEvent|DeleteEventImage|Events|GetEventWithAllTranslations|ListEventProposalsForAdmin|ListEventRegistrations|ListEventsForAdmin|PublishEvent|RejectEventProposal|UnregisterUserFromEvent|UpdateEvent|UpdateEventTranslation|UploadEventImage)|Infrastructure\\Adapter\\Persistence\\Sql\\SqlEvent|Infrastructure\\Adapter\\Api\\Controller\\EventController)' \
    -- src/ tests/ bootstrap/ routes/ 2>/dev/null
```
Expected: zero results.

- [ ] **Step 7: PHPStan + tests**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -3
cd C:/laragon/www/daems-platform && vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -3
cd C:/laragon/www/daems-platform && vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -3
```
Expected: 0 errors + all passing. Module's tests run via the autoload-dev mapping (Task 9.5).

- [ ] **Step 8: Commit**

```bash
cd C:/laragon/www/daems-platform && \
  git add -A && \
  git status -s | head && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(events): legacy Event code — moved to modules/events/"
```

(Use `git add -A` carefully — `git status -s` first to confirm only Event-related files staged. If any unrelated files appear, use targeted `git add` instead. Never stage `.claude/`.)

---

## Task 26: FIRST update daem-society/public/index.php front-controller routes (5 paths) + curl-smoke gate

**Repo for commits:** `daem-society`

**Why:** Wave F regression-prevention. The `daem-society/public/index.php` front controller hardcodes `__DIR__ . '/pages/events/...'` paths in 5 places. If we delete the originals (Task 27+) BEFORE updating these requires, every legacy `/events*` URL hits a fatal "failed to open required file" → HTTP 500 (the `/forums`-style regression we just fixed). Update routes FIRST, curl-smoke gate, THEN delete.

**Files (modify):**
- `daem-society/public/index.php`

- [ ] **Step 1: Locate the hardcoded paths**

```bash
grep -nE "pages/(events|backstage/events|backstage/event-proposals)" \
  C:/laragon/www/sites/daem-society/public/index.php
```
Expected: 5 paths (verify exact line numbers):
- `__DIR__ . '/pages/events/index.php'` in `$routes` array (~line 415)
- `__DIR__ . '/pages/events/propose.php'` in `/events/propose` if-block (~line 530)
- `__DIR__ . '/pages/events/detail.php'` in `/events/{slug}` if-block (~line 540)
- `__DIR__ . '/pages/backstage/events/index.php'` in admin map (~line 386)
- `__DIR__ . '/pages/backstage/event-proposals/index.php'` in admin map (~line 387)

- [ ] **Step 2: Edit `daem-society/public/index.php`**

**Change 1 (line ~415, `$routes` array):** remove the `/events` entry from `$routes` array (the module page router will serve `/events` natively because module name = "events"; no explicit handler needed unless slug capture is required).

Actually — since the module page router resolves `/events/` via pattern `^/<module>/<sub>?$`, and `index.php` exists at `modules/events/frontend/public/index.php`, the module router serves `/events` automatically. **Just remove the `/events` entry from `$routes`.** No replacement needed.

Old:
```php
'/events'           => __DIR__ . '/pages/events/index.php',
```
New: (delete the entire line)

**Change 2 (line ~530, `/events/propose`):**

The module router doesn't match `/events/propose` because no `propose.php` is in the module's public dir's root sub-handler... actually, `/events/propose` DOES match the module router (it tries `modules/events/frontend/public/propose/index.php` first, then `modules/events/frontend/public/propose.php`). The latter exists after Task 27. So the module router handles it. **Just remove the explicit handler.**

Old:
```php
if ($uri === '/events/propose') {
    require __DIR__ . '/pages/events/propose.php';
    exit;
}
```
New: (delete the entire if-block)

**Change 3 (line ~540, `/events/{slug}`):** This is the dynamic detail page that fetches via API and forwards. Keep the regex + API-fetch logic, only update the require path.

Old:
```php
if (preg_match('#^/events/([a-z0-9\-]+)$#', $uri, $m)) {
    $userId = $_SESSION['user']['id'] ?? null;
    $query  = $userId ? ['user_id' => $userId] : [];
    $event  = ApiClient::get('/events/' . rawurlencode($m[1]), $query);
    if ($event !== null) {
        require __DIR__ . '/pages/events/detail.php';
        exit;
    }
}
```
New:
```php
if (preg_match('#^/events/([a-z0-9\-]+)$#', $uri, $m)) {
    $userId = $_SESSION['user']['id'] ?? null;
    $query  = $userId ? ['user_id' => $userId] : [];
    $event  = ApiClient::get('/events/' . rawurlencode($m[1]), $query);
    if ($event !== null && isset($daemsKnownModules['events']['public'])) {
        $detailPath = realpath($daemsKnownModules['events']['public'] . '/detail.php');
        if ($detailPath !== false && is_file($detailPath)) {
            require $detailPath;
            exit;
        }
    }
}
```

**Change 4 + 5 (lines ~386, ~387, backstage admin map):** remove the 2 entries. Module router serves `/backstage/events` and `/backstage/event-proposals` natively.

Old:
```php
        '/events'             => __DIR__ . '/pages/backstage/events/index.php',
        '/event-proposals'    => __DIR__ . '/pages/backstage/event-proposals/index.php',
```
New: (delete both lines + leave a comment)
```php
        // /backstage/events + /backstage/event-proposals served by the module
        // page router (block above this) → modules/events/frontend/backstage/.
```

- [ ] **Step 3: PHP lint**

```bash
php -l C:/laragon/www/sites/daem-society/public/index.php
```
Expected: "No syntax errors detected".

- [ ] **Step 4: Curl-smoke gate (BLOCK if any returns 500)**

The originals still exist in this commit; module's frontend dir is empty (Tasks 27+). The module router will fall through to legacy paths. So the legacy URLs should still work after these route changes. **Verify:**

```bash
for u in /events /events/propose /events/some-published-slug \
         /backstage/events /backstage/event-proposals; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' http://daems.local$u) $u"
done
```
Expected: all non-500 (200 / 302 to login / 404 on non-existent slug). **STOP and investigate any 500.**

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/sites/daem-society && \
  git add public/index.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Wire(routes): point /events + /backstage/events admin map at module path

Front-controller paths updated to use \$daemsKnownModules['events'] before
modules/events/frontend/* takes over in the next 3 commits. Same fix as
the Forum 92fae96 regression-prevention (Wave F lesson #10)."
```

---

## Task 27: Move 8 daem-society public pages + __DIR__ rewrite

**Repo for commits:** `dp-events`

**Files (move):** `daem-society/public/pages/events/*` → `modules/events/frontend/public/*`. Final inventory:

```bash
ls C:/laragon/www/sites/daem-society/public/pages/events/
```
Expected: `cta.php`, `data/`, `detail/`, `detail.php`, `grid.php`, `hero.php`, `index.php`, `propose.php`. (8 entries.)

- [ ] **Step 1: Copy entire dir**

```bash
SRC=C:/laragon/www/sites/daem-society/public/pages/events
DST=C:/laragon/www/modules/events/frontend/public
mkdir -p "$DST"
cp -r "$SRC/." "$DST/"
ls -R "$DST" | head -30
```

- [ ] **Step 2: Identify chrome includes that need rewriting**

```bash
grep -rn '__DIR__' --include='*.php' C:/laragon/www/modules/events/frontend/public/
```

Inventory all `__DIR__ . '/../../...'` includes (chrome). Each should be rewritten to `DAEMS_SITE_PUBLIC . '/...'`:
- `__DIR__ . '/../../partials/top-nav.php'` → `DAEMS_SITE_PUBLIC . '/partials/top-nav.php'`
- `__DIR__ . '/../../partials/footer.php'` → `DAEMS_SITE_PUBLIC . '/partials/footer.php'`
- `__DIR__ . '/../../pages/errors/404.php'` → `DAEMS_SITE_PUBLIC . '/pages/errors/404.php'`
- (if any `__DIR__ . '/../../pages/404.php'` exists — nonexistent path bug from Forum) → fix to `DAEMS_SITE_PUBLIC . '/pages/errors/404.php'`

**Sibling includes** (e.g. `__DIR__ . '/hero.php'`, `__DIR__ . '/cta.php'`, `__DIR__ . '/grid.php'`, `__DIR__ . '/detail/<X>.php'`) — keep as `__DIR__`.

- [ ] **Step 3: Apply rewrites**

For each file with chrome includes, use Edit tool to replace the exact lines.

- [ ] **Step 4: Verify with grep**

```bash
grep -rn '__DIR__ . .\\.\\./\\.\\./' C:/laragon/www/modules/events/frontend/public/
```
Expected: zero results (all upward-traversing chrome includes rewritten).

```bash
grep -rn 'pages/(404|errors)/' --include='*.php' C:/laragon/www/modules/events/frontend/public/
```
Expected: only `pages/errors/404.php` (no orphan `pages/404.php`).

- [ ] **Step 5: PHP lint**

```bash
find C:/laragon/www/modules/events/frontend/public -name '*.php' -exec php -l {} \; | grep -v "No syntax errors"
```
Expected: empty.

- [ ] **Step 6: Curl-smoke**

```bash
for u in /events /events/propose; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' http://daems.local$u) $u"
done
```
Expected: 200 (events) and 302 to login (propose). **Block on 500.**

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add frontend/public && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 8 public Events pages with DAEMS_SITE_PUBLIC includes"
```

(Originals in daem-society stay until Task 29 commit deletes them.)

---

## Task 28: Move 1 backstage events PHP + 1 event-proposals PHP + asset URL update

**Repo for commits:** `dp-events`

**Files (move):**
- `daem-society/public/pages/backstage/events/index.php` → `modules/events/frontend/backstage/index.php`
- `daem-society/public/pages/backstage/event-proposals/index.php` → `modules/events/frontend/backstage/event-proposals/index.php`

(Asset files — `event-modal.css`, `event-modal.js`, `events-stats.js`, `upload-widget.js`, `proposal-modal.css`, `proposal-modal.js` — stay in source dir; move in Task 29.)

- [ ] **Step 1: Copy 2 PHP files**

```bash
mkdir -p C:/laragon/www/modules/events/frontend/backstage/event-proposals
cp C:/laragon/www/sites/daem-society/public/pages/backstage/events/index.php \
   C:/laragon/www/modules/events/frontend/backstage/index.php
cp C:/laragon/www/sites/daem-society/public/pages/backstage/event-proposals/index.php \
   C:/laragon/www/modules/events/frontend/backstage/event-proposals/index.php
```

- [ ] **Step 2: Rewrite chrome `__DIR__` includes**

```bash
grep -rn '__DIR__' --include='*.php' C:/laragon/www/modules/events/frontend/backstage/
```

For each `__DIR__ . '/../layout.php'` (or `'/../../layout.php'` for sub-dir), rewrite to `DAEMS_SITE_PUBLIC . '/pages/backstage/layout.php'`. For `shared/empty-state.php` similarly.

- [ ] **Step 3: Rewrite asset URLs**

```bash
grep -rn 'pages/backstage/(events|event-proposals)/' --include='*.php' C:/laragon/www/modules/events/frontend/backstage/
```

For each asset URL `/pages/backstage/(events|event-proposals)/<file>`, rewrite to `/modules/events/assets/backstage/<file>`. Files referenced:
- `/pages/backstage/events/event-modal.css` → `/modules/events/assets/backstage/event-modal.css`
- `/pages/backstage/events/event-modal.js` → `/modules/events/assets/backstage/event-modal.js`
- `/pages/backstage/events/events-stats.js` → `/modules/events/assets/backstage/events-stats.js`
- `/pages/backstage/events/upload-widget.js` → `/modules/events/assets/backstage/upload-widget.js`
- `/pages/backstage/event-proposals/proposal-modal.css` → `/modules/events/assets/backstage/proposal-modal.css`
- `/pages/backstage/event-proposals/proposal-modal.js` → `/modules/events/assets/backstage/proposal-modal.js`

- [ ] **Step 4: Verify**

```bash
grep -rn 'pages/backstage/(events|event-proposals)/' --include='*.php' C:/laragon/www/modules/events/frontend/backstage/
```
Expected: zero results.

```bash
grep -rn '/modules/events/assets/backstage/' --include='*.php' C:/laragon/www/modules/events/frontend/backstage/
```
Expected: 6 references (or as many asset-files as actually referenced).

- [ ] **Step 5: PHP lint**

```bash
find C:/laragon/www/modules/events/frontend/backstage -name '*.php' -exec php -l {} \;
```

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/modules/events && \
  git add frontend/backstage && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 2 backstage Events pages + asset URL rewrite to /modules/events/assets/"
```

---

## Task 29: Move 6 assets + delete daem-society originals

**Repo for commits:** `dp-events` (move-in) + `daem-society` (delete originals)

**Files (move):**
- `event-modal.css`, `event-modal.js`, `events-stats.js`, `upload-widget.js` from `daem-society/public/pages/backstage/events/`
- `proposal-modal.css`, `proposal-modal.js` from `daem-society/public/pages/backstage/event-proposals/`
- → `modules/events/frontend/assets/backstage/`

- [ ] **Step 1: Copy 6 assets**

```bash
SRC_E=C:/laragon/www/sites/daem-society/public/pages/backstage/events
SRC_P=C:/laragon/www/sites/daem-society/public/pages/backstage/event-proposals
DST=C:/laragon/www/modules/events/frontend/assets/backstage
mkdir -p "$DST"
cp "$SRC_E/event-modal.css" "$SRC_E/event-modal.js" "$SRC_E/events-stats.js" "$SRC_E/upload-widget.js" "$DST/"
cp "$SRC_P/proposal-modal.css" "$SRC_P/proposal-modal.js" "$DST/"
ls -la "$DST"
```
Expected: 6 files.

- [ ] **Step 2: HTTP smoke 6× 200**

```bash
for f in event-modal.css event-modal.js events-stats.js upload-widget.js \
         proposal-modal.css proposal-modal.js; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://daems.local/modules/events/assets/backstage/$f")
  echo "$code /modules/events/assets/backstage/$f"
done
```
Expected: 6× 200. **STOP** on any other code.

- [ ] **Step 3: Commit assets to dp-events**

```bash
cd C:/laragon/www/modules/events && \
  git add frontend/assets && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Move(frontend): 4 events JS/CSS + 2 event-proposals JS/CSS to assets/backstage/"
```

- [ ] **Step 4: Delete originals from daem-society**

```bash
cd C:/laragon/www/sites/daem-society && \
  rm -rf public/pages/events public/pages/backstage/events public/pages/backstage/event-proposals
```

- [ ] **Step 5: Verify daem-society git status before commit**

```bash
cd C:/laragon/www/sites/daem-society && git status -s | head -40
```
Expected: many `D` (deleted) lines for events + backstage/events + backstage/event-proposals files. **No `M` (modified)** lines for those subtrees (we shouldn't have edited originals — only the front controller in Task 26).

If any `M` line appears for events files: STOP — investigate, originals must not be modified.

- [ ] **Step 6: Curl-smoke after delete (module router serves them now)**

```bash
for u in /events /events/propose /backstage/events /backstage/event-proposals; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' http://daems.local$u) $u"
done
```
Expected: 200 (events) / 302 (propose, login) / 302 (backstage *, login). **All non-500.**

- [ ] **Step 7: Commit deletion in daem-society**

```bash
cd C:/laragon/www/sites/daem-society && \
  git add -u && \
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit \
      -m "Remove(frontend): events + backstage/events + backstage/event-proposals dirs — module router serves them now"
```

---

## Task 30: Final verification gate

**Repo for commits:** none (verification only — new fix commits if red)

- [ ] **Step 1: PHPStan**

```bash
cd C:/laragon/www/daems-platform && composer analyse 2>&1 | tail -3
```
Expected: `[OK] No errors` (with baseline).

- [ ] **Step 2: All test suites (run separately to bypass 600s composer timeout)**

```bash
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -3
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=Integration 2>&1 | tail -3
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=Isolation 2>&1 | tail -3
cd C:/laragon/www/daems-platform && \
  vendor/bin/phpunit --testsuite=E2E 2>&1 | tail -3
```
Expected: each suite OK. Total green tests close to current count (943 minus ~30 moved Event tests + module's tests if loaded via autoload-dev mapping = stays roughly even).

- [ ] **Step 3: Production-container controller smoke**

```bash
cd C:/laragon/www/daems-platform && cat > smoke.php <<'EOF'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$kernel = require __DIR__ . '/bootstrap/app.php';
$ref = new \ReflectionObject($kernel);
$prop = $ref->getProperty('container'); $prop->setAccessible(true);
$container = $prop->getValue($kernel);
$failures = [];
foreach ([
    \DaemsModule\Events\Controller\EventController::class,
    \DaemsModule\Events\Controller\EventBackstageController::class,
    \Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class,
    \Daems\Infrastructure\Adapter\Api\Controller\MediaController::class,
] as $fqcn) {
    try {
        $c = $container->make($fqcn);
        echo "OK: $fqcn\n";
    } catch (\Throwable $e) {
        $failures[] = "$fqcn: " . $e::class . ": " . $e->getMessage();
    }
}
if ($failures) { foreach ($failures as $f) echo "  FAIL: $f\n"; exit(1); }
EOF
php smoke.php
rm smoke.php
```
Expected: 4× OK (2 module + 2 core).

- [ ] **Step 4: git grep cleanup verification**

```bash
cd C:/laragon/www/daems-platform && \
  git grep -E 'Daems\\(Domain\\Event|Application\\Event|Application\\Backstage\\(ApproveEventProposal|ArchiveEvent|CreateEvent|DeleteEventImage|Events|GetEventWithAllTranslations|ListEventProposalsForAdmin|ListEventRegistrations|ListEventsForAdmin|PublishEvent|RejectEventProposal|UnregisterUserFromEvent|UpdateEvent|UpdateEventTranslation|UploadEventImage)|Infrastructure\\Adapter\\Persistence\\Sql\\SqlEvent|Infrastructure\\Adapter\\Api\\Controller\\EventController)' \
    -- src/ tests/ bootstrap/ routes/ 2>/dev/null
```
Expected: zero results.

- [ ] **Step 5: Curl-smoke all URL families**

```bash
for u in /events /events/propose /events/some-published-slug \
         /backstage/events /backstage/event-proposals; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' http://daems.local$u) $u"
done
```
Expected: 200 / 302-or-200 / 200-or-404 / 302 / 302. **All non-500.**

- [ ] **Step 6: Browser smoke (manual, mandatory)**

User runs through this checklist in browser at `http://daems.local`:

| URL | Expected |
|---|---|
| `/events` | Public listing renders, identical to pre-move |
| `/events/<existing-slug>` | Detail page renders, registration button visible if logged in |
| `/events/propose` (logged in member) | Proposal form renders + submission works |
| `/backstage/events` (logged in admin) | Events admin grid renders, KPI strip visible, modals work |
| `/backstage/events` — Create new event | Form submits → event appears in list |
| `/backstage/events` — Edit event chrome | Save persists |
| `/backstage/events` — Edit event translations (multi-locale) | Save per-locale works |
| `/backstage/events` — Publish/Archive | Status transition works |
| `/backstage/events` — Upload event image | Multipart upload returns 200 + image visible |
| `/backstage/events` — Delete event image | Image removed |
| `/backstage/events` — View registrations | List loads + remove user works |
| `/backstage/event-proposals` | Proposals list renders |
| `/backstage/event-proposals` — Approve | Spawns event in `/backstage/events` |
| `/backstage/event-proposals` — Reject | Status updates |
| Network tab | Every `/modules/events/assets/backstage/*` returns 200 |
| JS console | Zero errors |
| Theme | Backstage parity with other admin pages |

**Block on any failure** — fix, recommit, re-run gate.

- [ ] **Step 7: Final report to user**

Provide:
- All commit SHAs in `daems-platform`, `dp-events`, `daem-society`
- `composer test:all` summary (4 suites)
- PHPStan summary (0 errors w/ baseline)
- Browser smoke note from user
- Awaiting "pushaa" to push all 3 repos.

---

## Self-review notes

**Spec coverage check:** every section of the spec maps to one or more tasks:
- Spec §1 decisions → encoded in Tasks 4–10, 12–15
- Spec §2 lessons → embedded across all relevant tasks (Task 1 stub bindings = lesson 2; Task 9.5 = lesson 3; Task 26 = lesson 10; etc.)
- Spec §3 inventory → Tasks 4–9, 12, 16–20
- Spec §4 manifest → Task 1
- Spec §5 bindings + routes → Tasks 13–15
- Spec §6 removals → Tasks 21–25
- Spec §7 namespace rewrite map → applied in every move task
- Spec §8 frontend integration → Tasks 26–29
- Spec §9 BOTH-wiring rule → Tasks 13 + 14 + 21 + 24 + 30 (smoke)
- Spec §10 verification gates → Task 30
- Spec §11 risks → mitigations distributed (Task 26 = Wave F regression-prevention; Task 10 = TDD extraction; Task 13 = production smoke)
- Spec §12 success criteria → enforced in Task 30

**Tasks DROPPED from spec §15 preview:**
- Task 11 (Second controller) — single controller decision (E4=A)

**No placeholder content:** every code block + command is concrete. Migration NN number to be assigned in Task 2 Step 1 (highest existing + 1) and used consistently from there.

**Type consistency:**
- `Daems\Infrastructure\Framework\Container\Container` used as type hint in all binding closures
- `EventBackstageController` constructor parameter list in Task 10 Step 6 matches the `$container->make(...)` calls in Task 13 Step 2 (15 use cases + LocalImageStorage)
- Method names used in `routes.php` (Task 15) match method names in `EventBackstageController` (Task 10)

**Outstanding plan-phase research items** (carried from spec):
- Final inventory of `tests/Integration/Migration/Migration{NNN}Test.php` files (Task 25 Step 5 — delete what exists)
- `events-legacy` route alias confirmation (Task 15 Step 1 — verify if frontend still calls these; if not, drop from `routes.php`)
- Exact constructor parameters for each Backstage use case (Task 13 Step 2 — verify by reading moved files; adjust if any takes additional dependencies)
- MediaController `uploadEventImage` / `deleteEventImage` exact dependencies (Task 10 Step 6 — verify against current MediaController constructor)
