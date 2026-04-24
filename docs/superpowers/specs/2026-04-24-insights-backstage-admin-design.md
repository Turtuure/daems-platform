# Insights Backstage Admin — Design Spec

**Date:** 2026-04-24
**Status:** Approved, ready for planning
**Follow-up to:** Global Search milestone (2026-04-24). The search spec flagged "Insights backstage admin" as a deliberate out-of-scope deferral; this spec fills that gap.

## Goal

Ship a full-CRUD admin surface for insights at `/backstage/insights`, completing the last content-type admin after events, projects, and forum moderation. Mono-locale MVP; i18n is a separate future milestone.

## Decisions locked in brainstorming

1. **CRUD scope**: full Create / Read / Update / Delete + publish-via-date.
2. **i18n**: stays mono-locale. Insights keep the existing flat `insights` table. i18n is a separate future milestone if content teams need it.
3. **Content editor**: plain `<textarea>` for the HTML `content` field. No WYSIWYG in MVP.
4. **Slug**: auto-slugified from title on modal open; admin can override before save. Uniqueness enforced at DB level (existing UNIQUE constraint on `insights.slug`). Violations surface as `422 slug_taken`.
5. **Reading time**: auto-computed server-side as `max(1, ceil(str_word_count(strip_tags($content)) / 200))`. Shown read-only in the modal as a hint after content is typed.
6. **Hero image**: reuses the existing `public/pages/backstage/events/upload-widget.js` (event-path include; abstracting to shared location deferred to a later PR when actual duplication across three admins is clearer). Uploads land under `public/uploads/insights/{id}.{ext}`.
7. **Categories**: free text. Modal input has a `<datalist>` auto-completing from existing `category_label` values on the tenant. No managed category CRUD in MVP.
8. **Tags**: comma-separated input converted to `tags_json` array at save. No chip-input widget.
9. **Delete**: hard delete. Confirmation modal ("Are you sure?"). No soft-delete column added.
10. **Publish flow**: no `status` column added. `published_date` alone controls visibility:
    - `published_date <= CURDATE()` → Published (visible on public)
    - `published_date > CURDATE()` → Scheduled
    - "Unpublish" in the admin = set `published_date` to 2099-01-01 (displayed in the list as "Unpublished")
    The list renders a coloured pill (`Published` / `Scheduled` / `Unpublished`) based on date comparison.
11. **List filters**: client-side filter by title only (type-ahead). Sort default `published_date DESC`. No server-side filter dropdowns in MVP.
12. **Validation**: title required + ≤255 chars; slug required + unique per tenant; excerpt required + ≤500 chars; content required; published_date required; category required (min length 1).
13. **Auth**: `is_platform_admin=true` OR tenant `role='admin'`. Moderators are NOT granted insight-admin access in this MVP — global-search confirmed that `ActingUser` currently has `isAdminIn()` but no `isModeratorIn()`, and moderators don't edit content elsewhere in the codebase today. If moderators need access later, `ActingUser` gains `isModeratorIn()` and this gate loosens — tracked as a follow-up.
14. **Code reuse**: direct copy of the events-admin pattern (Option C from brainstorming). Shared `upload-widget.js` stays at its current events path; follow-up PR can abstract when a third duplication reveals concrete shared structure.
15. **Platform architecture**: three new use cases (`CreateInsight`, `UpdateInsight`, `DeleteInsight`) + `ListInsights` gets an `includeUnpublished` parameter. Re-use the existing `SqlInsightRepository::save` for insert + update (ON DUPLICATE KEY UPDATE already handles both). New repo method: `delete(InsightId, TenantId)`.
16. **Search sync preserved**: write-path sync into `insights.search_text` (added by migration 060, wired in the global-search milestone) remains in `SqlInsightRepository::save`. New create/update operations flow through that method so search stays in sync automatically.

## Architecture

### Platform (clean architecture)

New use cases:

```
src/Application/Insight/
    CreateInsight/
        CreateInsight.php          # use case (validate → construct Insight VO → repo.save)
        CreateInsightInput.php     # DTO — tenant, actor, all fields from the modal
        CreateInsightOutput.php    # returns the created Insight projected as an array
    UpdateInsight/
        UpdateInsight.php          # loads existing row, verifies tenant match, updates
        UpdateInsightInput.php
        UpdateInsightOutput.php
    DeleteInsight/
        DeleteInsight.php          # verifies tenant match, calls repo.delete
        DeleteInsightInput.php
    ListInsights/
        ListInsights.php           # MODIFIED — accepts $includeUnpublished, pipes to repo
        ListInsightsInput.php      # MODIFIED — adds bool $includeUnpublished
```

`InsightRepositoryInterface` gains:

```php
public function delete(InsightId $id, TenantId $tenantId): void;
public function listForTenant(TenantId $tenantId, ?string $category, bool $includeUnpublished): array;
// listForTenant second param stays ?string, third adds the new admin flag
// (Backwards compat: existing call sites get default false via controller.)
```

`SqlInsightRepository`:
- `delete(InsightId, TenantId)` — `DELETE FROM insights WHERE id = ? AND tenant_id = ?`
- `listForTenant` SELECT gets conditional `AND published_date <= CURDATE()` when `$includeUnpublished=false`.
- `save` unchanged (already handles both insert + update + search_text sync from the global-search milestone).

`BackstageController`:

```
listInsights(Request)          → GET  /api/v1/backstage/insights
createInsight(Request)         → POST /api/v1/backstage/insights
getInsight(Request, params)    → GET  /api/v1/backstage/insights/{id}
updateInsight(Request, params) → POST /api/v1/backstage/insights/{id}
deleteInsight(Request, params) → DELETE /api/v1/backstage/insights/{id}
```

Each method: check `isAdmin` (platform admin OR tenant admin/moderator), build Input with acting-user + tenant, call use case, map output to JSON.

### Routes

```php
$router->get('/api/v1/backstage/insights', ...)->use(TenantContext + Auth);
$router->post('/api/v1/backstage/insights', ...)->use(TenantContext + Auth);
$router->get('/api/v1/backstage/insights/{id}', ...)->use(TenantContext + Auth);
$router->post('/api/v1/backstage/insights/{id}', ...)->use(TenantContext + Auth);
$router->delete('/api/v1/backstage/insights/{id}', ...)->use(TenantContext + Auth);
```

DI bindings in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php` (BOTH-wire rule).

### Society

```
public/pages/backstage/insights/
    index.php             # list view, modelled on public/pages/backstage/events/index.php
    insight-modal.js      # create/edit modal, modelled on event-modal.js (trimmed)
    insight-modal.css     # modal styles, may inline or pick up existing event-modal.css patterns
public/api/backstage/
    insights.php          # proxy to platform, op=list/create/update/delete
public/index.php          # adds 2 routes:
                          #   GET /api/backstage/insights → api/backstage/insights.php (GET delegations)
                          #   GET /backstage/insights     → pages/backstage/insights/index.php
```

The proxy uses the `?op=` convention already established by `public/api/backstage/events.php` (list, create, update, delete). Actual HTTP verb translation happens inside the proxy — frontend fetches POSTs to `/api/backstage/insights?op=create` etc.

## Components

### `CreateInsight` use case

Validation sequence:
1. Title present + ≤255 chars — else 422
2. Slug present (if empty, derive from title via `Str::slug`-like helper) + unique per tenant (query repo) — else 422
3. Category non-empty
4. Excerpt present + ≤500 chars
5. Content present
6. `published_date` parseable as `Y-m-d`
7. Tags array accepted (can be empty)

Side-effects:
- `reading_time = max(1, ceil(str_word_count(strip_tags($content)) / 200))`
- Generates UUIDv7 for id via `Uuid7::generate()`

Returns `CreateInsightOutput` with the created Insight's fields as a plain array (hydrated back by controller to JSON).

### `UpdateInsight` use case

- Load existing insight via `findBySlugForTenant` or add `findByIdForTenant` (likely the latter; check the real API). If missing OR tenant mismatch → throw `NotFoundException('not_found')`.
- Same validation as CreateInsight except slug uniqueness check excludes the current row.
- Call `repo.save($updated)` — ON DUPLICATE KEY UPDATE handles the update path.

### `DeleteInsight` use case

- Load insight by id, verify tenant match (throw 404 on mismatch so the existence isn't leaked).
- Call `repo.delete($id, $tenantId)`.

### List modification

`ListInsights` executes the repo's `listForTenant` with the caller-provided `$includeUnpublished`. Controller public path passes `false`; backstage path passes `true`.

### BackstageController admin gate

Helper (private):
```php
private function requireInsightsAdmin(Request $r, Tenant $t): ActingUser
{
    $u = $r->requireActingUser();
    $ok = $u->isPlatformAdmin || $u->isAdminIn($t->id);
    if (!$ok) throw new ForbiddenException('forbidden');
    return $u;
}
```

Matches decision 13: admin-only. If `ActingUser::isModeratorIn()` is added in a future milestone, this gate can loosen without touching anything else.

### Society page — `pages/backstage/insights/index.php`

Copy the structural bits of `public/pages/backstage/events/index.php`:
- Header with page title + "Add insight" button
- Filter input (client-side, filters visible rows by title)
- Table columns: Title, Category, Author, Published Date + status pill, Featured badge, Actions (Edit / Delete)
- Modal mount div
- Scripts: upload-widget.js (events path), insight-modal.js

Fetch list via `/api/backstage/insights?op=list` on page load. Render rows in JS. Event handlers wire edit/delete buttons.

### Society modal — `insight-modal.js`

Structurally a trim of `event-modal.js`:
- Opens with either empty form (create) or pre-filled fields from `GET /api/backstage/insights/{id}`
- Fields rendered top-to-bottom: Title, Slug (auto-filled on title blur if empty), Category + Category label + datalist, Author, Published date (date input), Featured (checkbox), Excerpt (textarea), Hero image upload widget (existing events widget, image path = `/uploads/insights/`), Tags (comma-separated text), Content (big textarea)
- Reading-time arvio shown below content textarea, updated on blur
- Save → POST to `/api/backstage/insights?op=create` or `...?op=update&id=...`
- Cancel → close without save
- Validation mirrors server-side; client shows per-field errors returned from server's `errors` object

## Data flow — Create insight

```
admin opens /backstage/insights
  → page fetches GET /api/backstage/insights?op=list
    → society proxy → platform GET /api/v1/backstage/insights
    → returns all statuses (published + scheduled + unpublished)
  → table rendered

admin clicks "Add insight"
  → insight-modal.js opens empty modal
  → admin fills fields, uploads hero image via upload-widget
  → admin clicks "Save"
  → JS POSTs to /api/backstage/insights?op=create with JSON body
    → society proxy: POST /api/v1/backstage/insights with Bearer token
    → platform: TenantContext + Auth middleware
    → BackstageController::createInsight
    → requireInsightsAdmin (403 else)
    → CreateInsight::execute
      - validates
      - reading_time computed
      - new Insight VO with Uuid7::generate()->value() as id
      - repo.save() writes to DB + search_text synced
    → 201 {data: {...insight fields}}
  → modal closes on 2xx, table refreshes
  → toast "Insight created"
```

Update is identical but `?op=update&id=...` and a pre-populated modal.

## Error handling

| Condition | Response |
|---|---|
| Missing required field | `422 {error: 'validation', errors: {field: 'required_or_too_long'}}` |
| Slug already exists in tenant | `422 {error: 'slug_taken'}` |
| Non-admin caller | `403 {error: 'forbidden'}` (controller check) |
| Insight not found OR tenant mismatch on update/delete | `404 {error: 'not_found'}` |
| Body not valid JSON | `400 {error: 'invalid_json'}` |
| DB failure | `500 {error: 'internal'}` + log |
| Hero image upload fails | Upload widget surfaces inline; modal keeps other fields |

## Testing

### Unit (Application layer)

- `CreateInsightTest`:
  - `test_requires_title` / `test_requires_excerpt` / `test_requires_content`
  - `test_rejects_title_over_255_chars`
  - `test_rejects_duplicate_slug_in_same_tenant`
  - `test_computes_reading_time_from_content_word_count` (200-word content → 1 min, 500 → 3 min, empty → 1)
  - `test_allows_empty_tags_array`
- `UpdateInsightTest`:
  - `test_rejects_cross_tenant_edit` (NotFoundException)
  - `test_allows_slug_unchanged` (uniqueness check excludes own row)
  - `test_preserves_id_on_update`
- `DeleteInsightTest`:
  - `test_rejects_cross_tenant_delete`
- `ListInsightsTest` (existing class, add):
  - `test_include_unpublished_returns_future_dated`

### Integration

- `SqlInsightRepositoryTest` (new or expand):
  - `delete_removes_row_only_within_tenant` — insert into tenant A, call delete with tenant B id → row survives; with tenant A id → row gone
  - `save_upsert_for_existing_id_updates_fields_and_search_text`
  - `list_for_tenant_with_include_unpublished_true_returns_scheduled`

### E2E (Playwright chromium, smoke — same CI as today)

`tests/e2e/backstage-insights.spec.ts`:
- `non_admin_gets_redirected_from_backstage_insights` (if society gate enforces)
- `admin_sees_list` (seed one insight, open page, expect title visible)
- `add_insight_modal_opens_on_click`
- ~3 tests, gated on auth fixture if available; skip gracefully otherwise

## Out of scope (tracked for later)

- WYSIWYG editor (TinyMCE / Quill)
- i18n for insights (separate migration + admin-UI)
- Managed category CRUD
- Chip-input tag UI
- Soft delete / archive
- Image library browser (reuses upload-widget.js for single hero)
- Bulk operations (bulk publish/unpublish/delete)
- Draft auto-save

## Migration notes

No DB migration required. Existing `insights` schema already supports all fields. Existing `search_text` column (mig 060) + the write-path sync from global-search milestone handle search indexing automatically as new admin creates/updates flow through `SqlInsightRepository::save`.

## Security notes

- All backstage routes require auth (AuthMiddleware) + tenant context (TenantContextMiddleware) + admin/moderator check at controller
- Slug is user-controlled input; uniqueness enforced at DB level. No XSS risk in slug since it's URL-safe. Title/excerpt/content are stored raw; rendering must continue to use `htmlspecialchars` where they appear as text (existing public insights page already does this per spec).
- Hero image upload reuses proven events pattern; no new file-handling attack surface.
- Delete is hard delete — no recovery path in UI. Accidental deletion must be rare; admin confirmation modal mitigates.
