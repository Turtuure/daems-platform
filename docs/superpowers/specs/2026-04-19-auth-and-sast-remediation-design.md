# Auth Layer & SAST Remediation — Design Spec

**Date:** 2026-04-19
**Status:** Draft — awaiting user review
**Branch:** `fix/sast-auth-remediation`
**Source spec:** `/root/daems/daems-platform-sast-report.md` (10 findings, F-001..F-010)

## Goal

Close every finding in the SAST report by adding a coherent authentication + authorization layer to the Daems Platform without breaking its Clean Architecture conventions. One layer, one migration set, one test matrix.

## Non-goals

- No framework migration (no Laravel, no Symfony — matches project ethos).
- No admin UI. No "log out everywhere" endpoint (future work).
- No password algorithm migration to Argon2id (F-010 fixed by byte-length cap only).
- No refresh-token flow. One bearer token per session, sliding expiry.
- No trusted-proxy / `X-Forwarded-For` parsing. Documented limitation.
- No backfill of `projects.owner_id` for legacy rows (admin-only mutation for those).

## Architectural decisions

### AD-1 — Opaque bearer tokens hashed in DB

**Context.** F-001..F-008 all root in "no caller identity". We need to prove identity per request.
**Decision.** Random 32-byte token, transmitted as `Authorization: Bearer <token>`, stored only as `SHA-256(token)` in a new `auth_tokens` table.
**Rejected:** JWT / PASETO (revocation pain, crypto lib dependency), PHP native sessions (wrong transport for an API).

### AD-2 — Middleware pipeline on `Router`

**Context.** Router is a bare `preg_match` dispatcher. No hook point for cross-cutting concerns.
**Decision.** Extend `Router::get/post` to accept an optional middleware list. `Router::dispatch` composes the pipeline around the handler. Middleware classes implement `MiddlewareInterface::process(Request $req, callable $next): Response`.
**Rejected:** dual registration verbs (`authedPost`), wrapping closures at the routes file (leaks auth logic into route definitions).

### AD-3 — Authorization in Application use cases

**Context.** F-001, F-002, F-004, F-007 all failed because business rules were absent from the Application layer.
**Decision.** Every protected use case's Input DTO carries an `ActingUser` (id + role). The use case enforces policy internally and **throws** `ForbiddenException` on mismatch. Kernel translates to HTTP 403.

**Exception vs. Output DTO convention.** Existing use cases return Output DTOs with a string `error` field for business validation (e.g., `LoginUser` on wrong password). That convention stays for validation/business errors. Authorization violations are a different class — they indicate a caller accessing something they must not — and are modelled as thrown exceptions so they short-circuit execution and are translated uniformly by the Kernel. Rule of thumb: **Output.error** for "the request was well-formed but the business said no"; **thrown exception** for "the caller is not allowed here at all".

**Rejected:** policy objects (splits "who can do what" between layers), inline controller checks (invites drift, the exact trap the codebase fell into).

### AD-4 — 7-day sliding token expiry, 30-day hard cap

**Context.** Need a balance of security and UX without refresh-token machinery.
**Decision.** On each authenticated request, `UPDATE auth_tokens SET last_used_at = NOW(), expires_at = LEAST(NOW() + INTERVAL 7 DAY, issued_at + INTERVAL 30 DAY)`. `/auth/logout` sets `revoked_at`.

### AD-5 — DB-based fixed-window rate limit on login

**Context.** F-009 — no throttle on `/auth/login`. No Redis in stack.
**Decision.** `auth_login_attempts` table indexed on `(ip, email, attempted_at)`. 5 failures / 15 min per `(ip, email)` → 429 with `Retry-After: 900`. Secondary 20 failures / 15 min per `ip` catches stuffing across many emails. Opportunistic cleanup (1-in-100 sweep of rows older than 24 h). Indexing on `(ip, email)` not just `email` prevents DoS of arbitrary accounts by an attacker.

### AD-6 — Sanitise Kernel errors

**Context.** F-006 — `Kernel::handle` returns `$e->getMessage()` verbatim, leaking SQL state and becoming an email-enumeration oracle.
**Decision.** `Kernel::handle` dispatches by exception family and emits fixed literals. Unhandled `Throwable` → `"Internal server error."` + `error_log` for operators. Dev override: `APP_DEBUG=true` re-enables verbose body.

### AD-7 — Strip attacker-controlled identity fields from Input DTOs

**Context.** F-005, F-007 — forum/project DTOs accept `user_id`, `author_name`, `author_email`, `role`, `role_class`, `joined_text` from request body.
**Decision.** Delete those fields from the Input DTOs; derive server-side from `ActingUser` and `users` row lookup. DB columns stay (legacy rows unaffected).

### AD-8 — 72-byte password cap

**Context.** F-010 — bcrypt silently truncates.
**Decision.** Reject passwords where `strlen($pw) > 72` in `RegisterUser` and `ChangePassword`. No Argon2id migration.

## Data model

### New tables

**`auth_tokens`** (migration `014_create_auth_tokens.sql`)
```sql
CREATE TABLE auth_tokens (
    id              CHAR(36)     NOT NULL,            -- UUIDv7
    token_hash      CHAR(64)     NOT NULL,            -- SHA-256 hex of raw token
    user_id         CHAR(36)     NOT NULL,
    issued_at       DATETIME     NOT NULL,
    last_used_at    DATETIME     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    revoked_at      DATETIME         NULL,
    user_agent      VARCHAR(255)     NULL,
    ip              VARCHAR(45)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**`auth_login_attempts`** (migration `015_create_auth_login_attempts.sql`)
```sql
CREATE TABLE auth_login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip            VARCHAR(45)     NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    attempted_at  DATETIME        NOT NULL,
    success       TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_auth_login_attempts_window (ip, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Schema change

**`projects.owner_id`** (migration `016_add_owner_id_to_projects.sql`)
```sql
ALTER TABLE projects
    ADD COLUMN owner_id CHAR(36) NULL AFTER id,
    ADD KEY idx_projects_owner (owner_id),
    ADD CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
```

`NULL` allowed for legacy rows. New rows get `owner_id` from the acting user. Legacy `NULL`-owner rows are mutable only by admin.

### Input DTO field deletions (F-005, F-007)

Drop these fields entirely (no deprecation shim):

| DTO | Fields to drop |
|---|---|
| `CreateForumTopicInput` | `role`, `role_class`, `joined_text`, `author_name`, `author_email`, `user_id` |
| `CreateForumPostInput` | same |
| `AddProjectCommentInput` | `user_id`, `author_name` |
| `AddProjectUpdateInput` | `author_name` |
| `SubmitProjectProposalInput` | `user_id`, `author_name`, `author_email` |
| `JoinProjectInput` | `user_id` |
| `LeaveProjectInput` | `user_id` |

## Components

### Domain layer

```
src/Domain/Auth/
    ActingUser.php                     (value object: UserId + role)
    AuthToken.php                      (entity: id, userId, issuedAt, lastUsedAt, expiresAt, revokedAt, meta)
    AuthTokenId.php                    (UUIDv7 value object)
    AuthTokenRepositoryInterface.php   (findByHash, store, revoke, touchLastUsed)
    AuthLoginAttemptRepositoryInterface.php  (countFailuresSince, record, gcOlderThan)
    AuthorizationException.php         (base class)
    ForbiddenException.php
    UnauthorizedException.php
    TooManyRequestsException.php       (carries retryAfter seconds)

src/Domain/Shared/
    Clock.php                          (interface: now(): DateTimeImmutable)
```

### Application layer

```
src/Application/Auth/
    CreateAuthToken/{CreateAuthToken, Input, Output}.php
    AuthenticateToken/{AuthenticateToken, Input, Output}.php
    RevokeAuthToken/{RevokeAuthToken, Input, Output}.php
    LogoutUser/{LogoutUser, Input, Output}.php
```

Every existing use case whose route becomes protected gains an `ActingUser $acting` field on its `Input` DTO and an authorization check at the top of `execute()`. Use cases affected:

- `DeleteAccount`, `UpdateProfile`, `GetProfile` (degrade, not reject), `ChangePassword`, `GetUserActivity`
- `CreateProject`, `UpdateProject`, `ArchiveProject`, `AddProjectUpdate`, `AddProjectComment`, `LikeProjectComment`, `JoinProject`, `LeaveProject`, `SubmitProjectProposal`
- `CreateForumTopic`, `CreateForumPost`, `LikeForumPost`
- `RegisterForEvent`, `UnregisterFromEvent`
- `SubmitMemberApplication`, `SubmitSupporterApplication`

### Infrastructure layer

```
src/Infrastructure/Framework/Http/
    MiddlewareInterface.php
    Router.php                         (extended to accept middleware list per route)
    Kernel.php                         (rewritten: exception → HTTP mapping, logger)
    Request.php                        (adds withActingUser/actingUser/clientIp)
    Response.php                       (adds unauthorized/forbidden/tooManyRequests helpers)

src/Infrastructure/Framework/Http/Middleware/
    AuthMiddleware.php
    RateLimitLoginMiddleware.php

src/Infrastructure/Framework/Logging/
    LoggerInterface.php
    ErrorLogLogger.php                 (default, writes to error_log)

src/Infrastructure/Framework/Clock/
    SystemClock.php                    (implements Domain\Shared\Clock)

src/Infrastructure/Adapter/Persistence/Sql/
    SqlAuthTokenRepository.php
    SqlAuthLoginAttemptRepository.php

src/Infrastructure/Adapter/Api/Controller/
    AuthController.php                 (extended: login returns token, adds logout())
```

## Request flow (protected endpoint)

```
Request
  → Kernel::handle                                  (try/catch: 401/403/404/422/429/500 mapping)
      → Router::dispatch                            (matches route + middleware list)
          → AuthMiddleware::process
              → extract "Authorization: Bearer <raw>"
              → lookup auth_tokens by SHA-256(raw) + user JOIN
              → reject: 401 UnauthorizedException (missing/malformed/revoked/expired)
              → UPDATE last_used_at, expires_at
              → Request::withActingUser(ActingUser)
              → $next($request)
          → Controller::action($request, $params)
              → build Input DTO (actingUser + body + params)
              → UseCase::execute(input)
                  → authorization guard → ForbiddenException on mismatch
                  → existing business logic
              → return Response::json(...)
```

## HTTP contract

### Login (changed response shape)

```
POST /api/v1/auth/login
Content-Type: application/json
{ "email": "...", "password": "..." }

200 OK
{
  "data": {
    "user":       { ...all fields as before, minus password_hash... },
    "token":      "<43 char base64url>",
    "expires_at": "2026-04-26T12:34:56Z"
  }
}

401 { "error": "Invalid email or password." }
429 { "error": "Too many login attempts. Try again later." }   + Retry-After: 900
```

**Breaking change** from `{"data": {...flat user...}}` — acceptable because no client depends on the old shape (auth wasn't wired up before).

### Logout (new)

```
POST /api/v1/auth/logout
Authorization: Bearer <token>

204 No Content
401 if unauth
```

### Existing routes — authorization matrix

See **Section 4** of the brainstorm (full matrix preserved below).

| Endpoint | Auth | Policy |
|---|---|---|
| `POST /auth/register` | public | — |
| `POST /auth/login` | public + rate-limited | 5 fails / 15 min / (ip,email) → 429 |
| `POST /auth/logout` | required | revoke caller's token |
| `GET /users/{id}` | required | full view if self/admin; reduced (name only) otherwise |
| `POST /users/{id}` | required | self or admin |
| `POST /users/{id}/password` | required | self only |
| `GET /users/{id}/activity` | required | self or admin |
| `POST /users/{id}/delete` | required | self or admin |
| `POST /projects` | required | any authed; `owner_id = acting.id` |
| `POST /projects/{slug}` | required | owner or admin; legacy NULL owner → admin only |
| `POST /projects/{slug}/archive` | required | same |
| `POST /projects/{slug}/updates` | required | same; `author_name` derived |
| `POST /projects/{slug}/comments` | required | any authed; `user_id`/`author_name` derived |
| `POST /project-comments/{id}/like` | required | any authed |
| `POST /projects/{slug}/join` | required | `user_id` = acting.id (attacker-supplied ignored) |
| `POST /projects/{slug}/leave` | required | same; no evicting others |
| `POST /project-proposals` | required | identity derived |
| `POST /forum/categories/{slug}/topics` | required | role/role_class/joined_text derived from `users.role` |
| `POST /forum/topics/{slug}/posts` | required | same |
| `POST /forum/posts/{id}/like` | required | any authed |
| `POST /forum/topics/{slug}/view` | public | — |
| `POST /events/{slug}/register` | required | `user_id` = acting.id |
| `POST /events/{slug}/unregister` | required | same |
| `POST /applications/member` | required | `user_id` = acting.id |
| `POST /applications/supporter` | required | same |
| `GET /*` lists and shows (events, insights, projects, forum) | public | — |

## Error contract

| Exception | HTTP | Body |
|---|---|---|
| `UnauthorizedException` | 401 | `{"error":"Authentication required."}` |
| `ForbiddenException` | 403 | `{"error":"Forbidden."}` |
| `NotFoundException` | 404 | `{"error":"Not found."}` |
| `ValidationException` | 400 | `{"error":"<safe message the use case authored>"}` |
| `TooManyRequestsException` | 429 | `{"error":"Too many requests."}` + `Retry-After: <seconds>` |
| Any other `Throwable` | 500 | `{"error":"Internal server error."}` (+ details if `APP_DEBUG=true`) |

All 500s are logged via `LoggerInterface` with the full exception.

## Configuration

New env vars in `.env.example`:

```
APP_DEBUG=false
AUTH_TOKEN_TTL_DAYS=7
AUTH_TOKEN_HARD_CAP_DAYS=30
AUTH_RATE_LIMIT_WINDOW_MIN=15
AUTH_RATE_LIMIT_MAX_FAILS=5
AUTH_RATE_LIMIT_LOCKOUT_MIN=15
```

Read at bootstrap, injected as constants into the middleware. TTL and window are overridable only via env, never per-request.

## Testing strategy

### Tier 1 — Domain unit tests (no DB, no HTTP)

- `ActingUserTest` — role checks, id equality.
- `AuthTokenTest` — expiry arithmetic, revocation state transitions.
- `ClockTest` — `FrozenClock` arithmetic correctness.

### Tier 2 — Application unit tests (in-memory fakes)

Every affected use case gets positive + negative policy cases:

- `CreateAuthTokenTest` — distinct tokens, stores hash not raw, uses clock for expiry.
- `AuthenticateTokenTest` — valid → ActingUser; revoked/expired/missing → fail; `last_used_at` advances; hard cap honoured.
- `RevokeAuthTokenTest` — sets `revoked_at`; idempotent.
- `DeleteAccountTest` — self-delete 2xx; other-delete Forbidden; admin-delete-other 2xx (F-001).
- `UpdateProfileTest` — self/admin/other matrix; duplicate email → generic ValidationException (F-002, F-006 chain).
- `GetProfileTest` — self/admin full, other user reduced view (F-003).
- `GetUserActivityTest` — self/admin only (F-008).
- `CreateProjectTest` — `owner_id` = acting.id.
- `UpdateProjectTest` / `ArchiveProjectTest` / `AddProjectUpdateTest` — owner/admin/legacy-NULL matrix (F-004).
- `AddProjectCommentTest` — `user_id`/`author_name` from acting only; DTO no longer accepts those fields.
- `JoinProjectTest` / `LeaveProjectTest` — cannot evict others (F-007).
- `SubmitProjectProposalTest` — identity derived (F-007).
- `CreateForumTopicTest` / `CreateForumPostTest` — role badge from `users.role`, body field absent from DTO (F-005).
- `LoginUserTest` — records attempt; length cap 72 bytes.
- `ChangePasswordTest` — 72-byte cap (F-010).

In-memory fakes in `tests/Support/Fake/`: `InMemoryAuthTokenRepository`, `InMemoryAuthLoginAttemptRepository`, and any other new domain repositories.

### Tier 3a — Integration tests (middleware + DB)

- `AuthMiddlewareTest` — missing/malformed/wrong/expired token → 401; valid → handler invoked with ActingUser; sliding expiry advances.
- `RateLimitLoginMiddlewareTest` — 5 failures → 6th is 429; successful within window still 429; window rolls off via clock.
- `KernelErrorSanitisationTest` — forced exceptions return literal bodies; explicit assertion that `SQLSTATE`, `Duplicate entry`, and candidate-email string do not appear in 500 body (F-006).

### Tier 3b — E2E PoC replays

One test file per finding, each replaying the SAST report's `curl` and asserting 401/403/429 where it got 200:

- `F001_UnauthDeletionTest` — anonymous → 401; non-owner → 403; self → 200; admin → 200.
- `F002_UnauthUpdateTest` — same + duplicate-email generic message.
- `F003_UnauthPIIReadTest` — anonymous → 401; other → reduced view; self/admin → full.
- `F004_UnauthProjectMutationTest` — archive/update/addUpdate: anonymous → 401; non-owner → 403; owner/admin → 200; legacy NULL → admin only.
- `F005_ForumRoleImpersonationTest` — POST with `role=Administrator`; stored row's role reflects `users.role`, not body.
- `F006_ErrorSanitisationTest` — replays email-enum probe; asserts no `SQLSTATE`, no `Duplicate entry`, no candidate email.
- `F007_IdentitySpoofingTest` — POST body `user_id` ignored across comment/proposal/join/leave.
- `F008_UnauthActivityTest` — anonymous → 401; other → 403.
- `F009_LoginRateLimitTest` — 5 wrong → 6th is 429 + `Retry-After`.
- `F010_BcryptTruncationTest` — 73-byte password → 400; 72-byte → 201.

E2E tier runs against a dedicated MariaDB test database configured via `DB_TEST_*` env vars. Schema bootstrapped from `database/migrations/*.sql`. Truncation between tests via a shared fixture.

### Tier 4 — Mutation testing (Infection)

- `composer require --dev infection/infection`
- Configured to target `src/Application/Auth/`, `src/Application/User/`, `src/Application/Project/`, `src/Application/Forum/`, `src/Domain/Auth/`.
- MSI (Mutation Score Indicator) threshold: **85%** on targeted paths.
- Covered Code MSI: **90%**.
- Infection runs as a separate CI step (`composer test:mutation`), gated on Tier 1+2 passing first. Not part of `composer test` default (too slow for local loops).

### Tooling & configuration

- `phpunit.xml` split into three test suites: `Unit`, `Integration`, `E2E`.
- `composer test` — runs Unit + Integration.
- `composer test:e2e` — requires `DB_TEST_*` env.
- `composer test:mutation` — runs Infection against targeted paths.
- `composer analyse` — phpstan level max on new files only (existing 61 warnings out of scope).
- `.env.example` extended with new `AUTH_*` vars and `DB_TEST_*` vars.

## Migration order (implementation sequence)

1. **Foundations.** `Clock`, `SystemClock`, `FrozenClock`, `LoggerInterface`, `ErrorLogLogger`, `MiddlewareInterface`, exception family, `Response` helpers, `Request::withActingUser`.
2. **Router middleware pipeline.** Extend `get/post` to accept a middleware list; `dispatch` composes pipeline. Tests first.
3. **Domain entities.** `ActingUser`, `AuthToken`, `AuthTokenId`, repository interfaces.
4. **Migrations.** `014_auth_tokens`, `015_auth_login_attempts`, `016_projects_owner_id`.
5. **SQL adapters.** `SqlAuthTokenRepository`, `SqlAuthLoginAttemptRepository`.
6. **Token use cases.** `CreateAuthToken`, `AuthenticateToken`, `RevokeAuthToken`, `LogoutUser`.
7. **Middleware.** `AuthMiddleware`, `RateLimitLoginMiddleware`.
8. **Kernel rewrite.** Exception→HTTP mapping + logger; F-006 closes here.
9. **AuthController.** Login returns token + expiry; new `logout` action; `RegisterUser` and `ChangePassword` gain 72-byte cap (F-010).
10. **Policy-ify use cases.** Add `ActingUser` to Inputs + check in `execute` for each affected use case. Order by finding severity:
    - User-scoped: `DeleteAccount` (F-001), `UpdateProfile` (F-002), `GetProfile` (F-003), `GetUserActivity` (F-008).
    - Project-scoped: `CreateProject` sets `owner_id`; `UpdateProject`, `ArchiveProject`, `AddProjectUpdate` enforce owner/admin (F-004); `AddProjectComment`, `JoinProject`, `LeaveProject`, `SubmitProjectProposal` derive identity (F-007).
    - Forum: `CreateForumTopic`, `CreateForumPost` derive role from `users.role` (F-005).
    - Event / application: `RegisterForEvent`, `UnregisterFromEvent`, `SubmitMemberApplication`, `SubmitSupporterApplication` derive `user_id`.
11. **Wire routes.** Attach `AuthMiddleware` and `RateLimitLoginMiddleware` to the routes per the matrix.
12. **E2E PoC replays.** One test per finding.
13. **Infection.** Configure, run, tune MSI threshold.
14. **Docs.** Update `docs/api.md`, append ADR-006..ADR-013 (one per AD in this spec) to `docs/decisions.md`, extend `docs/database.md` with the new tables.

## Risks & accepted tradeoffs

| Risk | Mitigation | Accepted because |
|---|---|---|
| Reverse-proxy deployments see proxy IP for all rate-limit keys | Document the limitation; follow-up `TrustedProxies` config | No deployment config known yet |
| Legacy `projects` rows with NULL owner can only be mutated by admin | Explicit in the matrix | Correct behaviour in the absence of historical data |
| Login response shape changes from flat user to `{user, token, expires_at}` | Documented breaking change | No client is currently calling it (auth was absent) |
| One extra `UPDATE` per authenticated request (sliding expiry) | Primary-key lookup, negligible cost | Simpler than refresh tokens |
| 72-byte bcrypt cap, not Argon2id migration | Input validation only | Argon2id migration forces a global re-login event; out of proportion for a Low finding |
| `X-Forwarded-For` not honoured | REMOTE_ADDR only | Forgeable without a trusted-proxy allowlist |

## Follow-up candidates (explicitly out of scope)

- Admin UI / role management.
- "Log out everywhere" endpoint.
- Password reset by email (would turn F-002 hijack into ATO if retroactively added — must land with auth gating already in place).
- Trusted-proxy list for `X-Forwarded-For`.
- Argon2id migration with forced re-login.
- MFA.
- CSRF (only relevant if a cookie transport is ever added).
