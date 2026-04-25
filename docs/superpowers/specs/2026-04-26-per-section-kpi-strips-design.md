# Per-Section KPI Strips — Phases 3-7 of Backstage Modernization

**Date:** 2026-04-26
**Branch target:** `dev`
**Phases covered:** 3 (Members) · 4 (Applications) · 5 (Events) · 6 (Projects) · 7 (Notifications)
**Scope:** Add a 4-up KPI strip with ApexCharts sparklines to the top of five backstage pages. Reuses Phase 1's design-system primitives. No full-page redesigns — strip only.

## 1. Background

Phase 1 (insights pilot, 2026-04-25) shipped the shared design system (`kpi-card`, `kpis-grid`, `data-explorer`, `slide-panel`, `confirm-dialog`, `pill`, `empty-state`, `skeleton`, `error-state`, `btn` variants) and the canonical `ListInsightStats` backend pattern. Phase 2 (forum redesign, 2026-04-25) extended the system with sub-page navigation, the `kpi-card--compact` variant, and validated the `ListForumStats` shape on richer data.

Phases 3-7 close the loop: the remaining five backstage pages (Members, Applications, Events, Projects, Notifications) each get their own KPI strip on top of the existing UI. The page bodies are untouched in this iteration — full redesigns of those bodies are out of scope.

Settings (`/backstage/settings`) is **excluded** because it is read-only and has no natural KPIs. Dashboard (`/backstage`) and Insights and Forum are already done. After Phase 7 every backstage page that benefits from a KPI strip will have one.

## 2. Strategy

Each section is its own phase: own brainstorm-spec-plan-execute cycle is folded into one spec (this document) and one plan (the corresponding plan document) because the architectural pattern is identical across the five — only the KPI definitions and repo wiring change. Implementation runs section-by-section in five sub-phases, each producing its own commit chain.

Phase order (largest/highest-value first):

| Phase | Section | URL |
|-------|---------|-----|
| 3 | Members       | `/backstage/members`        |
| 4 | Applications  | `/backstage/applications`   |
| 5 | Events        | `/backstage/events`         |
| 6 | Projects      | `/backstage/projects`       |
| 7 | Notifications | `/backstage/notifications`  |

## 3. Architectural patterns (inherited from Phase 1 + 2)

These hold for **every** section in this spec — referenced once, not repeated per section.

### 3.1 Backend

- **Endpoint:** `GET /api/v1/backstage/{section}/stats`. One per section, mirroring Phase 1's `/insights/stats` and Phase 2's `/forum/stats`.
- **Use case:** `ListXStats` (e.g. `ListMembersStats`) under `src/Application/Backstage/Stats/` (or section-aligned namespace where one already exists — verify per section). Mirror `ListInsightStats` / `ListForumStats` shape: constructor takes the relevant repository interface(s), returns 4 KPI structures.
- **Repository method:** `statsForTenant(TenantId): array` on each owning repository interface. When the section spans multiple repos (Applications combines `MemberApplicationsRepo` + `SupporterApplicationsRepo`; Notifications combines four), the use case calls each method and assembles the result.
- **Controller method:** new method on `BackstageController` per section (e.g. `statsMembers`), admin-gated via the existing tenant-admin guard (`$acting->isAdminIn($tenantId)` → `ForbiddenException` → HTTP 403).
- **Route:** added to `routes/api.php` next to existing routes for that section.
- **DI BOTH-wire:** every new class is bound in *both* `bootstrap/app.php` AND `tests/Support/KernelHarness.php`. Per repository memory rule — wiring only the harness lets E2E tests pass while the live server breaks.
- **Sparkline shape:** `[{date: 'YYYY-MM-DD', value: int}, ...30]`. Backend zero-fills missing days, clamps to exactly 30 points unless the KPI declares an empty `[]` (see §4-§8 per KPI).
- **Time windows:** all "30d" KPIs use `>= NOW() - INTERVAL 30 DAY`. Forward-looking sparklines (Events Upcoming) use `BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY`.
- **PHPStan level 9** must remain at 0 errors per phase.

### 3.2 Frontend

- **Markup:** insert `.kpis-grid` directly under the page header, before the existing page body. Each grid contains four `<?php require __DIR__ . '/../shared/kpi-card.php'; ?>` includes (per Phase 1 partial contract).
- **JS:** new file `pages/backstage/{section}/{section}-stats.js` that:
  1. Fetches `/api/backstage/{section}/stats` via the existing `ApiClient` proxy.
  2. Updates each card's value, trend label, and trend direction.
  3. Calls `window.Sparkline.init(cardElement, dataPoints)` per card.
  4. Renders skeleton state during fetch and an inline error pill on failure.
- **Proxy:** `public/api/backstage/{section}.php` adds an `op=stats` case forwarding to `GET /api/v1/backstage/{section}/stats`. **`session_start()` MUST be called at the top of every proxy file** — direct Apache vhost serving (`daems.local/api/backstage/...`) bypasses the front controller, where session would otherwise start. Phase 1 added this to `insights.php`; Phase 2 to `forum.php`. Each new proxy follows the same fix.
- **CSS:** no new CSS. The `kpi-card`, `kpis-grid`, and theme tokens already exist in `daems-backstage-system.css`.
- **No transform hovers:** no `transform: translate*` or `transform: scale*` in any new code (Phase 1 hard rule).

### 3.3 Testing pattern (per section)

Each section ships:
- **Unit:** `tests/Unit/Application/Backstage/ListXStatsTest.php` — anonymous fake of the repo interface(s); asserts the use case wires inputs to outputs correctly.
- **Integration:** `tests/Integration/Backstage/ListXStatsTest.php` (or repo-aligned location) — exercises the SQL `statsForTenant` method against a seeded `daems_db_test`; asserts counts and sparkline shape.
- **Isolation:** `tests/Isolation/XStatsIsolationTest.php` — seeds two tenants (`daems` + `sahegroup`) with overlapping data; asserts the second tenant's data does not leak into the first's stats payload.
- **E2E:** `tests/E2E/Backstage/XStatsEndpointTest.php` — HTTP-level test through `KernelHarness`: 403 for non-admin, 200 + payload shape for admin, tenant-resolution via `Host` header.

PHPStan level 9 = 0 errors.

## 4. Phase 3 — Members KPI strip

**Page:** `daem-society/public/pages/backstage/members/index.php`

### 4.1 KPIs

| KPI | Value query | Sparkline | `icon_variant` |
|-----|------------|-----------|----------------|
| **Total members** | `SELECT COUNT(*) FROM user_tenants WHERE tenant_id=? AND left_at IS NULL` (all roles incl. `'registered'`) | Daily count of `user_tenants.joined_at` for last 30 days, scoped to tenant, `left_at IS NULL OR left_at > date` | `blue` |
| **New (30d)** | Same as Total but with `joined_at >= NOW() - INTERVAL 30 DAY` | Same series as Total — the 30-day window of joins | `green` |
| **Supporters** | `COUNT(*) FROM user_tenants ut JOIN users u ON u.id = ut.user_id WHERE ut.tenant_id=? AND ut.left_at IS NULL AND u.membership_type='supporter'` | Same `joined_at` daily series filtered by `membership_type='supporter'` last 30 days | `purple` |
| **Inactive** | `COUNT(*) FROM user_tenants ut JOIN users u ON u.id = ut.user_id WHERE ut.tenant_id=? AND ut.left_at IS NULL AND u.membership_status='inactive'` | Daily count of `member_status_audit` rows where `tenant_id=?` AND `new_status='inactive'`, `created_at` daily 30 days | `amber` |

Trend label format: `+N vs prev 30d` (compute by counting prior 30d window and diffing) — applies to Total, New, Supporters, Inactive.

### 4.2 Repo wiring

- **Owning repo:** `UserTenantsRepository` (verify exact interface name during planning — current Members API is served via a SQL repo for `users` joined with `user_tenants`). Method: `statsForTenant(TenantId): array`.
- **Auxiliary read:** `MemberStatusAuditRepository` for the Inactive sparkline series. May expose `dailyTransitionsToStatus(TenantId, string $status, int $days): array` if a clean read is needed.

### 4.3 Use case

`Daems\Application\Backstage\ListMembersStats\ListMembersStats` — constructor takes the two repos above, returns 4-KPI array. Admin-gated by controller (use case itself does not check; controller does).

### 4.4 Files

**Backend (`daems-platform`):**
- New: `src/Application/Backstage/ListMembersStats/{ListMembersStats,ListMembersStatsInput,ListMembersStatsOutput}.php`
- New method: `BackstageController::statsMembers(Request, array $params): Response`
- New route: `GET /api/v1/backstage/members/stats` in `routes/api.php`
- Modified: `bootstrap/app.php` + `tests/Support/KernelHarness.php` (BOTH wire)
- Modified: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantsRepository.php` (or current name) — add `statsForTenant`
- Modified: `src/Infrastructure/Adapter/Persistence/Memory/InMemoryUserTenantsRepository.php` — add `statsForTenant` for tests
- New tests: Unit + Integration + Isolation + E2E (per §3.3)

**Frontend (`daem-society`):**
- Modified: `pages/backstage/members/index.php` — insert `.kpis-grid` with 4 `kpi-card.php` includes after `.page-header` block, before existing filters/table
- New: `pages/backstage/members/members-stats.js` — fetches stats and inits sparklines
- Modified: `public/api/backstage/members.php` — add `op=stats` case + verify `session_start()` is at top

## 5. Phase 4 — Applications KPI strip

**Page:** `daem-society/public/pages/backstage/applications/index.php`

### 5.1 KPIs

Combined across `member_applications` + `supporter_applications` (mirrors how the page's filter chips already merge the two types).

| KPI | Value query | Sparkline | `icon_variant` |
|-----|------------|-----------|----------------|
| **Pending** | `COUNT(*) FROM member_applications WHERE tenant_id=? AND status='pending'` + same on `supporter_applications` | Combined daily `created_at` 30 days (incoming volume across both tables) | `amber` |
| **Approved (30d)** | `COUNT(*) WHERE tenant_id=? AND status='approved' AND decided_at >= NOW() - 30 DAY`, both tables | Daily `decided_at` count where status='approved', 30 days, both tables combined | `green` |
| **Rejected (30d)** | Same shape with `status='rejected'` | Analogous | `red` |
| **Avg response time** | `AVG(TIMESTAMPDIFF(HOUR, created_at, decided_at))` across both tables, where `decided_at IS NOT NULL AND decided_at >= NOW() - 30 DAY`. Render as integer hours | Empty `[]` — single average has no natural daily series | `gray` |

Trend label for Avg response time: `<N>h` plain (no comparison vs prev period — too noisy on small denominators). Other three use `+N vs prev 30d`.

### 5.2 Repo wiring

- **Two owning repos:** `MemberApplicationRepository` and `SupporterApplicationRepository` (verify names during planning). Add `statsForTenant(TenantId): array` to each, returning that repo's slice.
- The use case combines them: `Pending = members.pending + supporters.pending`; sparklines element-wise added; avg response time recomputed across union of decided rows.

### 5.3 Use case

`Daems\Application\Backstage\ListApplicationsStats\ListApplicationsStats` — constructor takes both repos. Pure aggregation logic; SQL lives in the repos.

### 5.4 Files

**Backend:**
- New: `src/Application/Backstage/ListApplicationsStats/{ListApplicationsStats,Input,Output}.php`
- New method: `BackstageController::statsApplications`
- New route: `GET /api/v1/backstage/applications/stats`
- Modified: `bootstrap/app.php` + `tests/Support/KernelHarness.php`
- Modified: `SqlMemberApplicationRepository` + `SqlSupporterApplicationRepository` (and InMemory counterparts)
- New tests per §3.3

**Frontend:**
- Modified: `pages/backstage/applications/index.php` — `.kpis-grid` insertion
- New: `pages/backstage/applications/applications-stats.js`
- Modified: `public/api/backstage/applications.php` — add `op=stats` + ensure `session_start()`

## 6. Phase 5 — Events KPI strip

**Page:** `daem-society/public/pages/backstage/events/index.php`

### 6.1 KPIs

| KPI | Value query | Sparkline | `icon_variant` |
|-----|------------|-----------|----------------|
| **Upcoming** | `COUNT(*) FROM events WHERE tenant_id=? AND status='published' AND event_date >= CURDATE()` | **Forward-looking 30d:** count of events with `event_date` falling on each of the next 30 days, `status='published'` (mirrors Phase 1 Insights "Scheduled" KPI) | `blue` |
| **Drafts** | `COUNT(*) FROM events WHERE tenant_id=? AND status='draft'` | `events.created_at` daily count last 30 days, filtered to `status='draft'` rows (proxy — no audit trail for status changes on events) | `gray` |
| **Registrations (30d)** | `COUNT(*) FROM event_registrations WHERE tenant_id=? AND registered_at >= NOW() - 30 DAY` | Daily `registered_at` count last 30 days, scoped to tenant | `green` |
| **Pending proposals** | `COUNT(*) FROM event_proposals WHERE tenant_id=? AND status='pending'` | `event_proposals.created_at` daily count last 30 days (incoming volume) | `amber` |

Trend label: `+N vs prev 30d` for Drafts/Registrations/Pending; `+N next 30d vs current` is awkward for Upcoming → use `Next 30d: N` plain text format for Upcoming.

### 6.2 Repo wiring

- **Owning repos:** `EventRepository` (covers `events` and the Upcoming/Drafts queries), `EventRegistrationRepository`, `EventProposalRepository`. Each gets `statsForTenant(TenantId): array` for its slice; the use case assembles.

### 6.3 Use case

`Daems\Application\Backstage\ListEventsStats\ListEventsStats`.

### 6.4 Files

**Backend:**
- New: `src/Application/Backstage/ListEventsStats/{...}`
- New: `BackstageController::statsEvents`
- New route: `GET /api/v1/backstage/events/stats`
- Modified: `bootstrap/app.php` + `KernelHarness.php`
- Modified: SQL + InMemory variants of the three repos
- New tests per §3.3

**Frontend:**
- Modified: `pages/backstage/events/index.php` — `.kpis-grid` insertion
- New: `pages/backstage/events/events-stats.js`
- Modified: `public/api/backstage/events.php` — `op=stats` + `session_start()`

## 7. Phase 6 — Projects KPI strip

**Page:** `daem-society/public/pages/backstage/projects/index.php`

### 7.1 KPIs

Project status enum is `'draft' | 'active' | 'archived'` (`ChangeProjectStatus.php:14`).

| KPI | Value query | Sparkline | `icon_variant` |
|-----|------------|-----------|----------------|
| **Active** | `COUNT(*) FROM projects WHERE tenant_id=? AND status='active'` | `projects.created_at` daily count last 30 days, `status='active'` filter (creation cadence proxy) | `green` |
| **Drafts** | `COUNT(*) WHERE status='draft'` | `created_at` 30d filtered to `status='draft'` | `gray` |
| **Featured** | `COUNT(*) WHERE status='active' AND featured=1` | Empty `[]` — featured is a curation toggle without audit trail | `purple` |
| **Pending proposals** | `COUNT(*) FROM project_proposals WHERE tenant_id=? AND status='pending'` | `project_proposals.created_at` daily count 30d (incoming) | `amber` |

Trend label: `+N vs prev 30d` for Active/Drafts/Pending; `Curated` plain text for Featured (no comparison meaningful).

### 7.2 Repo wiring

- **Owning repos:** `ProjectRepository` (covers Active/Drafts/Featured), `ProjectProposalRepository`. Each gets `statsForTenant(TenantId): array` for its slice.

### 7.3 Use case

`Daems\Application\Backstage\ListProjectsStats\ListProjectsStats`.

### 7.4 Files

**Backend:**
- New: `src/Application/Backstage/ListProjectsStats/{...}`
- New: `BackstageController::statsProjects`
- New route: `GET /api/v1/backstage/projects/stats`
- Modified: `bootstrap/app.php` + `KernelHarness.php`
- Modified: `SqlProjectRepository` + `SqlProjectProposalRepository` (and InMemory)
- New tests per §3.3

**Frontend:**
- Modified: `pages/backstage/projects/index.php` — `.kpis-grid` insertion
- New: `pages/backstage/projects/projects-stats.js`
- Modified: `public/api/backstage/projects.php` — `op=stats` + `session_start()`

## 8. Phase 7 — Notifications KPI strip

**Page:** `daem-society/public/pages/backstage/notifications/index.php`

The Notifications page is a unified pending-actions inbox aggregating four data sources: `member_applications`, `supporter_applications`, `project_proposals`, `forum_reports` (all `status='pending'`/`'open'`). It does **not** have its own table. Per-actor dismissal state lives in `admin_application_dismissals` (UNIQUE per `admin_id, app_id`).

### 8.1 KPIs (Activity-strip — Direction A from brainstorm)

The strip surfaces three signals not duplicated by the existing filter chips: actor-perspective, oldest-age anomaly, cleared velocity.

| KPI | Value query | Sparkline | `icon_variant` |
|-----|------------|-----------|----------------|
| **Pending (you)** | Total pending across all 4 sources for tenant, **minus** rows where `(admin_id, app_id) ∈ admin_application_dismissals` for the requesting admin's user_id | Combined daily `created_at` 30d across the 4 sources (incoming volume) | `amber` |
| **Pending (all)** | Total pending across all 4 sources, no dismissal filter | Same combined incoming sparkline as above | `gray` |
| **Cleared (30d)** | Sum of: `member_applications` + `supporter_applications` rows with `decided_at >= NOW() - 30 DAY`; `project_proposals` rows with `decided_at >= NOW() - 30 DAY`; `forum_reports` rows with `status IN ('resolved','dismissed')` AND a "cleared timestamp" — verify exact column during planning (likely `resolved_at` or `decided_at`) | Daily count of "cleared events" 30d, summed across sources | `green` |
| **Oldest pending (d)** | `MAX(DATEDIFF(NOW(), created_at))` across all pending rows from all 4 sources, scoped to tenant. Render as `Nd` integer days | Empty `[]` — single max has no daily series | `red` |

Trend label: `+N vs prev 30d` for Pending (you), Pending (all), Cleared. Oldest pending uses plain `Nd` text.

### 8.2 Repo wiring

The use case orchestrates four repos. Each repo gets a method returning its slice (the count + per-day series):
- `MemberApplicationRepository::notificationStatsForTenant(TenantId): array`
- `SupporterApplicationRepository::notificationStatsForTenant(TenantId): array`
- `ProjectProposalRepository::notificationStatsForTenant(TenantId): array`
- `ForumReportRepository::notificationStatsForTenant(TenantId): array`
- Plus: `AdminApplicationDismissalRepository` exposes a method to count dismissals by the requesting admin (acceptable to call this directly from the use case since it is per-actor, not per-tenant alone).

If existing methods on these repos already cover parts of this (they do for Members/Apps/Projects flows), reuse them and add only the missing slices. Decide during planning per repo.

### 8.3 Use case

`Daems\Application\Backstage\ListNotificationsStats\ListNotificationsStats` — constructor takes the five repos above + `UserId` of the acting admin from the controller. Returns the 4-KPI structure.

### 8.4 Files

**Backend:**
- New: `src/Application/Backstage/ListNotificationsStats/{...}`
- New: `BackstageController::statsNotifications`
- New route: `GET /api/v1/backstage/notifications/stats`
- Modified: `bootstrap/app.php` + `KernelHarness.php`
- Modified: SQL + InMemory variants of all five repos (delta methods)
- New tests per §3.3

**Frontend:**
- Modified: `pages/backstage/notifications/index.php` — `.kpis-grid` insertion (the page is server-rendered today, but the strip is JS-hydrated like every other section)
- New: `pages/backstage/notifications/notifications-stats.js`
- Modified: `public/api/backstage/notifications.php` (creates the file if it does not yet exist) — add `op=stats` case + `session_start()`. Today the page calls `applications/pending-count` directly; the strip uses the new dedicated stats endpoint.

## 9. Testing — overall

Per phase:
- Unit + Integration + Isolation + E2E (per §3.3).
- PHPStan level 9 = 0 errors.

Run order during execution (per phase, mirrors Phase 2):
```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana \
  -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test \
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
composer analyse
```

Manual UAT per section:
- KPI strip renders 4 cards.
- Values are non-negative integers (or `Nh`/`Nd`) and match a hand-spotcheck SQL query against the dev database.
- Sparklines render or render gracefully on empty array.
- Light + dark theme parity.
- Mobile (≤640px): grid collapses to single column.
- Skeleton during load (throttled network).
- Error pill on 500 from the endpoint.
- No `transform: translate*` / `scale*` in any new CSS rule (grep check before merge).

## 10. Acceptance criteria

The full 5-phase deliverable is done when:

1. Each of `/api/v1/backstage/{members,applications,events,projects,notifications}/stats` returns the documented payload, scoped to the request's tenant, admin-gated.
2. Each backstage page (`/backstage/{section}` for the five sections) renders 4 KPI cards above the existing body. Cards fetch on page load and render sparklines via ApexCharts.
3. The 20 KPI definitions in §4-§8 match what the endpoints return — verified by manual spotcheck against `daems_db`.
4. PHPStan level 9 = 0 errors. `vendor/bin/phpunit --testsuite Unit/Integration/E2E` all green per phase.
5. Every new use case class and SQL repository class is bound in **both** `bootstrap/app.php` AND `tests/Support/KernelHarness.php` (controllers are dispatched via routes; the binding rule applies to use-case and repo classes, not their methods).
6. No `transform: translate*` or `transform: scale*` in any new CSS (grep check across diff before each phase commit).
7. Existing page bodies still work — the table/list portions are not refactored. Regression check: each section's existing CRUD still works after the strip is added.

## 11. Out of scope

- Full redesigns of the five page bodies — those were Phase 2's role for Forum and would be future phases for the others.
- Settings page (read-only, no KPIs).
- Public-side UI.
- Dashboard polish (Phase 9 in the original phased plan).
- KPI date-range pickers (locked to 30 days this iteration).
- Real-time / WebSocket updates.
- KPI tooltip drill-down beyond ApexCharts default.
- New telemetry tables (e.g. event view tracking) — every KPI here uses existing data.
- CSV export of KPI data.

## 12. Risks and mitigations

| Risk | Mitigation |
|------|-----------|
| `statsForTenant` queries are slow on large tenants | All queries are tenant-scoped and use existing indexes (`tenant_id` is indexed on every per-tenant table). 30-day GROUP BY on indexed timestamps is fast. Add per-section `EXPLAIN` during implementation if the repo has unusual access patterns. |
| Sparkline empty `[]` confuses ApexCharts | `Sparkline.init` already returns early on empty data (Phase 1 contract). Verified during Phase 2 categories KPI. |
| BOTH-wire forgotten on a phase | Pre-merge checklist per phase: grep new class name in both `bootstrap/app.php` and `tests/Support/KernelHarness.php` and verify a binding exists. The CLAUDE.md rule and the phase plan call this out explicitly. |
| Notifications "Cleared" needs forum_reports.resolved_at column that may not exist | Verified during planning. If absent, fall back to `forum_moderation_audit.created_at` joined to the report id. Plan documents the exact column. |
| Avg response time drops to 0 with no decisions in 30d | Render `—` instead of `0h` when denominator is zero. Frontend handles via the existing trend_label override. |
| Adding the strip slows initial page render | Strip JS fetches asynchronously; cards show skeletons immediately. Page body renders unaffected. |
| Per-actor "Pending (you)" interacts oddly with admin handover | Acceptable: switching admin shows that admin's dismissal state. Same model as the dashboard toast stack. |

## 13. Rollback plan

Each phase is one or more commits on `dev`. Rollback = `git revert` of the phase's commits. The five sections are independent: rolling back Phase 5 (Events) does not affect Phase 3 (Members). Frontend includes are gated by the presence of `kpi-card.php` partials and `daems-backstage-system.js` (Phase 1 deliverables) — rolling back to before Phase 1 would also remove the strip code paths cleanly.

---
