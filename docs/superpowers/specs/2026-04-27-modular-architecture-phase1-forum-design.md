# Modular architecture — Phase 1 (Forum extraction) design spec

**Date:** 2026-04-27
**Status:** Approved (brainstorming complete, awaiting plan)
**Scope:** Extract the Forum domain from `daems-platform` core into a self-contained module `modules/forum/` (the `dp-forum` git repo), following the manifest convention proven by the Insights pilot. Zero user-visible change — Forum must work exactly as it does today after the move.

**Foundation already shipped (Insights pilot, branch `dev` HEADs `daems-platform@649806c`, `dp-insights@4792e73`):**
- `module.json` schema + `ModuleRegistry` boot-time loader
- Composer runtime autoloader extension for `DaemsModule\<Name>\` namespaces
- `daem-society/public/index.php` module-router block (handles `/<module>/...`, `/backstage/<module>/...`, `/modules/<module>/assets/...`)
- `MigrationTestCase` scans `../modules/*/backend/migrations/*.{sql,php}` (commit `649806c`)
- `KernelHarness` boots `ModuleRegistry` in test mode and loads each module's `bindings.test.php`
- PHPStan paths glob `paths: [src/, ../modules/*/backend/src/]`

**This spec does not redefine those — only Forum-specific extraction details.**

---

## 1. Decisions locked during brainstorming

Per the briefing, decisions Q1–Q3 (scope/disable/isolation) carry over from the foundation spec. Forum-specific decisions:

| # | Decision | Choice |
|---|---|---|
| F1 | Backstage namespace inside the module | **A** — keep `DaemsModule\Forum\Application\Backstage\*` (mirrors current `Daems\Application\Backstage\Forum\*` structure; smallest diff). |
| F2 | SQL repository class structure | **A** — keep four separate classes (`SqlForumRepository`, `SqlForumModerationAuditRepository`, `SqlForumReportRepository`, `SqlForumUserWarningRepository`). No mid-extraction refactor. |
| F3 | Frontend asset folder layout | **A** — flat `frontend/assets/backstage/` with `forum-`-prefixed filenames. URL pattern `/modules/forum/assets/backstage/<file>` matches Insights. |
| F4 | Backstage controller split | **B** — split into two controllers: `ForumModerationBackstageController` (11 methods, abuse-response actions) and `ForumContentBackstageController` (9 methods, structure + dashboard). |

### F4 — controller method split

| Controller | Methods | Reasoning |
|---|---|---|
| `ForumModerationBackstageController` (11) | `listForumReports`, `getForumReport`, `resolveForumReport`, `dismissForumReport`, `lockForumTopic`, `unlockForumTopic`, `deleteForumTopicAdmin`, `editForumPostAdmin`, `deleteForumPostAdmin`, `warnForumUser`, `listForumAudit` | All actions that respond to abuse / moderation flow |
| `ForumContentBackstageController` (9) | `listForumTopicsAdmin`, `pinForumTopic`, `unpinForumTopic`, `listForumPostsAdmin`, `listForumCategoriesAdmin`, `createForumCategoryAdmin`, `updateForumCategoryAdmin`, `deleteForumCategoryAdmin`, `statsForum` | Structure CRUD + dashboard / stats |

---

## 2. Inventory — what moves

Counts verified by `Glob` against `daems-platform` HEAD `649806c` on 2026-04-27.

### Backend (daems-platform → modules/forum/backend/)

| Layer | Source | Count | Destination |
|---|---|---|---|
| Domain | `src/Domain/Forum/*.php` | 18 | `modules/forum/backend/src/Domain/` |
| Application (public) | `src/Application/Forum/*` | 23 | `modules/forum/backend/src/Application/Forum/` |
| Application (admin) | `src/Application/Backstage/Forum/*` | 51 | `modules/forum/backend/src/Application/Backstage/Forum/` |
| SQL repos | `src/Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository.php` | 4 | `modules/forum/backend/src/Infrastructure/` |
| Public controller | `src/Infrastructure/Adapter/Api/Controller/ForumController.php` | 1 (171 LOC) | `modules/forum/backend/src/Controller/ForumController.php` |
| Backstage methods | `BackstageController.php` lines 680–1232 (20 methods, ~552 LOC) | extracted | Two new controllers in `modules/forum/backend/src/Controller/` |

### Migrations (9 files)

| Source | Destination |
|---|---|
| `database/migrations/007_create_forum_tables.sql` | `modules/forum/backend/migrations/forum_001_create_forum_tables.sql` |
| `013_add_user_id_to_forum.sql` | `forum_002_add_user_id_to_forum.sql` |
| `030_add_tenant_id_to_forum_tables.sql` | `forum_003_add_tenant_id_to_forum_tables.sql` |
| `047_add_locked_to_forum_topics.sql` | `forum_004_add_locked_to_forum_topics.sql` |
| `048_create_forum_reports_audit_warnings_and_edited_at.sql` | `forum_005_create_forum_reports_audit_warnings_and_edited_at.sql` |
| `049_extend_dismissals_enum_forum_report.sql` | `forum_006_extend_dismissals_enum_forum_report.sql` |
| `050_widen_app_id_for_compound_forum_report.sql` | `forum_007_widen_app_id_for_compound_forum_report.sql` |
| `061_search_text_forum_topics.sql` | `forum_008_search_text_forum_topics.sql` |
| `061_search_text_forum_topics_backfill.php` | `forum_009_search_text_forum_topics_backfill.php` |

A new core data-fix migration `database/migrations/065_rename_forum_migrations_in_schema_migrations_table.sql` updates existing dev DB rows in `schema_migrations` from old → new filenames. Idempotent (safe to re-run).

### Tests (37 total)

| Suite | Files |
|---|---|
| Unit (Domain) | `tests/Unit/Domain/Forum/{ForumPostTest,ForumTopicTest,ForumTopicLockedTest,ForumReportTest}.php` (4) |
| Unit (Application/Forum) | `tests/Unit/Application/Forum/{CreateForumTopicTest,CreateForumPostTest,ReportForumTargetTest}.php` (3) |
| Unit (Application/Backstage/Forum) | `tests/Unit/Application/Backstage/Forum/*.php` (24) |
| Integration | `tests/Integration/Persistence/SqlForumStatsTest.php`, `tests/Integration/Http/BackstageForumStatsTest.php` (2) |
| Isolation | `tests/Isolation/{ForumTenantIsolationTest,ForumStatsTenantIsolationTest}.php` (2) |
| E2E | `tests/E2E/F005_ForumRoleImpersonationTest.php` (1) |
| Support (InMemory fakes + seed) | `tests/Support/Fake/InMemoryForum{Repository,ModerationAuditRepository,ReportRepository,UserWarningRepository}.php` + `tests/Support/ForumSeed.php` (5) |

All move under `modules/forum/backend/tests/`. Namespace rewrite `Daems\Tests\* → DaemsModule\Forum\Tests\*` applies to every file.

### Frontend (daem-society → modules/forum/frontend/)

| Source | Destination |
|---|---|
| `daem-society/public/pages/forum/{index,categories,category,thread,new-topic,hero,cta,stats}.php` (8) | `modules/forum/frontend/public/` |
| `daem-society/public/pages/backstage/forum/index.php` | `modules/forum/frontend/backstage/index.php` |
| `daem-society/public/pages/backstage/forum/{reports,topics,categories,audit}/index.php` (4) | `modules/forum/frontend/backstage/{reports,topics,categories,audit}/index.php` |
| `daem-society/public/pages/backstage/forum/forum-kpi-strip.php` | `modules/forum/frontend/backstage/forum-kpi-strip.php` (PHP partial — stays in `backstage/`) |
| `daem-society/public/pages/backstage/forum/forum.css` | `modules/forum/frontend/assets/backstage/forum.css` |
| `daem-society/public/pages/backstage/forum/{forum-dashboard,forum-kpi-strip,forum-reports-page,forum-topics-page,forum-categories-page,forum-audit-page}.js` (6) | `modules/forum/frontend/assets/backstage/*.js` |
| `daem-society/public/pages/backstage/forum/{empty-state-topics,empty-state-reports}.svg` (2) | `modules/forum/frontend/assets/backstage/*.svg` |

`forum-kpi-strip.php` (PHP partial that emits an HTML fragment) stays in `frontend/backstage/`, not in `assets/`. Its includers in `index.php` reference it relatively.

---

## 3. Module manifest

`modules/forum/module.json`:

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

Validates against `daems-platform/docs/schemas/module-manifest.schema.json` (created in Insights pilot).

---

## 4. Bindings + routes

### `backend/bindings.php` (production)

Moves all Forum-specific bindings out of `daems-platform/bootstrap/app.php`. Container-resolves:

- 4× repositories: `ForumRepositoryInterface`, `ForumModerationAuditRepositoryInterface`, `ForumReportRepositoryInterface`, `ForumUserWarningRepositoryInterface` → corresponding `Sql*` impls. Each takes `Connection` from core.
- 1× shared service: `ForumIdentityDeriver`.
- 8× public use cases: `ListForumCategories`, `GetForumCategory`, `GetForumThread`, `CreateForumTopic`, `CreateForumPost`, `LikeForumPost`, `IncrementTopicView`, `ReportForumTarget`.
- 24× admin use cases: every class under `DaemsModule\Forum\Application\Backstage\Forum\*` directory. (The 51 files there are use cases plus their Input/Output companion value objects — bindings register only the use case classes themselves.)
- 3× controllers: `ForumController`, `ForumModerationBackstageController`, `ForumContentBackstageController`.

### `backend/bindings.test.php` (KernelHarness)

Identical structure. Repositories swap to InMemory fakes from `modules/forum/backend/tests/Support/`. Use cases + controllers reference the same classes — only the repository binding differs.

### `backend/routes.php`

26 routes total:

| Route count | Controller |
|---|---|
| 7 public reads + writes (`/api/v1/forum/*`) + 1 reports POST | `ForumController` |
| 11 backstage moderation (`/api/v1/backstage/forum/{reports,topics/.../{lock,unlock,delete},posts/.../{edit,delete},users/.../warn,audit}`) | `ForumModerationBackstageController` |
| 9 backstage content (`/api/v1/backstage/forum/{stats,topics,topics/.../{pin,unpin},posts,categories,categories/.../*}`) | `ForumContentBackstageController` |

Exact route inventory from `daems-platform/routes/api.php` lines 179–208 + 411–491 + 492–494, mapped to the controller split above.

---

## 5. Removals from core

### `daems-platform/`

- `bootstrap/app.php` — Forum bindings (~30+ lines) deleted
- `routes/api.php` lines 179–208 + 411–491 + 492–494 — Forum routes deleted
- `tests/Support/KernelHarness.php` — Forum bindings + InMemory fake registrations deleted
- `src/Application/Forum/`, `src/Application/Backstage/Forum/`, `src/Domain/Forum/` (directories)
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository.php` (4 files)
- `src/Infrastructure/Adapter/Api/Controller/ForumController.php`
- Forum methods in `BackstageController.php` lines 680–1232 (20 methods)
- `tests/Unit/{Domain,Application,Application/Backstage}/Forum/` (directories)
- `tests/Integration/Persistence/SqlForumStatsTest.php`
- `tests/Integration/Http/BackstageForumStatsTest.php`
- `tests/Isolation/Forum*Test.php`
- `tests/E2E/F005_ForumRoleImpersonationTest.php`
- `tests/Support/{ForumSeed.php,Fake/InMemoryForum*.php}` (5 files)
- 9 migration files in `database/migrations/` matching the table above

### `daem-society/`

- `public/pages/forum/` (entire directory)
- `public/pages/backstage/forum/` (entire directory)

---

## 6. Namespace rewrite map

Applied to every moved file via mechanical search + replace:

```
Daems\Domain\Forum\*                              → DaemsModule\Forum\Domain\*
Daems\Application\Forum\*                         → DaemsModule\Forum\Application\Forum\*
Daems\Application\Backstage\Forum\*               → DaemsModule\Forum\Application\Backstage\Forum\*
Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository                → DaemsModule\Forum\Infrastructure\SqlForumRepository
Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumModerationAuditRepository → DaemsModule\Forum\Infrastructure\SqlForumModerationAuditRepository
Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository          → DaemsModule\Forum\Infrastructure\SqlForumReportRepository
Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumUserWarningRepository     → DaemsModule\Forum\Infrastructure\SqlForumUserWarningRepository
Daems\Tests\Support\Fake\InMemoryForum*Repository → DaemsModule\Forum\Tests\Support\InMemoryForum*Repository
Daems\Tests\Support\ForumSeed                     → DaemsModule\Forum\Tests\Support\ForumSeed
Daems\Tests\Unit\Domain\Forum                     → DaemsModule\Forum\Tests\Unit\Domain
Daems\Tests\Unit\Application\Forum                → DaemsModule\Forum\Tests\Unit\Application\Forum
Daems\Tests\Unit\Application\Backstage\Forum      → DaemsModule\Forum\Tests\Unit\Application\Backstage\Forum
Daems\Tests\Integration\Persistence\SqlForumStatsTest    → DaemsModule\Forum\Tests\Integration\Persistence\SqlForumStatsTest
Daems\Tests\Integration\Http\BackstageForumStatsTest     → DaemsModule\Forum\Tests\Integration\Http\BackstageForumStatsTest
Daems\Tests\Isolation\Forum*Test                  → DaemsModule\Forum\Tests\Isolation\Forum*Test
Daems\Tests\E2E\F005_ForumRoleImpersonationTest   → DaemsModule\Forum\Tests\E2E\F005_ForumRoleImpersonationTest
```

After rewrite, `git grep "Daems\\\\\\(Domain\\|Application\\|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlForum\\)\\\\Forum"` in `daems-platform/` must return zero results.

---

## 7. Frontend module-router integration

No changes needed in `daem-society/public/index.php` — the module-router block from the Insights pilot already handles arbitrary module names. `daem-society/public/pages/_modules.php` discovers the new `module.json` automatically on next request.

URL → file mapping for Forum:

| URL | Resolves to |
|---|---|
| `/forum` (any path under) | `modules/forum/frontend/public/<path>` |
| `/backstage/forum` (any path under) | `modules/forum/frontend/backstage/<path>` |
| `/modules/forum/assets/backstage/<file>` | `modules/forum/frontend/assets/backstage/<file>` |

### Path-rewrite checklist for moved frontend files

Per Insights lesson #2: every moved file must convert `__DIR__`-relative includes → `DAEMS_SITE_PUBLIC`-rooted absolute paths. Targets:

- `top-nav.php`, `footer.php`, `layout.php`, `empty-state.php` (chrome includes)
- `ApiClient.php` includes
- `errors/404.php`, `errors/500.php` includes (if any)

Per Insights lesson #5: every moved file's `<script src=...>` and `<link href=...>` referencing JS/CSS now-in-`assets/` must update from `/pages/backstage/forum/<file>` → `/modules/forum/assets/backstage/<file>`.

Plan task uses `git grep -n "__DIR__\\|/pages/backstage/forum/\\|/pages/forum/"` over moved files to enumerate every edit point.

---

## 8. Bootstrap + harness wiring rule (CLAUDE.md "BOTH-wiring")

Forum-specific bindings must be removed from BOTH:
- `daems-platform/bootstrap/app.php` (production container)
- `daems-platform/tests/Support/KernelHarness.php` (test container)

Module's `bindings.php` and `bindings.test.php` replace both. Insights-lesson #3 says: extracted controllers MUST be instantiated against the production container before "done"-claim — KernelHarness's InMemory fakes hide constructor parameter-order bugs.

**Plan-task verification:** new-controller smoke task instantiates `ForumModerationBackstageController` and `ForumContentBackstageController` directly via `$container->make(...)` from a one-off CLI script using `bootstrap/app.php`'s real container. Catches `TypeError` from constructor-arg mismatch before commit.

---

## 9. Verification gates

Before final commit-stream "valmis"-claim, all must pass:

1. `composer analyse` → 0 errors at PHPStan level 9, in both `daems-platform/` and against `modules/forum/backend/src/`
2. `composer test` (Unit + Integration) → all green
3. `composer test:e2e` → all green (incl. moved `F005_ForumRoleImpersonationTest`)
4. `composer test:all` → all green
5. **Browser smoke-test (mandatory, Insights lesson #6):**
   - `GET /forum`, `/forum?slug=…` (category), `/forum/thread?slug=…` (thread) — all render
   - `POST /api/v1/forum/topics/{slug}/posts` — creates post, response renders in UI
   - `POST /api/v1/forum/posts/{id}/like` — likes, count updates
   - `GET /backstage/forum`, `/backstage/forum/{reports,topics,categories,audit}` — all render dashboards
   - JS console: zero `payload is undefined` / undefined-property errors
   - Network: every `/modules/forum/assets/backstage/*.{js,css,svg}` returns HTTP 200, no 404s
   - Theme: backstage parity with other admin pages (no theme regressions, per CLAUDE.md backlog item I13)

---

## 10. Risks + mitigations

| Risk | Mitigation |
|---|---|
| Namespace rewrite (~150+ use-statement updates) misses a site → fatal at first request | After rewrite, run PHPStan level 9 + all 3 test suites. Plan task runs `git grep "Daems\\\\\\(Domain\\|Application\\\\Forum\\|Application\\\\Backstage\\\\Forum\\|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlForum\\)"` over `daems-platform/` and asserts zero hits before delete-step. |
| `BackstageController` extraction misses one of 20 methods → 404 in admin UI | Diff lines 680–1232 of original `BackstageController.php` against the union of the two new controllers method-by-method; checklist enumerates all 20 method names. Smoke-test exercises every backstage admin sub-route. |
| Controller constructor-arg-order bug (Insights lesson #3) | Mandatory plan task: instantiate both new controllers via production container in a one-off CLI script before commit. KernelHarness alone is insufficient. |
| Migration rename causes runner to re-run already-applied migrations on dev DBs | One-shot core data-fix migration `065_rename_forum_migrations_in_schema_migrations_table.sql` updates `schema_migrations` rows from old filename → new filename. Idempotent. Tested against snapshot of dev DB. |
| Frontend `__DIR__` includes break inside module folder | Grep + edit pass over every moved file. CI smoke-test renders every page. |
| InMemory fakes left in `daems-platform/tests/Support/Fake/` after move → KernelHarness can't find them | Delete-step verifies `git grep "InMemoryForum"` returns only `modules/forum/...` results. |
| `forum-kpi-strip.php` partial path breaks after move | Grep `forum-kpi-strip.php` includers + verify they reference the new path inside `frontend/backstage/`. |
| `ForumIdentityDeriver` violates strict-A-isolation by referencing a non-core domain | Grep its imports — must reference only `Daems\Domain\{Tenant,User,Auth}`, `Daems\Domain\Membership` (for role lookups), `Daems\Infrastructure\Framework\*`. Document allowed imports in plan; fail check otherwise. |
| Migration sequence: forum's old `030_add_tenant_id_to_forum_tables.sql` ran between core migrations 029 and 031 — moving it to `forum_003` changes that ordering | Migration runner runs core + module migration paths as separate ordered streams. No core migration references `forum_*` tables. Verified by grep `forum_categories\\|forum_topics\\|forum_posts` over `database/migrations/*.sql`; if any cross-reference exists, plan must address it. |
| `dp-forum` repo creation timing — `git init` before module's `module.json` is committed leaves an empty-state push | Plan creates the repo skeleton in Task 1 (manifest + README + .gitignore) and commits before any code moves. |

---

## 11. Repos affected (3 commit streams)

| Repo | Branch | Stream |
|---|---|---|
| `daems-platform` | `dev` | Removals (Forum bindings, Forum routes, Forum source, Forum tests, Forum migrations) + new data-fix migration `065_*` + `KernelHarness` cleanup + final delete commits |
| `dp-forum` (new — `Turtuure/dp-forum`) | `dev` | Skeleton commit (manifest, README, .gitignore) + content-move commits (backend, frontend, tests, migrations) + verification commits |
| `daem-society` | `dev` | Frontend deletes (`public/pages/forum/`, `public/pages/backstage/forum/`) |

**Repo creation:** `cd C:\laragon\www\modules && mkdir forum && cd forum && git init -b dev && git remote add origin https://github.com/Turtuure/dp-forum.git`. GitHub repo created public, mirroring `dp-insights` settings.

**Push cadence:** dp-forum accumulates commits during plan execution. No auto-push — wait for explicit "pushaa" from user after final smoke-test passes.

---

## 12. Success criteria

- `composer analyse` → 0 errors PHPStan level 9
- `composer test:all` green in `daems-platform/`
- `daems-platform/src/{Domain,Application/Forum,Application/Backstage/Forum,Infrastructure/Adapter/Persistence/Sql/SqlForum*Repository,Infrastructure/Adapter/Api/Controller/ForumController}.php` no longer exist
- `BackstageController.php` contains zero `Forum`-named methods
- `daem-society/public/pages/{forum,backstage/forum}/` no longer exist
- `bootstrap/app.php` has no Forum-specific bindings
- `routes/api.php` has no Forum routes
- `modules/forum/` is fully populated as `dp-forum`'s `dev` branch
- All 5 browser smoke-test items pass
- Theme parity with other backstage pages confirmed

---

## 13. Decisions made during brainstorming (record)

- **Q1 (scope):** carries over from foundation spec — C, full vision phased out.
- **Q2 (disable mode):** carries over — B, no runtime gate yet for Forum.
- **Q3 (isolation):** carries over — A, strict (Forum may import only `Daems\Domain\{Tenant,User,Auth,Membership}` + `Daems\Infrastructure\Framework\*`).
- **F1 (admin namespace):** A — `DaemsModule\Forum\Application\Backstage\Forum\*`.
- **F2 (SQL repo class structure):** A — keep 4 separate.
- **F3 (asset folder):** A — flat `frontend/assets/backstage/`, `forum-`-prefixed filenames.
- **F4 (controller split):** B — `ForumModerationBackstageController` (11) + `ForumContentBackstageController` (9).
