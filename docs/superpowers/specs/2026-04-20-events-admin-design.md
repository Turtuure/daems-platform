# Events Admin — Design

**Date:** 2026-04-20
**Branch:** `dev`
**Status:** Design complete, pending user review
**Prereqs:** Backstage infra (tenant scoping, AuthMiddleware, member admin page) + `v2026-04-20-approve-anonymise` tag.

---

## 1. Goal

Add the `/backstage/events` admin page (roadmap §4). Admins can create, edit, publish, archive, and manage registrations for events, with image upload for hero + gallery. Published events appear on the public site; drafts stay hidden; archived events disappear from listings but persist for statistics.

---

## 2. Scope

**In:**
- Event CRUD from backstage with `draft / published / archived` status.
- Image upload for hero + gallery (multipart, server-side resize).
- Registration list + admin-initiated unregister (user can still unregister from archived events).
- Status filter + type filter on the admin listing.

**Out (YAGNI, explicit):**
- Capacity limits / waitlist.
- Registration approval workflow (registrations remain auto-approved).
- Recurring events.
- Ticketing / payments (roadmap §5–§6 territory).
- Calendar export, email reminders.
- Audit log per-event (add later if needed — member-level audit already covers who-did-what for members).

---

## 3. Data-model changes

### Migration 043 — `events.status`

```sql
ALTER TABLE events
    ADD COLUMN status ENUM('draft','published','archived')
        NOT NULL DEFAULT 'published'
        AFTER type;

UPDATE events SET status = 'published' WHERE status IS NULL;
```

All existing rows get `status = 'published'` (pre-release state — everything currently visible stays visible).

### No new tables

- `event_registrations` stays as-is; the "list participants" feature is just a `SELECT ... WHERE event_id = ?` + JOIN on `users`.
- Upload storage is filesystem-based (see §5). No DB table for images — URLs live in `events.hero_image` (string) and `events.gallery_json` (JSON array of strings).

---

## 4. Backend — use cases + HTTP

### 4.1 Use cases (all under `src/Application/Backstage/...`)

- **`ListEventsForAdmin`** — returns every event for the active tenant regardless of status. Filters: `status`, `type`, pagination.
- **`CreateEvent`** — validates required fields, generates slug, persists with status = `draft` by default (caller can override to `published`). Returns new event ID + slug.
- **`UpdateEvent`** — partial update: title, type, event_date, event_time, location, is_online, description, hero_image, gallery_json. Status unchanged by this endpoint (separate endpoints below).
- **`PublishEvent`** — transitions `draft` or `archived` → `published`.
- **`ArchiveEvent`** — transitions any status → `archived`.
- **`ListEventRegistrations`** — returns `[{user_id, name, email, registered_at}]` for a given event (tenant-scoped).
- **`UnregisterUserFromEvent`** — admin removes a user from an event. Reuses existing `EventRepositoryInterface::unregister`.

All use cases check `$acting->isAdminIn($tenantId)` (tenant admin) OR `$acting->isPlatformAdmin` — same pattern as `ChangeMemberStatus`.

### 4.2 Domain / repo additions

- `EventRepositoryInterface` gains: `listAllStatusesForTenant(TenantId, filters, page, perPage)`, `updateForTenant(EventId, TenantId, array $fields)`, `setStatus(EventId, TenantId, 'draft'|'published'|'archived')`, `listRegistrationsForEvent(EventId, TenantId): iterable`.
- Public `listForTenant` narrows its SQL to `WHERE status = 'published'` — existing callers (public events page) only ever see published events.
- `Event` entity gains `status()` accessor + constructor arg (`?string`, default `published` for back-compat in existing test constructions — widen, don't narrow).

### 4.3 HTTP routes

Under `/api/v1/backstage/events` — all `[TenantContextMiddleware, AuthMiddleware]`:

| Method | Path | Handler | Purpose |
|---|---|---|---|
| GET | `/backstage/events` | `BackstageController::listEvents` | Admin listing with filters |
| POST | `/backstage/events` | `BackstageController::createEvent` | Create (draft by default) |
| POST | `/backstage/events/{id}` | `BackstageController::updateEvent` | Update fields |
| POST | `/backstage/events/{id}/publish` | `BackstageController::publishEvent` | → published |
| POST | `/backstage/events/{id}/archive` | `BackstageController::archiveEvent` | → archived |
| GET | `/backstage/events/{id}/registrations` | `BackstageController::listEventRegistrations` | Participant list |
| POST | `/backstage/events/{id}/registrations/{user_id}/remove` | `BackstageController::removeEventRegistration` | Admin unregister |
| POST | `/backstage/events/{id}/images` | `MediaController::uploadEventImage` | File upload (multipart) |
| POST | `/backstage/events/{id}/images/delete` | `MediaController::deleteEventImage` | Delete by URL |

`MediaController` is a new controller to keep `BackstageController` focused. Body for delete is JSON `{ "url": "/uploads/events/..." }`.

### 4.4 Response shapes

- List: `{ items: [{id, slug, title, type, status, event_date, event_time, location, is_online, registration_count}], total }`.
- Create/Update: `{ data: { id, slug } }` → 201 (create) / 200 (update).
- Publish/Archive: `{ data: { id, status } }`.
- Registrations: `{ items: [{user_id, name, email, registered_at}] }`.
- Upload success: `{ data: { url: "/uploads/events/<event_id>/<filename>" } }` → 201.

---

## 5. File upload

### 5.1 Storage layout

`<daems-platform>/public/uploads/events/<event_id>/<uuid>.<ext>`

- `event_id` is UUID7 — not guessable. Serving is via Apache static file handler (Laragon already serves `public/` as document root). No DB row per image; URL goes straight into `events.hero_image` or `events.gallery_json`.
- On event archive/delete later, a cleanup task could prune the directory — not in scope for this PR.

### 5.2 Validation

- Accepted MIME types: `image/jpeg`, `image/png`, `image/webp`, `image/gif` (static — reject animated gifs via frame count check).
- Max file size: **5 MiB** before resize.
- Max files per event: **15** (1 hero + 14 gallery). Server enforces on upload — if over, 422.
- Extension derived from validated MIME, not from user-provided filename (prevents extension spoofing).

### 5.3 Processing

- On upload, load with GD (`imagecreatefromjpeg` / `imagecreatefrompng` / `imagecreatefromwebp`).
- If longest edge > **2048 px**, resize proportionally.
- Re-encode as JPEG quality 85 (non-transparent) or PNG (transparent). Strips EXIF metadata as a side effect — good for privacy and size.
- Store result under `<uuid>.jpg` or `.png`.
- If GD extension is missing (unlikely on Laragon but possible), fall back to storing the original — log a warning.

### 5.4 Authorization

Same admin check as other backstage endpoints. The upload endpoint binds the URL to `{id}` — uploads can only be placed in an event's folder that the admin has permission to modify. Cross-tenant abuse blocked by the tenant-scoped event lookup.

---

## 6. Frontend — `/backstage/events`

### 6.1 Route + navigation

- Already declared: `public/pages/backstage/layout.php:151` has the sidebar link.
- Add page: `public/pages/backstage/events/index.php`.
- Backstage layout renders the page; admin guard already applied.

### 6.2 Page structure

```
┌─────────────────────────────────────────────────────────────┐
│ Events                                         [+ New event] │
├─────────────────────────────────────────────────────────────┤
│ Status: [All ▼]  Type: [All ▼]  Search: [         ]         │
├──────┬──────────────┬──────────┬──────┬────────┬────────────┤
│ Date │ Title        │ Type     │ Stat │ Regist │ Actions    │
├──────┼──────────────┼──────────┼──────┼────────┼────────────┤
│ 2026 │ Demo Day     │ Upcoming │ Draft│ 0      │ ✏ 📢 🗄   │
│ 2025 │ Summer Mixer │ Past     │ Pub  │ 42 ▸   │ ✏ 🗄       │
│ 2024 │ Retro Talk   │ Past     │ Arch │ 18 ▸   │ ✏ 📢       │
└──────┴──────────────┴──────────┴──────┴────────┴────────────┘
```

- **+ New event** opens the Create modal.
- **✏ Edit** opens Edit modal (same modal, different mode).
- **📢 Publish** (only when draft/archived): one-click → confirm → `POST /publish`.
- **🗄 Archive** (only when draft/published): one-click → confirm → `POST /archive`.
- **Registrations column** shows count; clicking opens a "Participants" modal.

### 6.3 Create/Edit modal

Single modal, two modes. Fields:

- Title (required, 3–200 chars)
- Type (select: `upcoming` / `past` / `online`)
- Event date (required)
- Event time (optional)
- Location (optional, but required if `is_online = false`)
- Is online (checkbox)
- Description (required, multi-line, min 20 chars)
- Hero image — upload widget (single)
- Gallery — upload widget (up to 14 images, drag-reorder)

**Save** button → `POST /backstage/events` (create) or `POST /backstage/events/{id}` (edit) with JSON body of non-file fields. Files are uploaded separately via the upload endpoint; the returned URL is added to `hero_image` or `gallery_json` at save time.

**Submit flow for new event with images:**
1. User fills fields + drops image files.
2. On "Save draft" or "Save & publish":
   - Client first POSTs `/backstage/events` with the fields (status based on button). Receives `{id}`.
   - Client uploads each pending image via `POST /backstage/events/{id}/images`. Receives URLs.
   - Client sends one final `POST /backstage/events/{id}` with `hero_image` + `gallery_json` filled in.
3. UI shows progress during uploads (`3/5 images uploaded...`).

This two-phase save avoids a giant multipart request. If any image upload fails, the event exists as a draft without that image — user can retry from the edit modal.

### 6.4 Participants modal

- Table: Name · Email · Registered at · [Remove] button.
- Remove → confirm → `POST /backstage/events/{id}/registrations/{user_id}/remove`.
- After successful remove: row fades out, count in main table updates.

### 6.5 Upload widget

- Drag-and-drop area + "Browse" button.
- Immediate client-side checks: file type + size, reject bad files with inline message.
- Preview thumbnail once uploaded.
- Delete button on each thumbnail → `POST /backstage/events/{id}/images/delete` with `{url}`.
- Reorder: drag-to-sort within gallery; order persisted in `gallery_json` array.

### 6.6 Styling

- Reuse existing backstage CSS (`card`, `btn`, `btn--primary`, `btn--ghost`, status pills from Members page).
- Status pill colours: `draft` = gray, `published` = green, `archived` = amber.

### 6.7 Proxies

- `public/api/backstage/events.php` — relays list/create/update/publish/archive/registrations/remove.
- `public/api/backstage/event-upload.php` — relays multipart upload (requires forwarding `$_FILES` through `ApiClient`).

---

## 7. Testing

### 7.1 Unit (Application layer)

- `CreateEventTest` — required-field validation, slug generation, default status = draft, admin authorization, non-admin forbidden.
- `UpdateEventTest` — partial updates, tenant scoping, not-found, forbidden.
- `PublishEventTest`, `ArchiveEventTest` — status transitions, forbidden.
- `ListEventsForAdminTest` — includes all statuses, respects filter.
- `ListEventRegistrationsTest`, `UnregisterUserFromEventTest`.

### 7.2 Integration (real MySQL)

- `EventsAdminIntegrationTest` — full create → upload (mock file) → update → publish → archive flow; asserts DB state + registration_count join works.

### 7.3 Isolation

- `EventsAdminTenantIsolationTest` — tenant A admin cannot list/update/archive tenant B's events.

### 7.4 E2E (KernelHarness)

- `EventAdminEndpointsTest` — covers each HTTP endpoint's happy + error paths.

### 7.5 Upload smoke

Upload is hard to unit-test against GD. Integration-level test: POST a 1×1 PNG to the upload endpoint, verify the returned URL exists on disk + resizes don't mangle it.

### 7.6 Manual UAT

1. Create draft event → appears in list with `draft` pill, not visible on public `/events`.
2. Edit draft → change title, upload hero image → saved, thumbnail renders.
3. Publish → pill turns green → event appears on public `/events`.
4. Register from public as a logged-in user → registration count = 1 on admin page.
5. Open participants modal → remove registration → count = 0.
6. Archive event → pill turns amber → disappears from public list but remains in admin list.
7. As a logged-in registered user of archived event, unregister via user profile → succeeds.

---

## 8. Security considerations

- **Path traversal in uploads:** server ignores user filename; UUID + validated extension only.
- **MIME vs extension mismatch:** validate by reading file header (GD's image open fails on bad files), not trust `$_FILES['file']['type']`.
- **Cross-tenant uploads:** `{id}` parameter resolved against `events WHERE tenant_id = ?` — 404 if not in acting's tenant.
- **XSS in description:** description is plain text displayed with `htmlspecialchars` on output. No HTML/markdown rendering in MVP.
- **Disk filling:** 5 MiB × 15 images × 100 events × N tenants = bounded; low risk for internal-only site. Monitor disk; add alert later.

---

## 9. Migration plan

1. Ship migration 043 + all backend/frontend changes in one PR.
2. On dev DB: apply 043, existing events get `status='published'`.
3. No data migration for existing uploads — events without hero images stay that way.
4. Bump `IsolationTestCase::runMigrationsUpTo(43)`.

---

## 10. Files (inventory)

**New (backend):**
- `database/migrations/043_add_status_to_events.sql`
- `src/Application/Backstage/ListEventsForAdmin/{ListEventsForAdmin, Input, Output}.php`
- `src/Application/Backstage/CreateEvent/{CreateEvent, Input, Output}.php`
- `src/Application/Backstage/UpdateEvent/{UpdateEvent, Input, Output}.php`
- `src/Application/Backstage/PublishEvent/{PublishEvent, Input}.php`
- `src/Application/Backstage/ArchiveEvent/{ArchiveEvent, Input}.php`
- `src/Application/Backstage/ListEventRegistrations/{ListEventRegistrations, Input, Output}.php`
- `src/Application/Backstage/UnregisterUserFromEvent/{UnregisterUserFromEvent, Input}.php`
- `src/Application/Backstage/UploadEventImage/{UploadEventImage, Input, Output}.php`
- `src/Application/Backstage/DeleteEventImage/{DeleteEventImage, Input}.php`
- `src/Domain/Storage/ImageStorageInterface.php` + `src/Infrastructure/Storage/LocalImageStorage.php` (handles disk writes + GD resize)
- `src/Infrastructure/Adapter/Api/Controller/MediaController.php`
- Integration/Isolation/E2E tests as listed above.

**Modified (backend):**
- `src/Domain/Event/Event.php` — add status.
- `src/Domain/Event/EventRepositoryInterface.php` — add `listAllStatusesForTenant`, `updateForTenant`, `setStatus`, `listRegistrationsForEvent`.
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php` — implement above + tighten public `listForTenant` to `status='published'`.
- `src/Application/Event/ListEvents/ListEvents.php` — double-check it uses the now-narrowed `listForTenant` or remains correct.
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add event CRUD + registration methods.
- `routes/api.php` — register new routes.
- `bootstrap/app.php` + `tests/Support/KernelHarness.php` — bind all new classes (both containers — see memory on DI wiring).
- `tests/Isolation/IsolationTestCase.php` — bump to 43.

**New (daem-society):**
- `public/pages/backstage/events/index.php`
- `public/pages/backstage/events/event-modal.js`
- `public/pages/backstage/events/event-modal.css`
- `public/pages/backstage/events/upload-widget.js`
- `public/api/backstage/events.php` (JSON relay)
- `public/api/backstage/event-upload.php` (multipart relay — forwards `$_FILES` to backend via cURL)
- `public/uploads/events/` directory (gitignored or not — see .gitignore check in plan phase)

**Modified (daem-society):**
- `public/pages/backstage/layout.php` — sidebar link already exists; no change needed.
- Possibly `public/assets/css/daems-backstage.css` — modal + upload widget styles if not inline.

---

## 11. Rollout

Single PR on `dev`. Migration 043 forward-only. No breaking changes to the public API — `GET /api/v1/events` keeps returning only published events (new default), but since all existing rows get `status='published'`, nothing disappears from the public site. Manual UAT via checklist in §7.6.
