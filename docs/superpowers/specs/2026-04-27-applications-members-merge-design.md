# Applications + Members merge (admin panel UI consolidation)

**Date:** 2026-04-27
**Branch target:** `dev`
**Scope:** Frontend-only consolidation of `/backstage/applications` into `/backstage/members` as a tabbed page. Backend (use cases, controllers, routes) untouched.

## 1. Background

The backstage admin panel currently exposes **Applications** and **Members** as two separate sidebar entries with two separate pages. They cover the same domain (people in a tenant lifecycle): an Application is the pre-decision state, a Member is the post-approval state. After a successful Approve action on an Application, the same person enters the Members register.

Application volume is low. Two dedicated sidebar entries inflate sidebar noise without clear UX upside, and admins juggle two mental models for what is fundamentally one workflow ("manage the people in this tenant").

This spec describes a **UI-only** merge: the two pages become one tabbed page at `/backstage/members`. Backend domains stay separate — no schema migration, no merged use cases, no breaking API changes. The merge reduces sidebar entries by one and gives admins a single "people" entry point.

## 2. Goals

- One sidebar entry for people management ("Members").
- Pending applications visible inside the Members page via a "Pending" tab, with a count badge on the sidebar entry for at-a-glance awareness.
- All existing functionality preserved: filtering, sorting, status modals, audit log, CSV export, approve/reject + invite-link banner.
- URL stability: bookmarks to `/backstage/applications` continue to work via 302 redirect.
- Zero backend changes. PHPStan level 9 stays at 0 errors. Existing E2E coverage stays green.

## 3. Non-goals (YAGNI rejections)

- **No schema/data-model unification.** Applications stay in their own backend tables; the Members register is `user_tenants`-backed. The two domains have genuinely different fields (motivation text + decision payload vs. member_number + status workflow + audit log) and a unified table would force one to carry the other's nulls.
- **No combined `ListPeople` use case or unified API endpoint.** Keep `ListPendingApplications`, `DecideApplication`, `DismissApplication`, `ListApplicationsStats`, `ListMembers`, `ChangeMemberStatus`, `GetMemberAudit`, `ListMembersStats` exactly as they are. Frontend calls both sets.
- **No new tabs** for "Rejected history", "Archive", or "All people". Two tabs (Register, Pending) only.
- **No KPI consolidation.** The two existing stats endpoints stay separate; the frontend swaps which KPI strip is visible based on the active tab.
- **No category/membership_type logic changes.** Today's enum (`basic | full | supporter | honorary | founding`) and approval flow stay identical.
- **No backend file deletions.** `Daems\Application\Backstage\*Application*` use cases remain — they are still called by the backend route layer.

## 4. Architecture

### 4.1 Page structure (`pages/backstage/members/index.php`)

Single PHP page at `/backstage/members` with two tabs selected by `?tab=register|pending`. Default tab when omitted: `register`. Tabs use a real link-based tablist pattern (anchor-driven, full page reload on tab change) — not JS-only — so that POST→redirect responses preserve tab state through standard server-side flow.

**Tab bar markup** (above page header content, below H1):

```html
<div class="members-tabs" role="tablist" aria-label="Members views">
    <a role="tab" aria-selected="true" class="members-tab is-active"
       href="/backstage/members?tab=register">Register</a>
    <a role="tab" aria-selected="false" class="members-tab"
       href="/backstage/members?tab=pending">
        Pending
        <span class="members-tab__badge" data-pending-count><!-- N --></span>
    </a>
</div>
```

The two tab panels render conditionally based on `$activeTab = ($_GET['tab'] ?? 'register') === 'pending' ? 'pending' : 'register';`.

### 4.2 Register tab (default)

1:1 port of today's `pages/backstage/members/index.php` body — KPI strip, filter form, list/cards toggle, members table, pagination, status modals (activate/suspend/terminate), audit log card, CSV export. No content or behavior changes; the existing markup is wrapped in `<section role="tabpanel" data-tab="register">`.

The page H1 stays `Members Register`. Subtitle stays `Official register for kannattava, perus, varsinainen, and kunniajäsen categories.` (page H1/subtitle are tab-independent.)

### 4.3 Pending tab

1:1 port of today's `pages/backstage/applications/index.php` body — Applications KPI strip, two sub-section cards ("Member Applications" + "Supporter Applications"), per-applicant approve/reject form, invite-link banner on success, highlight-script on `?highlight=<id>`. The two sub-sections are preserved (not merged into one table) because Member applications and Supporter applications carry structurally different fields:

| Member application | Supporter application |
|---|---|
| `name`, `email`, `date_of_birth`, `country`, `motivation` | `org_name`, `contact_person`, `email`, `country`, `motivation` |

Wrapping markup: `<section role="tabpanel" data-tab="pending">`.

### 4.4 KPI strip behavior

Both KPI scripts (`members-stats.js`, `applications-stats.js`) are loaded on every page render. Only the active tab's strip is visible (`hidden` attribute on the inactive one). This keeps frontend logic dumb — no conditional script loading — at the cost of one extra stats API call per pageload. Cost is tolerable: stats endpoints are cheap and admin pageloads are infrequent.

| Tab | KPI strip |
|---|---|
| Register | `Total members` · `New (30d)` · `Supporters` · `Inactive` |
| Pending | `Pending` · `Approved (30d)` · `Rejected (30d)` · `Avg response` |

### 4.5 Approve flow

The approve/reject form `action` changes from `/backstage/applications` to `/backstage/members?tab=pending`. POST handling logic moves verbatim into `pages/backstage/members/index.php` and runs **only when** `$activeTab === 'pending'`. On success the same flash banner + invite-link toast renders inline at the top of the Pending tab; the existing `?highlight=<id>` scroll-into-view script keeps working.

Status-change POST handling (activate/suspend/terminate) on the Register tab stays intact, dispatched only when `$activeTab === 'register'`. The two POST handlers do not collide because they target different tabs and inspect distinct field sets (`type`+`decision` vs. `member_id`+`status`).

### 4.6 Sidebar + bottom nav + command palette (`pages/backstage/layout.php`)

- Remove the **Applications** entry from the sidebar (current lines ~172-181), bottom-nav (~439-444), and command palette listing (~476).
- Rename the existing `applications-badge` element → `members-pending-badge` and place it on the **Members** sidebar row.
- The existing top-of-layout fetch to `/backstage/applications/pending-count` stays — it now populates `members-pending-badge` instead of `applications-badge`.
- `$activePage = 'members'` for both `?tab=register` and `?tab=pending`. The Pending sub-state is signaled by the in-page tab UI, not by a separate sidebar item.

### 4.7 Routing + back-compat

- Add a 302 redirect: `/backstage/applications` → `/backstage/members?tab=pending`. Implementation: a one-line `pages/backstage/applications/index.php` containing `header('Location: /backstage/members?tab=pending', true, 302); exit;` (the original page body is deleted from this file).
- Update the dashboard pending-applications toast template's "View applications" href → `/backstage/members?tab=pending`.
- Backend routes in `daems-platform` (`/api/v1/backstage/applications/*`, `/api/v1/backstage/members/*`) stay untouched.

### 4.8 Backend — explicitly no change

No modifications to:
- `src/Application/Backstage/ListPendingApplications/*`
- `src/Application/Backstage/DecideApplication/*`
- `src/Application/Backstage/DismissApplication/*`
- `src/Application/Backstage/Applications/ListApplicationsStats/*`
- `src/Application/Backstage/ListMembers/*`
- `src/Application/Backstage/ChangeMemberStatus/*`
- `src/Application/Backstage/GetMemberAudit/*`
- `src/Application/Backstage/Members/ListMembersStats/*`
- The corresponding controllers, routes, repository ports, SQL repos, or DI bindings (`bootstrap/app.php` + `tests/Support/KernelHarness.php`).

This is a frontend-only PR on the `daem-society` repo. No commits land in `daems-platform`.

## 5. Files touched

**daem-society (frontend) — modified:**
- `public/pages/backstage/members/index.php` — wrap existing body in Register tabpanel, add tab bar, append Pending tabpanel with applications content, conditional POST handler dispatch.
- `public/pages/backstage/layout.php` — remove Applications nav entries (sidebar + bottom-nav + command-palette), rename badge element, update fetch target binding.
- `public/pages/backstage/index.php` (or wherever the dashboard pending-applications toast renders) — update "View applications" href.
- `public/assets/css/daems-backstage.css` — add `.members-tabs`, `.members-tab`, `.members-tab__badge`, `.members-tab.is-active` rules colocated with the existing `.members-*` block. Use existing design-system tokens (`--surface-border`, `--brand-primary`, `--text-muted`).

**daem-society (frontend) — replaced with redirect stub:**
- `public/pages/backstage/applications/index.php` — body deleted, replaced with 302 redirect to `/backstage/members?tab=pending`.

**daem-society (frontend) — deleted:**
- `public/pages/backstage/applications/applications-stats.js` — moved to `public/pages/backstage/members/applications-stats.js` (filename kept; it pairs with the Pending-tab KPI strip and remains identifiable by its endpoint binding).

**daem-society (frontend) — kept as-is:**
- `public/api/backstage/applications.php` (proxy to backend) — unchanged.
- `public/api/backstage/members.php` (proxy to backend) — unchanged.

**daems-platform (backend) — none.**

## 6. Testing plan

### 6.1 Existing tests must stay green

- `composer analyse` → 0 errors at PHPStan level 9 (no backend changes, so no new phpstan surface).
- `composer test:all` (Unit + Integration + E2E) → all green. Backstage applications/members E2E tests stay valid because backend routes do not change.

### 6.2 New E2E test

`tests/E2E/Backstage/MembersTabsE2ETest.php` (in daems-platform) verifying the **API contract** still holds — no UI tabs to test from PHP, but ensure:
- `GET /api/v1/backstage/applications/pending` and `GET /api/v1/backstage/applications/pending-count` keep their shapes (covered by existing tests).
- `GET /api/v1/backstage/members` keeps its shape.

If existing tests cover these, no new E2E test is required. Confirm during planning.

### 6.3 Browser smoke (manual, MUST run before commit-mark-done)

1. Visit `/backstage/members` → Register tab is active, Members KPI strip visible, table populated.
2. Visit `/backstage/members?tab=pending` → Pending tab is active, Applications KPI strip visible, both sub-section cards render.
3. Visit `/backstage/applications` → 302-redirected to `/backstage/members?tab=pending`.
4. Sidebar: only one "Members" entry. Click it → lands on `/backstage/members` (Register default).
5. Sidebar badge on Members row reflects the actual pending count (matches dashboard toast).
6. Approve a member application from Pending tab → invite-link banner renders inline, member_number assigned, page stays on Pending tab.
7. Open Activate/Suspend/Terminate modal from Register tab → modals work, status update persists, audit log shows new entry.
8. Click "View applications" in dashboard pending-toast → routes to `/backstage/members?tab=pending`.
9. Command palette: search "applications" → suggests "Pending applications" → routes to Pending tab. Search "members" → routes to Register tab.
10. CSV export from Register tab works as before.

## 7. Rollback

Single PR on `daem-society`. If a regression appears, revert the PR — backend is unchanged, no data migration to roll back. Old `pages/backstage/applications/index.php` body is recoverable from git history.

## 8. Open questions

None at spec close. All design decisions resolved during brainstorming:
- A: UI-only merge (no schema unification).
- A: Single sidebar nav entry "Members" with badge (no dual entries).
- A: Default tab = Register (no smart switching, no Pending-default).
- A: Tab labels = "Register" + "Pending" (matches existing page identity).
