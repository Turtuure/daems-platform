# Content i18n: events + projects — Design

**Date:** 2026-04-21
**Status:** Approved by user; ready for implementation planning
**Branch:** `dev`
**Milestone:** Admin Panel, section 1.8 (between forum moderation and settings)
**Related specs:**
- `2026-04-20-events-admin-design.md` (events CRUD baseline)
- `2026-04-20-projects-admin-design.md` (projects CRUD baseline)

## 1. Goal

Add full multilingual **content** support to events and projects across the daems-platform + daem-society stack. Admins can edit per-locale translations; the public API negotiates the caller's locale and returns translated content with per-field fallback. ProjectProposal and (new) EventProposal remain single-locale (member chooses one at submission).

Supported locales: **`fi_FI`, `en_GB`, `sw_TZ`**. Pattern is designed to extend to 50+ locales without refactor.

## 2. Scope

### In scope
- DB schema: `events_i18n`, `projects_i18n` per-locale tables; drop translated columns from parent tables after backfill
- Domain: `Daems\Domain\Locale\{SupportedLocale, LocaleNegotiator, TranslationMap}`; refactor `Event`, `Project` entities to carry `TranslationMap`
- Application: locale-aware read use cases (`ListEventsForLocale`, `GetEventBySlugForLocale`, same for projects); translation-update use cases (`UpdateEventTranslation`, `UpdateProjectTranslation`)
- Infrastructure: `SqlEventRepository` and `SqlProjectRepository` extended with `listForTenantInLocale`, `findBySlugForTenantInLocale`, `findByIdWithAllTranslations`, `saveTranslation`
- HTTP: locale negotiation middleware (Accept-Language / ?lang= / X-Daems-Locale); `*_fallback` and `*_missing` markers in responses
- **EventProposal domain** (new): entity, repo interface, SQL repo, `SubmitEventProposal` / `ApproveEventProposal` / `RejectEventProposal` / `ListEventProposalsForAdmin` use cases, controllers, `event_proposals` table with `source_locale`
- **ProjectProposal**: add `source_locale` column (migration 055)
- Backstage admin UI: locale-card pattern for event/project editors; per-row coverage badges in list views; event-proposal review (new) + project-proposal review (gets source_locale badge)
- Public frontend: `src/I18n.php` upgrade to full-locale form + `lang/{fi,en,sw}.php` → `lang/{fi_FI,en_GB,sw_TZ}.php`; `ApiClient` adds `Accept-Language`; events/projects pages consume localized API; member-facing submit-event-proposal and submit-project-proposal pages send `source_locale`
- Tests: unit for value objects & negotiator, integration for repos (MySQL), isolation tests extended, Playwright E2E

### Out of scope
- Backstage "translation coverage dashboard" (global cross-content report) — coverage visible per-item in list + editor; dashboard deferred
- Machine translation (Google Translate / DeepL API) — manual translation only
- `public/pages/events/data/*.php` hardcoded demo events — these are dev placeholders; ignored (admins will create real events in DB)
- 50+ locale scalability UI (search/filter affordances on locale-card grid) — architecture supports it; UI affordances added when supported list actually grows
- Language-specific URL slugs (e.g. `/events/kevätkokous` in fi_FI vs `/events/spring-meetup` in en_GB) — slugs remain shared across locales

## 3. Locale model

### Identifiers
- `Daems\Domain\Locale\SupportedLocale`: value object wrapping one of `['fi_FI', 'en_GB', 'sw_TZ']`. Throws `InvalidLocaleException` on unsupported input.
- Constants: `UiDefaultLocale = 'fi_FI'`, `ContentFallbackLocale = 'en_GB'`. These are *different*: UI chrome defaults to Finnish (user's preferred), content falls back to English when translation missing.

### Negotiation
`Daems\Domain\Locale\LocaleNegotiator::negotiate(Request): SupportedLocale` with priority:
1. `Accept-Language` header — first supported tag, supporting `fi-FI`, `fi_FI`, `fi` forms. Short form maps to default region: `fi → fi_FI`, `en → en_GB`, `sw → sw_TZ`.
2. `?lang=` query param — overrides Accept-Language when present.
3. `X-Daems-Locale` custom header — last resort, consistent with existing `X-Daems-Tenant` pattern.
4. Default: `ContentFallbackLocale` (`en_GB`) for API; `UiDefaultLocale` (`fi_FI`) for frontend chrome.

### Content fallback (per-field)
For a given requested locale, repository returns a `TranslationMap` containing that locale's row. When a field in the requested locale is `NULL` or the locale row doesn't exist, the field is taken from the `en_GB` row. API response includes:
- `{field}_fallback: true` when the value was taken from `en_GB` (not the requested locale)
- `{field}_missing: true` when neither locale has a value (field returned as `null`)

Both flags are boolean, present on every translatable field. Admin UI uses the `coverage` payload to render per-locale progress.

### `src/I18n.php` migration (UI chrome)
- `SUPPORTED = ['fi_FI', 'en_GB', 'sw_TZ']`, `DEFAULT_LOCALE = 'fi_FI'`
- Rename `lang/fi.php` → `lang/fi_FI.php`, `lang/en.php` → `lang/en_GB.php`, `lang/sw.php` → `lang/sw_TZ.php`. File contents unchanged.
- Cookie + session readers: if legacy 2-letter value encountered (`fi`, `en`, `sw`), remap to full form (`fi_FI` etc.) and overwrite cookie on next response.
- Accept-Language parser upgraded to match backend `LocaleNegotiator` logic so both sides agree.
- `I18n::t()` / `I18n::e()` signatures unchanged — no callers need modification beyond the rename.

## 4. DB schema

### Migration 051 — `create_events_i18n`
```sql
CREATE TABLE events_i18n (
    event_id    CHAR(36)     NOT NULL,
    locale      VARCHAR(10)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    location    VARCHAR(255) NULL,
    description TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, locale),
    CONSTRAINT fk_events_i18n_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Migration 052 — `create_projects_i18n`
```sql
CREATE TABLE projects_i18n (
    project_id  CHAR(36)     NOT NULL,
    locale      VARCHAR(10)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    summary     TEXT         NOT NULL,
    description LONGTEXT     NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, locale),
    CONSTRAINT fk_projects_i18n_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Migration 053 — `backfill_events_projects_i18n`
Insert one `fi_FI` row per existing event/project, copying `title`, `location`, `description` (events) and `title`, `summary`, `description` (projects).

```sql
INSERT INTO events_i18n (event_id, locale, title, location, description, created_at, updated_at)
SELECT id, 'fi_FI', title, location, description, created_at, created_at
FROM events;

INSERT INTO projects_i18n (project_id, locale, title, summary, description, created_at, updated_at)
SELECT id, 'fi_FI', title, summary, description, created_at, created_at
FROM projects;
```

### Migration 054 — `drop_translated_columns_from_events_projects`
After code is updated to read from `*_i18n` and backfill is verified:
```sql
ALTER TABLE events DROP COLUMN title, DROP COLUMN location, DROP COLUMN description;
ALTER TABLE projects DROP COLUMN title, DROP COLUMN summary, DROP COLUMN description;
```

### Migration 055 — `add_source_locale_to_project_proposals`
```sql
ALTER TABLE project_proposals
ADD COLUMN source_locale VARCHAR(10) NOT NULL DEFAULT 'fi_FI' AFTER description;
-- Existing rows keep 'fi_FI' (historical assumption)
```

### Migration 056 — `create_event_proposals`
Mirrors `project_proposals` structure. Includes `source_locale` from the start.
```sql
CREATE TABLE event_proposals (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    author_name     VARCHAR(255) NOT NULL,
    author_email    VARCHAR(255) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    event_date      DATE         NOT NULL,
    event_time      VARCHAR(50)  NULL,
    location        VARCHAR(255) NULL,
    is_online       TINYINT(1)   NOT NULL DEFAULT 0,
    description     TEXT         NOT NULL,
    source_locale   VARCHAR(10)  NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME     NULL,
    decided_by      CHAR(36)     NULL,
    decision_note   TEXT         NULL,
    PRIMARY KEY (id),
    KEY event_proposals_tenant_status (tenant_id, status),
    KEY event_proposals_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 5. Domain model

### New value objects (Agent A)
- `Daems\Domain\Locale\SupportedLocale` — enum-style: `fromString()` validating + throwing `InvalidLocaleException` on unsupported input; `value(): string`
- `Daems\Domain\Locale\LocaleNegotiator` — static `negotiate(array $server, array $query): SupportedLocale`; unit-tested across all priority paths
- `Daems\Domain\Locale\TranslationMap` — keyed by `SupportedLocale`, stores `array<string, ?string>` per locale; `forLocale(SupportedLocale): array`, `withFallback(SupportedLocale requested, SupportedLocale fallback): EntityTranslationView` where `EntityTranslationView` carries per-field value + `isFallback` + `isMissing`

### Entity refactor
`Event` and `Project` constructors accept a `TranslationMap` instead of individual `title/description` strings. Getters return a localized view: `$event->view(SupportedLocale $locale): EntityTranslationView`. Tenant-scoped invariants unchanged.

### New domain: `EventProposal`
Mirrors `ProjectProposal`:
- `Daems\Domain\Event\EventProposal` — constructor + getters (id, tenantId, userId, authorName, authorEmail, title, eventDate, eventTime, location, isOnline, description, sourceLocale, status, createdAt, decidedAt, decidedBy, decisionNote)
- `Daems\Domain\Event\EventProposalId`
- `Daems\Domain\Event\EventProposalRepositoryInterface` — `save`, `findById`, `listPendingForTenant`, `listAllForTenant` (with status filter)

## 6. Application layer

### Read path use cases
- `ListEventsForLocale(tenantId, locale)` → `EventView[]` with `{field}_fallback` / `{field}_missing` flags computed
- `GetEventBySlugForLocale(tenantId, slug, locale)` → single `EventView`
- `ListEventWithAllTranslations(tenantId, eventId)` → admin view with all 3 locales + coverage counts
- Same three for projects (`ListProjectsForLocale`, `GetProjectBySlugForLocale`, `ListProjectWithAllTranslations`)

### Write path use cases
- `UpdateEventTranslation(tenantId, eventId, locale, fields, actingUser)` — admin-only, upsert into `events_i18n`
- `UpdateProjectTranslation(tenantId, projectId, locale, fields, actingUser)` — admin-only, upsert into `projects_i18n`

### Proposal use cases (new)
- `SubmitEventProposal(tenantId, userId, authorName, authorEmail, title, eventDate, eventTime, location, isOnline, description, sourceLocale)` → `EventProposalId`
- `ApproveEventProposal(tenantId, proposalId, actingUser)` → creates `Event` entity + `events_i18n` row for `source_locale`; returns new `EventId`
- `RejectEventProposal(tenantId, proposalId, actingUser, note)` — sets status `rejected`, records `decided_at/by/note`
- `ListEventProposalsForAdmin(tenantId, status, limit, offset)`

## 7. API contract

### Public endpoints (locale-negotiated)

**`GET /api/v1/events`**
Request: `Accept-Language: sw-TZ` (or `?lang=sw_TZ`).
Response (200):
```json
[
  {
    "id": "uuid",
    "slug": "annual-meetup-2026",
    "type": "upcoming",
    "event_date": "2026-06-15",
    "event_time": "18:00",
    "is_online": false,
    "hero_image": "/uploads/events/...jpg",
    "title": "Annual Meetup 2026",
    "title_fallback": true,
    "title_missing": false,
    "location": "Helsinki",
    "location_fallback": true,
    "location_missing": false,
    "description": "...",
    "description_fallback": true,
    "description_missing": false
  }
]
```

**`GET /api/v1/events/{slug}`** — same shape, single object.

**`GET /api/v1/projects`** — analogous.
**`GET /api/v1/projects/{slug}`** — analogous.

### Admin endpoints (return all translations)

**`GET /api/v1/backstage/events/{id}`**
```json
{
  "id": "uuid", "slug": "...", "type": "...", "event_date": "...",
  "event_time": "...", "is_online": false, "hero_image": "...",
  "translations": {
    "fi_FI": { "title": "...", "location": "...", "description": "..." },
    "en_GB": { "title": "...", "location": null, "description": null },
    "sw_TZ": null
  },
  "coverage": {
    "fi_FI": { "filled": 3, "total": 3 },
    "en_GB": { "filled": 1, "total": 3 },
    "sw_TZ": { "filled": 0, "total": 3 }
  }
}
```

**`GET /api/v1/backstage/events`** — list view, each item includes `coverage` map (no full `translations`).

**Coverage definition:** `filled` counts the non-null, non-empty-string fields present in the locale's row among the translatable fields (`title`, `location`, `description` for events — `total = 3`; `title`, `summary`, `description` for projects — `total = 3`). If no row exists for the locale, `filled = 0`. `title` is NOT NULL in schema so any existing row has `filled >= 1`. Admins who intentionally leave `location` empty (e.g., online-only event) accept a `filled = 2` / `3` coverage; this is honest reporting, not a defect.

**`PUT /api/v1/backstage/events/{id}/translations/{locale}`**
Body: `{ "title": "...", "location": "...", "description": "..." }`. Null or missing keys allowed for nullable fields. Creates or updates `events_i18n` row.
Response (200): updated `coverage` for all locales.

Same pattern for projects: `GET/PUT /api/v1/backstage/projects/{id}`, `/translations/{locale}`.

### Proposal endpoints

**`POST /api/v1/event-proposals`** (member, authenticated)
Body:
```json
{
  "title": "...", "event_date": "2026-09-01", "event_time": "18:00",
  "location": "...", "is_online": false, "description": "...",
  "source_locale": "fi_FI"
}
```
`source_locale` is required, must be one of `SupportedLocale`. Response (201): `{"proposal_id": "uuid"}`.

**`GET /api/v1/backstage/event-proposals`** (admin) — list; each includes `source_locale` for badge display.

**`POST /api/v1/backstage/event-proposals/{id}/approve`** (admin) — creates event + i18n row for source_locale; returns `{"event_id": "..."}`.

**`POST /api/v1/backstage/event-proposals/{id}/reject`** (admin) — body `{"note": "..."}`; sets status `rejected`.

**`POST /api/v1/project-proposals`** — existing endpoint, body gains optional `source_locale` (default: negotiated locale); non-breaking for existing callers.

**`GET /api/v1/backstage/project-proposals`** — **renamed from existing `/api/v1/backstage/proposals`**. The old generic name `/proposals` becomes ambiguous once event-proposals exist. Rename is safe: the admin UI for this endpoint does not exist yet (Agent B is building it now), so no frontend callers need migration. Backend tests referencing `/backstage/proposals` get updated to `/backstage/project-proposals`. Response items include `source_locale`.

**`POST /api/v1/backstage/project-proposals/{id}/approve`** — renamed from `/backstage/proposals/{id}/approve`. Creates project + i18n row for source_locale.

**`POST /api/v1/backstage/project-proposals/{id}/reject`** — renamed from `/backstage/proposals/{id}/reject`.

## 8. HTTP layer

### Middleware
`Daems\Infrastructure\Adapter\Api\Middleware\LocaleMiddleware` runs after `TenantContextMiddleware` and before controllers. Uses `LocaleNegotiator` to populate `$request->getAttribute('locale')`.

### Controllers
- `EventController` — gains `handleListLocalized`, `handleGetBySlugLocalized`, `handleSubmitProposal`
- `ProjectController` — gains `handleListLocalized`, `handleGetBySlugLocalized`; existing `handleSubmitProposal` gets `source_locale` plumbing
- `BackstageController` — gains event/project translation endpoints and event-proposal CRUD

## 9. Backstage admin UX — locale-cards

### Event / Project editor
The existing event/project editor is a **modal** (`backstage/events/event-modal.js`, `backstage/projects/project-modal.js`) launched from the list page — not a standalone page. Locale cards integrate at the top of the modal body. Non-translated fields (slug, event_date, is_online, hero_image for events; slug, category, icon, status, sort_order, featured for projects) occupy a shared panel below the cards.

Top of modal body renders a card grid (one card per `SupportedLocale`):
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│ 🇫🇮 Suomi  fi_FI     │ │ 🇬🇧 English  en_GB  │ │ 🇹🇿 Kiswahili sw_TZ │
│ ████████████ 3/3    │ │ ██████░░░░░░ 1/3    │ │ ░░░░░░░░░░░░ 0/3    │
│ ✓ Complete          │ │ ⚠ Partial           │ │ ✗ Not translated    │
│        [aktiivinen] │ │                     │ │                     │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘

Title:       [______________________________]
Location:    [______________________________]
Description: [______________________________]
                                  [Save fi_FI]
```

- Click card → becomes active; editor fields repopulate from that locale's `translations` entry
- Save button submits single `PUT /backstage/events/{id}/translations/{active_locale}`
- Response updates coverage → card progress bars re-render without full page reload
- Empty card (locale with no row) opens editor with blank fields; first save creates the row
- Non-translated fields (event_date, slug, is_online, hero_image) live *below* the card grid as a single shared panel

### List views
`/backstage/events` and `/backstage/projects` — each row gets a compact coverage badge: `● ● ○` or textual `3/3 · 1/3 · 0/3` (color-coded). Clicking badge jumps into editor at the least-translated locale.

### Event-proposal review (new)
`/backstage/event-proposals` — new page. List with columns: author, title (in source_locale), event_date, submitted, source_locale badge, actions [Approve / Reject]. Approval flow opens a confirmation modal showing the proposal content and noting "This will create an Event visible only in `{source_locale}` until you translate it."

### Project-proposal review (new — backend exists, UI does not)
`/backstage/project-proposals` — **new page built as part of this work** (backend use cases `ApproveProjectProposal` / `RejectProjectProposal` exist but no admin UI yet). Same layout as event-proposals: author, title (in source_locale), submitted, source_locale badge, actions [Approve / Reject]. Approval confirmation notes the single-locale caveat.

## 10. Public frontend

### `src/I18n.php`
- Constants updated (`SUPPORTED`, `DEFAULT_LOCALE`)
- `lang/*.php` files renamed
- `locale()` method gains normalization: returns `fi_FI`, not `fi`
- Legacy 2-letter cookie/session values remapped to full form on first read
- Accept-Language parser mirrors backend `LocaleNegotiator`

### `ApiClient`
Adds `Accept-Language: {I18n::locale()}` header to every request by default. Callers can override via explicit header argument.

### Events/projects pages
- `public/pages/events/grid.php`, `events/detail.php`, `events/detail/content.php`, `events/detail/hero.php` — render API response fields as-is (`title`, `location`, `description`); no per-locale branching, no `*_fallback` indicator
- Same for `public/pages/projects/grid.php`, `projects/detail.php`, `projects/detail/content.php`, `projects/detail/hero.php`
- `projects/cta.php` and `events/cta.php` remain UI-chrome-only (uses `I18n::t()`)

### Member proposal forms (new + updated)
- **New** `public/pages/events/propose.php` — authenticated member page, form posts to `POST /api/v1/event-proposals` with `source_locale` = current session locale (hidden field, auto-populated from `I18n::locale()`)
- **Updated** existing project-proposal page: same treatment — adds hidden `source_locale` field; existing UX unchanged otherwise

### Admin-only pages remain admin-only
`projects/new.php`, `projects/edit.php`, and analogous event admin pages continue to enforce admin role check.

## 11. Testing

### Unit (`tests/Unit`)
- `SupportedLocaleTest` — valid codes, invalid codes, normalization
- `LocaleNegotiatorTest` — priority order, short→full mapping, fallback
- `TranslationMapTest` — per-field fallback, missing markers

### Integration (`tests/Integration`) — real MySQL via `MigrationTestCase`
- `SqlEventRepositoryTest` — `listForTenantInLocale` returns fallback-marked view; `saveTranslation` upserts; cascade delete via parent
- `SqlProjectRepositoryTest` — analogous
- `SqlEventProposalRepositoryTest` — submit, list by status, update on approve/reject
- `SqlProjectProposalRepositoryTest` — extended to cover `source_locale`

### Isolation (`tests/Isolation`) — extends existing `IsolationTestCase`
- `EventsI18nTenantIsolationTest` — seeding event in tenant A does not leak translations into tenant B's queries
- `ProjectsI18nTenantIsolationTest` — analogous
- `EventProposalTenantIsolationTest` — new proposal domain gets same isolation coverage as project proposals

### E2E (`tests/e2e` via KernelHarness)
- `EventsLocaleE2ETest` — Accept-Language variations return expected locale; `*_fallback` markers correct; admin translation save round-trip
- `ProjectsLocaleE2ETest` — analogous
- `EventProposalFlowE2ETest` — submit proposal with `source_locale`, approve, verify event exists with translation in that locale

### Playwright (daem-society)
- Extend `tests/e2e/i18n.spec.ts`: navigate `?lang=en_GB`, confirm events/projects list pages render English content
- New `tests/e2e/admin-locale-cards.spec.ts`: admin saves `sw_TZ` translation for an event, verifies coverage badge updates and frontend at `?lang=sw_TZ` shows new content

## 12. Workstream split (3 parallel agents)

### Agent A — Platform/Backend
**Owns:** Everything in `daems-platform` repo except frontend rendering.

Workload:
- Migrations 051–056
- Domain: `SupportedLocale`, `LocaleNegotiator`, `TranslationMap`; refactor `Event` and `Project` entities; new `EventProposal` / `EventProposalId` / `EventProposalRepositoryInterface`
- Application: 6 new read use cases + 2 translation-update use cases + 4 new event-proposal use cases
- Infrastructure: `SqlEventRepository` / `SqlProjectRepository` refactor; new `SqlEventProposalRepository`; `LocaleMiddleware`
- HTTP: `EventController` / `ProjectController` / `BackstageController` extensions
- Wiring: bootstrap/app.php **and** tests/Support/KernelHarness.php (see memory `feedback_bootstrap_and_harness_must_both_wire.md` — this MUST be done in both files before marking work complete)
- PHPStan lvl 9 zero errors
- All Unit + Integration + Isolation + E2E tests green

**Contract-lock point:** Agent A publishes the exact JSON response shapes for `GET /api/v1/events`, `GET /api/v1/backstage/events/{id}`, and `PUT /api/v1/backstage/events/{id}/translations/{locale}` at task 1 so agents B and C can start immediately with mocks.

### Agent B — Backstage admin UI
**Owns:** `daem-society/public/pages/backstage/{events,projects,event-proposals,project-proposals}`.

Workload:
- `locale-cards.php` partial + CSS + JS (shared between events and projects modal editors)
- Integrate cards into existing `event-modal.js` and `project-modal.js` (cards at top of modal body; non-translated fields in shared panel below)
- List-view coverage badges on `backstage/events/index.php` and `backstage/projects/index.php`
- **Build** `backstage/event-proposals/index.php` (new page) — list + approval/rejection flow with source_locale badges
- **Build** `backstage/project-proposals/index.php` (new page — UI does not exist yet; backend use cases already exist) — list + approval/rejection flow with source_locale badges
- Playwright smoke: admin saves `en_GB` translation, coverage badge updates; admin approves event proposal → new event visible at `?lang={source_locale}`

### Agent C — Public frontend + I18n migration
**Owns:** `daem-society/src/I18n.php`, `daem-society/lang/`, `daem-society/public/pages/{events,projects}` non-backstage, `ApiClient`.

Workload:
- Upgrade `src/I18n.php` to full-locale form; rename lang files; add legacy-value remapping
- Update `ApiClient` to send `Accept-Language` header
- Refactor events/projects public pages to consume localized API
- New `public/pages/events/propose.php`
- Update existing project-proposal form to include `source_locale` hidden field
- Extend `tests/e2e/i18n.spec.ts` + new `admin-locale-cards.spec.ts` (shared with Agent B)

### Dependencies & coordination
- B and C depend on A's API contract being locked. The spec above IS the contract — no separate contract-lock document needed.
- Merge order: A → B → C into `dev`. If B/C complete before A, they sit on PR branches until A merges.
- Each agent commits to `dev` with `-c user.name="Dev Team" -c user.email="dev@daems.org"`. No Co-Authored-By. Never auto-push.
- `.claude/` never staged (ignored in `.gitignore` already for this repo).

## 13. Rollout

One PR per agent, all targeting `dev`:
1. **PR-i18n-A**: backend + migrations + tests (largest)
2. **PR-i18n-B**: backstage UI (depends on A's API)
3. **PR-i18n-C**: frontend + I18n migration (depends on A's API)

No feature flags — full cutover. Migration 054 (column drop) is irreversible-ish but backfill in 053 is verified against row counts in the same PR. Pre-merge verification: run `composer analyse`, `composer test:all`, and manual smoke of `?lang=en_GB` on `daem-society.local`.

## 14. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Column drop (054) runs before all reads migrated → broken prod | 054 lives in same PR as the use-case refactor; migration helper has built-in row-count verification before dropping |
| Legacy 2-letter cookies break existing user sessions | I18n.php remaps on read; integration test covers the path |
| Accept-Language edge cases (q-values, wildcards) | `LocaleNegotiator` ignores q-values (first supported tag wins), unit-tested; wildcards (`*`) fall through to default |
| Agent B/C finish before A merges | Acceptable — they sit in PR, rebased on `dev` after A lands |
| Event proposal domain introduces surface-area late | Mirrors `ProjectProposal` structurally; pattern already proven |

## 15. Open questions

None at spec-approval time. Implementation planning will surface task-level questions.
