# Backstage Redesign — Design System + Insights Pilot

**Date:** 2026-04-25
**Branch target:** `dev`
**Phase 1 of:** Iterative backstage modernization (9 admin pages)
**Phase 1 scope:** Build shared design system primitives + redesign `/backstage/insights` end-to-end as the pilot.

## 1. Background

The backstage admin panel (in `daem-society/public/pages/backstage/`) has accumulated nine pages with inconsistent visual treatments:
- Dashboard uses modern `metric-card` + ApexCharts.
- Events / Projects use card-wrapped tables with custom `evt-modal` / `project-modal` patterns.
- Insights (shipped 2026-04-25) uses a raw Bootstrap `<table class="insight-table">` with no card wrap and a copy-paste of the events modal — this is the trigger for this redesign.
- Members uses a view-toggle (table / grid).
- Settings is a card-grid of read-only "coming soon" cards.
- Forum, Notifications, Applications each have their own ad-hoc treatment.

Tokens (light + dark) and the layout shell (sidebar, header bar, breadcrumb, command palette, theme toggle) are already strong. **The gap is shared *components*: data-explorer chrome, status pills, edit affordances, modal vs. panel patterns, empty/loading/error states.**

User feedback (2026-04-25): "Insights backstage sivu on aivan kauhea... Sen pitäisi olla moderni, tyylikäs ja selkeä. Niinkuin pitäisi niiden muidenkin." → all admin pages, not only insights.

## 2. Strategy

**Iterative, insights-first.** Build the shared design-system primitives directly inside the insights redesign rather than upfront in isolation. Once a primitive proves itself in real use, extract it to `pages/backstage/shared/`. Each subsequent backstage page becomes its own brainstorm → plan → execute cycle that consumes (and extends) the system.

Phase order (each its own future PR):

| Phase | Page | Notes |
|-------|------|-------|
| **1 (this spec)** | Insights | System foundation + pilot |
| 2 | Forum | Confirms moderation workflow + system fit |
| 3 | Events | Validates locale-cards inside slide-panel |
| 4 | Projects | Mirror of events |
| 5 | Members | Largest page (669 LOC); validates view-toggle inside system |
| 6 | Applications | Approve-flow regression check |
| 7 | Notifications | Simple list — quick win |
| 8 | Settings | Mostly card-grid, retains identity |
| 9 | Dashboard polish | Reuse new `.kpi-card` for visual consistency |

The public-facing site (`daem-society` non-`/backstage`) is **out of scope**.

## 3. Visual direction

**Hybrid: Linear/Notion-style data explorer + Stripe-style KPI cards with sparklines.**

Per page:
- **Header strip:** title + subtitle + primary action button (`+ New …`).
- **KPI strip (4-up):** small cards with category icon (top-right), uppercase label, large value, trend line, ApexCharts area sparkline that bleeds to the card edges. Optional `--clickable` variant: border-color shift on hover, **no transform**.
- **Toolbar:** segmented filter (All / Draft / Published / Archived) + secondary selects + right-aligned search box. Sits on a hairline border separator.
- **Table panel:** Linear-style — hairline row borders, hover-row tint (rgba black .02), action buttons reveal via opacity on row hover. Status cells use color-coded `.pill` components.
- **Edit flow:** opens as a slide-in panel from the right (~56% viewport width on desktop, 100% on `<768px`). The list stays visible behind the panel.
- **Confirms (delete / publish / archive):** small centered modal (320px) — yes/no with Cancel + Danger/Primary buttons.
- **States:** skeleton rows during load, illustrated SVG empty-state when zero items, inline error card with retry on load failure.

### Hard constraint on hover animations

Per user feedback (2026-04-25 brainstorm), no hover effect on `.kpi-card`, `.data-explorer-row`, or any tile/list element may use `transform: translate*` or `transform: scale*`. Allowed hover affordances:
- `border-color` change
- `background-color` shift to subtle tint (e.g. `rgba(0,0,0,.02)` for rows)
- `opacity` increase on revealed action buttons
- `color` change on icons

This applies across the whole design system and propagates to all subsequent phases.

## 4. Design system primitives — Phase 1

All new CSS lives in a new file `daems-backstage-system.css`, loaded by `layout.php` *after* the existing `daems-backstage.css` (so token overrides cascade correctly). All new shared partials live in `pages/backstage/shared/`.

### 4.1 `.kpi-card`

Component for a single KPI in the 4-up strip.

```
┌──────────────────────────┐
│ UPCOMING            📅   │  ← uppercase label + icon (top-right)
│ 12                       │  ← 24px bold value
│ ▲ +3 vs prev 30d         │  ← trend (green up / red down / muted)
│ ┌──────────────────────┐ │
│ │  ⤴ sparkline (36px)  │ │  ← bleed to edges, gradient under line
│ └──────────────────────┘ │
└──────────────────────────┘
```

- Container: `border-radius: 10px`, `border: 1px solid var(--surface-border)`, `padding: 14px 16px 0`.
- Sparkline area: `margin: 8px -16px 0; height: 36px;` (bleeds to card edges).
- Sparkline rendered via ApexCharts (`type: 'area'`, `sparkline.enabled: true`) — same library as dashboard, no new dependency.
- Optional `--clickable` modifier: cursor pointer, top-right `→` arrow, border-color shifts to `var(--brand-primary)` on hover. **No transform.**
- ApexCharts native tooltip on sparkline hover shows `{date label} · {value} {unit}`.

API contract per KPI:
```json
{
  "value": 12,
  "change": 0.25,         // optional: +25% vs prior period
  "trend_label": "+3 vs prev 30d",  // pre-rendered text — optional override
  "sparkline": [
    {"date": "2026-03-26", "value": 8},
    {"date": "2026-03-27", "value": 9},
    ...
  ]
}
```

PHP partial: `pages/backstage/shared/kpi-card.php`. Variables expected: `$kpi_id`, `$label`, `$icon` (svg or emoji), `$icon_variant` (`blue|gray|green|amber|purple`), `$value`, `$trend_label`, `$trend_direction` (`up|down|flat|warn|muted`), `$href` (optional → renders as `<a>`).

### 4.2 `.data-explorer`

Page-level layout primitive with three slots:

```
.data-explorer
├── .data-explorer__header        page-header  (title + subtitle + primary action)
├── .data-explorer__kpis          .kpis-grid (4-up, optional)
└── .data-explorer__panel         table panel
    ├── .data-explorer__toolbar   segmented filter + selects + search
    └── .data-explorer__table     <table class="data-explorer__data">
```

CSS classes (added to `daems-backstage-system.css`):
- `.kpis-grid` — `display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;` — works for 1, 2, 3, or 4 cards across pages without per-page overrides; collapses to single column ≤640.
- `.data-explorer__panel` — `background: var(--surface-dark); border: 1px solid var(--surface-border); border-radius: 10px; padding: 6px 18px 18px;`.
- `.data-explorer__toolbar` — flex row, `padding: 12px 0; border-bottom: 1px solid var(--surface-border);`.
- `.data-explorer__data` — full-width table; `th` is small caps muted; `tr.row` has `border-top: 1px solid var(--surface-border)`; `tr.row:hover` gets `background: rgba(0,0,0,.02)`; `.data-explorer__actions` opacity 0 → 1 on `tr:hover`.

### 4.3 `.pill` status badges

Single component, multiple variants. Phase 1 ships *all* variants up-front so subsequent phases (events, projects, forum, applications) can consume without redefining:
- `.pill--published` — green subtle bg + `var(--status-success)` text
- `.pill--scheduled` — blue subtle bg + `var(--brand-primary)` text *(future-dated content)*
- `.pill--draft` — slate subtle bg + slate text *(events, projects)*
- `.pill--archived` — amber subtle bg + `var(--status-warning)` text *(events, projects)*
- `.pill--pending` — amber subtle bg + `var(--status-warning)` text *(applications, proposals)*
- `.pill--featured` — purple subtle bg + `var(--accent)` text

For insights specifically, only `--published`, `--scheduled`, and `--featured` apply (insights have no draft/archived state — see §6).

### 4.3a `.btn` button variants

To support all components above, Phase 1 ships canonical button variants in the new system CSS:
- `.btn` — base: 6px radius, 600 weight, 13px font, 7px×14px padding, `var(--transition-fast)` for color transitions
- `.btn--primary` — `var(--brand-primary)` bg, white text
- `.btn--secondary` — `var(--surface-light)` bg, `var(--text-secondary)` text
- `.btn--danger` — `var(--status-error)` bg, white text
- `.btn--text` — transparent bg, `var(--brand-primary)` text, no border (for tertiary "Open report" / "Learn more" links)

The existing `.btn--primary` / `--secondary` rules in `daems-backstage.css` are kept; the system file overrides only when needed and adds the missing `--danger` and `--text` variants.

### 4.4 `.slide-panel`

Right-edge slide-in panel for create/edit flows.

Markup (built by `daems-backstage-system.js` panel-controller):
```html
<div class="slide-panel" role="dialog" aria-modal="true" aria-labelledby="...">
  <div class="slide-panel__backdrop"></div>
  <aside class="slide-panel__panel">
    <header class="slide-panel__header">
      <h2 class="slide-panel__title">Edit insight</h2>
      <button class="slide-panel__close" aria-label="Close">×</button>
    </header>
    <div class="slide-panel__body"><!-- form content --></div>
    <footer class="slide-panel__footer">
      <button class="btn btn--secondary">Cancel</button>
      <button class="btn btn--primary">Save</button>
    </footer>
  </aside>
</div>
```

Behavior:
- Width: 56% on `≥1024px`, 100% on `<768px`. On `<1024px && ≥768px`, width 80%.
- Animation: `transform: translateX(100% → 0)` on open, reverse on close, `250ms cubic-bezier(0.4, 0, 0.2, 1)`. Backdrop fades 0 → 0.45 opacity in same window.
- Closes on: ESC key, click on backdrop, click on `__close`, programmatic `Panel.close()`.
- Focus trap inside panel; focus restored to trigger element on close.
- Body scroll locked while open.
- Multiple panels are forbidden — opening a new one closes any current one.

PHP partial `pages/backstage/shared/slide-panel.php` is just the empty mount point (`<div id="{$mount_id}"></div>`); content is JS-rendered. Per-page editor JS (e.g. `insight-panel.js`) uses `window.Panel.open({title, body, footer, onSave})`.

### 4.5 `.confirm-dialog`

Small centered modal for yes/no decisions.

```html
<div class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="...">
  <div class="confirm-dialog__backdrop"></div>
  <div class="confirm-dialog__panel">
    <h2 class="confirm-dialog__title">Delete insight?</h2>
    <p class="confirm-dialog__body">"Q1 sustainability report" — this removes all three translations and cannot be undone.</p>
    <footer class="confirm-dialog__footer">
      <button class="btn btn--secondary">Cancel</button>
      <button class="btn btn--danger">Delete</button>
    </footer>
  </div>
</div>
```

Behavior:
- Width: 320px, centered with `transform: translate(-50%, -50%)`.
- Animation: opacity + `scale(0.94 → 1)` on open, `200ms ease`. **No translateY.**
- Closes on: ESC, click backdrop, click Cancel, click confirm action.
- Returns a `Promise<boolean>` from `window.ConfirmDialog.open({title, body, danger: true, confirmLabel: 'Delete'})`.

### 4.6 `.empty-state`

Illustrated empty state with CTA.

```html
<div class="empty-state">
  <img src="/pages/backstage/insights/empty-state.svg" alt="" class="empty-state__illustration">
  <h3 class="empty-state__title">No insights yet</h3>
  <p class="empty-state__body">Create your first insight to share news with members.</p>
  <button class="btn btn--primary">+ Add insight</button>
</div>
```

- `padding: 48px 24px; text-align: center;`
- Illustration: 160px width inline SVG, single-color (slate-400 fill) so it themes for dark mode automatically.
- Phase 1 ships only the insights illustration; subsequent phases add `events`, `projects`, etc. variants.

### 4.7 `.skeleton-row`

Reuses existing `.skeleton` shimmer animation (already in `daems-backstage.css` line 1031).

```html
<tr class="data-explorer__skeleton">
  <td><span class="skeleton skeleton--text" style="width: 60%"></span></td>
  <td><span class="skeleton skeleton--text" style="width: 80%"></span></td>
  <td><span class="skeleton skeleton--pill"></span></td>
  <td></td>
</tr>
```

New tokens: `.skeleton--text { height: 14px; }`, `.skeleton--pill { height: 18px; width: 64px; border-radius: 999px; }`.

Render 5 skeleton rows during load.

### 4.8 `.error-state`

Inline error card shown when API load fails:

```html
<div class="error-state" role="alert">
  <svg class="error-state__icon"><!-- alert-triangle --></svg>
  <div class="error-state__body">
    <h3 class="error-state__title">Could not load insights</h3>
    <p class="error-state__message">{HTTP code or message}</p>
  </div>
  <div class="error-state__actions">
    <button class="btn btn--primary" data-action="retry">Retry</button>
    <a class="btn btn--text" href="/backstage/notifications">Open report</a>
  </div>
</div>
```

- Background: `var(--status-error-subtle)`, left border: `4px solid var(--status-error)`, padding `16px 20px`.

## 5. Phase 1 deliverables — file changes

### 5.1 New files

**Frontend (`daem-society`):**
- `public/assets/css/daems-backstage-system.css` (~600 LOC)
- `public/assets/js/daems-backstage-system.js` (~250 LOC) — panel controller, confirm-dialog controller, sparkline-init helpers
- `public/pages/backstage/shared/kpi-card.php`
- `public/pages/backstage/shared/slide-panel.php`
- `public/pages/backstage/shared/confirm-dialog.php`
- `public/pages/backstage/shared/empty-state.php`
- `public/pages/backstage/insights/insight-panel.js` (replaces `insight-modal.js`)
- `public/pages/backstage/insights/empty-state.svg`

**Backend (`daems-platform`):**
- `src/Application/UseCase/Backstage/ListInsightStats.php` — returns 4 KPIs with sparklines for the active tenant
- New method `BackstageInsightController::stats(Request, Response): Response`
- New route `GET /api/v1/backstage/insights/stats` in `routes/api.php`
- DI bindings in `bootstrap/app.php` AND `tests/Support/KernelHarness.php` (memory rule — both containers)
- Test: `tests/Integration/Backstage/InsightStatsTest.php`
- Test: `tests/Isolation/InsightStatsIsolationTest.php`

### 5.2 Modified files

- `daem-society/public/pages/backstage/insights/index.php` — full redesign using `data-explorer` + `kpi-card` partials; removes inline `<table class="insight-table">`.
- `daem-society/public/pages/backstage/layout.php` — adds `<link rel="stylesheet" href="/assets/css/daems-backstage-system.css">` and `<script src="/assets/js/daems-backstage-system.js" defer>`. Inserts after existing CSS so cascade is correct.

### 5.3 Deleted files

- `daem-society/public/pages/backstage/insights/insight-modal.css`
- `daem-society/public/pages/backstage/insights/insight-modal.js`

## 6. KPIs for the insights page

The `insights` table (mig 002 + 026) has columns: `id, slug, title, category, category_label, featured, published_date, author, reading_time, excerpt, hero_image, tags_json, content, created_at, tenant_id`. **There is no `status` column and no view-tracking table** — publishedness is derived from `published_date` vs `CURDATE()`. There is also no `draft` or `archived` state.

Phase 1 therefore ships **3 KPI cards** for the insights page (the kpi-card grid uses `repeat(auto-fit, minmax(220px, 1fr))` so 3 cards fill the row cleanly):

| KPI | Definition | Icon | Color | Sparkline |
|-----|-----------|------|-------|-----------|
| Published | Count where `published_date <= CURDATE()` | 📰 | green | Daily count of insights published per day, last 30 days |
| Scheduled | Count where `published_date > CURDATE()` | 🕓 | blue | Daily count of insights with `published_date` falling on each of the next 30 days |
| Featured | Count where `featured = 1` AND `published_date <= CURDATE()` | ⭐ | purple | Daily featured-published count, last 30 days |

If a KPI's sparkline series would be all-zero (e.g. zero scheduled posts) the card renders the value with an empty sparkline area (gradient still drawn at 0 baseline). The component must not crash on empty data.

Backend clamps every series to exactly 30 points (zero-fills missing days).

A future phase may add view tracking (separate scoped change) which would introduce a fourth "Reads" KPI — out of scope for Phase 1.

## 7. Behavior — insights page flow

Status pill rendering for an insight row:
- `published_date <= CURDATE()` → `.pill.pill--published` ("Published")
- `published_date > CURDATE()` → `.pill.pill--scheduled` ("Scheduled · {date}")

If `featured = 1`, render an additional small `.pill.pill--featured` next to the status pill.

| Action | Trigger | Result |
|--------|---------|--------|
| Page load | `GET /api/v1/backstage/insights` + `/stats` in parallel | Skeleton rows + KPI skeletons during fetch |
| Load OK, list empty | `items.length === 0` | `.empty-state` replaces table body, KPIs still render |
| Load fails | non-2xx or network error | `.error-state` replaces table; KPIs render with — values + a small "—" sparkline |
| Add insight | Click `+ Add insight` button | Slide-panel opens, empty form, focus on title |
| Edit | Click row's edit icon (✎) | Slide-panel opens, prefilled with insight data |
| Save (panel) | Click Save | `POST` (create) or `PATCH` (update); on success: close panel, optimistic table update, refetch `/stats`, toast "Saved" |
| Delete | Click Delete in slide-panel footer (or row's trash icon) | `ConfirmDialog.open({danger:true, title:'Delete insight?'})` → if confirmed: DELETE, remove row, refetch `/stats`, toast "Deleted" |
| Toggle published date | Inside slide-panel: edit `published_date` field directly | Saved with the rest of the form. There is no separate "Publish" action — date is the single source of truth |
| Toggle featured | Inside slide-panel: checkbox | Saved with the rest of the form |

## 8. Out of scope

- Other 8 backstage pages (each is its own subsequent phase).
- Public site (`daem-society` non-`/backstage`).
- New telemetry / instrumentation (e.g. `insight_views` tracking — flagged as a future follow-up if Reads KPI is desired).
- Automated visual regression (manual QA only this phase).
- Internationalization of the *admin chrome* itself — admin UI is English; the i18n is for content (which insights doesn't currently use).

## 9. Risks and mitigations

| Risk | Mitigation |
|------|-----------|
| ApexCharts adds bundle size on every page | Already loaded by `layout.php` for dashboard; reuse |
| Slide-panel on mobile is awkward | Width = 100% below 768px, slide-from-right still works; same close affordances |
| Empty-state SVG needs theming | Single-color SVG with `currentColor` fill → automatic dark mode |
| Insights page has no i18n today, so slide-panel feels oversized | Body textarea + meta fields fill the space; tested in early Phase 1 manual QA |
| Per-page CSS duplicated when other pages adopt the system | Extract to `shared/` only after second use; keep system file authoritative |
| Phase 9 (dashboard polish) regresses existing metric-cards | Dashboard PR will swap `.card--metric` → `.kpi-card` 1-to-1; tokens identical |

## 10. Testing

- `composer analyse` (PHPStan level 9) — must remain at 0 errors.
- `composer test:all` — must remain green; new tests for `ListInsightStats` use case + isolation.
- Manual UAT on `daem-society.local/backstage/insights`, hitting `daems-platform.local`:
  - Loading state (throttle network)
  - Empty state (truncate `insights` table for the dev tenant)
  - Populated state with 1, 5, 25 items
  - Error state (stop the platform server)
  - Slide-panel open + close (ESC, backdrop, X)
  - Confirm-dialog flow for delete + publish
  - Light + dark theme parity
  - Keyboard navigation (Tab into panel, ESC closes)
  - Mobile breakpoint (≤768px) — panel fills viewport
- Verify no `transform: translate*` or `scale*` in any hover rule across new CSS file (`grep` check before merge).

## 11. Acceptance criteria

Phase 1 is done when:
1. `daem-society/public/pages/backstage/insights/` renders with the new `data-explorer` + `kpi-card` + `slide-panel` + `confirm-dialog` patterns end-to-end.
2. The 3 insights KPIs (Published / Scheduled / Featured) render with live data + 30-day sparklines via `GET /api/v1/backstage/insights/stats`.
3. New `daems-backstage-system.css` and `daems-backstage-system.js` are loaded by `layout.php` and used only by insights — they are inert for unmigrated pages.
4. No hover rule in the new CSS uses `translate*` or `scale*`.
5. PHPStan level 9 = 0 errors; `composer test:all` green.
6. Manual UAT passes for all states listed in §10.
7. The four shared partials and the JS controllers are documented in inline comments minimal enough to be discoverable when Phase 2 (forum) consumes them.

---
