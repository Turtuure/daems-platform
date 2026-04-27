# Modular architecture — Phase 1 (Events extraction) design spec

**Date:** 2026-04-27
**Status:** Approved (brainstorming complete, awaiting plan)
**Scope:** Extract the Events domain (Event + EventProposal + EventRegistration) from `daems-platform` core into a self-contained module `modules/events/` (the `dp-events` git repo), following the manifest convention proven by the Insights pilot, the Forum extraction (completed 2026-04-27, 943/943 tests green), and the Projects extraction design (parallel work, design committed 2026-04-27 — `dbfc1de`/`6c25c8f`). Zero user-visible change — Events must work exactly as it does today after the move.

**Foundation already shipped (Insights pilot + Forum + Projects design, branch `dev`):**
- `module.json` schema + `ModuleRegistry` boot-time loader
- Composer runtime autoloader extension for `DaemsModule\<Name>\` namespaces
- `daem-society/public/index.php` module-router block (`/<module>/...`, `/backstage/<module>/...`, `/modules/<module>/assets/...`)
- `MigrationTestCase` scans `../modules/*/backend/migrations/*.{sql,php}`
- `KernelHarness` boots `ModuleRegistry` in test mode and loads each module's `bindings.test.php`
- PHPStan paths glob includes `../modules/{insights,forum,projects}/backend/src` — Events to be added in Task 9.5
- **Forum + Wave-F lessons baked in (see "Lessons applied" below)** — 10 hard-won corrections that shape this plan from day 1

**This spec does not redefine those — only Events-specific extraction details.**

**Repo state precondition:** `C:\laragon\www\modules\events\.git\` is already initialised (HEAD → `refs/heads/dev`, `origin = https://github.com/Turtuure/dp-events.git`). Working copy is empty (no commits yet). Skeleton task starts from this state.

---

## 1. Decisions locked during brainstorming

Per the briefing, decisions Q1–Q3 (scope/disable/isolation) carry over from the foundation spec. Forum F1–F3 carry as Events-specific E1–E3. E4 was actively decided in this brainstorm.

| # | Decision | Choice |
|---|---|---|
| Q1 | Scope | **C** — phased vision (carries from foundation) |
| Q2 | Disable mode | **B** — UI hide + 403, jobs continue (carries) |
| Q3 | Isolation | **A** — strict (modules ref core only) (carries) |
| E1 | Module structure | **A** — single `events` module containing Event + EventProposal + EventRegistration. ApproveEventProposal stays inside the module (no cross-module dep) since approval spawns an Event. |
| E2 | Domain placement | **MODULE** — `Daems\Domain\Event\*` (7 files) MOVES into `DaemsModule\Events\Domain\*`. Verified zero cross-domain consumers in `src/` and `tests/` (grep `Daems\\(Domain\|Application)\\Event\\` outside `/Event/` folders → 0 matches). Insights pattern, NOT Forum pattern. |
| E3 | Backstage namespace inside the module | **A** — `DaemsModule\Events\Application\Backstage\<UseCase>\*` (mirrors current flat `Daems\Application\Backstage\<UseCase>\*` structure — 15 sibling dirs, no `Backstage\Events\*` umbrella). Smallest diff. Same as Forum F1, Projects F1. |
| E4 | Backstage controller split | **A** — single `EventBackstageController` (13 BackstageController methods + 2 MediaController image methods relocated = 15 total). Below the 20-method threshold that forced Forum's split; bundled because frontend already separates concerns at the route/page level (`/backstage/events` + `/backstage/event-proposals`). |
| E7 | MediaController cross-domain Event methods | **Relocate** — `MediaController::uploadEventImage` + `MediaController::deleteEventImage` (currently invoked inline by routes 249/253 in `routes/api.php`) MOVE to `EventBackstageController` (their use cases — `UploadEventImage` + `DeleteEventImage` — also move with the module). MediaController in core retains only domain-agnostic media helpers + non-Event methods (e.g. `uploadProjectImage` while Projects extraction is in flight, etc.). This avoids core MediaController importing module types, preserving Q3-A strict isolation. |
| E5 | SQL repository class structure | **A** — keep the two existing classes (`SqlEventRepository`, `SqlEventProposalRepository`). No mid-extraction refactor. Same as Forum F2, Projects F2. |
| E6 | Frontend asset folder layout | **A** — flat `frontend/assets/backstage/` with `events-`-prefixed filenames where ambiguous, otherwise keep original (`event-modal.css`, `event-modal.js`, `events-stats.js`, `upload-widget.js` for events admin; `proposal-modal.css`, `proposal-modal.js` for proposals admin). URL pattern `/modules/events/assets/backstage/<file>` matches Insights + Forum + Projects. |

### E4 — backstage controller method inventory (15 methods total in single controller)

13 from current `BackstageController.php`:

| # | Method | Source line in core BackstageController.php | Function |
|---|---|---|---|
| 1 | `listEvents` | 293 | List events for admin |
| 2 | `createEvent` | 308 | Create event |
| 3 | `updateEvent` | 331 | Update event chrome |
| 4 | `publishEvent` | 359 | Publish (status transition) |
| 5 | `archiveEvent` | 373 | Archive |
| 6 | `listEventRegistrations` | 387 | List registrations for an event |
| 7 | `removeEventRegistration` | 401 | Unregister user from event |
| 8 | `statsEvents` | 639 | Dashboard stats |
| 9 | `getEventWithTranslations` | 721 | Read event with all translations + coverage |
| 10 | `updateEventTranslation` | 740 | Save per-locale translation |
| 11 | `listEventProposals` | 812 | List proposals for admin |
| 12 | `approveEventProposal` | 833 | Approve proposal → spawns Event |
| 13 | `rejectEventProposal` | 855 | Reject proposal |

2 from current `MediaController.php` (relocated per E7):

| # | Method | Source location | Function |
|---|---|---|---|
| 14 | `uploadEventImage` | `MediaController.php` (invoked by `routes/api.php` line 249) | Multipart image upload for an event |
| 15 | `deleteEventImage` | `MediaController.php` (invoked by `routes/api.php` line 253) | Delete event image |

---

## 2. Lessons applied (baked into this spec from day 1)

Forum extraction (commits `001104d…0b9fb98` in daems-platform; `bcda770…b72fb57` in dp-forum; `46ff137…92fae96` in daem-society) surfaced 10 corrections. All apply identically to Events:

1. **Domain MOVES (Events-specific)** — unlike Forum (cross-domain consumers forced Domain to stay in core) and unlike Projects (3 cross-domain consumers force its Domain to stay), Events Domain has been verified to have **zero cross-domain consumers**. So `src/Domain/Event/` (7 files) MOVES into `modules/events/backend/src/Domain/`. This is the Insights pattern. Module's `bindings.php` binds **MODULE** repository interfaces (`DaemsModule\Events\Domain\EventRepositoryInterface` etc.) to **MODULE** SQL implementations. Plan tasks "Move Domain layer" + "Move Domain unit tests" ARE included for Events.

2. **Module skeleton needs `bindings.php` + `bindings.test.php` + `routes.php` STUBS day-1** (return-closure no-op). `ModuleRegistry` reads all three the moment it sees `module.json`; missing files crash with all 172+ tests erroring on harness boot.

3. **Task 9.5 (NEW infra commit on daems-platform):** add `DaemsModule\Events\` + `DaemsModule\Events\Tests\` to `composer.json` `autoload-dev`, add `../modules/events/backend/src` to `phpstan.neon` `paths`, run `composer dump-autoload`. Single commit on daems-platform `dev`. Without this, PHPStan + autoloader can't see the module.

4. **Module migration ALTERs on core tables need conditional guards** (proven in Forum's `forum_006_extend_dismissals_enum_forum_report.sql`):
   ```sql
   SET @t := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'admin_application_dismissals');
   SET @sql := IF(@t > 0, 'ALTER TABLE admin_application_dismissals ...', 'DO 0');
   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
   ```
   Same guard for the `06N_*` schema_migrations rename data-fix.

5. **Migration numbering (data-fix):** new core migration `database/migrations/0NN_rename_event_migrations_in_schema_migrations_table.sql` — idempotent, conditional, renames the existing `*event*` rows in `schema_migrations` table from old → new filenames so dev DBs don't re-run them. Number to be assigned during plan (one above the highest existing migration at extraction time, e.g. `067` if Projects shipped `066`).

6. **TDD-controller-extraction (Task 10):** use Reflection-based signature tests as Forum's Task 10/11 did. Verify constructor parameter list, method names, parameter types, return types BEFORE extracting body. Catches constructor-arg-mismatch bugs.

7. **Production-container smoke** mandatory after Task 13 + every Wave E task (T21–T25). Pattern:
   ```php
   $kernel = require __DIR__ . '/bootstrap/app.php';
   $ref = new \ReflectionObject($kernel);
   $prop = $ref->getProperty('container'); $prop->setAccessible(true);
   $container = $prop->getValue($kernel);
   foreach ([EventController::class, EventBackstageController::class] as $fqcn) {
       $container->make($fqcn); // assert no TypeError
   }
   ```
   Catches DI wiring drift the moment legacy code is removed.

8. **`composer test:all` exceeds 600s**. Run suites separately via `vendor/bin/phpunit --testsuite=Unit|Integration|Isolation|E2E` to bypass `COMPOSER_PROCESS_TIMEOUT`. Plan Task 29 verification gate enforces this.

9. **CLAUDE.md BOTH-wiring rule:** every controller, use case, and SQL repo bound in `bootstrap/app.php` (production) MUST also be bound in either the module's `bindings.test.php` (preferred) or `tests/Support/KernelHarness.php`. After Wave E removes Event bindings from core's `bootstrap/app.php` and `KernelHarness.php`, the module's `bindings.test.php` becomes the only source of truth for tests.

10. **Wave F regression — front-controller routes update BEFORE delete originals (NEW lesson from Forum 92fae96 fix).** When moving frontend pages from `daem-society/public/pages/<X>/` to `modules/<name>/frontend/public/`, the `daem-society/public/index.php` front controller hardcodes `require __DIR__ . '/pages/<X>/...'` paths in 3+ places (per-route `if (preg_match) { require __DIR__ . '...'; }` blocks AND the `$routes` array AND the backstage admin map). Forum's Wave F deleted the originals before updating these requires → fatal "failed to open required file" → HTTP 500 on every legacy URL the top-nav linked to. **Events plan Wave F MUST update the front controller FIRST (point requires at `$daemsKnownModules['events']['public']`/`['backstage']`), curl-smoke ALL legacy URLs returning non-500, THEN delete originals in the next commit.** See §8 for the exact route inventory.

---

## 3. Inventory — what moves

### Backend (`daems-platform/` → `modules/events/backend/`)

#### Domain layer (7 files — MOVES, unlike Forum/Projects)

```
src/Domain/Event/Event.php
src/Domain/Event/EventId.php
src/Domain/Event/EventProposal.php
src/Domain/Event/EventProposalId.php
src/Domain/Event/EventProposalRepositoryInterface.php
src/Domain/Event/EventRegistration.php
src/Domain/Event/EventRepositoryInterface.php
```
→ `modules/events/backend/src/Domain/{Event,EventId,EventProposal,EventProposalId,EventProposalRepositoryInterface,EventRegistration,EventRepositoryInterface}.php`

**Namespace rewrite:** `Daems\Domain\Event\*` → `DaemsModule\Events\Domain\*`. ALL references in core src/ + tests/ + bootstrap/ + routes/ change to the new namespace (verified zero outside-Event-folder refs by grep). Module's bindings.php binds `DaemsModule\Events\Domain\EventRepositoryInterface` etc. directly (no core interface to satisfy).

#### Application/Event public use cases (8 dirs, ~21 files)

```
src/Application/Event/GetEvent/                       (use case + Input + Output)
src/Application/Event/GetEventBySlugForLocale/        ...
src/Application/Event/ListEvents/
src/Application/Event/ListEventsForLocale/
src/Application/Event/RegisterForEvent/
src/Application/Event/SubmitEventProposal/
src/Application/Event/UnregisterFromEvent/
src/Application/Event/UpdateEvent/                    (NB: there's also a Backstage UpdateEvent — verify during plan)
```
→ `modules/events/backend/src/Application/`

**Namespace rewrite:** `Daems\Application\Event\*` → `DaemsModule\Events\Application\*`.

#### Application/Backstage admin use cases (15 sibling dirs, ~41 files — flat structure preserved)

```
src/Application/Backstage/ApproveEventProposal/
src/Application/Backstage/ArchiveEvent/
src/Application/Backstage/CreateEvent/
src/Application/Backstage/DeleteEventImage/
src/Application/Backstage/Events/                     (probably a stats container — verify)
src/Application/Backstage/GetEventWithAllTranslations/
src/Application/Backstage/ListEventProposalsForAdmin/
src/Application/Backstage/ListEventRegistrations/
src/Application/Backstage/ListEventsForAdmin/
src/Application/Backstage/PublishEvent/
src/Application/Backstage/RejectEventProposal/
src/Application/Backstage/UnregisterUserFromEvent/
src/Application/Backstage/UpdateEvent/
src/Application/Backstage/UpdateEventTranslation/
src/Application/Backstage/UploadEventImage/
```
→ `modules/events/backend/src/Application/Backstage/<same-names>/` (flat, 15 sibling dirs at module's Backstage level, no double-folder umbrella).

**Namespace rewrite:** `Daems\Application\Backstage\<UseCase>\*` → `DaemsModule\Events\Application\Backstage\<UseCase>\*`.

#### SQL repositories (2 files)

```
src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php
src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php
```
→ `modules/events/backend/src/Infrastructure/{SqlEventRepository,SqlEventProposalRepository}.php`

**Namespace rewrite:** `Daems\Infrastructure\Adapter\Persistence\Sql\*` → `DaemsModule\Events\Infrastructure\*`.

#### Public controller (1 file → split into module's Controller dir)

```
src/Infrastructure/Adapter/Api/Controller/EventController.php
```
→ `modules/events/backend/src/Controller/EventController.php`

**Namespace rewrite:** `Daems\Infrastructure\Adapter\Api\Controller\EventController` → `DaemsModule\Events\Controller\EventController`.

#### Backstage controller (NEW — extracted from `BackstageController` via TDD)

`modules/events/backend/src/Controller/EventBackstageController.php` — fresh class containing the 15 methods listed in §1 E4 (13 from BackstageController + 2 from MediaController per E7). Constructor wires the same use cases the parent controllers did for those methods. Other (non-Event) BackstageController methods stay in `daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`; MediaController retains only domain-agnostic media helpers + non-Event methods. Total core shrinkage: ~13 BackstageController methods + 2 MediaController methods + their Event-specific imports.

#### Migrations — split decision

**10 event-touching migrations exist in `database/migrations/`. Three of them (053, 054, 059) touch BOTH `events` AND `projects` tables** — these are the same cross-domain migrations Projects extraction kept in core. Decision:

| Old name | Move to module? | New name |
|---|---|---|
| `001_create_events_table.sql` | ✅ move | `event_001_create_events_table.sql` |
| `012_create_event_registrations.sql` | ✅ move | `event_002_create_event_registrations.sql` |
| `025_add_tenant_id_to_events.sql` | ✅ move | `event_003_add_tenant_id_to_events.sql` |
| `032_add_tenant_id_to_event_registrations.sql` | ✅ move | `event_004_add_tenant_id_to_event_registrations.sql` |
| `043_add_status_to_events.sql` | ✅ move | `event_005_add_status_to_events.sql` |
| `051_create_events_i18n.sql` | ✅ move | `event_006_create_events_i18n.sql` |
| `056_create_event_proposals.sql` | ✅ move | `event_007_create_event_proposals.sql` |
| `053_backfill_events_projects_i18n.sql` | ❌ stays | (mixed events+projects backfill, Projects also kept this) |
| `054_drop_translated_columns_from_events_projects.sql` | ❌ stays | (mixed events+projects DROP COLUMN) |
| `059_fulltext_events_projects_i18n.sql` | ❌ stays | (mixed events+projects FULLTEXT) |

**Plus possible newer event_proposals i18n / source_locale migrations to verify during plan** (e.g. `0NN_add_source_locale_to_event_proposals.sql` analogous to Projects' `055_*`). Inventory exact set during plan-phase research.

**7 migrations move to `modules/events/backend/migrations/` as `event_001..007`.** New core data-fix migration `0NN_rename_event_migrations_in_schema_migrations_table.sql` updates `schema_migrations` rows (idempotent + conditional). Migrations 053/054/059 stay in core (they were already retained during Projects extraction).

#### Tests (~30 files)

```
tests/Unit/Domain/Event/EventTest.php                                  (1 — moves with Domain)
tests/Unit/Application/Event/RegisterForEventTest.php                  (1 — Application/Event)
tests/Unit/Application/Backstage/{ArchiveEvent,CreateEvent,DeleteEventImage,
    ListEventRegistrations,ListEventsForAdmin,ListEventsStats,PublishEvent,
    UnregisterUserFromEvent,UpdateEvent,UploadEventImage}Test.php      (10 — Backstage)
tests/Integration/Application/EventsAdminIntegrationTest.php           (1)
tests/Integration/EventProposalStatsTest.php                           (1)
tests/Integration/EventStatsTest.php                                   (1)
tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php    (1)
tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php        (1)
tests/Isolation/EventProposalTenantIsolationTest.php                   (1)
tests/Isolation/EventsAdminTenantIsolationTest.php                     (1)
tests/Isolation/EventsI18nTenantIsolationTest.php                      (1)
tests/Isolation/EventsStatsTenantIsolationTest.php                     (1)
tests/Isolation/EventTenantIsolationTest.php                           (1)
tests/E2E/Backstage/EventAdminEndpointsTest.php                        (1)
tests/E2E/Backstage/EventsStatsEndpointTest.php                        (1)
tests/E2E/Backstage/EventUploadTest.php                                (1)
tests/E2E/EventProposalFlowE2ETest.php                                 (1)
tests/E2E/EventsLocaleE2ETest.php                                      (1)
tests/Support/Fake/InMemoryEventRepository.php                         (1 fake)
tests/Support/Fake/InMemoryEventProposalRepository.php                 (1 fake)
```
→ `modules/events/backend/tests/{Unit,Integration,Isolation,E2E}/...` (mirror folder shape) + `modules/events/backend/tests/Support/{InMemoryEventRepository,InMemoryEventProposalRepository}.php`

**Namespace rewrite:** `Daems\Tests\*` → `DaemsModule\Events\Tests\*` for all moved test files. All `use` statements importing `Daems\(Domain|Application|Infrastructure)\Event*` → `DaemsModule\Events\(Domain|Application|Infrastructure)\*`.

### Frontend (`daem-society/` → `modules/events/frontend/`)

#### Public pages (`daem-society/public/pages/events/`)

```
cta.php
data/                  (data files — inventory exact contents during plan)
detail/                (detail sub-includes — inventory exact contents during plan)
detail.php             (event detail view)
grid.php               (events grid component)
hero.php
index.php              (events listing page)
propose.php            (member event-proposal submit form)
```
→ `modules/events/frontend/public/{cta,detail,grid,hero,index,propose}.php` + sub-dirs.

**`__DIR__` chrome rewrite (Wave F pattern):** site-chrome includes (`top-nav.php`, `footer.php`, `pages/errors/404.php`) become `DAEMS_SITE_PUBLIC . '/...'`. Sibling includes (`hero.php`, `cta.php`, `grid.php` from same module dir) keep `__DIR__`. Sub-folder includes (`detail/<file>.php`) keep `__DIR__`.

#### Backstage events admin (`daem-society/public/pages/backstage/events/`)

```
event-modal.css
event-modal.js
events-stats.js
index.php
upload-widget.js
```
→ `modules/events/frontend/backstage/index.php` (PHP) + `modules/events/frontend/assets/backstage/{event-modal.css,event-modal.js,events-stats.js,upload-widget.js}` (assets).

#### Backstage event-proposals admin (`daem-society/public/pages/backstage/event-proposals/`)

```
index.php
proposal-modal.css
proposal-modal.js
```
→ `modules/events/frontend/backstage/event-proposals/index.php` (PHP) + `modules/events/frontend/assets/backstage/{proposal-modal.css,proposal-modal.js}` (assets).

**Asset URL rewrite (in moved PHP files):** `<script src="/pages/backstage/events/<X>"></script>` → `<script src="/modules/events/assets/backstage/<X>"></script>`. Same for `<link href=>`.

---

## 4. Module manifest

`modules/events/module.json`:

```json
{
  "name": "events",
  "version": "0.1.0",
  "description": "Events domain — public listing/detail/registration + admin CRUD + proposals workflow.",
  "namespace": "DaemsModule\\Events",
  "depends_on": [],
  "frontend": {
    "public_pages": "frontend/public",
    "backstage_pages": "frontend/backstage",
    "assets": "frontend/assets"
  }
}
```

`depends_on: []` — Events doesn't import other modules. The previous "Forum cross-domain stays in core" pattern doesn't apply (Events Domain is fully owned by the module).

---

## 5. Bindings + routes

### `backend/bindings.php` (production)

Binds **MODULE** repository interfaces (because Domain moved) to MODULE SQL implementations + 25–30 Application use cases + 2 controllers. Closure pattern matches Insights' bindings.php. Fixture:

```php
return static function (\Daems\Infrastructure\Framework\Container\Container $c): void {
    // Repositories — module owns the interfaces
    $c->singleton(\DaemsModule\Events\Domain\EventRepositoryInterface::class,
        static fn(Container $c) => new \DaemsModule\Events\Infrastructure\SqlEventRepository(
            $c->make(\Daems\Infrastructure\Framework\Database\Database::class),
        ),
    );
    $c->singleton(\DaemsModule\Events\Domain\EventProposalRepositoryInterface::class,
        static fn(Container $c) => new \DaemsModule\Events\Infrastructure\SqlEventProposalRepository(
            $c->make(\Daems\Infrastructure\Framework\Database\Database::class),
        ),
    );
    // Application use cases (~30) — full list during plan-phase enumeration
    $c->bind(\DaemsModule\Events\Application\GetEvent\GetEvent::class, ...);
    // ... etc
    // Controllers
    $c->bind(\DaemsModule\Events\Controller\EventController::class, ...);
    $c->bind(\DaemsModule\Events\Controller\EventBackstageController::class, ...);
};
```

### `backend/bindings.test.php` (KernelHarness)

Same shape but uses `InMemoryEventRepository` + `InMemoryEventProposalRepository` from module's tests/Support/ instead of the SQL repos.

### `backend/routes.php`

Returns a closure that, given Router + Container, registers all 22 Event-related routes (7 public + 12 backstage events + 3 backstage event-proposals).

#### Public (7 routes, all → `EventController`)

- `GET /api/v1/events` → `listEvents` / `listEventsForLocale`
- `GET /api/v1/events/{slug}` → `getEvent` / `getEventBySlugForLocale`
- `GET /api/v1/events-legacy` (legacy alias — verify during plan if still needed)
- `GET /api/v1/events-legacy/{slug}` (legacy alias)
- `POST /api/v1/event-proposals` → `submitEventProposal`
- `POST /api/v1/events/{slug}/register` → `registerForEvent`
- `POST /api/v1/events/{slug}/unregister` → `unregisterFromEvent`

(`events-legacy` aliases — confirm during plan if still wired in frontend; if not, drop from move and adjust public-route count.)

#### Backstage (15 routes, all → `EventBackstageController`)

- `GET /api/v1/backstage/events/stats` → `statsEvents`
- `GET /api/v1/backstage/events` → `listEvents`
- `POST /api/v1/backstage/events` → `createEvent`
- `POST /api/v1/backstage/events/{id}` → `updateEvent`
- `POST /api/v1/backstage/events/{id}/publish` → `publishEvent`
- `POST /api/v1/backstage/events/{id}/archive` → `archiveEvent`
- `GET /api/v1/backstage/events/{id}/registrations` → `listEventRegistrations`
- `POST /api/v1/backstage/events/{id}/registrations/{user_id}/remove` → `removeEventRegistration`
- `POST /api/v1/backstage/events/{id}/images` → `uploadEventImage`
- `POST /api/v1/backstage/events/{id}/images/delete` → `deleteEventImage`
- `GET /api/v1/backstage/events/{id}/translations` → `getEventWithTranslations`
- `POST /api/v1/backstage/events/{id}/translations/{locale}` → `updateEventTranslation`
- `GET /api/v1/backstage/event-proposals` → `listEventProposals`
- `POST /api/v1/backstage/event-proposals/{id}/approve` → `approveEventProposal`
- `POST /api/v1/backstage/event-proposals/{id}/reject` → `rejectEventProposal`

15 listed (some BackstageController methods may map to multiple routes or vice versa — final count nailed during plan-phase research).

---

## 6. Removals from core

### `daems-platform/`

After Wave E, all of these must be **deleted** (not stubbed):

- `src/Domain/Event/*` (7 files)
- `src/Application/Event/*` (8 dirs, ~21 files)
- `src/Application/Backstage/{ApproveEventProposal,ArchiveEvent,CreateEvent,DeleteEventImage,Events,GetEventWithAllTranslations,ListEventProposalsForAdmin,ListEventRegistrations,ListEventsForAdmin,PublishEvent,RejectEventProposal,UnregisterUserFromEvent,UpdateEvent,UpdateEventTranslation,UploadEventImage}/` (15 dirs, ~41 files)
- `src/Infrastructure/Adapter/Persistence/Sql/Sql{Event,EventProposal}Repository.php`
- `src/Infrastructure/Adapter/Api/Controller/EventController.php`
- 13 Event methods + their imports from `BackstageController.php`
- 2 Event image methods (`uploadEventImage`, `deleteEventImage`) + their imports from `MediaController.php`
- Event bindings from `bootstrap/app.php` (~25–30 lines + their `use` statements)
- Event routes from `routes/api.php` (22 entries)
- Event bindings from `tests/Support/KernelHarness.php` (mirror of bootstrap)
- Test files listed in §3 Tests inventory (~30 files)
- `tests/Support/Fake/InMemoryEvent{,Proposal}Repository.php` (2 fakes)
- 7 event migration files (event_001..007) — moved, originals deleted

### `daems-platform/` retained (NOT removed)

- 3 cross-domain migrations: `053_backfill_events_projects_i18n.sql`, `054_drop_translated_columns_from_events_projects.sql`, `059_fulltext_events_projects_i18n.sql` — they touch projects too.
- Any Event-cross-references in BackstageController surrounding methods (none expected based on grep).

### `daem-society/`

Wave F **after** front-controller route updates land:
- `public/pages/events/` (8 files + sub-dirs)
- `public/pages/backstage/events/` (5 files)
- `public/pages/backstage/event-proposals/` (3 files)

---

## 7. Namespace rewrite map

| Old (in `daems-platform/`) | New (in `modules/events/`) |
|---|---|
| `Daems\Domain\Event\*` | `DaemsModule\Events\Domain\*` |
| `Daems\Application\Event\*` | `DaemsModule\Events\Application\*` |
| `Daems\Application\Backstage\<EventThing>\*` | `DaemsModule\Events\Application\Backstage\<EventThing>\*` |
| `Daems\Infrastructure\Adapter\Persistence\Sql\Sql{Event,EventProposal}Repository` | `DaemsModule\Events\Infrastructure\Sql{Event,EventProposal}Repository` |
| `Daems\Infrastructure\Adapter\Api\Controller\EventController` | `DaemsModule\Events\Controller\EventController` |
| (NEW class — extracted) | `DaemsModule\Events\Controller\EventBackstageController` |
| `Daems\Tests\Unit\Domain\Event\*` | `DaemsModule\Events\Tests\Unit\Domain\*` |
| `Daems\Tests\Unit\Application\Event\*` | `DaemsModule\Events\Tests\Unit\Application\*` |
| `Daems\Tests\Unit\Application\Backstage\<EventThing>Test` | `DaemsModule\Events\Tests\Unit\Application\Backstage\<EventThing>Test` |
| `Daems\Tests\Integration\Application\EventsAdminIntegrationTest` | `DaemsModule\Events\Tests\Integration\Application\EventsAdminIntegrationTest` |
| `Daems\Tests\{Integration,Isolation,E2E}\<…Event…>` | `DaemsModule\Events\Tests\{Integration,Isolation,E2E}\<…>` |
| `Daems\Tests\Support\Fake\InMemoryEvent{,Proposal}Repository` | `DaemsModule\Events\Tests\Support\InMemoryEvent{,Proposal}Repository` |

**Cross-domain core consumers that import Event types: NONE** (verified by grep). No "core test imports module fakes" pattern needed for Events (unlike Forum's `ListPendingApplicationsForAdminTest` which had to import module fakes).

---

## 8. Frontend module-router integration

After Wave F, `/events`, `/events/{slug}`, `/events/propose`, `/backstage/events`, `/backstage/event-proposals` must work identically — but served from `modules/events/frontend/{public,backstage}/`.

### Module-router behaviour (already in `daem-society/public/index.php`)

- `^/<module>/<sub-path>` → `modules/<module>/frontend/public/<sub-path>.php` (or `<sub-path>/index.php`)
- `^/backstage/<module>/<sub-path>` → `modules/<module>/frontend/backstage/<sub-path>.php` (or `<sub-path>/index.php`)
- `^/modules/<module>/assets/<file>` → `modules/<module>/frontend/assets/<file>`

### Routes that need explicit front-controller updates (Wave F regression-prevention, lesson #10)

Today's `daem-society/public/index.php` hardcodes `__DIR__ . '/pages/events/...'` paths in **at least 4 places** — must update BEFORE delete:

| Today (line approx.) | Change to |
|---|---|
| `'/events' => __DIR__ . '/pages/events/index.php'` (line ~415, in `$routes`) | Move out of `$routes` array; explicit handler that requires `$daemsKnownModules['events']['public'] . '/index.php'` |
| `'/events/propose' === $uri` (line ~530) | Update require to `$daemsKnownModules['events']['public'] . '/propose.php'` |
| `preg_match('#^/events/([a-z0-9\-]+)$#', $uri, $m)` for detail (line ~536) | Keep regex (slug capture into `$m[1]`); update require to `$daemsKnownModules['events']['public'] . '/detail.php'` |
| `'/events' => __DIR__ . '/pages/backstage/events/index.php'` in admin map (line ~386) | Remove entry — `/backstage/events` handled by module page router (block before admin map) |
| `'/event-proposals' => __DIR__ . '/pages/backstage/event-proposals/index.php'` in admin map (line ~387) | Remove entry — `/backstage/event-proposals` handled by module page router |

Curl-smoke before delete-originals commit:

```bash
for u in /events /events/propose /events/some-published-slug \
         /backstage/events /backstage/event-proposals; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' http://daems.local$u) $u"
done
```

Expected: all non-500 (200 / 302 to login / 404 on non-existent slug). **Block Wave F final commit on this.**

### Path-rewrite checklist for moved frontend files

For each PHP file moved into `modules/events/frontend/`:

1. **`__DIR__` chrome includes** that traverse up out of the module dir (e.g. `__DIR__ . '/../../partials/top-nav.php'`) → rewrite to `DAEMS_SITE_PUBLIC . '/partials/top-nav.php'`.
2. **`__DIR__` siblings within the module** (e.g. `__DIR__ . '/hero.php'`, `__DIR__ . '/grid.php'` for index.php) → keep as-is; relative paths preserve when source + target both move together.
3. **Asset URLs** referenced in HTML → `/pages/backstage/events/<X>` → `/modules/events/assets/backstage/<X>`. Same for events-modal.css, event-modal.js, events-stats.js, upload-widget.js, proposal-modal.css, proposal-modal.js (6 files). Inventory exact list during plan-phase grep.
4. **Verify no latent path bugs** like Forum's `pages/404.php` (file didn't exist; correct path was `pages/errors/404.php`). Run plan-phase grep for `pages/404.php` and similar legacy chrome paths in moved files.

---

## 9. Bootstrap + harness wiring rule (CLAUDE.md "BOTH-wiring")

Every binding moved out of `bootstrap/app.php` MUST appear in module's `backend/bindings.php` (production) AND every binding moved out of `tests/Support/KernelHarness.php` MUST appear in module's `backend/bindings.test.php` (test). The plan's Task 13 + 14 verifications enumerate exact bindings via grep before-and-after.

---

## 10. Verification gates

**Wave-level gates** (between waves; soft-pass — fix issues before next wave):

| Wave | Gate |
|---|---|
| A | `composer dump-autoload` clean; `composer analyse` 0 errors; module-skeleton tests pass via stub closures |
| B | All moved files PHP-lint green; PHPStan 0 errors; module's Unit tests pass when run via `vendor/bin/phpunit --testsuite=Unit --filter=Module\\\\Events` |
| C | After Task 13 (production bindings), production-container smoke instantiates 2 module controllers via reflection (no TypeErrors) |
| D | All 30 moved test files green: Unit + Integration + Isolation + E2E |
| E | After EACH removal (T21–T25), production-container smoke STILL passes; `composer test:all` (run as 4 separate suites) STILL passes; PHPStan 0 errors |

**Final gate (Task 29-equivalent):** identical to Forum's. PHPStan 0 errors + 4 separate suites green + production smoke 2/2 + git grep cleanup verifications + curl smoke for all 5+ public/backstage URL families + user browser-smoke checklist.

---

## 11. Risks + mitigations

| Risk | Mitigation |
|---|---|
| Wave F regression (front-controller hardcoded requires) | Lesson #10 in §2; Wave F task ORDER explicit: routes-update FIRST, curl-smoke gate, THEN delete |
| `ApproveEventProposal` cross-aggregate (creates Event) breaks if Domain split | E1 prevents this — single module containing both |
| Migration 053/054/059 (cross-domain, projects+events) skipped twice | They stay in core through both Projects and Events extractions; no rename. Module migrations 053/054/059 are NOT created. |
| `events-legacy` route — orphaned legacy alias? | Plan-phase research task: confirm if `/api/v1/events-legacy` is still wired in frontend; if not, drop from move; if yes, move with the rest |
| `EventRegistration` cross-aggregate (Events table + users table FK) | Foreign-key columns stay; no schema change in this extraction |
| Image upload (UploadEventImage / DeleteEventImage) depends on `LocalImageStorage` (core service) | Module's bindings.php injects core's `Daems\Infrastructure\Storage\LocalImageStorage` instance into the use cases. Same pattern as Forum's auth services. |
| 15 backstage methods extraction (13 from BackstageController + 2 from MediaController per E7) | Same TDD-flow as Forum's B:T10 / B:T11; Reflection signature tests catch constructor-arg drift |
| `MediaController` cross-domain coupling — relocating Event methods leaves `uploadProjectImage` / `uploadInsightImage` as future TODOs | Acceptable for this scope; document as parallel work for Projects extraction (in flight) and any future Insights media-method relocations |
| Event entity has rich i18n via `EntityTranslationView` | TranslationMap stays in `Daems\Domain\Locale\*` (core). Module's SQL repo imports it. |

---

## 12. Repos affected (3 commit streams)

| Repo | Path | Branch | Commits |
|---|---|---|---|
| `daems-platform` | `C:\laragon\www\daems-platform\` | `dev` | spec + plan + Task 9.5 (autoload-dev/phpstan) + Wave E removals (~5 commits) + verification commits |
| `dp-events` | `C:\laragon\www\modules\events\` | `dev` (currently no commits — will be initialised by Wave A) | skeleton + Wave B/C/D moves + Wave F frontend (~15–20 commits) |
| `daem-society` | `C:\laragon\www\sites\daem-society\` | `dev` | front-controller routes update (1 commit) + delete originals (1 commit) |

**Total estimate: ~25 commits across 3 repos** (vs Forum's 30+).

---

## 13. Success criteria

1. `composer test:all` (4 separate suites) all green — Unit + Integration + Isolation + E2E. Daems-platform unit count drops by ~30 Event-touching tests (Forum dropped from 678 → 592). Module tests run via platform's PHPUnit + autoload-dev mapping (proven in Forum).
2. PHPStan level 9, 0 errors. `phpstan-baseline.neon` may grow if extraction surfaces new `list<>` propagation issues — fix or baseline per Forum-PHPStan-2.x precedent.
3. Production-container smoke instantiates `EventController` + `EventBackstageController` via Reflection — no TypeErrors.
4. Git grep verifies zero `Daems\Domain\Event\` / `Daems\Application\Event\` / `Daems\Application\Backstage\<EventThing>\` / `SqlEvent` / `EventController` references in `daems-platform/{src,tests,bootstrap,routes}/`.
5. Curl-smoke `/events`, `/events/propose`, `/events/<slug>`, `/backstage/events`, `/backstage/event-proposals` all return non-500.
6. User browser-smoke checklist passes (manual): create event, edit event, publish event, archive event, register/unregister, submit proposal, approve proposal, reject proposal, all 5 admin pages render with assets loading from `/modules/events/assets/`.
7. Disable mode (Q2-B): if module is removed from filesystem, `/events` returns 404 (page) and `/api/v1/events*` returns 404 (route not found) — graceful degradation.

---

## 14. Decisions made during brainstorming (record)

- **2026-04-27, Q1 (structure):** A — single `events` module containing Event + EventProposal + EventRegistration. ApproveEventProposal stays inside, no cross-module deps.
- **2026-04-27, Q2 (controller split):** A — single `EventBackstageController` (15 methods total: 13 BackstageController + 2 MediaController image methods relocated per E7). 15 still below 20-method threshold that triggered Forum's split.
- Other decisions (Domain placement, namespace, asset folder, SQL repo structure) follow established Insights/Forum/Projects pattern with Events-specific verifications recorded inline.

---

## 15. Plan task wave summary (preview — full plan in separate doc)

**Wave A — module skeleton** (3 tasks, ~3 commits)
- T1: `module.json` + README + `composer.json` (fresh repo) + `phpunit.xml.dist` + `.gitkeep`s
- T2: data-fix migration `0NN_rename_event_migrations_in_schema_migrations_table.sql` in core
- T3: stub `bindings.php` + `bindings.test.php` + `routes.php` (return-closure no-op)

**Wave B — backend moves** (8 tasks, ~9 commits)
- T4: move Domain (7 files + `Daems\Domain\Event` → `DaemsModule\Events\Domain`)
- T5: move SQL repos
- T6: move InMemory fakes + Domain unit tests
- T7: move Application/Event (~21 files)
- T8: move Application/Backstage event use cases (~41 files)
- T9: move EventController (public)
- T9.5: NEW infra commit on daems-platform (composer.json autoload-dev + phpstan.neon paths)
- T10: TDD-extract `EventBackstageController` from `BackstageController` (13 methods) + relocate `uploadEventImage`/`deleteEventImage` from `MediaController` (2 methods) → 15 total
- T11: move event migrations (event_001..007)

**Wave C — wire-up** (3 tasks, ~3 commits)
- T12: production `bindings.php` (full bindings, ~30 use cases + 2 controllers)
- T13: test `bindings.test.php` (InMemory fakes + same closure shape)
- T14: `routes.php` (22 routes, 7 public + 15 backstage)

**Wave D — test moves** (4 tasks, ~4 commits)
- T15: Unit/Application/Event tests
- T16: Unit/Application/Backstage event tests (10 tests)
- T17: Integration tests (5)
- T18: Isolation (5) + E2E (5) tests

**Wave E — core removals** (5 tasks, ~5 commits)
- T19: Remove Event bindings from `bootstrap/app.php`
- T20: Remove Event routes from `routes/api.php`
- T21: Remove 13 Event methods from `BackstageController.php` + 2 Event image methods from `MediaController.php` + update `routes/api.php` inline closures to reference module's controller
- T22: Remove Event bindings from `KernelHarness.php`
- T23: Delete legacy Event src/* + tests/Support/Fake/InMemoryEvent*Repository.php

**Wave F — frontend** (5 tasks, ~5 commits) — **REGRESSION-PREVENTION ORDER**
- T24: **FIRST** update `daem-society/public/index.php` front-controller routes (5 paths) to point at `$daemsKnownModules['events']` paths — single commit. Curl-smoke gate before T25.
- T25: Move 8 public pages → `modules/events/frontend/public/`. `__DIR__` chrome rewrite to `DAEMS_SITE_PUBLIC`. Commit dp-events.
- T26: Move 5 backstage events PHP + 3 backstage event-proposals PHP → `modules/events/frontend/backstage/`. Asset-URL rewrite. Commit dp-events.
- T27: Move 6 assets (CSS + JS) → `modules/events/frontend/assets/backstage/`. Curl-smoke 6× HTTP 200. Commit dp-events. Then delete originals from daem-society. Commit daem-society.
- T28: **Final verification gate**: PHPStan 0 errors + `composer test:all` 4 suites green + production smoke + git grep cleanup + curl-smoke 5+ URL families + user browser-smoke (manual).

**Total estimate: 28 tasks across 6 waves, ~25 commits, 3 repos.**
