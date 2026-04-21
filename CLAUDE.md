# Daems Platform — Working Guide for Claude

> Auto-loaded by any Claude session started from this directory. Read this first.

## Two-repo architecture

This project spans two Laragon web roots that work together:

| Repo | Path | Role |
|------|------|------|
| `daems-platform` | `C:\laragon\www\daems-platform` | **Backend API** only. PHP 8.1+, Clean Architecture, MySQL, REST at `http://daems-platform.local/api/v1/*`. |
| `daem-society` | `C:\laragon\www\sites\daem-society` | **Frontend** — public site + admin `/backstage` pages. Calls the platform API via `ApiClient` (`http://daems-platform.local/api/v1`). |

Open the **new terminal in `C:\laragon\www\daems-platform`** by default — this is the primary work location. Switch to `sites/daem-society` only when editing frontend pages, CSS, or JS.

**Multi-tenant:** the platform serves multiple frontends (daem-society, sahegroup, …). Tenant is resolved by `Host` header (`TenantContextMiddleware`). Dev-host fallback lives in `config/tenant-fallback.php`:
- `daems-platform.local` → `daems` tenant
- `daem-society.local` → `daems` tenant
- `localhost` → `daems` tenant
- `sahegroup.local` → `sahegroup` tenant

## Current state (updated 2026-04-21)

Branch: `dev` (pushed to origin). All work lands here; never push without explicit ask.

Completed milestones (see `docs/superpowers/plans/` for plans, `docs/superpowers/specs/` for specs):
- **PR 1** — PHPStan level 9 baseline (`2026-04-19-phpstan-level9-baseline.md`)
- **PR 2** — Tenant infrastructure (`2026-04-19-tenant-infrastructure.md`)
- **PR 3** — Tenant data migration (`2026-04-19-tenant-data-migration.md`): migrations 025–033 add `tenant_id` to every per-tenant table; 7 `tests/Isolation/*TenantIsolationTest.php` classes
- **PR 4** — Backstage Applications + Members API (`2026-04-20-backstage-applications-and-members-api.md`): migrations 034–035
- **2026-04-20** — Approve-flow + global toasts; Events admin; Projects admin; Forum moderation (migrations 036–050)
- **PR 5** — Content i18n for events + projects + EventProposal (`2026-04-21-content-i18n-events-projects-design.md`): migrations 051–056; `Daems\Domain\Locale\*` value objects; locales `fi_FI|en_GB|sw_TZ`; per-field fallback via `EntityTranslationView`; admin locale-cards pattern; EventProposal mirrors ProjectProposal + `source_locale`

Active roadmap (`docs/planning/roadmap.md`, section 1 Admin Panel):
1. ✅ Dashboard overview
2. ✅ Applications + Members pages
3. ✅ Approve-flow + global toast notifications
4. ✅ Events admin (`/backstage/events`)
5. ✅ Projects admin (`/backstage/projects`)
6. ✅ Forum moderation (`/backstage/forum`)
7. ⏭️ **Settings** (`/backstage/settings`) — the only remaining sidebar item

Outstanding technical debt — **A11 follow-up**: `SqlEventRepository` / `SqlProjectRepository` are hybrid (read/write both legacy columns + `*_i18n` tables). Migration 054 (drop legacy translated columns) not yet created. Dedicated plan: `docs/superpowers/plans/2026-04-21-a11-cleanup.md` (9 tasks, ~2-3h focused session, target branch `i18n-a11-cleanup`).

## i18n notes (PR 5)

- **UI chrome default locale** = `fi_FI` (frontend `I18n::DEFAULT_LOCALE`). **Content fallback** = `en_GB` (backend `SupportedLocale::CONTENT_FALLBACK`). These are intentionally different.
- Locale negotiation priority (highest first): `Accept-Language` header → `?lang=` query → `X-Daems-Locale` header → default.
- API response shape for translatable entities: `title`, `title_fallback` (bool), `title_missing` (bool) — same triple per translatable field. Admin read adds a `translations` map keyed by locale + a `coverage` map `{locale: {filled, total}}`.
- Backstage editor pattern: locale-cards grid inside the existing `event-modal` / `project-modal`; non-translated fields in a shared panel below; save is per-locale via `POST /api/v1/backstage/{kind}s/{id}/translations/{locale}` (NOT PUT).
- `lang/*.php` files renamed: `fi.php` → `fi_FI.php`, `en.php` → `en_GB.php`, `sw.php` → `sw_TZ.php`. Legacy 2-letter cookie/session values auto-remap on read.

## Conventions (enforce these)

### Git

- **Commit identity:** every commit must use `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. The repo's global git config is NOT set to Dev Team.
- **No `Co-Authored-By:`** trailer. Ever.
- **Never auto-push.** Report commit SHAs and wait for explicit "pushaa" instruction.
- **Never stage `.claude/`** — run `git reset HEAD .claude/` before committing if it got staged.
- Pre-commit hook failure → fix + create NEW commit, never `--amend`.

### Architecture

- Clean Architecture layers: `Domain` / `Application` / `Infrastructure`. Domain has no framework deps; Application has no HTTP/SQL; Infrastructure implements ports.
- Every per-tenant repository method takes `TenantId` explicitly. Non-scoped methods (`findBySlug`, `findAll`) are REMOVED — use `findBySlugForTenant`, `listForTenant`.
- Every entity for a per-tenant row carries `TenantId` as its 2nd constructor arg.
- Use cases check authorization (`$acting->isAdminIn($tenantId)` / `$acting->isPlatformAdmin`) and throw `ForbiddenException` on deny.
- Controllers are thin: unwrap `Request`, call use case, catch domain exceptions → map to `Response` status codes.

### DI wiring — **BOTH containers**

When adding a new controller, use case, or SQL repository:
- Bind it in `bootstrap/app.php` (production container)
- Bind it in `tests/Support/KernelHarness.php` (test container with InMemory fakes)

**If you only wire KernelHarness, E2E tests go green but the live server breaks** (see `~/.claude/projects/C--laragon-www-daems-platform/memory/feedback_bootstrap_and_harness_must_both_wire.md`). This bit us on PR 4 — Members page showed empty until we added the prod binding. Always grep for the new class in BOTH files before marking a task done.

### Testing

```bash
composer analyse      # PHPStan level 9, must be 0 errors
composer test         # Unit + Integration (MySQL required on 127.0.0.1)
composer test:e2e     # E2E via KernelHarness (fast, no DB)
composer test:all     # Everything
```

Dev DB: `daems_db` on `127.0.0.1:3306`, user `root`, password `salasana`. Test DB: `daems_db_test`. MySQL binary: `C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe`.

Test suites use `tests/Integration/MigrationTestCase` (resets DB + runs migrations fresh per test — slow but deterministic). Add new isolation tests under `tests/Isolation/` — `IsolationTestCase` base class runs migrations up to the current highest (currently 35) and seeds `daems` + `sahegroup` tenants.

### Role & identity

After PR 2 (ADR-014):
- `users.role` column was DROPPED.
- Tenant-scoped role lives in `user_tenants.role` (enum: `admin|moderator|member|supporter|registered`).
- Platform admin is `users.is_platform_admin BOOLEAN`. GSA = "Global System Administrator" = `is_platform_admin = true`.
- Frontend session: `$_SESSION['user']['is_platform_admin']` (populated by login response via `projectUser()`). Legacy frontend checks for `$_SESSION['user']['role'] === 'global_system_administrator'` still work because `daem-society/public/api/auth/login.php` synthesizes the legacy `role` field from `is_platform_admin`.

### Workflow

Use the superpowers skills for new feature work:

```
brainstorming → writing-plans → subagent-driven-development → finishing-a-development-branch
```

Plans go to `docs/superpowers/plans/YYYY-MM-DD-<feature>.md`. Specs to `docs/superpowers/specs/`.

**Forbidden tool:** do NOT call any `mcp__code-review-graph__*` tool. A subagent session previously hung indefinitely on `mcp__code-review-graph__build_or_update_graph_tool` during PR 3 Task 1. Always include this warning in subagent prompts that might dispatch graph-building work.

## How to start a new session

1. Open terminal in `C:\laragon\www\daems-platform`
2. Run `claude` — this CLAUDE.md loads automatically
3. Paste the session opener prompt (see below) to orient Claude on what's next

## Memory store

Persistent memory for this project lives at `C:\Users\Sam\.claude\projects\C--laragon-www-daems-platform\memory\`. Key files:
- `user_language_and_style.md` — Finnish, terse
- `feedback_commit_signature.md` — Dev Team git identity
- `feedback_bootstrap_and_harness_must_both_wire.md` — DI wiring gotcha
- `project_ds_upgrade_status.md` — PR 1-3 status
- `project_admin_panel_next.md` — roadmap ordering (Settings is next after i18n milestone)
- `project_i18n_milestone.md` — PR 5 status + A11 follow-up details
- `project_forum_moderation_next.md` — forum moderation shipped 2026-04-20
- `feedback_phpunit_testsuite_names.md` — Unit/Integration/E2E capitalization gotcha

These load automatically on session start.
