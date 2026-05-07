# Module Registry, Tenant Management UI & Default Public Site — Design

- **Date:** 2026-05-07
- **Branch base:** `dev`
- **Parent epic:** Multi-tenant association management platform — sub-project A of the larger roadmap (see brainstorming session 2026-05-07)
- **Status:** Spec — awaiting plan and execution

## 1. Summary

Add five tightly-related capabilities to the daems-platform:

1. **Module Registry** — a hybrid PHP-manifest + DB-state system that lets each top-level backstage feature (Forum, Events, Projects, Insights, Members, Search, Settings) declare itself as a module, with metadata, dependencies, and route ownership.
2. **Two-tier tenant module gating** — Global System Admin (GSA) controls which modules are *available* to a tenant; the tenant's own admin chooses which *available* modules to *enable*. Disabled modules return 404 on direct URL access. Data is preserved across toggles.
3. **Tenant Management backstage UI** — GSA-only screens to create, edit, suspend, and reactivate tenants; manage `tenant_domains`; assign tenant admins; and grant/revoke module availability. Tenant admins get a Settings → Modules page to enable/disable available modules.
4. **Default public site fallback** — a minimal public site (home + join form + login link + footer + suspended state) served from `public/sites/_default/` when a tenant has no custom `c:/laragon/www/sites/{slug}/` frontend.
5. **Platform-wide locale default change** — `I18n::DEFAULT_LOCALE` flips from `fi_FI` to `en_GB`, aligning the platform UI default with the multi-tenant baseline (tenants can override).

These ship in a single PR because the parts are deeply interdependent (resolver needs registry; routers need resolver; UI needs API; default site needs tenant resolution).

## 2. Goals

- GSA can manage tenants without touching SQL.
- New modules added in future phases (Meetings, Voting, Billing, Grants, Honors, MemberPortal, Communications, Documents) plug into the registry without bespoke wiring per module.
- Tenant admins control their own footprint within the limits GSA grants.
- A new tenant has a working public site the moment its `tenants` row exists, with no need to scaffold a `sites/{slug}/` repo first.
- Existing tenants (`daems`, `sahegroup`) experience zero functional regression — all current modules stay enabled by virtue of a backfill migration.
- Disabled modules cannot leak via direct URL — 404 is returned **before** auth check, so the existence/state of a module is not observable to unauthenticated visitors.

## 3. Non-goals

- Branding (logo upload, primary colour) — separate spec, future.
- Hard-delete of tenants — only soft suspend in MVP. GDPR-compliant deletion is its own spec.
- Module sub-feature flags (e.g., `forum.pinning`, `events.location-hours`) — overengineering for MVP. Add when concrete need arises.
- Public-site rendering of enabled modules' data (events list, blog feed, forum on the public domain) — the default site is a holding page + join flow only. A "public-site module rendering pipeline" is a separate large spec.
- Replacing tenants' existing custom frontends in `c:/laragon/www/sites/{slug}/` — the default site is a fallback, not a replacement.
- Membership lifecycle (4 membership types, 12-month rule, board approval flows) — that is **MembershipCore v2**, the next sub-project on the roadmap. The default-site join form creates a basic `applications` row using the existing flow.
- Custom-CMS or rich-text editing of the default site content beyond a few i18n description fields stored on `tenants`.

## 4. Architecture overview

```text
┌─────────────────────────────────────────────────────────────────────┐
│  Bootstrap                                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ModuleRegistry  (singleton, scans config/modules/*.php)    │   │
│  │  - validates: slug uniqueness, no cycles, no route overlap  │   │
│  │  - throws ModuleManifestException on failure                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                       │
│                              ▼                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  TenantModuleResolver                                        │   │
│  │  - isEnabledFor(TenantId, slug): bool                        │   │
│  │  - statesForTenant(TenantId): array<slug, ModuleState>       │   │
│  │  - reads tenant_modules table via repository                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│           │                  │                  │                    │
│           ▼                  ▼                  ▼                    │
│  ┌─────────────┐   ┌──────────────┐   ┌────────────────┐           │
│  │RouteGuard   │   │SidebarBuilder│   │TenantModule-   │           │
│  │(404 disabled│   │(filters by   │   │ Service (GSA + │           │
│  │ before auth)│   │ enabled)     │   │ tenant-admin   │           │
│  │             │   │              │   │ toggle ops)    │           │
│  └─────────────┘   └──────────────┘   └────────────────┘           │
└─────────────────────────────────────────────────────────────────────┘
```

The Registry is the source of truth for *what modules exist*. The Resolver is the source of truth for *which modules are active for tenant X right now*. RouteGuard, SidebarBuilder, and TenantModuleService all depend on the Resolver — they are the only places that translate "module is/isn't on" into user-visible behaviour.

## 5. Module manifest format

### Location

`config/modules/<slug>.php` — one file per module. Returns a PHP array.

### Schema

```php
<?php declare(strict_types=1);

return [
    'slug'              => 'forum',
    'name_key'          => 'modules.forum.name',
    'description_key'   => 'modules.forum.description',
    'category'          => 'community',          // 'core'|'members'|'community'|'governance'|'content'
    'is_core'           => false,                // true => always-on, not in toggle UI, not in tenant_modules
    'default_available' => true,                 // backfilled to existing tenants on migration 072
    'sidebar' => [
        'group' => 'community',
        'order' => 30,
        'icon'  => 'forum',
        'href'  => '/backstage/forum',
    ],
    'routes' => [
        'backstage' => ['/backstage/forum'],
        'api'       => ['/api/v1/backstage/forum', '/api/v1/forum'],
    ],
    'dependencies' => [],                        // array of slugs this module requires
];
```

### Validation rules (enforced at boot)

1. `slug` is unique across all manifest files.
2. `slug` matches `/^[a-z][a-z0-9_-]*$/`.
3. `name_key` and `description_key` exist in `lang/en_GB.php` (English is the platform default; missing keys in other locales fall back to English).
4. `category` is one of the allowed values.
5. `dependencies` lists only known slugs (each must resolve to another manifest).
6. The dependency graph is acyclic.
7. No two manifests claim overlapping route prefixes (longest-prefix match must be unambiguous).

Failure ⇒ `ModuleManifestException` at bootstrap time. There is no silent fallback.

### Initial module catalog

| slug | is_core | default_available | category | retrofit-source |
|---|---|---|---|---|
| `settings` | true | — | core | `public/backstage/pages/settings/` |
| `search` | true | — | core | `src/Domain/Search` |
| `members` | false | true | members | `src/Domain/Member`, `src/Domain/Membership` |
| `events` | false | true | content | `src/Domain/Project` (Events part) |
| `projects` | false | true | content | `src/Domain/Project` |
| `forum` | false | true | community | `src/Domain/Forum` |
| `insights` | false | true | content | `src/Domain/Insight` (blog) |

`dashboard` is **not** a module — it is the backstage shell index page, hardcoded into `_shared.php` because it is the entry surface for every backstage user regardless of tenant.

## 6. Database schema

### New table: `tenant_modules`

```sql
CREATE TABLE tenant_modules (
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

Domain-level rules (enforced by `TenantModule` value object and Service layer, not DB constraints):
- `enabled_at` non-null requires `available_at` non-null. Disabling availability of a currently-enabled module cascades to a forced disable in the same transaction.
- `disabled_at` is the timestamp of the most recent disable; it is preserved across re-enable cycles for audit visibility.
- `module_slug` is **not** an FK to anything, because the manifest is the source of truth and lives in code, not in the DB. If a manifest is deleted, the orphaned rows render as "unknown module" in the GSA UI with a cleanup option.

### New table: `module_audit`

```sql
CREATE TABLE module_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    module_slug     VARCHAR(64)  NOT NULL,
    action          ENUM('made_available','revoked_availability','enabled','disabled') NOT NULL,
    actor_user_id   CHAR(36)     NOT NULL,
    actor_role      VARCHAR(32)  NOT NULL,    -- 'platform_admin' | 'tenant_admin'
    reason          TEXT         NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tenant_slug_time (tenant_id, module_slug, created_at),
    CONSTRAINT fk_ma_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ma_actor  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Every state change writes one row. The Tenant Edit "Audit" tab (Phase 2 polish, not blocking MVP acceptance) reads from this table.

### Extension to `tenants`

Migration 071 adds:

```sql
ALTER TABLE tenants
    ADD COLUMN display_name_i18n      JSON         NULL          AFTER name,
    ADD COLUMN public_description_i18n JSON        NULL          AFTER display_name_i18n,
    ADD COLUMN supported_locales      VARCHAR(255) NOT NULL DEFAULT 'en_GB' AFTER public_description_i18n,
    ADD COLUMN default_locale         VARCHAR(8)   NOT NULL DEFAULT 'en_GB' AFTER supported_locales,
    ADD COLUMN suspended_at           DATETIME     NULL          AFTER status,
    ADD COLUMN suspended_reason       TEXT         NULL          AFTER suspended_at;
```

- `display_name_i18n` JSON: `{"fi_FI":"Daem Society ry","en_GB":"Daem Society"}`. The existing `name` column stays as the short ASCII slug-class identifier; UI always renders the i18n version with fallback to `name`.
- `public_description_i18n` JSON: same shape, used by the default public site's home page.
- `supported_locales` comma-separated, defaults to `en_GB`. Existing `daems` and `sahegroup` rows are backfilled in migration 071 to `'fi_FI,en_GB,sw_TZ'` (matches current behaviour) and `default_locale` left at the new column default `en_GB` *unless* the existing row already has a tenant-context-derived default — in which case Daem Society is set to `fi_FI` explicitly in the migration's data block (it is the live tenant and its UI runs in Finnish today).
- `suspended_at` non-null = tenant is in the soft-suspended state.

### Untouched: `tenant_domains`, `user_tenants`, `users`, `tenant_member_counters`, `tenant_supporter_counters`

The existing schema covers these features; the UI exposes them, no schema change.

## 7. Migrations

Numbered sequentially after the current high-water mark (068).

- `069_create_tenant_modules_table.sql`
- `070_create_module_audit_table.sql`
- `071_extend_tenants_for_management_ui.sql` — column additions plus the inline data block for existing tenants' `display_name_i18n`, `supported_locales`, `default_locale`. Daem Society row gets `default_locale = 'fi_FI'`; Sahegroup row gets `'en_GB'`.
- `072_seed_tenant_modules.php` — runs `scripts/seed-tenant-modules.php`, which loads the manifests via `ModuleRegistry`, iterates non-core modules with `default_available=true`, and inserts a `tenant_modules` row per (existing tenant × such module) with `available_at = NOW(), available_by = NULL, enabled_at = NOW(), enabled_by = NULL`. This preserves current behaviour exactly: every module currently shipping is auto-enabled for both existing tenants. New tenants created after this migration get the same treatment via `CreateTenant` use case.

The `.php` migration suffix is supported by the existing migration runner. If the runner expects `.sql` only, the seed step is run as a post-migration script gated to dev/prod environments via `composer migrate:seed-modules` or equivalent — chosen at plan time after inspecting the runner's behaviour.

## 8. Code surface — Domain and Application layers

### `src/Domain/Tenant/`

- `ModuleManifest` (new value object) — immutable, parsed from a PHP file
- `ModuleRegistry` (new) — singleton, validates and exposes manifests
- `ModuleManifestException` (new) — thrown from validation
- `ModuleState` (new enum) — `ENABLED | AVAILABLE_NOT_ENABLED | DISABLED | CORE`
- `TenantModule` (new entity) — represents one row of `tenant_modules`
- `TenantModulesRepositoryInterface` (new port)
- `TenantModuleResolver` (new) — pure logic, depends on Registry + repository
- `ModuleRouteGuard` (new) — pure logic, depends on Registry + Resolver
- `ModuleAuditEntry` (new entity)
- `ModuleAuditRepositoryInterface` (new port)
- `Tenant.php` (existing) — extended with `displayName(string $locale): string`, `publicDescription(string $locale): ?string`, `supportedLocales(): array`, `defaultLocale(): string`, `suspended(): bool`, `suspendedReason(): ?string`
- `TenantRepositoryInterface` (existing) — extended with `update(Tenant)`, `suspend(TenantId, string $reason)`, `reactivate(TenantId)`

### `src/Domain/Tenant/` — domain exceptions

- `ModuleNotAvailableException` — tenant admin tries to enable a module not yet granted by GSA
- `ModuleDependencyUnmetException` — tenant admin tries to enable A while dependency B is disabled
- `ModuleDependentEnabledException` — tenant admin tries to disable B while dependent A is enabled
- `TenantSlugImmutableException` — slug change attempt after creation
- `TenantPrimaryDomainRequiredException` — last primary domain delete attempt

### `src/Application/Backstage/Platform/`

Use cases (one class each, single public method `execute(Input $i): Output`):
- `CreateTenant` (Input: slug, names_i18n, descriptions_i18n, supported_locales, default_locale, member_number_prefix; ActingUser=GSA)
- `UpdateTenantBasics` (same fields except slug)
- `SuspendTenant`, `ReactivateTenant`
- `AddTenantDomain`, `UpdateTenantDomain` (primary toggle, hostname change), `RemoveTenantDomain`
- `GrantAdminToUserForTenant`, `RevokeAdminFromUserForTenant`
- `GrantModuleAvailability` (idempotent — re-grant of already-available is a no-op + audit row)
- `RevokeModuleAvailability` (cascades enable→disable in same transaction, with audit rows for each)

Controllers (thin HTTP wrappers):
- `TenantsController`
- `TenantDomainsController`
- `TenantAdminsController`
- `TenantModulesController` (GSA-availability operations)

### `src/Application/Backstage/Tenant/`

Use cases:
- `EnableModuleForTenant`, `DisableModuleForTenant` (ActingUser=tenant_admin or platform_admin acting on tenant)
- `ListTenantModulesForCurrentTenant` (read model for Settings → Modules page)

Controller:
- `TenantModulesController` (tenant-admin enable/disable operations)

### `src/Infrastructure/Persistence/Mysql/`

- `SqlTenantModulesRepository`
- `SqlModuleAuditRepository`
- `SqlTenantDomainRepository` (new — currently `tenant_domains` is read-only via fallback config; we add a write repo)
- `SqlTenantRepository` extensions for the new fields

### `src/Frontend/`

- `BackstageSidebar` (new) — renders the sidebar from `TenantModuleResolver::statesForTenant()` plus hardcoded shell items (Dashboard, optionally Platform when `is_platform_admin`)
- `I18n.php` — `DEFAULT_LOCALE` flips from `'fi_FI'` to `'en_GB'` (see §13)

### DI bindings — both containers

Per CLAUDE.md, every new controller, use case, and repository **must** be bound in **both** `bootstrap/app.php` (production container) and `tests/Support/KernelHarness.php` (test container with InMemory fakes where applicable). Failing to wire the prod container while wiring the test container leaves E2E green and the live server broken — this regression has bitten us before.

InMemory fakes to add:
- `InMemoryTenantModulesRepository`
- `InMemoryModuleAuditRepository`
- `InMemoryTenantDomainRepository`

## 9. Resolver state machine

Given a `(TenantId, slug)`:

```text
manifest exists?
  no  → ModuleState::DISABLED                (and surfaces as "unknown module" in GSA UI)
  yes:
    manifest.is_core?
      yes → ModuleState::CORE
      no:
        tenant_modules row exists?
          no  → ModuleState::DISABLED
          yes:
            available_at IS NOT NULL?
              no  → ModuleState::DISABLED
              yes:
                enabled_at IS NOT NULL?
                  no  → ModuleState::AVAILABLE_NOT_ENABLED
                  yes → ModuleState::ENABLED
```

Caching: per-request memoisation in the resolver instance only. No app-level cache in MVP — measured cost is sub-millisecond at expected fan-out (8 modules × 1 tenant). Add caching only when a benchmark says so.

## 10. Route guard — 404 before auth

The guard runs in both `public/backstage/router.php` and `public/backstage/api-router.php`, **before** `_guard.php`'s auth check. If a route's owning module is `DISABLED` or `AVAILABLE_NOT_ENABLED`, the response is HTTP 404 with no body content beyond a generic Not-Found page — identical to a route that genuinely does not exist. This prevents an unauthenticated visitor from probing whether a module exists, is enabled, or merely disabled.

`is_core` modules always pass the guard. Routes that match no manifest also pass — they are either `core` shell routes (`/backstage/login`, `/backstage/`, `/backstage/api/auth/...`) or, in dev, intentional debug paths.

The longest-prefix-match rule resolves the case where two manifests claim overlapping prefixes — but the validation step at boot rejects such overlaps, so the resolver guarantees one canonical owner per request path.

## 11. Sidebar rendering

`BackstageSidebar::buildFor(Tenant, User)` returns an ordered list of grouped items:

1. **Hardcoded shell items** — Dashboard always; Platform group (Tenants, Audit) only if `User::is_platform_admin`.
2. **Module items** — for each module in registry order, included if `ModuleState::ENABLED` or `ModuleState::CORE`. Excluded if `AVAILABLE_NOT_ENABLED` or `DISABLED`. Group label comes from the manifest's `category` translated via i18n.
3. **Settings → Modules** sub-link — always included if `members` or any non-core module exists in the registry (i.e., the tenant has something they can toggle).

Available-but-not-enabled modules are surfaced **only** in `/backstage/settings/modules`, with a clear "Activate" affordance. They do not pollute the main sidebar, because the sidebar is for things in active use.

## 12. RBAC

| Operation | Required role | Implementation point |
|---|---|---|
| GSA: any tenant create/edit/suspend/reactivate | `users.is_platform_admin = true` | `requirePlatformAdmin()` in shared base controller |
| GSA: tenant_domains CRUD | same | same |
| GSA: assign/revoke `user_tenants.role = 'admin'` for any tenant | same | same |
| GSA: grant/revoke module availability for any tenant | same | same |
| Tenant admin: enable/disable own tenant's available modules | `user_tenants.role = 'admin'` AND tenant matches request | `requireTenantAdmin($tenantId)` |
| Tenant admin: read own tenant's module list | same | same |
| Anyone else | — | denied |

GSA role is global and resolved against `users.is_platform_admin`. Tenant admin role is scoped via `TenantContextMiddleware` resolving the request's tenant context, then matching `user_tenants(user_id, tenant_id, role='admin')`.

There is no separate "GSA-delegated platform user" tier in MVP — multiple users can hold `is_platform_admin = true`, and that is the delegation mechanism. Finer-grained delegation (read-only platform admin, billing-only platform admin, etc.) is a future spec.

## 13. Platform locale default change to `en_GB`

### Changes

- `src/Frontend/I18n.php` line 28: `public const DEFAULT_LOCALE = 'fi_FI';` → `'en_GB';`
- `src/Domain/Locale/SupportedLocale::CONTENT_FALLBACK` stays `'en_GB'` (already aligned).
- Migration 071 sets new tenants' default `default_locale = 'en_GB'`. Daem Society's existing row gets `'fi_FI'` explicitly (it is the live tenant operating in Finnish). Sahegroup's existing row gets the new default `'en_GB'`.

### Behaviour shifts to verify

- Anonymous users hitting backstage login (before tenant context resolves) see English UI by default; Accept-Language `fi-FI` still wins via `LocaleNegotiator`.
- Logged-in users with an explicit `users.preferred_locale` are unaffected.
- Cookie/session legacy `'fi'` and `'fi_FI'` values still auto-remap correctly (the existing remapping logic from the i18n milestone handles legacy two-letter values).
- All `lang/en_GB.php` translations must be complete — no untranslated keys, since en_GB becomes primary fallback. A diff check against `lang/fi_FI.php` keys is part of the test suite.

### CLAUDE.md update

The current "UI chrome default = fi_FI; content fallback = en_GB" line in CLAUDE.md is replaced with: "UI chrome default = en_GB. Tenants override via `tenants.default_locale`. Daem Society tenant's locale is `fi_FI`."

The corresponding memory file `project_i18n_milestone.md` is updated to reflect the new default.

## 14. TenantManagement UI flows

### Sidebar

Two new shell-level groups (not modules — hardcoded into `_shared.php`):

- **Platform** group — visible only to `is_platform_admin`:
  - `/backstage/platform/tenants` — list + create
  - `/backstage/platform/tenants/{id}` — edit (tabs)
  - `/backstage/platform/audit` — Phase 2 polish, not MVP-blocking

- **Settings → Modules** sub-link — added to the existing Settings module, visible to any tenant admin.

### `/backstage/platform/tenants` — list

Columns: slug, display name (in user's locale), status (badge), domains (comma list), member count, admin count, modules ratio (e.g., "5 / 6" = 5 enabled of 6 available).

Toolbar: "New tenant" button (modal form), text search (slug or name substring), status filter (active/suspended/all).

Row click → `/backstage/platform/tenants/{id}?tab=basics`.

### `/backstage/platform/tenants/{id}` — edit, 5 tabs

URL pattern follows the existing Members admin's `?view=` convention (memory: `project_members_admin_merge.md`).

#### Tab 1: Basics (`?tab=basics`)
- `slug` — read-only (immutable post-creation)
- `display_name_i18n` — locale cards, one per supported locale (existing pattern from events/projects i18n milestone)
- `public_description_i18n` — same locale-card pattern; multi-line text
- `supported_locales` — multi-checkbox (fi_FI, en_GB, sw_TZ)
- `default_locale` — single-select restricted to checked supported locales
- `member_number_prefix` — single text input (e.g., `DS-`)
- `status` — read-only badge; suspend/reactivate buttons live in Tab 5

API: `PATCH /api/v1/backstage/platform/tenants/{id}` (body = updateable fields)

#### Tab 2: Domains (`?tab=domains`)
- Table of `tenant_domains`: hostname, primary (badge), created_at, delete button
- "Add domain" button → modal: hostname (validated), primary checkbox
- Setting primary on one row demotes any existing primary in the same transaction
- Cannot delete the last primary; UI disables the button and the API enforces it

API:
- `GET /api/v1/backstage/platform/tenants/{id}/domains`
- `POST /api/v1/backstage/platform/tenants/{id}/domains` (create)
- `PATCH /api/v1/backstage/platform/tenants/{id}/domains/{domainId}` (toggle primary, edit hostname)
- `DELETE /api/v1/backstage/platform/tenants/{id}/domains/{domainId}`

#### Tab 3: Admins (`?tab=admins`)
- Table of `user_tenants` rows where `role='admin'`: full name, email, granted_at, revoke button
- "Add admin" button → user-search modal (email/name substring against `users` table); selecting a user upserts a row with `role='admin'`
- Revoke changes `role` to `'member'` (or whatever the prior value was, if persisted; otherwise default to `'member'`)

API:
- `GET /api/v1/backstage/platform/tenants/{id}/admins`
- `POST /api/v1/backstage/platform/tenants/{id}/admins` (body: `{user_id}`)
- `DELETE /api/v1/backstage/platform/tenants/{id}/admins/{userId}`

#### Tab 4: Modules (`?tab=modules`)
- Lists every non-core manifest. For each:
  - Slug, translated name, translated description, category badge
  - State chip: Available / Not available (green / grey)
  - Toggle button: "Grant" or "Revoke"
  - Dependency tag: "Requires: <other-slug>" if applicable; the Grant button is disabled if any dependency is not available

- Revoke confirmation dialog displays:
  - Which modules are currently `enabled` and will be force-`disabled` by the cascade
  - A required `reason` text field (audit log)
  - "Revoke" submit + "Cancel" buttons

API:
- `GET /api/v1/backstage/platform/tenants/{id}/modules` — combines manifest data with `tenant_modules` rows
- `POST /api/v1/backstage/platform/tenants/{id}/modules/{slug}/availability` — body `{action: 'grant'|'revoke', reason?: string}`

#### Tab 5: Danger zone (`?tab=danger`)
- Suspend tenant — confirm dialog with required `reason`. Sets `status='suspended'`, `suspended_at=NOW()`, `suspended_reason=<reason>`. Public domain returns 503; backstage login shows "tenant suspended" page.
- Reactivate tenant — confirm dialog. Clears suspension fields, restores `status='active'`.
- Hard-delete is **explicitly out of scope** — the section says so in the UI, with a placeholder "GDPR-compliant deletion: future feature."

API:
- `POST /api/v1/backstage/platform/tenants/{id}/suspend` (body: `{reason}`)
- `POST /api/v1/backstage/platform/tenants/{id}/reactivate`

### `/backstage/settings/modules` — tenant-admin view

Two lists on one page:

#### List 1: Enabled modules
- Slug, translated name, translated description, "Enabled since" timestamp
- Disable button. Disabled if any other enabled module declares this as a dependency; UI shows blocking modules in a tooltip and disables the action.

#### List 2: Available, not yet enabled
- Slug, translated name, translated description, "Granted by GSA at" timestamp
- Activate button. Disabled if any unmet dependency exists; UI lists which dependencies must be enabled first.

API:
- `GET /api/v1/backstage/tenant/modules` — returns all module states for the current tenant resolved from request context
- `POST /api/v1/backstage/tenant/modules/{slug}/state` — body `{action: 'enable'|'disable'}`

### Page rendering pattern

Follows the existing backstage convention:
- `public/backstage/pages/_shared.php` — header, sidebar, layout, theme
- New `public/backstage/pages/platform/` directory for GSA pages: `tenants/index.php`, `tenants/edit.php`
- Settings page extension: `public/backstage/pages/settings/modules.php`
- JS uses the existing fetch-plus-toasts pattern (`toasts.js`, dashboard module). No new framework.

## 15. Default public site

### Routing

Apache vhost for any new tenant points its document root at `c:/laragon/www/daems-platform/public/`. The new front controller `public/sites-router.php` (or equivalently, an addition to the existing front-controller logic in `public/index.php`):

1. Resolves the tenant from the host header against `tenant_domains` (existing `TenantSlugResolverInterface`).
2. Checks for `c:/laragon/www/sites/{slug}/public/index.php` on disk.
3. If found — delegate via `require` (same shape as backstage delegation in `c:/laragon/www/sites/daem-society/public/index.php`).
4. Otherwise — delegate to the default site at `public/sites/_default/index.php`, with the resolved `Tenant` available via `TenantContextMiddleware`.

Backstage delegation is unaffected. `/backstage/*` always serves from the platform.

### File layout

```
public/sites/_default/
├── index.php                # Home — uses Tenant displayName/publicDescription
├── join.php                 # Join form
├── login.php                # Redirect helper to /backstage/login
├── suspended.php            # 503 page when tenants.status='suspended'
├── partials/
│   ├── header.php
│   ├── footer.php
│   └── join-form.php
├── assets/
│   ├── default.css
│   └── default.js
└── lang/
    ├── fi_FI.php
    ├── en_GB.php
    └── sw_TZ.php
```

The `lang/` files inside `_default/` hold keys local to the public site (e.g., `default.home.welcome`, `default.join.submit`, `default.suspended.title`). Module names and category labels reuse the platform-level `lang/<locale>.php` files.

### Behaviour

- **Home (`/`)** — banner with `Tenant::displayName($locale)`, paragraph from `Tenant::publicDescription($locale)`, "Join" CTA, "Login" link. Locale switcher in the header iterates `Tenant::supportedLocales()`. No module data on the public side in MVP.
- **Join (`/join`)** — basic application form. Submits to the existing `/api/v1/applications` endpoint, creating an `applications` row. Acknowledgement page on success.
- **Login (`/login`)** — 302 redirect to `/backstage/login`.
- **Suspended state** — when `Tenant::suspended()` is true, every public route returns HTTP 503 with the suspended page. Backstage routes show a similar "tenant suspended" page on the login screen and prevent further access until reactivated.

### Membership type selection in join form

The join form's "membership type" UI uses the simplified set the platform supports today (the `applications` table predates the four-tier model in the MembershipCore v2 spec). Specifically: a single radio between *member application* and *supporter application*, mirroring the current daem-society public site's join flow. The richer four-tier flow (SUPPORTING/BASIC/FULL/HONORARY with the 12-month rule) lands with MembershipCore v2.

## 16. i18n keys

### `lang/<locale>.php` additions

```php
// Module names & descriptions
'modules.settings.name'       => 'Settings',
'modules.settings.description'=> 'Tenant configuration and module activation.',
'modules.search.name'         => 'Search',
'modules.search.description'  => 'Cross-module search with full-text indexing.',
'modules.members.name'        => 'Members',
'modules.members.description' => 'Association membership registry and applications.',
'modules.events.name'         => 'Events',
'modules.events.description'  => 'Event creation, scheduling, and registration.',
'modules.projects.name'       => 'Projects',
'modules.projects.description'=> 'Project tracking and member collaboration.',
'modules.forum.name'          => 'Forum',
'modules.forum.description'   => 'Discussion forums with moderation.',
'modules.insights.name'       => 'Blog',
'modules.insights.description'=> 'Editorial blog posts and announcements.',

// Categories
'modules.category.core'       => 'Core',
'modules.category.members'    => 'Members',
'modules.category.community'  => 'Community',
'modules.category.governance' => 'Governance',
'modules.category.content'    => 'Content',

// Platform group
'platform.tenants.title'              => 'Tenants',
'platform.tenants.create'             => 'New tenant',
'platform.tenants.tab.basics'         => 'Basics',
'platform.tenants.tab.domains'        => 'Domains',
'platform.tenants.tab.admins'         => 'Admins',
'platform.tenants.tab.modules'        => 'Modules',
'platform.tenants.tab.danger'         => 'Danger zone',
'platform.tenants.suspend.confirm'    => 'Confirm tenant suspension',
'platform.tenants.reactivate.confirm' => 'Confirm tenant reactivation',
'platform.modules.grant.confirm'      => 'Grant module to tenant',
'platform.modules.revoke.confirm'     => 'Revoke module availability',
'platform.modules.cascade_warning'    => 'These currently-enabled modules will be disabled:',
'platform.modules.requires'           => 'Requires: %s',

// Settings → Modules
'settings.modules.title'     => 'Modules',
'settings.modules.enabled'   => 'In use',
'settings.modules.available' => 'Available, not activated',
'settings.modules.activate'  => 'Activate',
'settings.modules.deactivate'=> 'Deactivate',
'settings.modules.deps_unmet'=> 'Activate first: %s',
'settings.modules.dependents_block'=> 'Cannot disable — required by: %s',

// Default public site
'default.home.welcome'       => 'Welcome to %s',
'default.home.cta_join'      => 'Join us',
'default.home.cta_login'     => 'Sign in',
'default.join.title'         => 'Join %s',
'default.join.submit'        => 'Submit application',
'default.join.success'       => 'Your application has been submitted.',
'default.suspended.title'    => 'Site temporarily unavailable',
'default.suspended.body'     => 'This site is temporarily suspended. Please check back later.',
```

All keys ship in fi_FI, en_GB, and sw_TZ. en_GB is the canonical source; the other two are translated to match.

## 17. Testing strategy

### Unit (`tests/Unit/`)

- `ModuleManifestTest` — value object construction, immutability, validation messages
- `ModuleRegistryTest` — slug uniqueness, cycle detection, route overlap rejection, missing dependency rejection
- `TenantModuleResolverTest` — every state transition (manifest absent, is_core, no row, available no enabled, available + enabled, suspended tenant)
- `ModuleRouteGuardTest` — longest-prefix matching, is_core bypass, disabled → NOT_FOUND, unknown route → ALLOW
- `BackstageSidebarTest` — group ordering, locale-translated labels, hardcoded shell items, platform-group GSA-only visibility
- `TenantTest` — new field accessors, fallback to `name`, suspended state
- `LocaleNegotiatorTest` — re-verify under new `DEFAULT_LOCALE = 'en_GB'`

### Integration (`tests/Integration/`)

- `SqlTenantModulesRepositoryTest` — CRUD, uniqueness constraint, cascade on tenant delete
- `SqlModuleAuditRepositoryTest` — insert and listing
- `SqlTenantRepositoryExtensionTest` — read/write of new fields, JSON round-trip for `display_name_i18n` and `public_description_i18n`
- `SqlTenantDomainRepositoryTest` — primary uniqueness per tenant, last-primary-delete blocked
- `Migration069Test`, `Migration070Test`, `Migration071Test`, `Migration072Test` — each migration applies cleanly to a fresh DB and the chain through 072 leaves the existing tenants with the expected backfilled `tenant_modules` rows

### Isolation (`tests/Isolation/`)

`IsolationTestCase`'s migration high-water mark is bumped to 072. New `TenantModulesIsolationTest` verifies:
- Tenant A's `tenant_modules` rows do not leak into Tenant B's `GET /api/v1/backstage/tenant/modules` response
- A GSA user can read every tenant's module list across tenant boundaries
- Tenant B's admin cannot toggle Tenant A's modules — request returns HTTP 403

### E2E (`tests/E2E/`)

- `BackstagePlatformTenantsE2ETest` — full GSA flow: create tenant, add domain, add admin, grant module availability, revoke availability with cascade verification
- `BackstageTenantModulesE2ETest` — tenant-admin enable/disable + dependency lock + dependent block
- `ModuleRouteGuardE2ETest` — disabled module URL returns 404 even before auth check; enabled module URL returns the expected content
- `DefaultPublicSiteE2ETest` — home page renders with tenant name and description; join form posts an `applications` row; suspended tenant returns 503

### Manual smoke (recorded in CLAUDE.md, run before merge)

1. GSA creates tenant `testi-yhdistys`.
2. GSA adds domain `testi.local`; configures Apache vhost pointing to platform.
3. Visit `http://testi.local/` → see default home page with the tenant's name.
4. Click Join, submit form, see success page.
5. Verify the application landed in the `applications` table with the correct `tenant_id`.
6. GSA revokes Forum availability from `daems`. Verify cascade audit row.
7. `daems.local/backstage/forum` returns 404. Sidebar no longer shows Forum.
8. GSA re-grants Forum. `daems` admin enables it from Settings → Modules. Sidebar shows Forum, route returns content.
9. Suspend `testi-yhdistys`. Public domain returns 503. Reactivate. Verify recovery.

### Performance sanity

- `ModuleRegistry` boot < 50ms on local dev machine
- `TenantModuleResolver::statesForTenant()` < 5ms with 8 modules and a warm MySQL connection

These are not strict gates — they are values to record and inspect during testing. Regression beyond 2× either threshold is a flag for review.

## 18. Migration deployment plan

All changes ship in **one PR** because the parts are interdependent and partial deployment leaves the system in inconsistent states:

1. Migrations 069–072 (tables + extensions + backfill).
2. Manifests in `config/modules/` for the seven non-dashboard modules.
3. Domain layer (Registry, Resolver, RouteGuard, value objects, exceptions).
4. Application layer (use cases and controllers, both Platform and Tenant scopes).
5. Infrastructure (SQL repositories, InMemory fakes for tests).
6. DI bindings in `bootstrap/app.php` and `tests/Support/KernelHarness.php`.
7. Backstage shell integration (sidebar builder, router guard, api-router guard).
8. UI pages (`public/backstage/pages/platform/`, `public/backstage/pages/settings/modules.php`).
9. Default public site (`public/sites/_default/`, `public/sites-router.php` integration).
10. `I18n::DEFAULT_LOCALE` flip and `lang/*.php` additions.
11. Tests across all four levels.
12. CLAUDE.md and memory updates (`project_module_registry.md`, `project_default_public_site.md`, `project_i18n_milestone.md` correction).

Estimated commit count: 30–50, on the `module-registry-tenant-mgmt` branch off `dev`. Final review and merge happens after the manual smoke list passes.

## 19. CLAUDE.md updates

Sections to add or modify:

- **Architecture** subsection: add a paragraph on module manifests and the registry-resolver-guard split.
- **Adding a new module** recipe: step-by-step (create `config/modules/<slug>.php`, add lang keys, add `tenant_modules` rows for existing tenants if `default_available=true`, wire DI for any module-internal services).
- **Default public site** subsection: explain when it kicks in and how to override per-tenant.
- **i18n notes**: replace "UI chrome default = fi_FI" with "UI chrome default = en_GB; tenants override via `tenants.default_locale`. Daem Society tenant runs in `fi_FI`."
- **DI wiring — BOTH containers** warning: keep as-is; the new controllers and use cases are added to the listed gotcha-prone items.

## 20. Memory updates

- `project_module_registry.md` (new) — implementation status, manifest format pointer, key-design decisions log.
- `project_default_public_site.md` (new) — what triggers the fallback, how to override per-tenant.
- `feedback_module_manifests_are_truth.md` (new) — durable instruction: manifest is canonical, DB is cache, do not invert this.
- `project_i18n_milestone.md` — correction: platform default is now `en_GB`; Daem Society tenant overrides to `fi_FI`.

## 21. Acceptance criteria

The implementation is complete only when **all** of the following hold:

1. Migrations 069–072 apply cleanly to a fresh test DB and to a copy of the current production schema.
2. `ModuleRegistry` loads all seven module manifests and rejects malformed manifests with `ModuleManifestException` (covered by tests).
3. `TenantModuleResolver` returns the correct `ModuleState` for every input combination (covered by tests).
4. A direct request to a disabled module's URL (`/backstage/forum` or `/api/v1/backstage/forum/threads`) returns HTTP 404 **before** the auth layer, leaking no information about module state to anonymous visitors.
5. The sidebar hides disabled modules; `AVAILABLE_NOT_ENABLED` modules surface only on `/backstage/settings/modules`.
6. GSA can: create a tenant, add a domain, assign an admin, grant a module, revoke a module (with cascade and audit row); all with no SQL.
7. Tenant admin can: enable a module from the available list and disable an enabled module, both subject to dependency rules.
8. Cascade: revoking availability of a currently-enabled module force-disables it in the same transaction and writes audit rows for the cascade.
9. Dependency locks: cannot enable A while its dependency B is disabled; cannot disable B while a dependent A is enabled.
10. A new tenant with no `c:/laragon/www/sites/{slug}/` directory serves a working default public site (home + join + login + footer) with the tenant's display name and description from the DB.
11. Suspending a tenant: the public domain returns HTTP 503 with the suspended page; the backstage login refuses access with a "tenant suspended" message; reactivation restores normal behaviour.
12. `I18n::DEFAULT_LOCALE` is `'en_GB'`. Anonymous users hitting backstage login default to English UI; explicit `Accept-Language` headers and tenant defaults still resolve correctly.
13. PHPStan level 9 reports zero errors.
14. All four test levels pass: Unit, Integration, Isolation, E2E.
15. The manual smoke sequence in §17 has been run and recorded.

## 22. Open questions for the plan

These are intentionally not decided in this spec — they belong in the plan/execution phase:

- Whether `072_seed_tenant_modules` ships as a `.php` file in `database/migrations/` or as a post-migration command in `composer.json`. Both work; the migration runner's existing behaviour decides.
- Specific Apache vhost template for the default public site fallback. Documentation-only; not blocking.
- The shape of the GSA "user-search" component for the Admins tab — autocomplete vs. paginated picker. UX decision at plan time after looking at how the Members admin does user search.
- Whether the `module_audit` table grows fast enough to justify partitioning or retention policy. Defer until measured.
