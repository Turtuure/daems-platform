# Modular architecture — Phase 1 (Projects extraction) design spec

**Date:** 2026-04-27
**Status:** Approved (brainstorming complete, awaiting plan)
**Scope:** Extract the Projects domain from `daems-platform` core into a self-contained module `modules/projects/` (the `dp-projects` git repo), following the manifest convention proven by the Insights pilot and the Forum extraction (completed 2026-04-27, 943/943 tests green). Zero user-visible change — Projects must work exactly as it does today after the move.

**Foundation already shipped (Insights pilot + Forum extraction, branch `dev`):**
- `module.json` schema + `ModuleRegistry` boot-time loader
- Composer runtime autoloader extension for `DaemsModule\<Name>\` namespaces
- `daem-society/public/index.php` module-router block (`/<module>/...`, `/backstage/<module>/...`, `/modules/<module>/assets/...`)
- `MigrationTestCase` scans `../modules/*/backend/migrations/*.{sql,php}`
- `KernelHarness` boots `ModuleRegistry` in test mode and loads each module's `bindings.test.php`
- PHPStan paths glob `paths: [src/, ../modules/*/backend/src/]`
- **Forum lessons baked in (see "Forum lessons applied" below)** — 9 hard-won corrections that shape this plan from day 1

**This spec does not redefine those — only Projects-specific extraction details.**

**Repo state precondition:** `C:\laragon\www\modules\projects\.git\` is already initialised (HEAD → `refs/heads/dev`, `origin = https://github.com/Turtuure/dp-projects.git`). Working copy is empty. Skeleton task starts from this state.

---

## 1. Decisions locked during brainstorming

Per the briefing, decisions Q1–Q3 (scope/disable/isolation) carry over from the foundation spec. Forum F1–F3 carry as Projects-specific F1–F3. F4 resolved by inventory.

| # | Decision | Choice |
|---|---|---|
| Q1 | Scope | **C** — phased vision (carries from foundation) |
| Q2 | Disable mode | **B** — UI hide + 403, jobs continue (carries) |
| Q3 | Isolation | **A** — strict (modules ref core only) (carries) |
| F1 | Backstage namespace inside the module | **A** — `DaemsModule\Projects\Application\Backstage\<UseCase>\*` (mirrors current flat `Daems\Application\Backstage\<UseCase>\*` structure — 12 sibling dirs, no `Backstage\Projects\*` umbrella). Smallest diff. |
| F2 | SQL repository class structure | **A** — keep three separate classes (`SqlProjectRepository`, `SqlProjectCommentModerationAuditRepository`, `SqlProjectProposalRepository`). No mid-extraction refactor. |
| F3 | Frontend asset folder layout | **A** — flat `frontend/assets/backstage/` with `projects-`-prefixed filenames (or kept original where already prefixed). URL pattern `/modules/projects/assets/backstage/<file>` matches Insights + Forum. |
| F4 | Backstage controller split | **A** — single `ProjectsBackstageController` (10 methods). Below the 15-method threshold; no split needed. |

### F4 — controller method inventory (10 methods, all in single controller)

| # | Method | Source line in core BackstageController.php | Function |
|---|---|---|---|
| 1 | `listProjectsAdmin` | 416 | List all projects for admin |
| 2 | `createProjectAdmin` | 434 | Create project as admin |
| 3 | `updateProjectAdmin` | 458 | Update project chrome |
| 4 | `changeProjectStatus` | 486 | Change status (draft/published/archived) |
| 5 | `setProjectFeatured` | 506 | Toggle featured flag |
| 6 | `listProjectComments` | 574 | List comments for moderation |
| 7 | `deleteProjectComment` | 590 | Delete a comment as admin |
| 8 | `statsProjects` | 656 | Dashboard stats |
| 9 | `getProjectWithTranslations` | 767 | Read project with all translations + coverage |
| 10 | `updateProjectTranslation` | 786 | Save per-locale translation |

Note: Proposal-handling routes (`/api/v1/backstage/proposals/*`) call `ApproveProjectProposal` / `RejectProjectProposal` / `ListProposalsForAdmin` use cases. `ListProposalsForAdmin` is a **cross-domain** core use case (it lists proposals for both Projects AND Events) — it stays in core. The `approve` / `reject` proposal routes need a Projects-side controller method; we add **two new methods** to `ProjectsBackstageController` (`approveProjectProposal`, `rejectProjectProposal`) extracted from the existing core BackstageController equivalents (currently lines ~525–565 in BackstageController). Final method count: **12** in `ProjectsBackstageController`. Still well below 15-threshold; F4=A holds.

Updated F4 method list for plan:

| # | Method | Source |
|---|---|---|
| 1–10 | (above) | BackstageController.php lines 416–786 |
| 11 | `approveProjectProposal` | new in module, calls `ApproveProjectProposal` use case |
| 12 | `rejectProjectProposal` | new in module, calls `RejectProjectProposal` use case |

The `listProposalsForAdmin` route + use case **STAY in core** because `ListProposalsForAdmin` aggregates Project + Event proposals.

---

## 2. Forum lessons applied (baked into this spec from day 1)

Forum extraction (commits `001104d…0b9fb98` in daems-platform; `bcda770…549237f` in dp-forum; `46ff137…4742dd3` in daem-society) surfaced 9 corrections. All apply identically to Projects:

1. **Domain stays in core.** `src/Domain/Project/` (14 files) does NOT move. Three core consumers reference Project domain types: `ListPendingApplicationsForAdmin`, `ListProposalsForAdmin`, `Notifications/ListNotificationsStats`. Module's `bindings.php` binds **CORE** repository interfaces (`Daems\Domain\Project\ProjectRepositoryInterface` etc.) to **MODULE** SQL implementations (`DaemsModule\Projects\Infrastructure\SqlProjectRepository` etc.). Plan tasks "Move Domain layer" + "Move Domain unit tests" DROP from this plan.
2. **Module skeleton needs `bindings.php` + `bindings.test.php` + `routes.php` STUBS day-1** (return-closure no-op). `ModuleRegistry` reads all three the moment it sees `module.json`; missing files crash with all 172+ tests erroring on harness boot.
3. **Task 9.5 (NEW infra commit on daems-platform):** add `DaemsModule\Projects\` + `DaemsModule\Projects\Tests\` to `composer.json` `autoload-dev`, add `../modules/projects/backend/src` to `phpstan.neon` `paths`, run `composer dump-autoload`. Single commit on daems-platform `dev`. Without this, PHPStan + autoloader can't see the module.
4. **Module migration ALTERs on core tables need conditional guards.** Pattern (proven in Forum's `forum_006_extend_dismissals_enum_forum_report.sql`):
   ```sql
   SET @t := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'admin_application_dismissals');
   SET @sql := IF(@t > 0, 'ALTER TABLE admin_application_dismissals ...', 'DO 0');
   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
   ```
   Same guard for the `066_*` schema_migrations rename data-fix.
5. **Migration numbering (data-fix):** new core migration `database/migrations/066_rename_project_migrations_in_schema_migrations_table.sql` — idempotent, conditional, renames 10 existing `*project*` rows in `schema_migrations` table from old → new filenames so dev DBs don't re-run them.
6. **TDD-controller-extraction (Task 10):** use Reflection-based signature tests as Forum's Task 10/11 did. Verify constructor parameter list, method names, parameter types, return types BEFORE extracting body. Catches constructor-arg-mismatch bugs.
7. **Production-container smoke** mandatory after Task 13 + every Wave E task (T21–T25). Pattern:
   ```php
   $kernel = require 'bootstrap/app.php';
   $ref = new ReflectionProperty($kernel, 'container');
   $ref->setAccessible(true);
   $container = $ref->getValue($kernel);
   $container->make(ProjectsBackstageController::class); // ← must succeed
   ```
   Smoke script NOT committed.
8. **Cross-domain core tests** that use `Daems\Tests\Support\Fake\InMemoryProject*` will need to import `DaemsModule\Projects\Tests\Support\InMemoryProject*` once Wave E moves the fakes. Targets: `tests/Support/KernelHarness.php`, `tests/Unit/Application/Backstage/{ListPendingApplicationsForAdminTest,ListProposalsForAdminTest,ListNotificationsStatsTest}.php`. The cross-module-import pattern is correct strict-A behaviour (Forum commit 6fbeeb7 legitimised it). Do not try to delete these import lines.
9. **Migration-tests** (`tests/Integration/Migration/Migration0NN_Test.php`) that test moved migrations are dangling after extract. Targets to delete in Task 25: `Migration027Test.php` (add tenant_id), `Migration031Test.php` (add tenant_id to extras), `Migration044Test.php` (featured), `Migration046Test.php` (proposal decision metadata). NOT deleted: tests for migrations 052/053/054/055/059 — those touch shared/i18n scope.

---

## 3. Inventory — what moves

Counts verified by `Glob` + `find` against `daems-platform` HEAD `0b9fb98` on 2026-04-27.

### Backend (daems-platform → modules/projects/backend/)

| Layer | Source | Count | Destination |
|---|---|---|---|
| Domain | `src/Domain/Project/*.php` | 14 | **STAYS in core** (Forum lesson 1) |
| Application (public) | `src/Application/Project/*` (13 use case dirs) | 38 files | `modules/projects/backend/src/Application/Project/` |
| Application (admin) | 12 sibling dirs under `src/Application/Backstage/` | **29 files** | `modules/projects/backend/src/Application/Backstage/` |
| SQL repos | `src/Infrastructure/Adapter/Persistence/Sql/SqlProject*Repository.php` | 3 | `modules/projects/backend/src/Infrastructure/` |
| Public controller | `src/Infrastructure/Adapter/Api/Controller/ProjectController.php` | 1 (~270 LOC) | `modules/projects/backend/src/Controller/ProjectController.php` |
| Backstage methods | `BackstageController.php` lines 416–786 (10 methods) + `approveProjectProposal` + `rejectProjectProposal` extracted from same file | extracted | New `ProjectsBackstageController` in `modules/projects/backend/src/Controller/` |

### Application/Project public use cases (13 dirs, 38 files)

```
AddProjectComment (3) | AddProjectUpdate (3) | ArchiveProject (3) | CreateProject (3) |
GetProject (3) | GetProjectBySlugForLocale (3) | JoinProject (3) | LeaveProject (3) |
LikeProjectComment (2) | ListProjects (3) | ListProjectsForLocale (3) |
SubmitProjectProposal (3) | UpdateProject (3)
```

### Application/Backstage admin use cases (12 sibling dirs, 29 files — flat structure preserved)

```
AdminUpdateProject (3) | ApproveProjectProposal (3) | ChangeProjectStatus (2) |
CreateProjectAsAdmin (3) | DeleteProjectCommentAsAdmin (2) |
GetProjectWithAllTranslations (3) | ListProjectCommentsForAdmin (3) |
ListProjectsForAdmin (3) | Projects/ListProjectsStats (3) |
RejectProjectProposal (2) | SetProjectFeatured (2) | UpdateProjectTranslation (3)
```

The `Backstage/Projects/ListProjectsStats/` nested directory is the only outlier — it follows the recent stats-pattern. Move as-is.

### SQL repositories (3 files)

```
SqlProjectRepository.php
SqlProjectCommentModerationAuditRepository.php
SqlProjectProposalRepository.php
```

All three constructor-take `Connection` from core. No other dependencies.

### Migrations — split decision

**13 project-touching migrations exist in `database/migrations/`. Three of them (053, 054, 059) touch BOTH `projects` AND `events` tables** as part of the 2026-04-23 i18n A11 cleanup (CLAUDE.md notes this). Splitting them mid-Project-extraction would couple this PR to a future Events extraction. Decision:

| Old name | Move to module? | New name |
|---|---|---|
| `003_create_projects_table.sql` | ✅ move | `project_001_create_projects_table.sql` |
| `009_create_project_extras.sql` | ✅ move | `project_002_create_project_extras.sql` |
| `010_create_project_proposals.sql` | ✅ move | `project_003_create_project_proposals.sql` |
| `016_add_owner_id_to_projects.sql` | ✅ move | `project_004_add_owner_id_to_projects.sql` |
| `027_add_tenant_id_to_projects.sql` | ✅ move | `project_005_add_tenant_id_to_projects.sql` |
| `031_add_tenant_id_to_project_extras.sql` | ✅ move | `project_006_add_tenant_id_to_project_extras.sql` |
| `044_add_featured_to_projects.sql` | ✅ move | `project_007_add_featured_to_projects.sql` |
| `046_add_decision_metadata_to_project_proposals.sql` | ✅ move | `project_008_add_decision_metadata_to_project_proposals.sql` |
| `052_create_projects_i18n.sql` | ✅ move | `project_009_create_projects_i18n.sql` |
| `055_add_source_locale_to_project_proposals.sql` | ✅ move | `project_010_add_source_locale_to_project_proposals.sql` |
| `053_backfill_events_projects_i18n.sql` | ❌ stays | (mixed events+projects backfill) |
| `054_drop_translated_columns_from_events_projects.sql` | ❌ stays | (mixed events+projects DROP COLUMN) |
| `059_fulltext_events_projects_i18n.sql` | ❌ stays | (mixed events+projects FULLTEXT) |

**10 migrations move to `modules/projects/backend/migrations/` as `project_001..010`.** New core data-fix migration `066_rename_project_migrations_in_schema_migrations_table.sql` updates `schema_migrations` rows (idempotent + conditional). Migrations 053/054/059 stay in core; when Events module is extracted in a future phase, those can be re-evaluated.

None of the 10 moving migrations ALTER a non-project core table, so no per-migration conditional guard is needed inside their bodies.

### Tests (37 total)

| Suite | Files |
|---|---|
| Unit (Application/Project) | `tests/Unit/Application/Project/{CreateProjectTest,UpdateProjectTest}.php` (2) |
| Integration | `tests/Integration/Application/{ProjectCommentModerationIntegrationTest,ProjectsAdminIntegrationTest}.php`, `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`, `tests/Integration/{ProjectProposalStatsTest,ProjectStatsTest}.php` (5) |
| Isolation | `tests/Isolation/{ProjectTenantIsolationTest,ProjectsAdminTenantIsolationTest,ProjectsI18nTenantIsolationTest,ProjectsStatsTenantIsolationTest}.php` (4) |
| E2E | `tests/E2E/{F004_UnauthProjectMutationTest,ProjectsLocaleE2ETest}.php` (2) |
| Support (InMemory fakes — no ProjectSeed) | `tests/Support/Fake/InMemoryProject{Repository,CommentModerationAuditRepository,ProposalRepository}.php` (3) |

All move under `modules/projects/backend/tests/`. Namespace rewrite `Daems\Tests\* → DaemsModule\Projects\Tests\*` applies.

**Cross-domain core tests** that REFERENCE Project types but are NOT Project tests stay in core, with import updates only:
- `tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php`
- `tests/Unit/Application/Backstage/ListProposalsForAdminTest.php`
- `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php`
- `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php`
- `tests/E2E/F007_IdentitySpoofingTest.php`
- `tests/Support/KernelHarness.php`

These get their imports updated in Wave E (Task 24/25).

**Migration tests to delete in Task 25** (per Forum lesson 9):
- `tests/Integration/Migration/Migration027Test.php` (tested 027_add_tenant_id_to_projects)
- `tests/Integration/Migration/Migration031Test.php` (tested 031_add_tenant_id_to_project_extras)
- `tests/Integration/Migration/Migration044Test.php` (tested 044_add_featured_to_projects)
- `tests/Integration/Migration/Migration046Test.php` (tested 046_add_decision_metadata_to_project_proposals)

### Frontend (daem-society → modules/projects/frontend/)

| Source | Count | Destination |
|---|---|---|
| `daem-society/public/pages/projects/{cta,detail,edit,grid,hero,index,new}.php` (7) | 7 PHP | `modules/projects/frontend/public/` |
| `daem-society/public/pages/projects/detail/{content,hero}.php` (2) | 2 PHP | `modules/projects/frontend/public/detail/` |
| `daem-society/public/pages/backstage/projects/index.php` | 1 PHP | `modules/projects/frontend/backstage/index.php` |
| `daem-society/public/pages/backstage/projects/project-modal.css` | 1 CSS | `modules/projects/frontend/assets/backstage/project-modal.css` |
| `daem-society/public/pages/backstage/projects/{project-modal,projects-stats}.js` | 2 JS | `modules/projects/frontend/assets/backstage/*.js` |

**Total frontend:** 9 public PHP + 1 backstage PHP + 3 assets = 13 files. (Forum had 14.)

No SVGs or empty-state assets in current Projects backstage. If subsequent UI work adds them, they go to `frontend/assets/backstage/`.

---

## 4. Module manifest

`modules/projects/module.json`:

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

Validates against `daems-platform/docs/schemas/module-manifest.schema.json`.

---

## 5. Bindings + routes

### `backend/bindings.php` (production)

Moves all Projects-specific bindings out of `daems-platform/bootstrap/app.php`. Container-resolves:

- **3× repositories**: `Daems\Domain\Project\ProjectRepositoryInterface`, `ProjectProposalRepositoryInterface`, `ProjectCommentModerationAuditRepositoryInterface` → corresponding `DaemsModule\Projects\Infrastructure\Sql*` impls. Each takes `Connection` from core.
- **13× public use cases**: every class under `DaemsModule\Projects\Application\Project\*`.
- **12× admin use cases**: every class under the 12 admin dirs in `DaemsModule\Projects\Application\Backstage\*`.
- **2× controllers**: `ProjectController`, `ProjectsBackstageController`.

### `backend/bindings.test.php` (KernelHarness)

Identical structure. Repositories swap to InMemory fakes from `modules/projects/backend/tests/Support/`. Use cases + controllers reference the same classes — only the repository binding differs.

### `backend/routes.php`

**25 routes total** (verified line-by-line against `routes/api.php`):

#### Public (13 routes, all → `ProjectController`)

| # | Method + URL | core line | Controller method |
|---|---|---|---|
| 1 | `GET /api/v1/projects` (locale-aware) | 63 | `indexLocalized` |
| 2 | `GET /api/v1/projects/{slug}` (locale-aware) | 67 | `showLocalized` |
| 3 | `GET /api/v1/projects-legacy` | 72 | `index` |
| 4 | `GET /api/v1/projects-legacy/{slug}` | 76 | `show` |
| 5 | `POST /api/v1/projects` | 81 | `create` |
| 6 | `POST /api/v1/projects/{slug}` | 85 | `update` |
| 7 | `POST /api/v1/projects/{slug}/archive` | 89 | `archive` |
| 8 | `POST /api/v1/projects/{slug}/join` | 93 | `join` |
| 9 | `POST /api/v1/projects/{slug}/leave` | 97 | `leave` |
| 10 | `POST /api/v1/projects/{slug}/comments` | 101 | `addComment` |
| 11 | `POST /api/v1/project-comments/{id}/like` ⚠ | 105 | `likeComment` |
| 12 | `POST /api/v1/projects/{slug}/updates` | 109 | `addUpdate` |
| 13 | `POST /api/v1/project-proposals` ⚠ | 113 | `propose` |

#### Backstage (12 routes, all → `ProjectsBackstageController`)

| # | Method + URL | core line | Controller method |
|---|---|---|---|
| 14 | `GET /api/v1/backstage/projects/stats` | 263 | `statsProjects` |
| 15 | `GET /api/v1/backstage/projects` | 267 | `listProjectsAdmin` |
| 16 | `POST /api/v1/backstage/projects` | 271 | `createProjectAdmin` |
| 17 | `POST /api/v1/backstage/projects/{id}` | 275 | `updateProjectAdmin` |
| 18 | `POST /api/v1/backstage/projects/{id}/status` | 279 | `changeProjectStatus` |
| 19 | `POST /api/v1/backstage/projects/{id}/featured` | 283 | `setProjectFeatured` |
| 20 | `POST /api/v1/backstage/proposals/{id}/approve` ⚠ | 291 | `approveProjectProposal` |
| 21 | `POST /api/v1/backstage/proposals/{id}/reject` ⚠ | 295 | `rejectProjectProposal` |
| 22 | `GET /api/v1/backstage/projects/{id}/translations` | 364 | `getProjectWithTranslations` |
| 23 | `POST /api/v1/backstage/projects/{id}/translations/{locale}` | 368 | `updateProjectTranslation` |
| 24 | `GET /api/v1/backstage/comments/recent` ⚠ | 372 | `listProjectComments` |
| 25 | `POST /api/v1/backstage/projects/{id}/comments/{comment_id}/delete` | 376 | `deleteProjectComment` |

⚠ = URL does NOT follow `/projects/` or `/backstage/projects/` prefix. Five out-of-prefix URLs:
- `/api/v1/project-comments/{id}/like` (likeComment)
- `/api/v1/project-proposals` (propose)
- `/api/v1/backstage/proposals/{id}/approve` + `/reject` (proposal decisions)
- `/api/v1/backstage/comments/recent` (listProjectComments)

These URLs still register cleanly in the module's `routes.php` because **API routes are not URL-prefix-constrained** — only the frontend module-router (in `daem-society/public/index.php`) requires the `/<module>/...` pattern, and that's for HTML page resolution, not API. Module's `routes.php` registers handlers with the same Slim/dispatcher as core. **No URL renames in this PR** — frontend already calls these URLs and renaming would be a breaking change.

Note: `GET /api/v1/backstage/proposals` (lines 287–290, the listing route) **stays in core** — it calls `Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin` which aggregates Project + Event proposals. Cross-domain consumer.

The dependency chain in core after extraction: `BackstageController::listProposalsForAdmin` → `Daems\Application\Backstage\ListProposalsForAdmin` → reads from `Daems\Domain\Project\ProjectProposalRepositoryInterface` (resolved by module's binding) + `Daems\Domain\Event\EventProposalRepositoryInterface` (resolved by core).

---

## 6. Removals from core

### `daems-platform/`

- `bootstrap/app.php` — Projects use case + repo + controller bindings (~30+ lines, lines 23–35 imports + bindings ~225–360) deleted
- `routes/api.php` — Projects routes (lines 63–112 public, 263–296 + 364–376 admin) deleted
- `tests/Support/KernelHarness.php` — Projects bindings + InMemory fake registrations deleted; cross-domain test imports updated to `DaemsModule\Projects\Tests\Support\*`
- `src/Application/Project/` (13 dirs, 38 files) — directory deleted
- `src/Application/Backstage/{12 admin dirs}` — directories deleted (one per use case)
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProject*Repository.php` (3 files)
- `src/Infrastructure/Adapter/Api/Controller/ProjectController.php`
- 10 Project-named methods + 2 proposal-decision methods (`approveProjectProposal`, `rejectProjectProposal`) in `BackstageController.php` lines 416–786 + ~525–565
- `tests/Unit/Application/Project/{CreateProjectTest,UpdateProjectTest}.php`
- `tests/Integration/Application/{ProjectCommentModerationIntegrationTest,ProjectsAdminIntegrationTest}.php`
- `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- `tests/Integration/{ProjectProposalStatsTest,ProjectStatsTest}.php`
- `tests/Isolation/{ProjectTenantIsolationTest,ProjectsAdminTenantIsolationTest,ProjectsI18nTenantIsolationTest,ProjectsStatsTenantIsolationTest}.php`
- `tests/E2E/{F004_UnauthProjectMutationTest,ProjectsLocaleE2ETest}.php`
- `tests/Support/Fake/InMemoryProject*.php` (3 files)
- `tests/Integration/Migration/{Migration027Test,Migration031Test,Migration044Test,Migration046Test}.php` (4 files)
- 10 migration files in `database/migrations/` (003, 009, 010, 016, 027, 031, 044, 046, 052, 055)

### `daems-platform/` retained (NOT removed)

- `src/Domain/Project/` — 14 files stay (per Forum lesson 1)
- `tests/Unit/Application/Backstage/{ListPendingApplicationsForAdminTest,ListProposalsForAdminTest,ListNotificationsStatsTest}.php` — stay; imports updated only
- `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php` — stays; imports updated
- `tests/E2E/F007_IdentitySpoofingTest.php` — stays; imports updated
- Migrations 053, 054, 059 — stay (mixed events+projects)
- Existing core BackstageController methods that aren't Project-named — stay

### `daem-society/`

- `public/pages/projects/` (entire directory: 7 PHP + `detail/` subfolder with 2 PHP)
- `public/pages/backstage/projects/` (entire directory: 1 PHP + 1 CSS + 2 JS)

---

## 7. Namespace rewrite map

Applied to every moved file via mechanical search + replace:

```
Daems\Application\Project\*                           → DaemsModule\Projects\Application\Project\*
Daems\Application\Backstage\AdminUpdateProject\*      → DaemsModule\Projects\Application\Backstage\AdminUpdateProject\*
Daems\Application\Backstage\ApproveProjectProposal\*  → DaemsModule\Projects\Application\Backstage\ApproveProjectProposal\*
Daems\Application\Backstage\ChangeProjectStatus\*     → DaemsModule\Projects\Application\Backstage\ChangeProjectStatus\*
Daems\Application\Backstage\CreateProjectAsAdmin\*    → DaemsModule\Projects\Application\Backstage\CreateProjectAsAdmin\*
Daems\Application\Backstage\DeleteProjectCommentAsAdmin\*  → DaemsModule\Projects\Application\Backstage\DeleteProjectCommentAsAdmin\*
Daems\Application\Backstage\GetProjectWithAllTranslations\* → DaemsModule\Projects\Application\Backstage\GetProjectWithAllTranslations\*
Daems\Application\Backstage\ListProjectCommentsForAdmin\*   → DaemsModule\Projects\Application\Backstage\ListProjectCommentsForAdmin\*
Daems\Application\Backstage\ListProjectsForAdmin\*    → DaemsModule\Projects\Application\Backstage\ListProjectsForAdmin\*
Daems\Application\Backstage\Projects\*                → DaemsModule\Projects\Application\Backstage\Projects\*
Daems\Application\Backstage\RejectProjectProposal\*   → DaemsModule\Projects\Application\Backstage\RejectProjectProposal\*
Daems\Application\Backstage\SetProjectFeatured\*      → DaemsModule\Projects\Application\Backstage\SetProjectFeatured\*
Daems\Application\Backstage\UpdateProjectTranslation\* → DaemsModule\Projects\Application\Backstage\UpdateProjectTranslation\*
Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository                     → DaemsModule\Projects\Infrastructure\SqlProjectRepository
Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectCommentModerationAuditRepository → DaemsModule\Projects\Infrastructure\SqlProjectCommentModerationAuditRepository
Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository             → DaemsModule\Projects\Infrastructure\SqlProjectProposalRepository
Daems\Infrastructure\Adapter\Api\Controller\ProjectController                         → DaemsModule\Projects\Controller\ProjectController
Daems\Tests\Support\Fake\InMemoryProject*Repository → DaemsModule\Projects\Tests\Support\InMemoryProject*Repository
Daems\Tests\Unit\Application\Project              → DaemsModule\Projects\Tests\Unit\Application\Project
Daems\Tests\Integration\Application\ProjectCommentModerationIntegrationTest → DaemsModule\Projects\Tests\Integration\Application\ProjectCommentModerationIntegrationTest
Daems\Tests\Integration\Application\ProjectsAdminIntegrationTest             → DaemsModule\Projects\Tests\Integration\Application\ProjectsAdminIntegrationTest
Daems\Tests\Integration\Infrastructure\SqlProjectRepositoryI18nTest          → DaemsModule\Projects\Tests\Integration\Infrastructure\SqlProjectRepositoryI18nTest
Daems\Tests\Integration\ProjectProposalStatsTest  → DaemsModule\Projects\Tests\Integration\ProjectProposalStatsTest
Daems\Tests\Integration\ProjectStatsTest          → DaemsModule\Projects\Tests\Integration\ProjectStatsTest
Daems\Tests\Isolation\Project*Test                → DaemsModule\Projects\Tests\Isolation\Project*Test
Daems\Tests\Isolation\Projects*Test               → DaemsModule\Projects\Tests\Isolation\Projects*Test
Daems\Tests\E2E\F004_UnauthProjectMutationTest    → DaemsModule\Projects\Tests\E2E\F004_UnauthProjectMutationTest
Daems\Tests\E2E\ProjectsLocaleE2ETest             → DaemsModule\Projects\Tests\E2E\ProjectsLocaleE2ETest
```

**NOT rewritten (stay in `Daems\`):**
- `Daems\Domain\Project\*` — Domain stays in core
- All `Daems\Application\Backstage\ListPendingApplications\*`, `ListProposalsForAdmin\*`, `Notifications\*` — cross-domain consumers stay in core; their `use` statements that previously referenced `Daems\Tests\Support\Fake\InMemoryProject*` get updated to `DaemsModule\Projects\Tests\Support\InMemoryProject*` in Wave E

After rewrite, `git grep "use Daems\\\\\\(Application\\\\Project\\|Application\\\\Backstage\\\\\\(AdminUpdateProject\\|ApproveProjectProposal\\|ChangeProjectStatus\\|CreateProjectAsAdmin\\|DeleteProjectCommentAsAdmin\\|GetProjectWithAllTranslations\\|ListProjectCommentsForAdmin\\|ListProjectsForAdmin\\|RejectProjectProposal\\|SetProjectFeatured\\|UpdateProjectTranslation\\)\\|Application\\\\Backstage\\\\Projects\\|Infrastructure\\\\Adapter\\\\Persistence\\\\Sql\\\\SqlProject\\|Infrastructure\\\\Adapter\\\\Api\\\\Controller\\\\ProjectController\\)" src/ tests/ bootstrap/ routes/` in `daems-platform/` must return zero results.

---

## 8. Frontend module-router integration

No changes needed in `daem-society/public/index.php` — the module-router block from the Insights pilot already handles arbitrary module names. `daem-society/public/pages/_modules.php` discovers the new `module.json` automatically on next request.

URL → file mapping for Projects:

| URL | Resolves to |
|---|---|
| `/projects` (any path under) | `modules/projects/frontend/public/<path>` |
| `/backstage/projects` (any path under) | `modules/projects/frontend/backstage/<path>` |
| `/modules/projects/assets/backstage/<file>` | `modules/projects/frontend/assets/backstage/<file>` |

### Path-rewrite checklist for moved frontend files

Per Insights/Forum lesson: every moved file must convert `__DIR__`-relative includes → `DAEMS_SITE_PUBLIC`-rooted absolute paths. Targets:

- `top-nav.php`, `footer.php`, `layout.php`, `empty-state.php` (chrome includes)
- `ApiClient.php` includes
- `errors/404.php`, `errors/500.php` includes (use canonical `DAEMS_SITE_PUBLIC . '/pages/errors/404.php'` — the bug Forum's Task 26 uncovered)

Per Forum lesson: every moved file's `<script src=...>` and `<link href=...>` referencing JS/CSS now in `assets/` must update from `/pages/backstage/projects/<file>` → `/modules/projects/assets/backstage/<file>`.

Plan task uses `git grep -n "__DIR__\\|/pages/backstage/projects/\\|/pages/projects/"` over moved files to enumerate every edit point.

---

## 9. Bootstrap + harness wiring rule (CLAUDE.md "BOTH-wiring")

Projects-specific bindings must be removed from BOTH:
- `daems-platform/bootstrap/app.php` (production container)
- `daems-platform/tests/Support/KernelHarness.php` (test container)

Module's `bindings.php` and `bindings.test.php` replace both. Forum lesson 7 requires: extracted controllers MUST be instantiated against the production container before "done"-claim — KernelHarness's InMemory fakes hide constructor parameter-order bugs.

**Plan-task verification:** new-controller smoke task instantiates `ProjectsBackstageController` directly via `$container->make(...)` from a one-off CLI script using `bootstrap/app.php`'s real container at every Wave E gate. Catches `TypeError` from constructor-arg mismatch before commit.

---

## 10. Verification gates

Before final commit-stream "valmis"-claim, all must pass:

1. `composer analyse` → 0 errors at PHPStan level 9, in both `daems-platform/` and against `modules/projects/backend/src/`
2. `composer test` (Unit + Integration) → all green
3. `composer test:e2e` → all green (incl. moved `F004_UnauthProjectMutationTest`, `ProjectsLocaleE2ETest`)
4. `composer test:all` → all green (run as 4 separate suites if total exceeds 600s timeout — Forum gotcha 3)
5. **Production-container smoke** (Forum lesson 7): `ProjectController` + `ProjectsBackstageController` instantiate cleanly via Reflection + `$container->make(...)`
6. **Browser smoke-test (mandatory):**
   - `GET /projects`, `/projects/{slug}` (detail), `/projects/new` (proposal form), `/projects/edit?slug=…` — all render
   - `POST /api/v1/projects/{slug}/comments` — creates comment, response renders in UI
   - `POST /api/v1/projects/{slug}/like` (if exists), `/join`, `/leave`, `/archive` — all succeed
   - `GET /backstage/projects` — admin dashboard renders
   - `POST /api/v1/backstage/projects/{id}/translations/{locale}` — saves translation, UI reflects
   - JS console: zero `payload is undefined` / undefined-property errors
   - Network: every `/modules/projects/assets/backstage/*.{js,css}` returns HTTP 200, no 404s
   - Theme: backstage parity with other admin pages (no theme regressions, per CLAUDE.md backlog item I13)

---

## 11. Risks + mitigations

| Risk | Mitigation |
|---|---|
| Namespace rewrite (~100+ use-statement updates) misses a site → fatal at first request | After rewrite, run PHPStan level 9 + all 4 test suites. Plan task runs `git grep` (see §7) over `daems-platform/` and asserts zero hits before delete-step. |
| `BackstageController` extraction misses one of 10+2 methods → 404 in admin UI | Diff lines 416–786 + ~525–565 of original `BackstageController.php` against the new `ProjectsBackstageController` method-by-method; checklist enumerates all 12 method names. Smoke-test exercises every backstage admin sub-route. |
| Controller constructor-arg-order bug (Forum lesson 7) | Mandatory plan task: instantiate `ProjectsBackstageController` via production container in a one-off CLI script before commit + after every Wave E task. KernelHarness alone is insufficient. |
| Migration rename causes runner to re-run already-applied migrations on dev DBs | One-shot core data-fix migration `066_rename_project_migrations_in_schema_migrations_table.sql` updates `schema_migrations` rows from old filename → new filename. Idempotent + conditional. Tested against snapshot of dev DB. |
| Mixed events+projects migrations (053, 054, 059) — extracting projects breaks events when Events module ships later | Decision: **leave mixed migrations in core**. Document in spec + plan that future Events extraction will need to re-evaluate (split or move to a shared module). No action this PR. |
| Frontend `__DIR__` includes break inside module folder | Grep + edit pass over every moved file (Forum's `git grep -n "__DIR__"` pattern). Browser smoke renders every page. |
| InMemory fakes left in `daems-platform/tests/Support/Fake/` after move → KernelHarness can't find them | Delete-step verifies `git grep "InMemoryProject"` returns only `modules/projects/...` results. Cross-domain core tests must update imports. |
| `ProjectsBackstageController` constructor over-injection (12 use cases × constructor arg = ugly) | Acceptable — Forum's `ForumModerationBackstageController` had 11 args, `ForumContentBackstageController` had 9. Same precedent. Argument count doesn't drive split below threshold. |
| Cross-domain test imports (KernelHarness, ListPending/ListProposals/Notifications tests) not updated → fatal | Wave E task enumerates exact files via grep + applies sed across all of them in single commit. Forum proved the pattern (commit 6fbeeb7). |
| `routes/api.php` has 25 Projects routes — easy to miss one (especially the 5 out-of-prefix URLs) | Plan task lists all 25 in §5 above; deletion-task script counts before + after, asserts -25 net change. |
| `dp-projects` repo already has an empty `.git` — no GitHub repo created yet at github.com/Turtuure/dp-projects | Plan Task 1 verifies repo exists on GitHub (gh-cli or browser), creates if missing, before first commit/push. Existing local `.git` is reused. |

---

## 12. Repos affected (3 commit streams)

| Repo | Branch | Stream |
|---|---|---|
| `daems-platform` | `dev` | New `066_*` data-fix migration; autoload-dev + phpstan paths (Task 9.5); removals (Projects bindings, Projects routes, Projects source, Projects tests, 10 Projects migrations, 4 dangling Migration tests); KernelHarness cleanup; final delete commits |
| `dp-projects` (existing — `Turtuure/dp-projects`) | `dev` | Skeleton commit (manifest, README, .gitignore, stub bindings/routes) + content-move commits (backend, frontend, tests, migrations) + verification commits |
| `daem-society` | `dev` | Frontend deletes (`public/pages/projects/`, `public/pages/backstage/projects/`) |

**Repo creation:** local `.git` already initialised at `C:\laragon\www\modules\projects\` with `origin = https://github.com/Turtuure/dp-projects.git`, HEAD → `refs/heads/dev`. GitHub repo creation: verify in Task 1 via `gh repo view Turtuure/dp-projects`; create if missing with `gh repo create Turtuure/dp-projects --public --description "..." --homepage "https://daems.fi"`.

**Push cadence:** dp-projects accumulates commits during plan execution. No auto-push — wait for explicit "pushaa" from user after final smoke-test passes.

**Estimated commit count:** ~20 dp-projects + ~6 daems-platform + 1 daem-society = **~27 commits**.

---

## 13. Success criteria

- `composer analyse` → 0 errors PHPStan level 9
- `composer test:all` green in `daems-platform/` (≈940+ tests; Forum left it at 943/943)
- `daems-platform/src/{Application/Project,Application/Backstage/{12 Project dirs},Infrastructure/Adapter/Persistence/Sql/SqlProject*Repository,Infrastructure/Adapter/Api/Controller/ProjectController}.php` no longer exist
- `BackstageController.php` contains zero `Project`-named methods (10 + 2 proposal-decision = 12 removed)
- `daem-society/public/pages/{projects,backstage/projects}/` no longer exist
- `bootstrap/app.php` has no Projects-specific bindings (only Domain interface refs from cross-domain consumers may remain — verify)
- `routes/api.php` has no Projects routes (-25)
- `modules/projects/` is fully populated as `dp-projects`'s `dev` branch
- All 6 browser smoke-test items pass
- Theme parity with other backstage pages confirmed
- `src/Domain/Project/` (14 files) intact in core — Forum lesson 1
- Migrations 053, 054, 059 intact in core — mixed scope
- Cross-domain core tests (3 unit + 2 E2E + KernelHarness) use module InMemory fakes via cross-module imports

---

## 14. Decisions made during brainstorming (record)

- **Q1 (scope):** carries over from foundation — C, full vision phased out.
- **Q2 (disable mode):** carries over — B, no runtime gate yet for Projects.
- **Q3 (isolation):** carries over — A, strict (Projects may import only `Daems\Domain\{Tenant,User,Auth,Membership,Project,Locale}` + `Daems\Infrastructure\Framework\*` + `Daems\Application\Shared\*` if needed).
- **F1 (admin namespace):** A — `DaemsModule\Projects\Application\Backstage\<UseCase>\*` (12 sibling dirs, flat structure mirrors current).
- **F2 (SQL repo class structure):** A — keep 3 separate.
- **F3 (asset folder):** A — flat `frontend/assets/backstage/`.
- **F4 (controller split):** A — single `ProjectsBackstageController` (10+2 methods, below 15-threshold).
- **Forum lesson 1 (Domain in core):** confirmed by inventory — 3 cross-domain consumers exist.
- **Migration split:** 10 project-pure migrations move; 3 mixed (events+projects) stay in core.
- **Migration tests to delete:** 4 (`Migration027/031/044/046Test`). Tests for mixed migrations (052/053/054/055/059) stay or are evaluated case-by-case.
- **Cross-domain test imports:** stay in core, imports updated to module fakes in Wave E.

---

## 15. Plan task wave summary (preview — full plan in separate doc)

| Wave | Tasks | Repo | Description |
|---|---|---|---|
| **A — Skeleton + infra** | T1 (skeleton), T2 (066_* data-fix), T3 (inventory verify), **T3.5 (autoload-dev + phpstan paths)** | dp-projects + daems-platform | Foundations. T3.5 is the new infra commit per Forum lesson 3. |
| **B — Move backend** | T5 (3 SQL repos), T6 (3 InMemory fakes), T7 (Application/Project, 13 dirs), T8 (Application/Backstage, 12 dirs), T9 (ProjectController), T10 (extract `ProjectsBackstageController` TDD, 12 methods), T12 (10 migrations rename) | dp-projects | Pure backend moves with namespace rewrite. |
| **C — Wiring** | T13 (bindings.php production), T14 (bindings.test.php), T15 (routes.php, 25 routes) | dp-projects | Module DI + routing. **Production-smoke after T13.** |
| **D — Move tests** | T17 (Application/Project unit, 2), T18 (Backstage unit, count from grep), T19 (Integration, 5), T20 (Isolation, 4 + E2E, 2) | dp-projects | Test-suite migration. |
| **E — Core cleanup** | T21 (rm bindings bootstrap), T22 (rm routes), T23 (rm 12 BackstageController methods), T24 (rm KernelHarness bindings + update cross-domain test imports), T25 (apply 066_* + delete originals + delete 4 dangling Migration tests) | daems-platform | Surgical core cleanup. **Production-smoke after each task.** |
| **F — Frontend** | T26 (9 public pages → modules/projects/frontend/public/), T27 (1 backstage page + asset URL update), T28 (3 assets → modules/projects/frontend/assets/backstage/, delete originals) | dp-projects + daem-society | Frontend moves. |
| **Final gate** | T29 (PHPStan + all suites + production-smoke + git grep + browser smoke) | all | Verification. |

**Tasks 4 and 16 (Move Domain + Move Domain unit tests) explicitly DROP from this plan** per Forum lesson 1.

The full plan document with per-task acceptance criteria, exact file lists, and verification commands lives at `docs/superpowers/plans/2026-04-27-modular-architecture-phase1-projects.md` (to be created next).
