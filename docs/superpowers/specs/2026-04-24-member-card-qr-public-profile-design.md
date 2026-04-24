# Member Card QR + Public Profile + Tenant Prefix Setting — Design

**Date:** 2026-04-24
**Branches:** `member-card-qr` in both `daems-platform` and `daem-society` repos
**Scope:** Both repos. New backend column (tenants), new public API endpoint, new use cases for tenant settings + member privacy, frontend QR rendering on the membership card, new public profile page, two new admin/member UIs.

## Problem

The Daem Society membership card (visible at `/profile/overview.php`) is the user's primary identity artifact, but it is currently inert — there is no way to verify a member's identity from the card alone. The roadmap (§4) calls for a QR code on the card linking to a public verification page that anyone can scan to confirm "this person is a member of Daem Society."

Two related gaps surfaced during brainstorming:

1. **Per-tenant member-number formatting.** The platform is multi-tenant. Daem Society uses a "DAEMS-123" style label for member numbers; other tenants will want different prefixes (or none at all). The display format must be a tenant-level setting, not a hardcoded constant.
2. **Member privacy on the public profile.** A member must be able to choose whether their photo appears on the public profile page. Default = visible, but a single toggle in their own settings flips to monogram-only (initials).

The roadmap also describes the QR target as "name, role, joined date — verifiable identity data." This is a brand-new public page; nothing currently lives at `/members/{number}`.

## Goal

After this work:

- Every Daem Society member's card displays a QR code in the bottom-right corner. Scanning it lands on a verification page.
- The verification page is publicly reachable (no login required), shows the member's name, type, role, joined date, and either their photo or initials based on their privacy preference, plus a disabled "Add as friend" placeholder for the future friend system.
- Tenant admins can configure their own member-number prefix in Settings (or leave it empty for raw numbers).
- Members can toggle their public-profile photo visibility from their own settings.

## Non-goals

- The friend system itself (the "+ Add as friend" button is rendered as disabled "Coming soon"). Separate roadmap item.
- A public member directory or search. The page resolves only by direct URL `/members/{number}`.
- Tenant-aware UIs for non-Daem-Society tenants. Other tenants build their own frontends — this work targets daem-society only on the frontend side. Platform API is generic.
- A unique constraint on `users.member_number`. Today's schema allows duplicates; a real cleanup is a separate concern.
- QR code error-correction-level tuning. Default level used.
- Tenant prefix migration / batch updates for existing data. New column nullable, defaults to NULL, admin sets it later.

## Architecture

The work splits into three sub-packages. Sub-package 1 is backend-only and unblocks 2+3. Sub-package 2 is the QR + public profile flow. Sub-package 3 is the privacy toggle.

| # | Sub-package | Repos | Summary |
|---|---|---|---|
| 1 | Tenant member-number prefix setting | both | New `tenants.member_number_prefix` column + use case + API + Settings UI |
| 2 | QR code on card + public profile page | both | Backend: `GetPublicMemberProfile` use case + `/api/v1/members/{n}` endpoint. Frontend: QR on member card + new `/members/{n}` page |
| 3 | Member privacy toggle for public photo | both | New `users.public_avatar_visible` column + member-self use case + API + profile-settings UI |

Each sub-package is independently mergeable but they share a single PR per repo for shipping convenience (six commits across two PRs).

### Sub-package 1: Tenant member-number prefix

**Backend (daems-platform):**

- Migration: `057_add_member_number_prefix_to_tenants.sql` — `ALTER TABLE tenants ADD COLUMN member_number_prefix VARCHAR(20) NULL`. Default NULL = no prefix shown.
- Domain: extend `Tenant` value object with `memberNumberPrefix(): ?string`.
- Use case: `UpdateTenantSettings` (Application/Backstage/UpdateTenantSettings) — admin only. Input: `TenantId`, `ActingUser`, `?string $memberNumberPrefix`. Validates prefix length (≤20 chars, alphanumeric + hyphen). Updates the tenant row.
- API endpoint: `PATCH /api/v1/backstage/tenant/settings` — body `{member_number_prefix: "DAEMS"}` or `{member_number_prefix: null}`.
- DI: bind `UpdateTenantSettings` in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php` (per CLAUDE.md). Same for new `SqlTenantSettingsRepository` if introduced; otherwise extend existing `SqlTenantRepository`.

**Frontend (daem-society):**

- `public/pages/backstage/settings/index.php` — new "Membership card" section card with input field for the prefix. Read current value from `/auth/me` response (extend `tenant` object to include `member_number_prefix`). Save via `PATCH /api/v1/backstage/tenant/settings`.

**Display logic (shared frontend convention):**

```
function formatMemberNumber(string $rawNumber, ?string $prefix): string {
  $stripped = ltrim($rawNumber, '0');
  if ($stripped === '') $stripped = '0';
  if ($prefix === null || $prefix === '') return $stripped;
  return $prefix . '-' . $stripped;
}
```

`000123` + `DAEMS` → `DAEMS-123`. `000123` + `null` → `123`. `000` + anything → `0`.

### Sub-package 2: QR code on card + public profile page

**Backend (daems-platform):**

- Use case: `GetPublicMemberProfile` (Application/Member/GetPublicMemberProfile) — input: `member_number` (string). Output: `{name, member_type, role, joined_at, member_number_raw, tenant_name, tenant_slug, member_number_prefix, avatar_visible: bool, avatar_initials, avatar_url: ?string}`. No auth. Read-only.
  - Lookup: `SELECT u.* FROM users u WHERE u.member_number = ? AND u.deleted_at IS NULL LIMIT 1`. If duplicates exist (no unique constraint), first match wins.
  - Tenant + role: `SELECT t.name, t.slug, ut.role FROM user_tenants ut JOIN tenants t ON t.id = ut.tenant_id WHERE ut.user_id = ? ORDER BY ut.joined_at ASC LIMIT 1` — first (oldest) tenant membership.
  - Tenant prefix: included from the tenant row.
  - Avatar: if `users.public_avatar_visible = false` (sub-package 3), `avatar_url = null` and frontend renders initials. Otherwise resolve avatar disk path → public URL.
- Repository: `SqlPublicMemberRepository` (Infrastructure/Adapter/Persistence/Sql) — single `findPublicProfile(string $memberNumber): ?PublicMemberProfile` method. NOT tenant-scoped (the whole point is cross-context lookup).
- API endpoint: `GET /api/v1/members/{number}` — public (no auth middleware). Returns `{data: {…}}` or 404.
- DI: bind `GetPublicMemberProfile` + `SqlPublicMemberRepository` in BOTH containers. New `MemberController::getPublicProfile`.

**Frontend (daem-society):**

- `public/index.php` — new route handler `^/members/(\d+)$` BEFORE the existing `^/members/([a-z0-9\-]+)$` member-only-docs handler (numeric-only regex avoids collision with `/members/benefits|board-minutes|guides`).
- `public/pages/members/profile.php` (new) — server-side cURL to `/api/v1/members/{n}`. Renders the page from v8 mockup: avatar (or initials) with diagonal-cut clip-path, "✓ Verified Daem Society member" badge, name, "{type} · {tenant_name}", 4-field grid (№, Type, Role, Since), disabled "+ Add as friend" button with "Coming soon" subtext. NO auth gate.
- `public/pages/profile/overview.php` — add QR canvas inside `member-card`:
  - `<canvas class="member-card-qr" width="160" height="160"></canvas>` at end of card markup
  - JS: render QR via `qrcode-generator` library. URL = `<scheme>://<host>/members/<member_number_raw>`
- `public/assets/css/daems.css` — new `.member-card-qr` CSS:
  - Position absolute, bottom-right (16px from edges)
  - Display 64×64px (canvas drawn at 160×160 for retina), white background
  - Same diagonal-cut clip-path as `.member-card-avatar` (passport-style: top-left + bottom-right diagonal cuts, top-right + bottom-left rounded corners), scaled to a 64×64 square: `clip-path: path('M 0,8 L 0,60 Q 0,64 4,64 L 56,64 Q 58,64 60,62 L 62,60 Q 64,58 64,56 L 64,4 Q 64,0 60,0 L 8,0 Q 5,0 3,2 L 2,3 Q 0,5 0,8 Z')`
- `public/assets/css/daems.css` — also relocate the existing `.member-card-logo` rule from bottom-right to bottom-left (16px offset).
- `public/assets/js/daems.js` — extend the existing `generateCardPNG()` function to draw the QR onto the canvas before export, using the qrcode-generator API to read modules and render dark/light pixels at the correct position.
- `package.json` — add dep `qrcode-generator` (~3 KB, MIT, zero transitive deps).
- Display the formatted `№ DAEMS-123` value in the card's `mcf-val` field — call `formatMemberNumber($user['member_number'], $tenant['member_number_prefix'])` server-side in overview.php.
- Display Country and DOB in full forms: country shown as ISO 3166-1 short name (already stored as country code in `users.country`; map via existing locale-tables or new helper), DOB as ISO `YYYY-MM-DD` (already stored that way; just expose it).

### Sub-package 3: Member privacy toggle

**Backend (daems-platform):**

- Migration: `058_add_public_avatar_visible_to_users.sql` — `ALTER TABLE users ADD COLUMN public_avatar_visible TINYINT(1) NOT NULL DEFAULT 1`. Default 1 = visible. Backfilled implicit.
- Domain: extend `User` value object with `publicAvatarVisible(): bool`.
- Use case: `UpdateMyPublicProfilePrivacy` (Application/Profile/UpdateMyPublicProfilePrivacy) — input: `ActingUser`, `bool $publicAvatarVisible`. Updates the row.
- API endpoint: `PATCH /api/v1/me/privacy` — body `{public_avatar_visible: bool}`.
- DI: bind in both containers.

**Frontend (daem-society):**

- `public/pages/profile/settings.php` — new "Privacy" section with one toggle: "Show my photo on the public profile page (the QR target)". Default ON. Save via `PATCH /api/v1/me/privacy`.

## Component boundaries

- **Backend:** four new use cases (`UpdateTenantSettings`, `GetPublicMemberProfile`, `UpdateMyPublicProfilePrivacy`, plus existing `GetMe` extended with `tenant.member_number_prefix`). Each uses the existing repository + controller pattern. The public endpoint is the only one without `AuthMiddleware`.
- **Frontend:** four touchpoints — Settings page (admin), member card (member self-view), profile settings (member self), public profile page (anyone). Each is a separate file, independently editable.
- **Library boundary:** `qrcode-generator` is sandboxed to `daems.js` and the public profile page. No transitive deps. Easy to swap if needed.

## Data flow

```
Admin sets prefix:
  Settings UI → PATCH /api/v1/backstage/tenant/settings
    → UpdateTenantSettings → SqlTenantRepository → tenants.member_number_prefix updated

Member toggles avatar:
  Profile-settings UI → PATCH /api/v1/me/privacy
    → UpdateMyPublicProfilePrivacy → SqlUserRepository → users.public_avatar_visible updated

Member views own card:
  /profile/overview.php → server-side: load $user + $tenant
    → format member number with prefix
    → render card markup (with QR canvas placeholder)
    → daems.js: build QR with URL https://<host>/members/<raw_number>
    → render QR onto card canvas

Public scans QR → opens https://<host>/members/123:
  /members/123 → daem-society/router → public/pages/members/profile.php
    → server-side cURL GET /api/v1/members/123
    → platform: GetPublicMemberProfile → returns profile + tenant + prefix + avatar
    → page renders verification badge + name + fields + (avatar if visible else initials) + disabled friend button
```

## Error handling

| Failure | Behaviour |
|---|---|
| `member_number` is NULL on the user | Card renders without QR (silent — no QR canvas drawn) |
| `/members/{nonexistent}` | 404 page |
| `/members/{slug-that-isnt-numeric}` | Falls through to existing member-only-docs handler (`/members/benefits` etc still works) |
| Two users share `member_number` (no unique constraint) | Endpoint returns the first match; profile renders normally. Admin can resolve duplicates via dev DB if it ever happens. |
| Tenant prefix is too long (>20 chars) | API returns 400 `validation_error`. Settings UI shows inline error. |
| Tenant prefix contains invalid chars | Same as too long. |
| Member toggles avatar off → public page | `avatar_url = null` in API response → frontend renders monogram (initials) instead of `<img>` |
| `/api/v1/members/{n}` is unreachable from the daem-society server | `/members/{n}` page returns 503 with friendly message |
| Member uploads new avatar, scans QR before page cache clears | Browser cache may serve old image; cache-bust via `?v=<mtime>` already in place for own profile, extend to public profile |

## Testing

**Backend (daems-platform):**

- Unit:
  - `UpdateTenantSettingsTest` — happy path, validation (length, chars), forbidden (non-admin)
  - `GetPublicMemberProfileTest` — happy path, member not found, multi-tenant pick first, no avatar URL when visibility off
  - `UpdateMyPublicProfilePrivacyTest` — happy path
- Integration:
  - `MemberControllerIntegrationTest::testGetPublicProfile200` and `…404`
  - `BackstageTenantSettingsIntegrationTest::testPatchPrefix200` and `…403ForNonAdmin`
  - `MePrivacyIntegrationTest::testPatchPrivacy200`

**Frontend (daem-society):**

- E2E (chromium smoke list, expand if CI-safe):
  - `tests/e2e/public-member-profile.spec.ts` — anonymous visit `/members/{seeded-number}` → verification badge visible, name visible, disabled friend button visible
  - existing card tests gain assertions: QR canvas exists, QR has `data-url` matching pattern

Manual verification:
- Visit `/profile/overview.php` as a member → card shows QR with proper diagonal-cut shape; scanning with phone opens `/members/{number}` page on daem-society host.
- Visit `/backstage/settings` as admin → "Membership card" section with prefix input; save persists; refresh; value remains.
- Visit `/profile/settings` as member → privacy toggle; flip off; visit `/members/{own-number}` from incognito → initials shown instead of photo.

## File inventory

**daems-platform repo:**

| Path | Action |
|---|---|
| `database/migrations/057_add_member_number_prefix_to_tenants.sql` | Create |
| `database/migrations/058_add_public_avatar_visible_to_users.sql` | Create |
| `src/Domain/Tenant/Tenant.php` | Modify (add prefix field + getter) |
| `src/Domain/User/User.php` | Modify (add publicAvatarVisible field + getter) |
| `src/Application/Backstage/UpdateTenantSettings/` | Create (3 files: UseCase + Input + Output) |
| `src/Application/Member/GetPublicMemberProfile/` | Create (3 files) |
| `src/Application/Profile/UpdateMyPublicProfilePrivacy/` | Create (3 files) |
| `src/Domain/Member/PublicMemberProfile.php` | Create (value object) |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlPublicMemberRepository.php` | Create |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php` | Modify (add update + read prefix) |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` | Modify (add update privacy + read flag) |
| `src/Infrastructure/Adapter/Api/Controller/MemberController.php` | Create (light) |
| `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` | Modify (add updateTenantSettings method) |
| `src/Infrastructure/Adapter/Api/Controller/AuthController.php` | Modify (extend `me()` response with prefix + privacy) |
| `src/Infrastructure/Adapter/Api/Controller/UserController.php` | Modify (add `updateMyPrivacy` method) — existing controller, no new file |
| `routes/api.php` | Add 3 new routes |
| `bootstrap/app.php` | Add 3 new bindings |
| `tests/Support/KernelHarness.php` | Add 3 new bindings |
| `tests/Unit/Application/…` | 3 new test files |
| `tests/Integration/…` | 3 new integration test files |
| `tests/Support/Fake/InMemoryTenantRepository.php` | Modify (support prefix field) |
| `tests/Support/Fake/InMemoryUserRepository.php` | Modify (support privacy flag) |

Estimated diff: ~600 lines (mostly tests + DI plumbing).

**daem-society repo:**

| Path | Action |
|---|---|
| `package.json` + `package-lock.json` | Modify (add qrcode-generator dep) |
| `public/index.php` | Modify (add `^/members/(\d+)$` route BEFORE existing slug handler) |
| `public/pages/members/profile.php` | Create (public profile page) |
| `public/pages/profile/overview.php` | Modify (QR canvas + format member_number with prefix) |
| `public/pages/profile/settings.php` | Modify (add Privacy section with avatar toggle) |
| `public/pages/backstage/settings/index.php` | Modify (add Membership card section with prefix input) |
| `public/assets/css/daems.css` | Modify (add `.member-card-qr` rule + relocate `.member-card-logo` from bottom-right to bottom-left) |
| `public/assets/js/daems.js` | Modify (QR rendering + canvas-export QR drawing) |
| `tests/e2e/public-member-profile.spec.ts` | Create (1 spec, ~30 lines) |

Estimated diff: ~250 lines.

## Implementation order

Two PRs. Backend ships first (Frontend depends on backend endpoints existing).

1. **PR `member-card-qr` in daems-platform** — Sub-packages 1+2+3 backend (migrations, domain, use cases, controllers, routes, tests, DI). ~3-4h.
2. **PR `member-card-qr` in daem-society** — Sub-packages 1+2+3 frontend (Settings UI, public profile page, member privacy toggle, QR rendering on card + canvas export). ~2-3h.

Mergaa platform → sync society dev → start society work. No parallel because society server-side cURLs the platform endpoints.
