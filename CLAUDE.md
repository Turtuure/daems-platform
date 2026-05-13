# Daems Platform — Working Guide for Claude

> Auto-loaded by any Claude session started from this directory. Read this first.

## Two-repo architecture

This project spans two Laragon web roots that work together:

| Repo | Path | Role |
|------|------|------|
| `daems-platform` | `C:\laragon\www\daems-platform` | **Backend API + Backstage code**. PHP 8.1+, Clean Architecture, MySQL. REST at `http://daems-platform.local/api/v1/*`. Backstage code lives at `public/backstage/*` and is served by tenant frontend hosts via filesystem delegation. |
| `daem-society` | `C:\laragon\www\sites\daem-society` | **Public site + backstage delegation**. Public pages own. `/backstage*`, `/api/backstage/*`, and the related static-asset paths are delegated to platform's filesystem code via `require` — no cross-host redirect. Tenant-frontend session carries to backstage. |

Open the **new terminal in `C:\laragon\www\daems-platform`** by default — this is the primary work location. Switch to `sites/daem-society` only when editing public-site pages, CSS, or JS.

**Multi-tenant:** the platform serves multiple tenant frontends (daems, sahegroup, …) AND hosts the same backstage UI code (delegated to each frontend host). Tenant is resolved by `Host` header (`TenantContextMiddleware`). Dev-host fallback lives in `config/tenant-fallback.php`:
- `daems.local` / `daem-society.local` → `daems` tenant (frontend + backstage delegation)
- `daems-platform.local` → `daems` tenant (direct API + direct-access backstage fallback)
- `sahegroup.local` → `sahegroup` tenant (frontend; backstage delegation needs Phase-2 X-Daems-Forwarded-Host)
- `sahegroup-platform.local` → `sahegroup` tenant (direct API)
- `localhost` → `daems` tenant

**Backstage:** Code lives in `daems-platform/public/backstage/*` + `modules/<n>/frontend/backstage/*`. Tenant frontends serve it at `<host>/backstage/*` via a filesystem-include delegation block in their `index.php`. NO cross-host redirect; the tenant-frontend session is the source of truth (`$_SESSION['user']` shape compatible with platform's `_guard.php`). Platform's own `daems-platform.local/backstage` works as a direct-access fallback with its own login form. Adding a new tenant frontend: add Apache vhost pointing at the tenant's repo and ensure the same delegation block is in that repo's index.php (today only `daem-society/public/index.php` has it).

## Module registry & tenant gating (2026-05-07)

The platform uses a **two-tier manifest split** for module configuration:

- **`c:/laragon/www/modules/<name>/module.json`** — module's own technical metadata (name, namespace, src_path, bindings, routes, migrations_path, frontend.{public_pages, backstage_pages, assets}). Lives in the module's repo, not this one.
- **`config/modules.php`** (this repo) — platform-side gating metadata (category, sidebar position, `is_core`, `default_available`, `route_prefixes`, `depends_on`, name_key, description_key).

Both are merged at boot in `\Daems\Infrastructure\Module\ModuleRegistry::discover()`, which autoloads each module's namespace and runs its `bindings.php`.

**Per-tenant runtime gating** uses two columns in `tenant_modules`:

- `available_at` — GSA grants availability to a tenant
- `enabled_at` — tenant admin enables the available module

`\Daems\Domain\Tenant\TenantModuleResolver` is the single source of truth for "is module M enabled for tenant T". `\Daems\Domain\Tenant\ModuleRouteGuard` runs **before auth** in `public/backstage/router.php` + `api-router.php`, returning 404 for disabled modules so unauthenticated visitors can't probe module state.

The 5 existing modules (events, forum, insights, members, projects) are seeded for `daems` + `sahegroup` tenants by migration 072.

### Adding a new module

1. **Module repo** (`c:/laragon/www/modules/<name>/`):
   - Create or update `module.json` with: name, namespace, src_path, bindings, routes, migrations_path, frontend.{public_pages, backstage_pages, assets}.
2. **daems-platform** (this repo):
   - Add an entry to `config/modules.php` with gating metadata: category, sidebar position (group/order/icon/href), `route_prefixes` (must NOT overlap with other modules), `is_core`, `default_available`, `depends_on`, `name_key`, `description_key`.
   - Add i18n keys `modules.<name>.name` and `modules.<name>.description` to `lang/{fi_FI,en_GB,sw_TZ}.php`.
   - If `default_available=true`, add a one-off SQL migration that inserts `tenant_modules` rows for existing tenants (or use the GSA UI per tenant if rollout should be gated).
3. **Boot the platform**: `ModuleRegistry::discover()` picks up `module.json`, autoloads its namespace, runs its bindings.php, registers its routes. The new module appears in GSA tenant management (Modules tab).

## Default public site (2026-05-07)

Lives at `public/sites/_default/` in this repo — fallback for tenants without a custom `c:/laragon/www/sites/<slug>/` directory.

`public/sites-router.php` is the entry point:

1. Resolves tenant from host header.
2. If suspended (non-null `tenants.suspended_at`) → HTTP 503 from `public/sites/_default/suspended.php`.
3. If `c:/laragon/www/sites/<slug>/public/index.php` exists → delegate to it.
4. Else → render from `public/sites/_default/` (home, join, login, suspended).

## Current state (updated 2026-05-13)

Branch: `membership-billing-v1` (pushed up to G end; H1-H4 pending). All work lands on this branch; never push without explicit ask.

Completed milestones (see `docs/superpowers/plans/` for plans, `docs/superpowers/specs/` for specs):
- **PR 1** — PHPStan level 9 baseline (`2026-04-19-phpstan-level9-baseline.md`)
- **PR 2** — Tenant infrastructure (`2026-04-19-tenant-infrastructure.md`)
- **PR 3** — Tenant data migration (`2026-04-19-tenant-data-migration.md`): migrations 025–033 add `tenant_id` to every per-tenant table; 7 `tests/Isolation/*TenantIsolationTest.php` classes
- **PR 4** — Backstage Applications + Members API (`2026-04-20-backstage-applications-and-members-api.md`): migrations 034–035
- **2026-04-20** — Approve-flow + global toasts; Events admin; Projects admin; Forum moderation (migrations 036–050)
- **PR 5** — Content i18n for events + projects + EventProposal (`2026-04-21-content-i18n-events-projects-design.md`): migrations 051–056; `Daems\Domain\Locale\*` value objects; locales `fi_FI|en_GB|sw_TZ`; per-field fallback via `EntityTranslationView`; admin locale-cards pattern; EventProposal mirrors ProjectProposal + `source_locale`
- **A11 follow-up** (2026-04-23, branch `i18n-a11-cleanup`): migration 054 drops legacy `events.title/location/description` + `projects.title/summary/description`; `*_i18n` tables are sole source of truth; SqlEventRepository / SqlProjectRepository derive convenience scalars via firstAvailable() over translation map; UpdateEvent + AdminUpdateProject split chrome vs translation field updates; 7 fixture files split raw INSERTs into base + companion i18n inserts.
- **2026-05-13 — MembershipBilling v1 (0.7)** (`docs/superpowers/plans/2026-05-12-membership-billing-v1.md`): annual fees by board decision, anniversary-based invoice cron, waive/reduce/manual-payment, CSV-import (Nordea), § 4 deemed-resignation (2y unpaid → lapse) cron, GSA reverse-lapse override, honorary auto-waive (use case wired; UI integration deferred until ChangeMembershipType flow lands). Migrations 089-096 (096 = nullable `member_status_audit.performed_by` for cron-driven flips). New `Daems\Domain\Membership\Billing\*` namespace + `Daems\Domain\Audit\GsaOverrideAction::ReverseLapse` enum case. `bin/console membership:{generate-anniversary-invoices,mark-overdue-invoices,lapse-inactive-members}` CLI commands. Backstage UI: `/backstage/governance/billing` + sub-pages (invoices, overrides, import) with KPI strip. Stripe → 0.7.1, Visma → 0.7.2 as separate milestones. PHPStan level 9 = 0, ~80 new unit/integration/E2E tests.

Active roadmap (`docs/planning/roadmap.md`, section 1 Admin Panel):
1. ✅ Dashboard overview
2. ✅ Applications + Members pages
3. ✅ Approve-flow + global toast notifications
4. ✅ Events admin (`/backstage/events`)
5. ✅ Projects admin (`/backstage/projects`)
6. ✅ Forum moderation (`/backstage/forum`)
7. ⏭️ **Settings** (`/backstage/settings`) — the only remaining sidebar item

## i18n notes (PR 5)

- **UI chrome default locale** (`\Daems\Frontend\I18n::DEFAULT_LOCALE`) is `'en_GB'`. Tenants override via `tenants.default_locale` (column added in migration 071). Daem Society tenant runs in `fi_FI`; Sahegroup tenant runs in `en_GB`. **Content fallback** = `en_GB` (backend `SupportedLocale::CONTENT_FALLBACK`).
- Locale negotiation priority (highest first): `Accept-Language` header → `?lang=` query → `X-Daems-Locale` header → default.
- API response shape for translatable entities: `title`, `title_fallback` (bool), `title_missing` (bool) — same triple per translatable field. Admin read adds a `translations` map keyed by locale + a `coverage` map `{locale: {filled, total}}`.
- Backstage editor pattern: locale-cards grid inside the existing `event-modal` / `project-modal`; non-translated fields in a shared panel below; save is per-locale via `POST /api/v1/backstage/{kind}s/{id}/translations/{locale}` (NOT PUT).
- `lang/*.php` files renamed: `fi.php` → `fi_FI.php`, `en.php` → `en_GB.php`, `sw.php` → `sw_TZ.php`. Legacy 2-letter cookie/session values auto-remap on read.

## Conventions (enforce these)

### Git

- **Commit identity:** every commit must use `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. The repo's global git config is NOT set to Dev Team.
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

Test suites use `tests/Integration/MigrationTestCase` (resets DB + runs migrations fresh per test — slow but deterministic). Add new isolation tests under `tests/Isolation/` — `IsolationTestCase` base class runs migrations up to the current highest (currently 72) and seeds `daems` + `sahegroup` tenants.

### Markdown files — zero lint errors

Every `.md` file written by Claude (memory, docs, PR bodies, READMEs, planning notes, spec drafts) MUST produce zero `markdownlint` warnings/errors. Fix the writing, don't ignore warnings.

Specific rules:

- **MD041 (first line must be H1):** every file starts with `# Title`. For memory files with YAML frontmatter, the order is `---\nfrontmatter\n---\n\n# Title\n\nBody`. One blank line between frontmatter close and the H1.
- **MD022 (blank lines around headings):** one blank line before AND after every heading.
- **MD032 (blank lines around lists):** one blank line before the first list item AND after the last. Never let a list touch a paragraph or another block element.
- **MD031 (blank lines around fenced code):** one blank line before the opening fence and after the closing fence.
- **MD040 (fenced code language):** always specify a language on the opening fence (`php`, `bash`, `sql`, `text` for plain). Never leave a fence with no language tag.
- **MD033 (no inline HTML):** never write angle-bracket placeholders or tags in flowing prose — wrap them in backticks or escape. Real HTML elements (`<details>` for collapsibles) only when they add reader value.
- **MD038 (no space inside code spans):** never leave a leading/trailing space directly inside a code span. To show triple-backticks literally, prefer an indented code block over inline backtick acrobatics.

Before finalising any `.md` write, mentally scan: first line is a heading, every list and code block has blank lines around it, no angle-bracket placeholders in flowing text, every fenced block has a language tag, no leading/trailing spaces inside code spans.

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
