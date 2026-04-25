# Forum Redesign — Phase 2 of Backstage Modernization

**Date:** 2026-04-25
**Branch target:** `dev`
**Phase 2 of:** Iterative backstage modernization
**Phase 2 scope:** Convert `/backstage/forum` from a 4-tab single-page admin into a sub-page model with a dashboard landing page (4 clickable KPI cards) and 4 dedicated sub-pages (`/reports`, `/topics`, `/categories`, `/audit`). Reuses Phase 1's design system primitives — no new components introduced.

## 1. Background

The current `/backstage/forum` page is 1191 LOC across `index.php` (403), `forum.js` (343), `forum-modal.js` (399), and `forum.css` (46). It uses a custom tab-switcher (`proj-tabs` / `proj-tab-content`) with four tabs:
- **Reports** — moderation queue, card-based list of compound reports
- **Topics** — moderation table for forum topics (pin / lock / delete)
- **Categories** — CRUD list of forum categories
- **Audit** — read-only log of moderation actions

Phase 1 (insights pilot, shipped 2026-04-25) established the shared design system primitives: `kpi-card`, `kpis-grid`, `data-explorer__panel`, `slide-panel`, `confirm-dialog`, `pill`, `empty-state`, `skeleton-row`, `error-state`, `btn` variants. They live in `assets/css/daems-backstage-system.css` + `assets/js/daems-backstage-system.js`. Phase 2 is the *first consumer beyond insights* and will validate that the system holds at richer scale.

## 2. Visual direction (inherited from Phase 1)

Same. The system specifies it; Phase 2 follows verbatim.

- Linear/Notion data-density (hairline borders, hover-row tint, action-icons reveal-on-hover).
- Stripe-style KPI cards with ApexCharts area sparklines for the dashboard.
- **Hard constraint:** no `transform: translate*` / `scale*` on hover for any tile/row/card. Border-color, background-color, opacity are the only allowed hover affordances.
- Edit / detail flows use slide-panel from the right.
- Yes/no decisions use centered confirm-dialog.

## 3. Routing

| URL | Purpose |
|-----|---------|
| `/backstage/forum` | Dashboard — 4 clickable KPI cards + a "Recent activity" card pulling 5 most-recent audit rows |
| `/backstage/forum/reports` | Reports moderation page |
| `/backstage/forum/topics` | Topics moderation page |
| `/backstage/forum/categories` | Categories CRUD page |
| `/backstage/forum/audit` | Audit log (read-only) |

The sidebar `Forum` link continues to point at `/backstage/forum` (the dashboard). The dashboard's KPI cards are the entry points to the sub-pages — sidebar nav structure does not expand.

The legacy `/backstage/forum?tab=reports` URL parameter is silently ignored (the dashboard renders regardless of `?tab=`). No redirect is wired — internal links throughout the codebase are updated to point at the new sub-page paths.

`public/index.php` (front controller) needs four new path matches added to dispatch the sub-pages. The existing `/backstage/forum` match continues to dispatch the dashboard.

## 4. Landing dashboard — `/backstage/forum`

### Layout

```
┌──────────────────────────────────────────────────────┐
│ Forum                                                │
│ Moderate reports, topics, and categories.            │
├──────────────────────────────────────────────────────┤
│ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐     │
│ │ Open    │ │ Topics  │ │ Cat-    │ │ Mod     │     │
│ │ reports │ │         │ │ egories │ │ actions │     │
│ │   7  ⤴ │ │  142  ⤴ │ │   8  ⤴ │ │  23  ⤴ │     │
│ │ ▁▂▁▃▄  │ │ ▂▃▂▄▅  │ │ —      │ │ ▁▁▂▁▃  │     │
│ └─────────┘ └─────────┘ └─────────┘ └─────────┘     │
│                                                      │
│ Recent activity                                      │
│ 5 most recent audit entries (chrome-only card)       │
└──────────────────────────────────────────────────────┘
```

Each KPI card uses the existing `.kpi-card.kpi-card--clickable` variant (border-color hover, top-right `→` arrow). Clicking the card navigates to the sub-page.

### KPIs

| KPI | `kpi_id` | Linkki | Definition | Sparkline | `icon_variant` |
|-----|----------|--------|-----------|-----------|----------------|
| Open reports | `open_reports` | `/reports` | `forum_reports` count where `status = 'open'` for tenant | Daily new-report count, last 30 days | `amber` |
| Topics | `topics` | `/topics` | `forum_topics` count for tenant | Daily new-topic count, last 30 days | `blue` |
| Categories | `categories` | `/categories` | `forum_categories` count (categories are tenant-shared via existing structure — verify scoping during implementation) | Empty array (categories rarely change; render an empty sparkline gracefully) | `gray` |
| Mod actions (30d) | `mod_actions` | `/audit` | `forum_moderation_audit` count where `created_at >= NOW() - INTERVAL 30 DAY` | Daily mod-action count, last 30 days | `green` |

Sparkline shape per KPI mirrors Phase 1: `[{date: 'YYYY-MM-DD', value: int}, ...30]`. Empty sparkline (categories) renders the kpi-card without crashing — the `Sparkline.init` helper already returns early when `dataPoints.length === 0`.

### Recent activity card

Below the KPI grid, a regular `.card` (existing primitive) with header `Recent activity` and 5 most-recent rows from `forum_moderation_audit` for the tenant: `{when} · {actor} · {action} · {target_type} {target_id_short}`. Below the list, a "View all" text link to `/audit`.

### Backend support

Single new endpoint `GET /api/v1/backstage/forum/stats` returns:

```json
{
  "data": {
    "open_reports": {"value": 7,   "sparkline": [{"date": "2026-03-26", "value": 1}, ...30]},
    "topics":       {"value": 142, "sparkline": [...]},
    "categories":   {"value": 8,   "sparkline": []},
    "mod_actions":  {"value": 23,  "sparkline": [...]},
    "recent_audit": [
      {"when": "2026-04-25T13:24:00Z", "actor_name": "Saija K.", "action": "deleted", "target_type": "post", "target_id": "abc..."},
      ...5
    ]
  }
}
```

`recent_audit` is included in the same payload to avoid a second round-trip from the dashboard.

## 5. Reports sub-page — `/backstage/forum/reports`

Reports are not row-data — they're compound objects: a target (post or topic) plus N reporter rows with reason + comment. Cards remain the right primitive. The redesign upgrades the visual language but keeps the card layout.

### Layout

```
[Page header]
[Toolbar: All / Open / Resolved / Dismissed segments | type select | search]
[Card list — one card per compound report]
```

The toolbar uses `.data-explorer__toolbar` (the segmented switcher classes) but the content area is **not** a `<table>` — it's a vertical list of cards. The toolbar pattern is reusable independent of whether the body is a table.

### Per-report card

```
┌─────────────────────────────────────────────────────┐
│ [Post-pill] 4 reports · open                14 May  │
│                                                     │
│ "Original post excerpt — first 240 chars..."        │
│ — by @author, posted 12 May                         │
│                                                     │
│ Reasons: [spam ×2] [harassment ×1] [other ×1]       │
│                                                     │
│                  [Resolve…] [Dismiss]               │
└─────────────────────────────────────────────────────┘
```

`pill--scheduled` for type-pill (Post / Topic). Reason chips use a small ad-hoc `.report-reason-chip` class (defined in this PR's CSS) — three or four reasons fit on one row at typical widths.

`Resolve…` opens slide-panel. `Dismiss` opens confirm-dialog.

### Slide-panel — Resolve report

The panel body shows full target content (full post body or topic title + first 5 posts), the list of reporter rows (name + reason + comment timestamp), and a 5-button action grid:

| Action | Behavior | Confirm? |
|--------|---------|----------|
| Delete content | DELETE on backend → marks report `resolved` with action='deleted' | Yes (danger confirm-dialog) |
| Lock topic | (only when target_type=topic) POST topic-lock → marks report `resolved` with action='locked' | Yes |
| Warn user | Optional reason text field → POST warn → resolved with action='warned' | Yes |
| Edit content | Inline content editor in panel → POST edit → resolved with action='edited' | Yes (review pre-save) |
| Dismiss | Closes report without action → marks `dismissed` | Yes |

When the user clicks one of the five action buttons in the panel, it inline-expands a focused form (e.g. "Why warn?" textfield, or the inline content editor). A `Cancel` button reverts to the action-grid view. A final `Apply` button triggers the confirm-dialog → POST → close panel → refresh list + KPIs.

Backend endpoints already exist for each resolution (`POST /backstage/forum/reports/{id}/resolve` with payload).

## 6. Topics sub-page — `/backstage/forum/topics`

Standard `.data-explorer__panel` + table.

### Toolbar
- Segmented: All / Pinned / Locked / Open
- Select: Category (dropdown of categories)
- Search

### Columns
- Title (with link to public-side topic in new tab)
- Category
- Author
- Replies (post count)
- Status pills — render up to two: `pinned` (purple `--featured`-style) and/or `locked` (amber `--archived`-style); else `open` muted
- Created (date)
- Actions (icon buttons, hover-revealed): Pin / Unpin (toggle), Lock / Unlock (toggle), View (external link), Delete

### Actions
- Pin/Unpin/Lock/Unlock → confirm-dialog ("Pin this topic?" / etc.) → POST → row updates inline (re-fetch single topic)
- View → opens `https://daem-society.local/forum/{category}/{topic-slug}` in new tab
- Delete → confirm-dialog (danger, "Topic and all its posts will be deleted") → POST → row removes + KPIs refresh

### Empty / Error / Loading
Phase 1 patterns: skeleton rows during load, illustrated empty-state when no topics, inline error card with retry on load failure. Empty-state SVG: a generic "discussion" illustration shipped in this PR.

## 7. Categories sub-page — `/backstage/forum/categories`

`forum_categories` schema: `id, slug, name, icon, description, sort_order`. (No `hidden` field — visibility is controlled by sort/inclusion.)

### Toolbar
- Search (filter by name/slug)
- `+ New category` button (right) → opens slide-panel in create mode

### Columns
- Name (with icon as inline emoji or icon-name)
- Slug
- Description (truncated to ~80 chars with ellipsis)
- Topic count (computed via `LEFT JOIN forum_topics`)
- Sort order
- Actions: Edit (✎) → slide-panel, Delete (trash) → confirm-dialog

### Slide-panel — Create / Edit
Form fields: `name` (text), `slug` (text, auto-generated from name on create), `icon` (text — user pastes an emoji or icon-name), `description` (textarea), `sort_order` (number).

Save POSTs to `POST /api/v1/backstage/forum/categories` (create) or `POST /api/v1/backstage/forum/categories/{id}` (update). Existing endpoints exist for both.

### Delete confirm
If category has topics, the dialog body warns: `"This category has {N} topics. Topics will be moved to '{default-category}'."` (or whatever the existing backend behavior is — verify during implementation; if backend rejects deletion of non-empty category, surface that error inline rather than letting the confirm-dialog complete).

## 8. Audit sub-page — `/backstage/forum/audit`

Read-only log. `.data-explorer__panel` + table.

### Toolbar
- Select: Action type (10 actions from the enum: `deleted`, `locked`, `unlocked`, `pinned`, `unpinned`, `edited`, `category_created`, `category_updated`, `category_deleted`, `warned`)
- Date-range select: `Last 7 days` / `Last 30 days` / `All time`
- Search by actor name (client-side filter on loaded rows; pagination is server-side)

### Columns
- When (`created_at`, formatted as `dd.mm.yyyy HH:mm`)
- Actor (performed_by → user name; if user deleted, show "(deleted user)")
- Action (pill: `deleted`/`warned`/etc., color per action category)
- Target type (Post / Topic / Category)
- Target id (last 6 chars; if target still exists, link to it)
- Reason (if present)

### Pagination
50 rows per page. Existing backend endpoint `GET /backstage/forum/audit` supports `?limit=` and `?offset=`. Add a simple "Load more" button at the bottom that increments offset.

No slide-panel (read-only). No confirm-dialog. Empty state: "No moderation actions in this period."

## 9. Backend changes (`daems-platform`)

### New use case

`src/Application/Forum/ListForumStats/ListForumStats.php` (+ Input + Output DTOs). Mirrors Phase 1's `ListInsightStats` shape:
- Constructor: `ForumRepositoryInterface` (or split: `ForumReportsRepository`, `ForumTopicsRepository`, `ForumCategoriesRepository`, `ForumAuditRepository` if those exist as separate interfaces — verify during planning)
- Returns 4 KPI structures + 5-row recent_audit list

### Repository methods

Add `statsForTenant(TenantId): array` returning the documented structure to whichever forum repo interface(s) own the data. If multiple repos exist (reports / topics / categories / audit each have their own), then the use case calls each and assembles the result — and a thin `ForumStatsRepository` could wrap them. Decide during planning based on what's already there.

### Controller method

`BackstageController::statsForum(Request): Response` — admin guard via `requireForumAdmin()` (verify the helper name; same pattern as `requireInsightsAdmin`).

### Route

`GET /api/v1/backstage/forum/stats` placed alongside existing forum routes.

### DI wiring

BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php` (memory rule).

### Testing

- Unit test for `ListForumStats` use case (anonymous fake of the repo interface)
- Integration test for the SQL stats method (per repo or in one test file, matching the implementation choice above)
- Tenant-isolation test (mirror Phase 1's pattern)
- E2E HTTP test for `/backstage/forum/stats` (admin gate + payload shape)

PHPStan level 9 must remain at 0 errors.

## 10. Frontend changes (`daem-society`)

### New files

| Path | Purpose |
|------|---------|
| `pages/backstage/forum/index.php` | (Replaces existing) — dashboard layout |
| `pages/backstage/forum/reports/index.php` | Reports sub-page |
| `pages/backstage/forum/topics/index.php` | Topics sub-page |
| `pages/backstage/forum/categories/index.php` | Categories sub-page |
| `pages/backstage/forum/audit/index.php` | Audit sub-page |
| `pages/backstage/forum/forum-dashboard.js` | KPI sparkline init + recent-activity render |
| `pages/backstage/forum/forum-reports-page.js` | Reports list + slide-panel resolve flow |
| `pages/backstage/forum/forum-topics-page.js` | Topics table + actions |
| `pages/backstage/forum/forum-categories-page.js` | Categories table + slide-panel CRUD |
| `pages/backstage/forum/forum-audit-page.js` | Audit table + filters + load-more |
| `pages/backstage/forum/empty-state-topics.svg` | Topics empty illustration |
| `pages/backstage/forum/empty-state-reports.svg` | Reports empty illustration |
| `pages/backstage/forum/forum.css` | Re-purposed: holds only forum-specific bits not in the system file (e.g. `.report-reason-chip`, report-card layout) |

### Modified files

| Path | Change |
|------|--------|
| `public/index.php` | Add 4 new path matches (`/backstage/forum/reports`, `/topics`, `/categories`, `/audit`) |
| `public/api/backstage/forum.php` | Add `op=stats` case (proxy to `/backstage/forum/stats`); also add `session_start()` + `ApiClient` require if missing (same fix Phase 1 had to apply to insights.php proxy) |
| `public/pages/backstage/layout.php` | No changes (system CSS/JS already loaded) |

### Deleted files

| Path | Reason |
|------|-------|
| `pages/backstage/forum/forum.js` | Superseded by per-page JS files |
| `pages/backstage/forum/forum-modal.js` | Replaced by slide-panel + confirm-dialog from the system |

## 11. Testing

- **Backend:** unit + integration + isolation + E2E HTTP tests for the new stats endpoint. PHPStan level 9 = 0 errors. `composer test:all` green.
- **Frontend:** manual UAT after deployment (no automated frontend tests in this codebase). Verification checklist:
  1. `/backstage/forum` renders dashboard with 4 KPI cards. Values arrive from backend, sparklines render via ApexCharts. Categories KPI shows empty/flat sparkline gracefully.
  2. Each KPI card click navigates to the correct sub-page.
  3. Recent activity card shows 5 most-recent audit rows.
  4. Reports sub-page: cards render, segmented filter switches set correctly, search works, slide-panel opens with full target + reporter list, all 5 resolution actions work end-to-end.
  5. Topics sub-page: data-explorer table with all rows, segmented filter (Pinned/Locked/Open), category select, search, pin/unpin/lock/unlock/delete confirm-dialogs all work.
  6. Categories sub-page: table renders with topic counts, slide-panel create + edit work, delete with non-empty category surfaces backend error correctly.
  7. Audit sub-page: read-only table, action-type + date-range filters, load-more pagination.
  8. All sub-pages: skeleton during load, empty-state when no rows, error-state on API failure.
  9. Light + dark theme parity on all 5 pages.
  10. Mobile (≤768px): dashboard KPI grid stacks, table sub-pages scroll horizontally OR row layout adapts.
  11. No `transform: translate*` / `scale*` on hover anywhere in new CSS — grep check before merge.

## 12. Out of scope

- Forum public-side UI (anything outside `/backstage/forum/*`)
- New moderation actions (only re-packaging existing ones into the new UI)
- Real-time updates to the moderation queue
- Audit export (CSV / JSON download)
- KPI date-range beyond 30 days
- Search across audit reasons (only actor-name search this phase)
- Public-site dashboard widgets

## 13. Acceptance criteria

Phase 2 is done when:
1. The 5 forum URL routes (`forum`, `forum/reports`, `forum/topics`, `forum/categories`, `forum/audit`) all render their respective new layouts.
2. `GET /api/v1/backstage/forum/stats` returns the documented payload, scoped to the request's tenant.
3. All 4 dashboard KPI cards click-navigate to the correct sub-pages.
4. The 5 report-resolution flows (delete / lock / warn / edit / dismiss) work end-to-end through slide-panel + confirm-dialog.
5. Topics pin / unpin / lock / unlock / delete all work via confirm-dialog.
6. Categories create / edit / delete all work, with delete surfacing backend errors when the category has topics.
7. Audit page filters and load-more pagination work.
8. PHPStan level 9 = 0 errors. `composer test:all` green.
9. Existing public-side `/forum` continues to work (regression check — no public-side files modified).
10. The deleted `forum.js` and `forum-modal.js` produce no 404s in DevTools Network on any backstage page (verify via the smoke test pre-merge).
11. No `transform: translate*` / `scale*` on hover in any new CSS rule (grep check).
12. The hard-coded "no hover translate" rule from Phase 1 is preserved across the new CSS.

---
