# Dashboard Widgets & Per-User Customization — Design

**Date:** 2026-05-10
**Branch target:** `dashboard-widgets` (new, off `dev`)
**Status:** Design — pending review

## Problem

The current backstage dashboard ([public/backstage/pages/index.php](../../../public/backstage/pages/index.php)) renders identical content for every user:

- 4 hardcoded KPI cards (Members, Pending Applications, Upcoming Events, Active Projects)
- 2 charts (Member Growth, Platform Activity)

This ignores three real differences:

1. **Role** — a GSA cares about cross-tenant overview, a tenant admin about their own organisation, a moderator about the moderation queue. The current dashboard serves the tenant admin best and is wasteful for the other two.
2. **Modules** — `config/modules.php` allows tenants to enable/disable Events, Forum, Insights, etc., but the dashboard hardcodes Events and Projects KPIs regardless.
3. **Personal preference** — there is no way to hide irrelevant cards or focus on specific data.

## Goal

Replace the static dashboard with a widget system where:

- A **catalog** of typed widgets (KPIs, charts, lists, quick actions, activity feed, platform-only) is registered by the core platform and contributing modules.
- Each role has a **default layout** that is sensible out of the box.
- Disabled modules' widgets are silently filtered out of both the layout and the catalog.
- Users enter an **edit mode** on the dashboard to drag-drop reorder, hide widgets, or add new ones from the catalog.
- Customisations persist per (user × tenant) so an admin in two tenants can keep separate layouts.

## Non-goals (deferred to v2)

- Tenant-level default override (a tenant admin setting a default for everyone in their tenant). The schema reserves space for this without committing to it.
- Free width resize per widget — widgets keep their declared `default_span`.
- Horizontal reorder within a row beyond what auto-flow gives us (vertical reorder only in v1).
- Cross-module widget dependencies (e.g., a widget that pulls data from two modules).
- Full module catalog — v1 ships the widgets required for all three role defaults to render correctly (see widget table below). Catalog-only widgets (`forum.activity_chart`, `insights.*`, `projects.recent_proposals_list`) are deferred to follow-up commits — they reuse the same widget contract.

## Architecture

### Layered components (Clean Architecture)

```text
Domain/Dashboard/
  Widget.php                              abstract — id, category, default_span,
                                          min_role, module, render(), data()
  WidgetRegistry.php                      collects + filters widgets by role/modules
  UserDashboard.php                       entity (user_id, tenant_id, layout)
  UserDashboardRepositoryInterface.php    port

Application/Dashboard/
  GetUserLayout.php                       resolves saved layout or role default
  SaveUserLayout.php                      validates + persists user customisation
  ResetUserLayout.php                     deletes user row → fall back to default
  ListCatalog.php                         filtered widget catalog for current user

Infrastructure/Dashboard/
  SqlUserDashboardRepository.php          MySQL implementation
  InMemoryUserDashboardRepository.php     for KernelHarness E2E tests

  CoreWidgets/                            platform-owned, always available
    MembersKpiWidget.php                    (core.members_kpi, span 1)
    ApplicationsKpiWidget.php               (core.applications_kpi, span 1)
    MemberGrowthChartWidget.php             (core.member_growth_chart, span 3)
    PlatformActivityChartWidget.php         (core.platform_activity_chart, span 3 — catalog only, not in default)
    QuickActionsWidget.php                  (core.quick_actions, span 1)
    PendingAppsListWidget.php               (core.pending_apps_list, span 2)
    ActivityFeedWidget.php                  (core.activity_feed, span 2 — single widget, data() returns
                                             role-appropriate events: admin sees membership/content events,
                                             moderator sees moderation events, GSA sees cross-tenant events)

  PlatformWidgets/                        GSA-only (min_role: 'gsa', module: 'platform')
    TenantsKpiWidget.php                    (platform.tenants_kpi, span 1)
    PlatformUsersKpiWidget.php              (platform.users_kpi, span 1)
    DbSizeKpiWidget.php                     (platform.db_size_kpi, span 1)
    UptimeKpiWidget.php                     (platform.uptime_kpi, span 1)
    TenantStatusGridWidget.php              (platform.tenant_status_grid, span 4)
    TenantActivityChartWidget.php           (platform.tenant_activity_chart, span 2)

Frontend/Dashboard/
  DefaultLayouts.php                      static role → layout array map
  WidgetRenderer.php                      ties Widget::render() to the grid HTML
```

### Module-contributed widgets (registered via each module's `bindings.php`)

Modules ship their own widgets; the resolver filters them out when the module is disabled on the active tenant.

| Module | Widget id | Used in default for | Span | Status in v1 |
|--------|-----------|---------------------|------|--------------|
| events | `events.events_kpi` | admin | 1 | shipped |
| events | `events.upcoming_list` | admin | 2 | shipped |
| projects | `projects.projects_kpi` | admin | 1 | shipped |
| forum | `forum.reports_kpi` | moderator | 1 | shipped |
| forum | `forum.posts_today_kpi` | moderator | 1 | shipped |
| forum | `forum.flagged_users_kpi` | moderator | 1 | shipped |
| forum | `forum.pinned_topics_kpi` | moderator | 1 | shipped |
| forum | `forum.reports_queue` | moderator | 4 | shipped |
| forum | `forum.recent_posts_list` | moderator | 2 | shipped |
| forum | `forum.activity_chart` | catalog only | 2 | deferred (post-v1) |
| insights | `insights.recent_list` | catalog only | 2 | deferred (post-v1) |
| insights | `insights.published_kpi` | catalog only | 1 | deferred (post-v1) |
| projects | `projects.recent_proposals_list` | catalog only | 2 | deferred (post-v1) |

The "shipped" widgets are required for the default layouts of admin/moderator/GSA to render correctly. The "deferred" widgets follow the same pattern and are added in subsequent commits — implementation is mechanical once the framework is in place.

### Widget contract

```php
abstract class Widget {
    public function id(): string;                 // e.g. 'core.members_kpi'
    public function category(): string;           // numbers | lists | charts | actions | activity | platform
    public function defaultSpan(): int;           // 1 | 2 | 3 | 4
    public function minRole(): string;            // member | moderator | admin | gsa
    public function module(): ?string;            // null = core, otherwise module slug
    public function labelKey(): string;           // i18n key for catalog display
    public function descriptionKey(): string;     // i18n key for catalog tooltip
    abstract public function render(TenantId $t, User $u): string; // HTML
    abstract public function data(TenantId $t): array;             // JSON for charts/lists
}
```

Modules register widgets in their own `bindings.php` via the platform-provided `WidgetRegistry`:

```php
$registry = $container->make(WidgetRegistry::class);
$registry->register(new ForumReportsKpiWidget(...));
$registry->register(new RecentForumPostsListWidget(...));
```

Core widgets are registered in `bootstrap/app.php` after module discovery completes.

### Layout structure

A layout is an ordered list of widget instances:

```json
[
  {"widget_id": "core.members_kpi",         "span": 1},
  {"widget_id": "core.applications_kpi",    "span": 1},
  {"widget_id": "core.events_kpi",          "span": 1},
  {"widget_id": "core.projects_kpi",        "span": 1},
  {"widget_id": "core.member_growth_chart", "span": 3},
  {"widget_id": "core.quick_actions",       "span": 1},
  {"widget_id": "core.pending_apps_list",   "span": 2},
  {"widget_id": "core.activity_feed",       "span": 2}
]
```

`span` is stored explicitly so a future v2 can offer per-instance overrides without a schema change.

### Resolution order at request time

`GetUserLayout` produces the final list to render:

1. Look up `user_dashboards` row for `(user_id, tenant_id)`. If present → use its `layout`.
2. Otherwise → use `DefaultLayouts::for($role)`.
3. Filter: drop widgets whose `module` is not enabled for the active tenant.
4. Filter: drop widgets whose `min_role` exceeds the user's effective role.
5. Hydrate each entry into a `Widget` instance via `WidgetRegistry::find($id)`.

## Default layouts per role

### Tenant Admin (default)

- Row 1 (4×span-1): Members KPI, Applications KPI, Events KPI, Projects KPI
- Row 2 (span-3 + span-1): Member Growth chart, Quick Actions
- Row 3 (span-2 + span-2): Pending Applications list, Activity Feed

### Moderator (default)

- Row 1 (4×span-1): Forum Reports KPI, Posts Today KPI, Flagged Users KPI, Pinned Topics KPI
- Row 2 (span-4): Forum Reports queue
- Row 3 (span-2 + span-2): Recent Forum Posts, Activity Feed

### GSA (default)

- Row 1 (4×span-1): Tenants KPI, Platform Users KPI, DB Size KPI, Uptime KPI
- Row 2 (span-4): Tenant status grid
- Row 3 (span-2 + span-2): Tenant activity chart, Cross-tenant Activity Feed

GSA-specific widgets (`module: 'platform'`, `min_role: 'gsa'`) are reserved for GSA defaults but appear in the catalog for any GSA logged in. They never appear in non-GSA catalogs.

## Database schema

New migration adds one table:

```sql
CREATE TABLE user_dashboards (
  id            BINARY(16)    PRIMARY KEY,
  user_id       BINARY(16)    NOT NULL,
  tenant_id     BINARY(16)    NOT NULL,
  layout        JSON          NOT NULL,
  updated_at    DATETIME      NOT NULL,
  UNIQUE KEY uniq_user_tenant (user_id, tenant_id),
  KEY idx_tenant (tenant_id),
  CONSTRAINT fk_user_dashboards_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_dashboards_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

Empty table = every user sees the role default. The first save creates the row; reset deletes it.

## API contract

All endpoints sit under `/api/v1/backstage/dashboard/*` and require the standard backstage auth (admin, moderator, or GSA on the active tenant).

```text
GET    /dashboard/layout
       → { layout: [...], is_default: bool, role: 'admin'|'moderator'|'gsa' }

PUT    /dashboard/layout
       Body: { layout: [{ widget_id, span }, ...] }
       Validates: widget exists in catalog, span ∈ [1,4], user has access.
       400 if any widget_id unknown or out of role/module reach.
       Returns 204 on success.

DELETE /dashboard/layout
       Resets — deletes the row. 204 on success (idempotent).

GET    /dashboard/catalog
       → [
           {
             widget_id,
             label, description,
             category, default_span,
             module,
             in_layout: bool,         // already on user's current layout
             locked_reason: string?   // e.g. "requires GSA role" — null if available
           },
           ...
         ]

GET    /dashboard/widget/{id}/data
       → { ...widget-specific shape, e.g. { value, change, sparkline } for KPI,
           or { labels, series } for charts }
```

KPI cards may later be batched via `GET /dashboard/widgets-data?ids=a,b,c`; v1 ships individual endpoints for simplicity.

## Frontend

[public/backstage/pages/index.php](../../../public/backstage/pages/index.php) is rewritten:

1. PHP fetches the resolved layout (use case `GetUserLayout`) and renders each widget's HTML server-side via `Widget::render()`.
2. The grid uses CSS Grid with `grid-template-columns: repeat(4, 1fr)`. Each widget cell sets `grid-column: span N` based on its `span`.
3. A pencil button in the page header toggles edit mode (URL param `?edit=1` for shareability).
4. In edit mode, JS adds drag handles (`⋮⋮`) and hide buttons (`✕`) to each widget. Bottom sentinel becomes "+ Add widget" button.
5. **SortableJS** (~25 KB, MIT, vendored at `public/backstage/assets/js/vendor/sortable.min.js`) handles drag-drop. On drop, the new layout posts to `PUT /dashboard/layout`.
6. Catalog modal is a new component reusing the existing modal pattern (events-admin / projects-admin). It hits `GET /dashboard/catalog`, groups by category, and shows already-added widgets dimmed with a checkmark.
7. Done button exits edit mode (drops `?edit=1`); Cancel reverts UI state to pre-edit (no API call needed since changes posted incrementally).
8. Reset link (lower-left in edit mode) calls `DELETE /dashboard/layout` after a confirm dialog.

## Module integration

Modules contribute widgets by:

1. Implementing `Widget` subclasses in their own `src/Frontend/Backstage/Widgets/` directory.
2. Calling `$registry->register(new MyWidget(...))` in `bindings.php`.

v1 ships all module widgets needed for the role defaults to render — see the "Module-contributed widgets" table earlier in this spec. The deferred widgets in that table use the same contract and are added in follow-up commits.

## Validation rules

`SaveUserLayout` rejects requests where:

- `layout` is not an array, or any item is missing `widget_id` / `span`.
- `widget_id` is not in `WidgetRegistry`.
- The widget's `min_role` exceeds the user's effective role.
- The widget's `module` is not enabled for the active tenant.
- `span` is outside `[1, 4]`.
- The same `widget_id` appears more than once in the layout.

Domain exception → 400 with structured error body.

## Migration of current dashboard

- The 4 current KPIs split between core (members, applications) and modules (events, projects). Their data sources are unchanged; only the framing moves into per-widget `data()` methods.
- The Member Growth chart becomes `core.member_growth_chart`. The Platform Activity chart is dropped from the v1 admin default but stays in the catalog as `core.platform_activity_chart` for users who want it back.
- `/backstage/stats` is decomposed: each widget owns its data fetch via `Widget::data()`. The endpoint can be removed once nothing else uses it (grep first; if anything outside the dashboard does, leave it for a separate cleanup).
- Existing users start with no `user_dashboards` row → see the new tenant-admin default. No data loss because nothing was persisted before.

## Testing

| Suite | Coverage |
|-------|----------|
| Unit | `WidgetRegistry` register/find/filter, `DefaultLayouts::for(role)`, `GetUserLayout` (with InMemoryRepo across all 4 resolution branches), `SaveUserLayout` (validation rejection cases), `ResetUserLayout` |
| Integration | `SqlUserDashboardRepository` CRUD against MySQL via `MigrationTestCase` |
| Isolation | `UserDashboardIsolationTest` — tenant A's user X cannot read or write tenant B's user X layout |
| E2E | All 4 endpoints via `KernelHarness` — happy path, validation rejection, GSA-only widget for non-GSA, disabled-module widget filter |
| Static | PHPStan level 9 with 0 errors |
| i18n | New keys in fi_FI/en_GB/sw_TZ for widget labels, descriptions, edit-mode chrome |

## DI wiring (BOTH containers)

Each new class is bound in:

- `bootstrap/app.php` — production container
- `tests/Support/KernelHarness.php` — test container with InMemory variants

This is the [feedback_bootstrap_and_harness_must_both_wire](file:///C:/Users/Sam/.claude/projects/c--laragon-www-daems-platform/memory/feedback_bootstrap_and_harness_must_both_wire.md) rule. Skipping the test container leaves prod broken; skipping prod leaves the live server broken even though E2E tests pass.

## Out-of-scope tickets created in passing

When implementing, log these as backlog items, do not address inline:

- Tenant-default override (v2)
- Per-instance widget span override (v2)
- Free 2D drag-drop within a row (v2)
- Remaining module widget contributions (separate commits after v1)
