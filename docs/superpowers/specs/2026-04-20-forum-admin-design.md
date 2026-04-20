# Forum Admin — Design

**Date:** 2026-04-20
**Branch:** `dev`
**Status:** Design complete, pending user review
**Prereqs:** Projects admin (tag `v2026-04-20-projects-admin` / commit `8644a53`) — migrations up to 046 assumed applied.

---

## 1. Goal

Deliver `/backstage/forum` (roadmap §1.6): full moderator console for reports, topics, categories, and audit trail. Mirrors the projects-admin shape (report inbox → moderation workflow → admin CRUD) but with a richer resolution set (delete / lock / warn / edit) and a user-facing "Raportoi" entry point on posts and topics.

---

## 2. Scope

**In:**
- Report-based moderation: authenticated users report **posts or topics** (not categories) via a new `forum_reports` table.
- Deduplicated admin queue: one row per `(reporter_user_id, target_type, target_id)`, aggregated per target in the admin UI.
- Three report statuses: `open` / `resolved` / `dismissed`.
- Report reason: enum category **and** optional free-text detail.
- Full moderator console — admin can `Resolve → delete target`, `Resolve → lock topic`, `Resolve → warn user`, `Resolve → edit post content`, or `Dismiss` a report.
- Category CRUD (create / update / delete) with a guard that blocks deletion while the category has topics.
- Pin / unpin and lock / unlock on topics (pinned column exists; `locked` added).
- Editing a post preserves the original content inside the audit payload; public UI shows "edited by moderator" marker.
- Admin inbox toast integration: open forum reports flow into the same pending-toast stack as member/supporter applications and project proposals.
- `CreateForumPost` guards against posting to a locked topic (returns 409 `topic_locked`).

**Out (explicit YAGNI):**
- Reporting of categories (c2 discussion) — deferred to a later iteration. Noted in `CLAUDE.md` follow-ups.
- Automatic ban threshold wired to warnings count — warnings only write an audit row in MVP.
- Email notification to the reporter or reported user — Mailu is not yet wired. Same reasoning as earlier PRs.
- Soft delete / ghost rows — hard delete for posts and topics, mirroring projects-admin comment moderation.
- Bulk operations (mass-pin, mass-delete) — single-row actions only in MVP.
- Rate limiting on `POST /forum/reports` — open trolling risk acknowledged; MVP ships without, tracked for future hardening.
- Edit history visible to end users as a diff — only the "edited by moderator at X" marker is shown publicly.
- User-level ban / suspend feature — warnings are audit-only; disabling accounts is a separate future feature.

---

## 3. Data-model changes

### Migration 047 — lock column for topics

```sql
ALTER TABLE forum_topics
    ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0 AFTER pinned;
```

No backfill needed — default `0` is correct for existing rows.

### Migration 048 — reports + moderation audit + warnings

```sql
CREATE TABLE forum_reports (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    reporter_user_id  CHAR(36)     NOT NULL,
    reason_category   ENUM('spam','harassment','off_topic','hate_speech','misinformation','other') NOT NULL,
    reason_detail     VARCHAR(500) NULL,
    status            ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_at       DATETIME     NULL,
    resolved_by       CHAR(36)     NULL,
    resolution_note   VARCHAR(500) NULL,
    resolution_action ENUM('deleted','locked','warned','edited','dismissed') NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reporter_target (reporter_user_id, target_type, target_id),
    KEY idx_fr_tenant_status (tenant_id, status, created_at),
    KEY idx_fr_target (target_type, target_id),
    CONSTRAINT fk_fr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_moderation_audit (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic','category') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    action            ENUM(
        'deleted',
        'locked',
        'unlocked',
        'pinned',
        'unpinned',
        'edited',
        'category_created',
        'category_updated',
        'category_deleted',
        'warned'
    ) NOT NULL,
    original_payload  JSON         NULL,
    new_payload       JSON         NULL,
    reason            VARCHAR(500) NULL,
    performed_by      CHAR(36)     NOT NULL,
    related_report_id CHAR(36)     NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fma_target (target_type, target_id),
    KEY idx_fma_performer (performed_by),
    KEY idx_fma_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_user_warnings (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    user_id           CHAR(36)     NOT NULL,
    reason            VARCHAR(500) NOT NULL,
    related_report_id CHAR(36)     NULL,
    issued_by         CHAR(36)     NOT NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fuw_user (user_id, created_at),
    KEY idx_fuw_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fuw_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE forum_posts
    ADD COLUMN edited_at DATETIME NULL AFTER created_at;
```

`forum_posts.edited_at` captures when a post was last admin-edited; public thread view uses it to render the "moderator edited" caption without joining to `forum_moderation_audit`.

### Migration 049 — extend dismissals enum

```sql
ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal','forum_report') NOT NULL;
```

The dismissal row for forum reports uses the **aggregated target key** (`target_type:target_id`) as the `app_id`, so a single dismissal suppresses all raw reports for that target. Concrete format: `app_id = <target_type>:<target_id>` (e.g. `post:019d9dfc-...`). Dismissals are refreshed when a new raw report arrives for an already-dismissed target (insert triggers toast re-surfacing by clearing the dismissal).

---

## 4. Backend

### 4.1 Domain entities + repositories

**New entities** (under `src/Domain/Forum/`), all tenant-scoped (2nd ctor arg `TenantId`):
- `ForumReport` — id, tenantId, targetType, targetId, reporterUserId, reasonCategory, reasonDetail, status, resolvedAt, resolvedBy, resolutionNote, resolutionAction, createdAt.
- `ForumModerationAuditEntry` — id, tenantId, targetType, targetId, action, originalPayload (array|null), newPayload (array|null), reason, performedBy, relatedReportId, createdAt.
- `ForumUserWarning` — id, tenantId, userId, reason, relatedReportId, issuedBy, createdAt.

**Additions to `ForumRepositoryInterface`:**
- `setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void`
- `setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void`
- `deleteTopicForTenant(string $topicId, TenantId $tenantId): void` — cascades posts.
- `deletePostForTenant(string $postId, TenantId $tenantId): void`
- `updatePostContentForTenant(string $postId, TenantId $tenantId, string $content): void`
- `findPostByIdForTenant(string $id, TenantId $tenantId): ?ForumPost`
- `findTopicByIdForTenant(string $id, TenantId $tenantId): ?ForumTopic`
- `listRecentTopicsForTenant(TenantId, int $limit = 100, array $filters = []): ForumTopic[]`
- `listRecentPostsForTenant(TenantId, int $limit = 100, array $filters = []): ForumPost[]`
- `countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int`
- `updateCategoryForTenant(ForumCategory $category): void`
- `deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void` — throws domain exception if topics exist.

**New repositories:**
- `ForumReportRepositoryInterface`:
  - `upsert(ForumReport $report): void` — INSERT ... ON DUPLICATE KEY UPDATE reason_category/reason_detail/created_at (re-open semantics when user re-reports).
  - `findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport`
  - `listOpenAggregatedForTenant(TenantId, array $filters = []): AggregatedReport[]` — each row = one target with report count, earliest/latest, top reasons, list of raw report ids.
  - `listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): ForumReport[]`
  - `resolveAllForTarget(string $targetType, string $targetId, TenantId $tenantId, string $resolutionAction, string $resolvedBy, ?string $note, DateTimeImmutable $now): void`
  - `dismissOne(string $reportId, TenantId $tenantId, string $resolvedBy, ?string $note, DateTimeImmutable $now): void`
  - `countOpenForTenant(TenantId $tenantId): int`
- `ForumModerationAuditRepositoryInterface`:
  - `record(ForumModerationAuditEntry $entry): void`
  - `listRecentForTenant(TenantId $tenantId, int $limit = 200): ForumModerationAuditEntry[]`
- `ForumUserWarningRepositoryInterface`:
  - `record(ForumUserWarning $warning): void`
  - `listForUserForTenant(string $userId, TenantId $tenantId): ForumUserWarning[]`

Implementations: `SqlForumReportRepository`, `SqlForumModerationAuditRepository`, `SqlForumUserWarningRepository` under `src/Infrastructure/Adapter/Persistence/Sql/`; InMemory counterparts under `tests/Support/InMemory/`.

**Guard in existing `CreateForumPost`:** after loading the thread, if `topic.locked === true`, return a domain error and let the controller map it to `409 Conflict` with `error: topic_locked`.

### 4.2 Application use cases

Under `src/Application/Backstage/Forum/`:

| Use case | Responsibility |
|---|---|
| `ReportForumTarget` | User side — validates target exists, upserts report, dedup by unique key. Authenticated only. Stores `ForumReport` row. Triggers: clear matching dismissal in `admin_application_dismissals` so toast re-surfaces. |
| `ListForumReportsForAdmin` | Returns aggregated rows per target (status=open default, filters: status, target_type). |
| `GetForumReportDetail` | Returns one aggregated entry + all raw reports + current target content (post body or topic title+first-post). |
| `ResolveForumReportByDelete` | Transactional: delete target (post or topic), `resolveAllForTarget(action='deleted')`, write audit row with `original_payload` containing the deleted content. |
| `ResolveForumReportByLock` | Target must be `topic`; set `topics.locked=1`, resolve with `action='locked'`, audit. |
| `ResolveForumReportByWarn` | Insert `forum_user_warnings` row for the **author of the target**, resolve with `action='warned'`, audit. |
| `ResolveForumReportByEdit` | Target must be `post`; overwrite post content, resolve with `action='edited'`, audit including `original_payload.content`. |
| `DismissForumReport` | Resolve all raw reports for target as `dismissed` (no moderation action), audit entry with action=`dismissed` omitted — dismissal is bookkeeping, not moderation. Actually: do NOT write to `forum_moderation_audit` for dismiss; the dismissed status on reports is the trail. |
| `PinForumTopic` / `UnpinForumTopic` | Toggle + audit. |
| `LockForumTopic` / `UnlockForumTopic` | Toggle + audit. |
| `DeleteForumTopicAsAdmin` | Direct deletion (no report required). Audit. |
| `DeleteForumPostAsAdmin` | Direct deletion. Audit. |
| `EditForumPostAsAdmin` | Direct edit. Audit (original preserved). |
| `CreateForumCategoryAsAdmin` / `UpdateForumCategoryAsAdmin` | Simple CRUD + audit. |
| `DeleteForumCategoryAsAdmin` | Guard `countTopicsInCategoryForTenant > 0 → throw ConflictException`. Audit on success. |
| `ListForumModerationAuditForAdmin` | Last 200 entries, filters: action, performer. |
| `WarnForumUser` | Direct warn (no report). Audit (action=`warned`). |
| `UpdateListPendingApplicationsForAdmin` | Existing `ListPendingApplicationsForAdmin` gains a fourth branch: merge aggregated open forum reports not present in dismissals; item shape `{id: "<target_type>:<target_id>", type: "forum_report", name: <short_excerpt_or_topic_title>, created_at: <latest_report_time>}`. |

**Authorization:** every admin use case checks `$acting->isAdminIn($tenantId) || $acting->isPlatformAdmin`; `ReportForumTarget` only requires authenticated member (any role ≥ `registered`).

**Dismiss target key format:** `DismissApplication` accepts `appType='forum_report'` and `appId='<target_type>:<target_id>'`; validation allow-lists both the enum value and the compound id format via a simple regex guard.

### 4.3 HTTP endpoints

All `[TenantContextMiddleware, AuthMiddleware]` except where public reads are explicitly allowed by existing forum endpoints.

**User side (authenticated member):**
| Method | Path | Handler |
|---|---|---|
| POST | `/forum/reports` | `ForumController::createReport` |

**Admin (extends `BackstageController`):**
| Method | Path | Handler |
|---|---|---|
| GET  | `/backstage/forum/reports` | `listForumReports` |
| GET  | `/backstage/forum/reports/{id}` | `getForumReport` |
| POST | `/backstage/forum/reports/{id}/resolve` | `resolveForumReport` (body: `{action, note?, new_content?}`) |
| POST | `/backstage/forum/reports/{id}/dismiss` | `dismissForumReport` |
| GET  | `/backstage/forum/topics` | `listForumTopicsAdmin` |
| POST | `/backstage/forum/topics/{id}/pin` | `pinForumTopic` |
| POST | `/backstage/forum/topics/{id}/unpin` | `unpinForumTopic` |
| POST | `/backstage/forum/topics/{id}/lock` | `lockForumTopic` |
| POST | `/backstage/forum/topics/{id}/unlock` | `unlockForumTopic` |
| POST | `/backstage/forum/topics/{id}/delete` | `deleteForumTopicAdmin` |
| GET  | `/backstage/forum/posts` | `listForumPostsAdmin` |
| POST | `/backstage/forum/posts/{id}/edit` | `editForumPostAdmin` |
| POST | `/backstage/forum/posts/{id}/delete` | `deleteForumPostAdmin` |
| POST | `/backstage/forum/users/{id}/warn` | `warnForumUser` |
| GET  | `/backstage/forum/categories` | `listForumCategoriesAdmin` |
| POST | `/backstage/forum/categories` | `createForumCategoryAdmin` |
| POST | `/backstage/forum/categories/{id}` | `updateForumCategoryAdmin` |
| POST | `/backstage/forum/categories/{id}/delete` | `deleteForumCategoryAdmin` |
| GET  | `/backstage/forum/audit` | `listForumAudit` |

Existing `/backstage/applications/pending-count` response shape unchanged — just starts emitting `forum_report` items.

### 4.4 DI wiring

Bind every new controller dep, use case, and Sql repo in **BOTH**:
- `bootstrap/app.php`
- `tests/Support/KernelHarness.php` (with InMemory repo fakes)

Per `feedback_bootstrap_and_harness_must_both_wire.md`, grep for each new class name in both files before marking wiring tasks done.

---

## 5. Frontend (daem-society)

### 5.1 Admin page — `public/pages/backstage/forum/index.php`

Four tabs selected via `?tab=reports|topics|categories|audit` (client-side tab switching, URL-linkable). Default tab = `reports` if `open > 0`, otherwise `topics`.

**Reports tab**
- Filter row: status pill group (Open / Resolved / Dismissed), target_type (all / posts / topics).
- Aggregated cards: target excerpt (first 160 chars of post content or topic title), category-badges for reason distribution ("12× spam, 3× off_topic"), reporter-count ("15 raportoijaa"), earliest / latest report timestamps.
- Actions row per card: **Delete**, **Lock** (disabled if target is a post), **Warn author**, **Edit** (disabled if target is a topic), **Dismiss**. Each primary action opens a small inline confirm with an optional note textarea. `Edit` opens a modal with the current content pre-filled.
- After resolve/dismiss: row fades out, queue count badge updates, top-level toast count decrements.

**Topics tab**
- Filters: category, pinned-only, locked-only, search.
- Table: Title · Category · Author · 📌 Pin toggle · 🔒 Lock toggle · Replies · Last activity · Actions (Delete, View on site).
- No inline edit for topic titles in MVP — admin must delete + recreate if rename needed. (Note in spec: keep YAGNI, add if it becomes common ask.)

**Categories tab**
- Table: Name · Slug · Icon · Sort · Topic count · Actions (Edit, Delete).
- "New category" opens modal (slug / name / icon / description / sort_order).
- Delete button disabled (with tooltip) if `topic_count > 0`, shows "Siirrä topicit ensin" link that scrolls to Topics tab filtered by that category.

**Audit tab**
- Read-only table: Timestamp · Action · Target (clickable if not deleted) · Performer · Reason · Linked report (if any).
- Paging optional — MVP shows last 200; filter by action enum.

### 5.2 Public forum changes

- Each post renders a small "⚠ Raportoi" link under `likes / timestamp`. Clicking opens a dialog:
  - Dropdown `reason_category` (enum with localized labels in Finnish).
  - Optional textarea `reason_detail` (max 500 chars, character counter).
  - Submit → `POST /api/v1/forum/reports` with `target_type='post'`, `target_id=<postId>`.
  - Success state: "Kiitos raportista. Moderaattori tarkistaa." — link remains but click shows "Jo raportoitu — päivitä syytä?" reopening dialog.
- Topic header gets a "⚠ Raportoi aihe" link on the right side, same dialog with `target_type='topic'`.
- Locked topic renders banner: "🔒 Keskustelu on lukittu — uusia viestejä ei voi lähettää." Reply textarea is disabled + placeholder updated.
- Edited posts render small caption: `(moderaattorin muokkaama YYYY-MM-DD)`. Uses the existence of the latest matching `forum_moderation_audit` entry via a lightweight enriching query in `GetForumThread`; simplest implementation: add a boolean `moderator_edited` on the API output, derived from last-edited-at or audit presence. Keep it cheap: add `edited_at DATETIME NULL` to `forum_posts` in migration 048 (amendment below) so the read path does not need an audit join.

(Column `forum_posts.edited_at` already included in migration 048, see §3.)

### 5.3 Frontend proxies

- `public/api/backstage/forum.php` — single relay with `op=reports_list|report_detail|report_resolve|report_dismiss|topics_list|topic_pin|topic_unpin|topic_lock|topic_unlock|topic_delete|posts_list|post_edit|post_delete|user_warn|categories_list|category_create|category_update|category_delete|audit_list`.
- `public/api/forum/report.php` — user-side relay for the report dialog.

Same pattern as `public/api/backstage/projects.php`.

### 5.4 Sidebar nav

`public/pages/backstage/layout.php` gets a `Forum` entry (icon: `bi-chat-left-dots`) pointing to `/backstage/forum`. Verify no existing entry and add if missing.

### 5.5 Toast routing

`public/pages/backstage/toasts.js` adds:
```js
if (item.type === 'forum_report') {
    window.location.href = '/backstage/forum?tab=reports&highlight=' + encodeURIComponent(item.id);
}
```
`highlight` may be the compound `target_type:target_id` key — dialog scrolls to the matching card.

---

## 6. Testing

### 6.1 Unit (~18 use-case tests)

One class per new use case:
- `ReportForumTargetTest` — happy, unauthenticated forbidden, invalid target, dedup upsert, dismissal cleared.
- `ListForumReportsForAdminTest` — aggregated shape, filters, forbidden for non-admin.
- `GetForumReportDetailTest` — aggregation + raw reports + content, tenant-scoped.
- `ResolveForumReportByDeleteTest` — target removed, reports resolved, audit written.
- `ResolveForumReportByLockTest` — topic locked, invalid on post target, resolved.
- `ResolveForumReportByWarnTest` — warning row created for target author, resolved.
- `ResolveForumReportByEditTest` — content overwritten, original in audit payload, `edited_at` set.
- `DismissForumReportTest` — all raw reports dismissed, no audit row.
- `PinForumTopicTest` / `UnpinForumTopicTest` — toggle, audit.
- `LockForumTopicTest` / `UnlockForumTopicTest` — toggle, audit, locked topic blocks new posts.
- `DeleteForumTopicAsAdminTest` — cascade posts, audit.
- `DeleteForumPostAsAdminTest` — audit.
- `EditForumPostAsAdminTest` — audit with original, `edited_at` stamped.
- `WarnForumUserTest` — warning row + audit.
- `CreateForumCategoryAsAdminTest` / `UpdateForumCategoryAsAdminTest` — happy + slug conflict.
- `DeleteForumCategoryAsAdminTest` — blocked when topics present, success when empty, audit.
- `ListForumModerationAuditForAdminTest` — recent ordering, filters.
- `ListPendingApplicationsForAdminTest` (update existing) — now includes aggregated `forum_report` items and respects their dismissals.

### 6.2 Integration (MySQL)

- `ForumReportLifecycleIntegrationTest` — user reports → admin resolves via delete → target gone, all reports resolved, audit present.
- `ForumCategoryCrudIntegrationTest` — create → list → update → delete blocked → move topics → delete succeeds.
- `ForumLockedTopicRejectsPostsIntegrationTest` — lock topic → `CreateForumPost` returns `topic_locked`.
- `ForumReportDismissalToastIntegrationTest` — dismissed target does not surface in pending-toast until a new raw report arrives, then re-surfaces.

### 6.3 Isolation

- `ForumAdminTenantIsolationTest` — every admin route and every use case rejects cross-tenant access (report created in tenant A cannot be listed/resolved by admin of tenant B).

### 6.4 E2E (KernelHarness)

- `ForumAdminEndpointsTest` — happy + error path for each new route.
- `AdminInboxIncludesForumReportsTest` — seed aggregated reports, GET `/backstage/applications/pending-count`, assert `items` contains `type=forum_report` with compound id.

### 6.5 Manual UAT (≥12 checks)

1. As member, open a topic, click "⚠ Raportoi" on a post → dialog, submit spam+detail → success.
2. Same member re-reports same post with different reason → dialog shows existing reason, submit updates.
3. Different member reports same post → aggregated count = 2.
4. Admin loads backstage page anywhere → toast shows "1 forum report" with aggregated target excerpt.
5. Click toast → lands on `/backstage/forum?tab=reports&highlight=<key>`, card scrolled into view.
6. Open detail → see both raw reports and current post content.
7. Click `Edit` → modal with content prefilled → change text + note "loiviksi" → save → both reports resolved, audit row present, `edited_at` stamped, public view shows moderator-edited caption.
8. Different topic: Admin clicks `Lock` → topic marked locked, banner on public view, reply textarea disabled.
9. Regular user tries to POST to locked topic via API → 409 `topic_locked`.
10. Admin dismisses a report → queue count decreases, no audit row.
11. Admin tries to delete a category that has topics → error banner with "Siirrä topicit ensin" link.
12. Audit tab shows the last 5 actions in correct order with performer names.

---

## 7. Files inventory

**New backend:**
- `database/migrations/047_add_locked_to_forum_topics.sql`
- `database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql`
- `database/migrations/049_extend_dismissals_enum_forum_report.sql`
- `src/Domain/Forum/ForumReport.php`
- `src/Domain/Forum/ForumReportRepositoryInterface.php`
- `src/Domain/Forum/ForumModerationAuditEntry.php`
- `src/Domain/Forum/ForumModerationAuditRepositoryInterface.php`
- `src/Domain/Forum/ForumUserWarning.php`
- `src/Domain/Forum/ForumUserWarningRepositoryInterface.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumUserWarningRepository.php`
- `tests/Support/InMemory/InMemoryForumReportRepository.php`
- `tests/Support/InMemory/InMemoryForumModerationAuditRepository.php`
- `tests/Support/InMemory/InMemoryForumUserWarningRepository.php`
- ~18 use-case classes under `src/Application/Backstage/Forum/`
- Controller methods appended to `BackstageController`
- User-side `ForumController::createReport`
- Route registrations

**Modified backend:**
- `ForumRepositoryInterface` + `SqlForumRepository` + `InMemoryForumRepository` — new methods (pin/lock/delete/edit/listRecent*/categoryCrud guards).
- `ForumTopic` entity — gains `locked` field.
- `ForumPost` entity — gains `editedAt` field.
- `CreateForumPost` — locked-topic guard.
- `ListPendingApplicationsForAdmin` — fourth branch for forum reports.
- `DismissApplication` — allow `forum_report` appType + compound appId validation.

**New frontend daem-society:**
- `public/pages/backstage/forum/index.php`
- `public/pages/backstage/forum/forum-admin.js`
- `public/pages/backstage/forum/forum-admin.css`
- `public/pages/backstage/forum/edit-post-modal.js`
- `public/pages/backstage/forum/category-modal.js`
- `public/api/backstage/forum.php`
- `public/api/forum/report.php`
- `public/pages/forum/_report-dialog.js` (reusable) + associated CSS

**Modified frontend:**
- `public/pages/backstage/toasts.js` — forum_report routing.
- `public/pages/backstage/layout.php` — sidebar entry (verify + add).
- Public forum view files — add Report link under posts, topic header Report link, locked banner, moderator-edited caption.

**Tests:**
- ~18 unit, 4 integration, 1 isolation, 2 E2E classes.

---

## 8. Rollout

Single PR on `dev`. Migrations 047–049 forward-only, additive. `IsolationTestCase::runMigrationsUpTo(49)`. No data backfill needed — reports and warnings start empty; locked defaults to 0; dismissals enum extension is additive. Per CLAUDE.md: never auto-push; report SHAs and wait for "pushaa".

After this PR, `CLAUDE.md` roadmap update: Forum moderation → done. Remaining admin sections: Settings + Dashboard metric-integration, plus deferred items (category reporting c2, auto-ban thresholds, rate limiting on reports).

---

## 9. Open deferrals to track

- **Category reporting (c2)** — user-facing "Raportoi kategoria" and category as a valid report target. Captured earlier in conversation; add to `CLAUDE.md` "Forum later" section.
- **Auto-ban thresholds** — derive ban suggestions from `forum_user_warnings` count per rolling window.
- **Rate limiting on `POST /forum/reports`** — currently unconstrained; add per-user-per-target + global per-user limits before a public launch.
- **Topic rename** — in-line edit of topic title for admins (skipped in MVP).
- **Insights admin featured-UI** — still pending from projects-admin spec, unchanged by this work.
