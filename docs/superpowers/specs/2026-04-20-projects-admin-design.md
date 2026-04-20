# Projects Admin — Design

**Date:** 2026-04-20
**Branch:** `dev`
**Status:** Design complete, pending user review
**Prereqs:** Events admin (v2026-04-20-events-admin tag) + application-toast infra.

---

## 1. Goal

Add `/backstage/projects` admin page (roadmap §5): proposal moderation, project CRUD with `featured` toggle, comment moderation. Extend the global admin pending-toast to include pending project proposals alongside member/supporter applications.

---

## 2. Scope

**In:**
- Admin proposal inbox: approve / reject pending `project_proposals` with optional note.
- Approve copies proposal fields into a new `projects` row (status=`draft`); proposal row stays for audit with `status='approved'`.
- Reject marks proposal `status='rejected'` with optional note; no project created.
- Admin project list: every tenant project regardless of status, plus `featured` toggle.
- Admin project CRUD: create/update/archive/restore (active) — bypasses owner check.
- `projects.featured TINYINT(1)` column + toggle in admin UI + public ordering boost.
- Comment moderation: list recent comments + hard-delete with audit row.
- Pending proposals feed into the existing global admin-toast stack (same UX as member/supporter applications).

**Out (explicit YAGNI):**
- Insights admin `featured` UI — Insights already has the DB column; the UI belongs in a later Insights-admin iteration (track in CLAUDE.md).
- Comment report/flag workflow — only hard-delete in MVP.
- Soft-delete for comments (ghost rows) — hard-delete.
- Project ownership transfer (admin → user handoff).
- Featured carousel / hero layout changes on the public `/projects` page — only a cheap ordering boost in MVP.
- Bulk operations on proposals (approve many at once).
- Email notification to proposers when approved/rejected — Mailu isn't wired yet; same bridge as invites (admin can copy the URL if they want to message manually).

---

## 3. Data-model changes

### Migration 044 — `projects.featured`

```sql
ALTER TABLE projects
    ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0
        AFTER status;
```

No backfill needed — default `0` is correct for existing rows.

### Migration 045 — extend dismissal + audit tables for proposals

Two small changes to support proposals in the existing admin-inbox infra:

```sql
ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal') NOT NULL;
```

Plus a new audit table for comment moderation (mirrors `member_status_audit` style):

```sql
CREATE TABLE project_comment_moderation_audit (
    id              CHAR(36) NOT NULL,
    tenant_id       CHAR(36) NOT NULL,
    project_id      CHAR(36) NOT NULL,
    comment_id      CHAR(36) NOT NULL,
    action          ENUM('deleted') NOT NULL,
    reason          VARCHAR(500) NULL,
    performed_by    CHAR(36) NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pcma_project (project_id),
    KEY idx_pcma_performer (performed_by),
    CONSTRAINT fk_pcma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Migration 045 bundles both — one PR, one forward-only migration file.

---

## 4. Backend

### 4.1 Domain + repo changes

- `Project` entity gains `bool $featured` ctor arg (default `false`) + `featured()` getter. Existing ctor call sites stay valid via default.
- `ProjectRepositoryInterface` additions:
  - `listAllStatusesForTenant(TenantId, array $filters = []): Project[]` — admin listing (filters: status, category, featured, q).
  - `findByIdForTenant(string $id, TenantId $tenantId): ?Project`.
  - `setStatusForTenant(string $id, TenantId $tenantId, string $status): void`.
  - `setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void`.
  - `listRecentCommentsForTenant(TenantId, int $limit = 100): iterable` — joined with users for author display.
  - `deleteComment(string $commentId, TenantId $tenantId): void`.
- `ProjectProposalRepositoryInterface` additions:
  - `listPendingForTenant(TenantId): ProjectProposal[]` (already may exist as `findPendingForTenant` — reuse).
  - `findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal`.
  - `recordDecision(string $id, TenantId $tenantId, string $decision, string $decidedBy, ?string $note, DateTimeImmutable $now): void` — sets status + decided_at metadata. If `project_proposals` lacks decision columns, add migration 046 to add `decided_at`, `decided_by`, `decision_note` (mirror applications migration 034 pattern).
- Public `ListProjects::execute` boosts featured items to the top of the result (ORDER BY `featured DESC, sort_order ASC, created_at DESC`).

If `project_proposals` has no `decided_at/decided_by/decision_note`, add migration 046 in this PR:
```sql
ALTER TABLE project_proposals
    ADD COLUMN decided_at    DATETIME NULL,
    ADD COLUMN decided_by    CHAR(36) NULL,
    ADD COLUMN decision_note TEXT     NULL;
```

### 4.2 Application use cases (new)

Under `src/Application/Backstage/`:

- **`ListProjectsForAdmin`** — admin-scoped listing with filters.
- **`CreateProjectAsAdmin`** — wraps existing `CreateProject` but without owner coupling (admin is the creator, `owner_id = acting->id` OR allow null).
- **`UpdateProjectAsAdmin`** — partial update, bypasses `Project::assertMutableBy` that blocks non-owners. Simplest: the existing UpdateProject use case accepts a `bypassOwnerCheck` flag when called by an admin. Alternative: a new `AdminUpdateProject` use case with its own input. **Preferred: new class** (`AdminUpdateProject`) so admin and user paths stay separately auditable.
- **`ChangeProjectStatus`** — transitions between `draft|active|archived`. Tenant-scoped admin check.
- **`SetProjectFeatured`** — toggles `featured`.
- **`ListProposalsForAdmin`** — lists pending + optionally recently-decided proposals.
- **`ApproveProjectProposal`** — transactional: mark proposal approved, INSERT new `projects` row with `status='draft'`, `owner_id = proposal->userId`, slug derived from title (same slug-unique pattern as events), delete pending-dismissals for this proposal id, return new project id/slug.
- **`RejectProjectProposal`** — mark proposal rejected with note, delete dismissals, no project row created.
- **`ListProjectCommentsForAdmin`** — returns recent comments with project context.
- **`DeleteProjectCommentAsAdmin`** — hard-delete + audit row in `project_comment_moderation_audit`.

Each use case has admin authorization (tenant admin OR platform admin), tenant scoping, and returns a focused Output.

### 4.3 Pending-toast integration

Extend `ListPendingApplicationsForAdmin` (rename internally to `ListPendingAdminInboxForAdmin` OR keep the name and broaden semantics — **keep the name**; its response shape already has a polymorphic `type` field). Add a third branch:
- In addition to member + supporter applications, merge pending project proposals filtered by dismissal.
- `items` entries for proposals: `{id, type: "project_proposal", name: <title>, created_at}`.

`DismissApplication` input's `appType` validation adds `'project_proposal'`.

Frontend `toasts.js` decides navigation by `type`:
- `member` / `supporter` → `/backstage/applications?highlight=...`
- `project_proposal` → `/backstage/projects?tab=proposals&highlight=...`

### 4.4 HTTP endpoints

All `[TenantContextMiddleware, AuthMiddleware]`:

| Method | Path | Handler |
|---|---|---|
| GET | `/backstage/projects` | `BackstageController::listProjects` |
| POST | `/backstage/projects` | `BackstageController::createProjectAdmin` |
| POST | `/backstage/projects/{id}` | `BackstageController::updateProjectAdmin` |
| POST | `/backstage/projects/{id}/status` | `BackstageController::changeProjectStatus` |
| POST | `/backstage/projects/{id}/featured` | `BackstageController::setProjectFeatured` |
| GET | `/backstage/projects/{id}/comments` | `BackstageController::listProjectComments` |
| POST | `/backstage/projects/{id}/comments/{comment_id}/delete` | `BackstageController::deleteProjectComment` |
| GET | `/backstage/proposals` | `BackstageController::listProposals` |
| POST | `/backstage/proposals/{id}/approve` | `BackstageController::approveProposal` |
| POST | `/backstage/proposals/{id}/reject` | `BackstageController::rejectProposal` |
| GET | `/backstage/comments/recent` | `BackstageController::listRecentComments` |

The `/comments/recent` cross-project listing is a convenience for the Comments tab; it returns the last 100 across all the admin's projects.

Existing `/backstage/applications/pending-count` and dismissal endpoint stay — they just return `project_proposal` items too now.

---

## 5. Frontend (daem-society)

### 5.1 Admin page — `public/pages/backstage/projects/index.php`

Three tabs rendered as inline sections (no URL routing needed — keep it simple, tab switching is client-side). Tab selected via `?tab=proposals|projects|comments` for linkability.

**Tab: Proposals (default when pending > 0)**
- Counter badge "N pending" in tab title.
- Cards or compact rows: proposer name + email + project title, category, summary, expandable description, `Approve` / `Reject` buttons, optional note textarea for reject.
- On approve: success banner "Approved — project created as draft" with link to edit the new project.
- Pending count updates in-place after decision.

**Tab: Projects**
- Filters: status (All/draft/active/archived), category, featured-only toggle, search.
- Table: Title · Owner · Status pill · Featured ★ toggle · Participants · Comments · Actions (✏ edit, 🗄 archive / ↩ restore).
- "+ New project" button opens the same create/edit modal as Events admin uses (separate component for projects).
- Edit modal fields: title, category, icon (picker or free-text Bootstrap Icons class), summary, description (textarea, plain — HTML sanitisation can come later if needed), status (select).
- Featured toggle is a row-level action (no modal needed) — clicking ★ flips the state and POSTs immediately.

**Tab: Comments**
- List of last 100 comments across all admin-accessible projects.
- Each row: Avatar + name · project title (clickable to project) · excerpt · timestamp · Delete button.
- Delete opens a small confirm prompt with optional reason; POSTs and fades the row.
- Filter by project (dropdown) above the list.

### 5.2 Sidebar nav

Already declared in `layout.php` (same as events case) — link to `/backstage/projects` should already exist. Verify; add if missing.

### 5.3 Proxies

- `public/api/backstage/projects.php` — JSON relay with `op=list|create|update|status|featured|comments|delete_comment`.
- `public/api/backstage/proposals.php` — JSON relay with `op=list|approve|reject`.

Same pattern as `public/api/backstage/events.php`.

### 5.4 Global toast update

`toasts.js` already renders items blindly from `window.DAEMS_PENDING_APPS`. The only change needed is the navigation routing on click:
```js
if (item.type === 'project_proposal') {
    window.location.href = '/backstage/projects?tab=proposals&highlight=' + encodeURIComponent(item.id);
} else {
    window.location.href = '/backstage/applications?highlight=' + encodeURIComponent(item.id);
}
```

Dismiss payload already includes `type` — the backend extension accepts `'project_proposal'` via migration 045.

### 5.5 Public `/projects` page — featured ordering

Minimal change: backend `ListProjects` already orders; tightening to `ORDER BY featured DESC, sort_order ASC, created_at DESC` surfaces featured first. Public page renders unchanged — the order just changes. If there's a visible "Featured" badge desired, add one based on `project.featured === true` — a single `<span class="badge ...">Featured</span>` next to the title. Scope: **yes, add the badge** since the data is now meaningful.

---

## 6. Testing

### 6.1 Unit

One test class per new use case:
- `ListProjectsForAdminTest` — all statuses returned, tenant-scoped, filters (status/category/featured/q), forbidden for non-admin.
- `CreateProjectAsAdminTest` — admin can create without owner coupling; validation errors.
- `AdminUpdateProjectTest` — admin bypasses owner check, validation, tenant scoping.
- `ChangeProjectStatusTest` — valid transitions, invalid status error, forbidden.
- `SetProjectFeaturedTest` — toggle on/off, forbidden.
- `ListProposalsForAdminTest` — pending + optionally historical, forbidden.
- `ApproveProjectProposalTest` — creates `projects` row, marks proposal approved, dismissals cleared, tenant scoping, already-decided guard.
- `RejectProjectProposalTest` — marks rejected, no project created, dismissals cleared, already-decided guard.
- `ListProjectCommentsForAdminTest` — cross-project listing, tenant-scoped, forbidden.
- `DeleteProjectCommentAsAdminTest` — deletes comment + writes audit row, forbidden, not-found.
- `ListPendingApplicationsForAdminTest` (update existing) — now includes project_proposal items.

### 6.2 Integration

- `ProjectsAdminIntegrationTest` — proposal → approve lifecycle via real MySQL; verifies projects row created with correct fields and proposal status=`approved`.
- `ProjectCommentModerationIntegrationTest` — seed comment, delete via admin, assert row gone + audit row present.

### 6.3 Isolation

- `ProjectsAdminTenantIsolationTest` — cross-tenant attempts throw NotFoundException / ForbiddenException.

### 6.4 E2E

- `ProjectsAdminEndpointsTest` — each new route's happy + error paths.
- `AdminInboxIncludesProposalsTest` — seed a pending proposal, GET `/backstage/applications/pending-count`, assert `items` contains entry with `type=project_proposal`.

### 6.5 Manual UAT

1. Submit a project proposal as a regular user → toast appears on admin backstage pages with "New project proposal".
2. Click toast → lands on `/backstage/projects?tab=proposals` highlighting the row.
3. Approve with note → success banner, new project appears in "Projects" tab as draft.
4. Edit the new draft → change title + mark featured → save.
5. Visit public `/projects` → featured project shows first with "Featured" badge.
6. Status = archived → disappears from public list.
7. As a regular user, leave a comment on a project.
8. Admin deletes the comment from Comments tab → audit row in DB.
9. Dismiss the proposal toast → refresh → still gone (same session). Logout/login → proposal reappears in toast because it's still pending (this requires the proposal to NOT have been approved yet).

---

## 7. Files inventory (high level)

**New backend:**
- `database/migrations/044_add_featured_to_projects.sql`
- `database/migrations/045_extend_dismissals_enum_and_comment_audit.sql`
- `database/migrations/046_add_decision_metadata_to_project_proposals.sql` (only if missing; verify first)
- ~10 new use cases under `src/Application/Backstage/`
- 1 audit-writer repository: `src/Domain/Project/ProjectCommentModerationAuditRepositoryInterface.php` + Sql + InMemory fakes
- Controller methods on `BackstageController`
- Route additions

**Modified backend:**
- `Project` entity — `featured` field
- `ProjectRepositoryInterface` + `SqlProjectRepository` + `InMemoryProjectRepository` — new methods + featured ordering in public `ListProjects`
- `ProjectProposalRepositoryInterface` + Sql + InMemory — `listPendingForTenant`, `findByIdForTenant`, `recordDecision`
- `ListPendingApplicationsForAdmin` — merges project_proposals
- `AdminApplicationDismissalRepositoryInterface` implementers — no code change; table-level enum change via migration 045
- `DismissApplication` input — accept `project_proposal` in validation whitelist

**New frontend daem-society:**
- `public/pages/backstage/projects/index.php`
- `public/pages/backstage/projects/project-modal.js`
- `public/pages/backstage/projects/project-modal.css` (reuse event-modal.css mostly)
- `public/api/backstage/projects.php`
- `public/api/backstage/proposals.php`

**Modified frontend:**
- `public/pages/backstage/toasts.js` — route by `type` to proposals URL for `project_proposal`.
- `public/pages/projects/index.php` + relevant render files — show Featured badge + reorder (if the rendering isn't purely driven by API order already).

**Tests:**
- ~10 unit test classes, 2 integration, 1 isolation, 2 E2E.

---

## 8. Rollout

Single PR on `dev`. Migrations 044, 045, (046) forward-only. Featured column defaults to 0 — pre-existing projects invisible change. Enum extension on `admin_application_dismissals.app_type` is additive. `project_proposal` pending items start flowing into the admin toast immediately on deploy (but there likely aren't any — proposals feature exists, depending on whether users have submitted any).

Bump `IsolationTestCase::runMigrationsUpTo(46)` (or whatever the highest applied number ends up being).

---

## 9. Explicit cross-reference: Insights featured UI

Insights already has `insights.featured TINYINT(1) NOT NULL DEFAULT 0`. The insights-admin page (roadmap §6 "Forum moderation" + potential §7 Settings — Insights admin is actually not a standalone roadmap line, so may land with Forum/Settings work) should add the same featured toggle UX we're building here. Track as a reminder in `CLAUDE.md` at end of this spec so the next iteration doesn't forget.
