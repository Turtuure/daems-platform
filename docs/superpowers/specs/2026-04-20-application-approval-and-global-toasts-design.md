# Application Approval Flow + Global Toast Notifications — Design

**Date:** 2026-04-20
**Branch:** `dev`
**Status:** Design complete, pending user review
**Prereqs:** PR 1–4 merged (tenant infrastructure, Backstage Applications + Members API).

---

## 1. Goal

Complete two tightly related pieces of the Admin Panel that PR 4 left unfinished:

1. **Approve flow** — turn `DecideApplication` from a status+audit update into a real activation: when an admin approves a member/supporter application, the system creates the `users` row, attaches them to the tenant with the correct role, assigns a member number (members only), and issues an invite token so the new user can set their own password.
2. **Global toast notifications** — lift the pending-applications toast out of the dashboard so it appears on every backstage page, backed by the `admin_application_dismissals` table roadmap section 7 specified, with per-session dismiss behaviour.

These ship in one PR because the toast is the UI contract for the approve flow: approving an application must make its toast disappear, and a newly-created pending application must make a toast appear on whichever backstage page the admin is on.

---

## 2. Current state (as of commit b5a3b94)

**Approve flow (broken):**
- `DecideApplication.php` updates `{member,supporter}_applications.status`, writes `decided_at/by/note`, writes an audit row. Nothing else happens.
- No `users` row is created. No `user_tenants` attach. No member number. No credentials for the new user.
- Result: an "approved" application is a dead record; the applicant cannot log in, does not appear in `/backstage/members`, does not exist anywhere outside the applications table.

**Toast (partial):**
- Dashboard (`sites/daem-society/public/pages/backstage/index.php` L108–230) renders an inline PHP toast from `/backstage/stats` data. Fixed top-right, no dismiss logic, no persistence.
- Other backstage pages (`/applications`, `/members`, future `/events`, etc.) have no toast at all.
- No `admin_application_dismissals` table exists yet.

**Supporting infra that exists and we can lean on:**
- `auth_tokens` (session tokens keyed by `token_hash`) — we'll tie dismissals to session identity via `admin_id` and clear on new login.
- `user_tenants` pivot with `role ENUM(admin, moderator, member, supporter, registered)`.
- `users` has `member_number VARCHAR(30) NULL`, `membership_type`, `membership_status`, country, address fields — enough for activation without schema extension.
- `member_status_audit` (migration 035) is the right audit sink when we flip a new user from no-status to `active`.

---

## 3. Design decisions (auto-mode assumptions)

These are the open questions the user listed, with the chosen answers:

| Question | Decision | Rationale |
|---|---|---|
| Supporter: login user vs organisation entity? | Create a `users` row for `contact_person` + `email`, attach to tenant with `role='supporter'`. Org fields (`org_name`, `reg_no`) stay on `supporter_applications` as the record of truth. No `organizations` table yet. | YAGNI. A supporter today is fundamentally a person who acts on behalf of an org; organisation-as-first-class-entity can come when projects/events need to attribute ownership to orgs (roadmap §5–6). Avoiding premature refactor. |
| Member number format | Zero-padded 5-digit string, tenant-scoped, `CAST(member_number AS UNSIGNED) + 1` per tenant, starting at `00001`. Allocated inside the same transaction as the `users` INSERT under `SELECT ... FOR UPDATE` on a `tenant_member_counters` row to avoid races. | Simple, predictable, matches existing `member_number VARCHAR(30)` column without migration. Tenant-scoped counter prevents cross-tenant collisions and keeps numbers dense per tenant. |
| Password: generate & email vs invite-link | **Invite token.** `users.password_hash` left `NULL`; `LoginUser` treats NULL hash as "account not activated" (same generic error, no user enumeration). `user_invites` row holds `token_hash + expires_at + used_at`. Invite URL returned in the decision API response so admin can copy/paste while Mailu is not yet deployed. | No SMTP yet (roadmap §2, not blocking). Invite flow is what we want long-term anyway; returning the URL in the approval response is a temporary bridge until Mailu lands — no code is thrown away, we just add a mailer that calls the same invite issuer. |
| `password_hash` NOT NULL | Make nullable in a new migration (036). LoginUser rejects NULL hashes with the generic "invalid email or password" message. | Cheaper than placeholder hashes and makes invite state unambiguous at the schema level. |
| `date_of_birth` NOT NULL blocks supporter creation | Make nullable in a new migration (037). Supporter applications have no DoB — we must allow it. Member applications already carry DoB, so members stay populated. | DoB is a member-only attribute in practice; schema should reflect that. |
| Toast dismissal persistence | Backend `admin_application_dismissals` table exactly as roadmap §7 specifies. On every successful login, `LoginUser` deletes rows `WHERE admin_id = ?`. Approving/rejecting an application also deletes its dismissal rows. | Matches roadmap contract ("dismissed for this session, reappears on next login"). Session identity is cheaply approximated by "did you log in again?" — we don't need to tie rows to a specific `auth_tokens.id`. |

---

## 4. Data model changes

Five new migrations, numbered 036–040, in order:

### 036 — `users.password_hash` nullable
```sql
ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL;
```

### 037 — `users.date_of_birth` nullable
```sql
ALTER TABLE users MODIFY date_of_birth DATE NULL;
```

### 038 — `tenant_member_counters`
```sql
CREATE TABLE tenant_member_counters (
    tenant_id  CHAR(36) NOT NULL,
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tmc_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
Seed: one row per existing tenant with `next_value = COALESCE(MAX(CAST(member_number AS UNSIGNED)), 0) + 1` via backfill in the same migration file, joining `users` ↔ `user_tenants` to scope per tenant.

### 039 — `user_invites`
```sql
CREATE TABLE user_invites (
    id         CHAR(36) NOT NULL,
    user_id    CHAR(36) NOT NULL,
    tenant_id  CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    issued_at  DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_invites_token (token_hash),
    KEY idx_user_invites_user (user_id),
    CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_invites_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
Token lifetime: 7 days. Raw token never stored — `token_hash = SHA256(token)`. Invite URL format: `https://<host>/invite/<raw-token>`.

### 040 — `admin_application_dismissals`
Verbatim from roadmap §7:
```sql
CREATE TABLE admin_application_dismissals (
    id           CHAR(36) NOT NULL,
    admin_id     CHAR(36) NOT NULL,
    app_id       CHAR(36) NOT NULL,
    app_type     ENUM('member','supporter') NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_app (admin_id, app_id),
    KEY idx_aad_admin (admin_id),
    CONSTRAINT fk_aad_admin FOREIGN KEY (admin_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No `tenant_id` column: a platform admin's dismissal of a specific `app_id` is unambiguous because `app_id` is a UUID. Tenant scoping happens when we query pending applications, not when we ask "did this admin dismiss this row?"

---

## 5. Backend: use cases, repositories, API

### 5.1 Approve flow — rework of `DecideApplication`

Current `DecideApplication` becomes two code paths:

- **Rejected:** unchanged — `recordDecision('rejected', ...)`, return.
- **Approved:** orchestrate activation inside a DB transaction:
  1. Look up application row for tenant; reject if already decided.
  2. If `type === 'member'`:
     - Allocate member number: `SELECT next_value FROM tenant_member_counters WHERE tenant_id = ? FOR UPDATE`, increment, write back, zero-pad to 5 digits.
     - INSERT `users` row from application fields (`name`, `email`, `date_of_birth`, `country`), `password_hash = NULL`, `member_number = '00042'`, `membership_status = 'active'`, `membership_type = 'individual'`.
     - INSERT `user_tenants` row `(user_id, tenant_id, role='member', joined_at=now, left_at=NULL)`.
     - INSERT `member_status_audit` row: `previous_status=NULL, new_status='active', reason='application_approved'`.
  3. If `type === 'supporter'`:
     - INSERT `users` row from application fields (`name=contact_person`, `email`, `country`), `password_hash = NULL`, `date_of_birth = NULL`, `member_number = NULL`, `membership_type = 'supporter'`, `membership_status = 'active'`.
     - INSERT `user_tenants` row `(user_id, tenant_id, role='supporter', ...)`.
     - (No member_status_audit — that table is member-scoped.)
  4. Call `IssueInvite(user_id, tenant_id)` — generates raw token (32 random bytes, base64url), stores hash, returns raw token + URL.
  5. Call existing `recordDecision('approved', ...)` to stamp the application row.
  6. Delete any `admin_application_dismissals` rows for this `app_id` (so it disappears from all admins' toast lists).
  7. Commit.

New wiring (bootstrap + KernelHarness BOTH — see memory `feedback_bootstrap_and_harness_must_both_wire.md`):

- `MemberActivationService` (Application layer) — wraps steps 2a–2c above; takes repos for `users`, `user_tenants`, `member_status_audit`, `tenant_member_counters`.
- `SupporterActivationService` (Application layer) — wraps 3a–3b.
- `IssueInvite` (Application layer) — signature: `execute(UserId, TenantId): IssueInviteOutput { rawToken, inviteUrl, expiresAt }`. Depends on `UserInviteRepositoryInterface`, `Clock`, `TokenGenerator`, a `BaseUrlResolver` for the host component of the URL.
- New repos: `UserInviteRepositoryInterface` + `SqlUserInviteRepository` + `InMemoryUserInviteRepository`. Same for `TenantMemberCounterRepositoryInterface`.
- `DecideApplication` constructor gains the two activation services + `IssueInvite` + a DB transaction boundary. Output grows: `approved` responses now carry `activated_user_id`, `invite_url`, `invite_expires_at`, `member_number` (member only).

### 5.2 Invite redemption (minimal)

Out of scope **to fully build**, in scope **to stub the endpoint** so the invite URL has somewhere to point:

- `POST /api/v1/auth/invites/redeem` — body `{ token, password }`. Validates unused + unexpired, hashes `password` with bcrypt cost 10, writes to `users.password_hash`, marks invite `used_at = now`. Returns a standard login response (same shape as `POST /auth/login`).
- Daem-society gets a minimal page `public/pages/invite.php` that reads `?token=` from the URL, posts to the redeem endpoint, and drops into session on success. No styling beyond the existing auth layout.
- This is intentionally the thinnest slice that makes the invite URL usable. Password strength rules, re-send flow, admin revocation, email delivery — all out of scope.

### 5.3 Pending-applications feed for toasts

New endpoint:
```
GET /api/v1/backstage/applications/pending-count
  → { items: [{ id, type, name, created_at }...], total }
```
- Returns every pending member + supporter application for the active tenant that the **calling admin has not dismissed**.
- Filter: `LEFT JOIN admin_application_dismissals aad ON aad.app_id = app.id AND aad.admin_id = ?` where `aad.id IS NULL`.
- Capped at 50 items (more than 50 pending applications should trigger a roll-up badge, not 50 individual toasts — see §6).

New endpoint:
```
POST /api/v1/backstage/applications/{type}/{id}/dismiss
  → 204
```
- Upsert into `admin_application_dismissals` keyed by `(admin_id, app_id)`.
- Idempotent — dismissing an already-dismissed application is a no-op 204.

### 5.4 LoginUser change

`LoginUser.execute` gains one side effect on the success path: delete `admin_application_dismissals WHERE admin_id = user.id`. This is the "session" boundary. Failed logins do not clear dismissals.

Also: `LoginUser` rejects `password_hash IS NULL` as "invalid email or password" (same message as real failures to avoid enumerating invite-pending accounts).

---

## 6. Frontend: global toast system (daem-society)

### 6.1 Scope

Every backstage page must show the toast stack. Today `layout.php` is the shared backstage chrome — we add the toast there, not in individual pages.

### 6.2 Architecture

One JS module `public/pages/backstage/toasts.js`, one CSS file `public/pages/backstage/toasts.css`, one include in `layout.php`:

- On DOMContentLoaded: call the existing `ApiClient` wrapper (same auth-token mechanism other backstage pages already use — do not hand-roll fetch with credentials) against `/backstage/applications/pending-count`.
- Render up to 3 individual toasts; if `total > 3`, render a roll-up "N pending applications" badge toast that links to `/backstage/applications`.
- Click toast body: navigate to `/backstage/applications?highlight=<id>` (page already exists, we add `?highlight` scroll-into-view).
- Click ✕: `ApiClient.post('/backstage/applications/{type}/{id}/dismiss')`, then remove toast from DOM. No re-render of full list.
- No polling. The toast list is a snapshot of "pending applications at the moment this page loaded". Approving/dismissing something here and navigating is what refreshes it.

### 6.3 Current dashboard toast

Delete the inline PHP toast block in `sites/daem-society/public/pages/backstage/index.php` L108–230. The global toast system replaces it. Dashboard still shows the `$applications` counter stat card — unchanged.

### 6.4 Styling

Reuse existing tokens (`card card--warning`, amber palette, dark-mode support) from the dashboard toast so visual identity is preserved. Position: `position: fixed; top: var(--space-6); right: var(--space-6)`, `z-index: 500`, stacked vertically with `gap: var(--space-3)`. Animation: slide in from right, 200ms ease-out. No auto-dismiss.

### 6.5 Approval-success toast

When the approve form on `/backstage/applications` returns the new `invite_url` field, render a second, separate success toast (green, not in the pending-applications stack) reading:

> "Application approved. Invite link (valid 7 days): `<url>` — [Copy]"

This is the manual bridge until Mailu is deployed. Clicking Copy uses the clipboard API. The toast auto-dismisses after 60 seconds OR when the user clicks away, whichever comes first — but the invite URL remains visible in a banner on the approved application's detail view too, so missing the toast doesn't lose the link.

---

## 7. Testing strategy

### 7.1 Unit tests (Application layer, no DB)

- `DecideApplication` approve-member path: mocks for all repos, asserts activation service + invite issuer called, dismissal clear called, output carries invite URL.
- `DecideApplication` approve-supporter path: same with supporter activation.
- `DecideApplication` reject path: unchanged, no activation, no invite.
- `DecideApplication` double-decide guard: application already decided → `ValidationException`.
- `IssueInvite`: token hashed before storage, URL composed from `BaseUrlResolver`, expires_at = issued_at + 7 days.
- `LoginUser` NULL password_hash → failure with generic message, no enumeration.
- `LoginUser` success → dismissals cleared.

### 7.2 Integration tests (real MySQL, `MigrationTestCase`)

- Full approve-member flow: seed application → call use case → assert users row, user_tenants row, member_status_audit row, member_number allocated, tenant_member_counters incremented, user_invites row, application status, dismissal cleanup.
- Approve-supporter flow: same minus member_number, plus supporter-role on user_tenants.
- Concurrent member number allocation: two approvals hitting the same counter return sequential numbers, no duplicates (one transaction waits on the row lock).
- Invite redemption: redeem valid token sets password + used_at; redeem used token fails; redeem expired token fails.

### 7.3 Isolation tests (`tests/Isolation/`)

Extend the existing pattern:
- `ApplicationApprovalTenantIsolationTest`: approving an application in tenant A never creates users/user_tenants/invites in tenant B. Tenant B admins cannot dismiss tenant A's applications.
- `AdminDismissalTenantIsolationTest`: tenant A admin dismissing app cannot affect tenant B admin's view of unrelated apps.

### 7.4 E2E tests (KernelHarness)

- `POST /backstage/applications/member/{id}/decision` with `decision=approved` returns `invite_url`, `member_number`.
- `GET /backstage/applications/pending-count` excludes dismissed items for the calling admin.
- `POST /backstage/applications/{type}/{id}/dismiss` is idempotent.
- `POST /auth/invites/redeem` completes password setup.

### 7.5 Manual frontend smoke

Not automated, listed for the execution checklist:
1. Approve a member on `/backstage/applications` — toast appears with invite URL, pending-count toast on next page load no longer includes that application.
2. Approve a supporter — same.
3. Dismiss a pending toast — refresh page, still gone. Log out and back in — reappears.
4. Redeem an invite URL in a fresh browser session — lands on `/` logged in as the new member.

---

## 8. Out of scope (explicit YAGNI)

- Email delivery of invite links. Bridge is "admin copies URL from success toast".
- `organizations` table / org-as-entity. Supporters remain `users` with `role='supporter'`.
- Password strength validation beyond bcrypt length ≤ 72. Rules land when mailu + /register hardens.
- Resend invite, revoke invite, extend invite expiry. Manual re-approval if needed.
- Badge-toast for > 50 pending applications. Endpoint caps at 50; list view handles the rest.
- Toast notifications for non-application events (new forum post, event registration, etc.) — roadmap §7 scope is applications only for this pass.
- Search/highlight query param on applications list is a `scroll-into-view` only; no server filter change.

---

## 9. Files touched (inventory)

**New:**
- `database/migrations/036_make_users_password_hash_nullable.sql`
- `database/migrations/037_make_users_date_of_birth_nullable.sql`
- `database/migrations/038_create_tenant_member_counters.sql`
- `database/migrations/039_create_user_invites.sql`
- `database/migrations/040_create_admin_application_dismissals.sql`
- `src/Application/Backstage/ActivateMember/MemberActivationService.php`
- `src/Application/Backstage/ActivateSupporter/SupporterActivationService.php`
- `src/Application/Backstage/IssueInvite/IssueInvite.php` (+ Input/Output)
- `src/Application/Backstage/DismissApplication/DismissApplication.php` (+ I/O)
- `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php` (+ I/O)
- `src/Application/Auth/RedeemInvite/RedeemInvite.php` (+ I/O)
- `src/Domain/Invite/{UserInvite.php, UserInviteRepositoryInterface.php, InviteToken.php}`
- `src/Domain/Tenant/TenantMemberCounterRepositoryInterface.php`
- `src/Domain/Dismissal/{AdminApplicationDismissal.php, AdminApplicationDismissalRepositoryInterface.php}`
- `src/Infrastructure/Persistence/Sql/{SqlUserInviteRepository.php, SqlTenantMemberCounterRepository.php, SqlAdminApplicationDismissalRepository.php}`
- `src/Infrastructure/InMemory/{InMemoryUserInviteRepository.php, InMemoryTenantMemberCounterRepository.php, InMemoryAdminApplicationDismissalRepository.php}`
- `src/Infrastructure/Token/{RandomTokenGenerator.php, TokenGeneratorInterface.php}`
- `src/Infrastructure/Config/BaseUrlResolver.php`
- Controller methods in `BackstageController` for `pending-count`, `dismiss`, and a new `AuthController::redeemInvite` for the invite endpoint.
- Routes in `routes/api.php`.
- Frontend: `sites/daem-society/public/pages/backstage/toasts.js`, `toasts.css`, `public/pages/invite.php`, wiring in `backstage/layout.php`.

**Modified:**
- `src/Application/Backstage/DecideApplication/DecideApplication.php` — new dependencies, approve path orchestration, expanded output.
- `src/Application/Backstage/DecideApplication/DecideApplicationOutput.php` — add invite fields.
- `src/Application/Auth/LoginUser/LoginUser.php` — NULL-hash rejection, dismissal cleanup on success.
- `src/Infrastructure/Persistence/Sql/SqlUserRepository.php` — `createActivated(...)` method (inserts with NULL password_hash).
- `bootstrap/app.php` — bind all new classes.
- `tests/Support/KernelHarness.php` — bind InMemory equivalents.
- `sites/daem-society/public/pages/backstage/index.php` — remove inline toast.
- `sites/daem-society/public/pages/backstage/applications/index.php` — render success toast with invite URL.

---

## 10. Rollout

Single PR on `dev`. Migrations 036–040 run forward-only on the dev DB. No rollback migrations (repo convention — we forward-fix).

Post-merge manual verification checklist in §7.5. Smoke-test with the existing seeded 4 pending members + 2 pending supporters.
