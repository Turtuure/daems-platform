# Architecture Decision Records

---

## ADR-001: Clean Architecture over a full-stack framework

**Status:** Accepted

**Context:**
The Daems Platform is a focused REST API consumed by a single front-end application (`daem-society`). A general-purpose framework such as Laravel or Symfony would bring large dependency trees, opinionated conventions (Eloquent ORM, service providers, Artisan), and abstractions that do not map cleanly to the domain model.

**Decision:**
Implement a three-layer Clean Architecture (Domain → Application → Infrastructure) with a purpose-built HTTP micro-framework. The only Composer dependencies are `phpunit/phpunit` and `phpstan/phpstan` (both dev-only).

**Consequences:**
- Zero production dependencies; the entire framework fits in `src/Infrastructure/Framework/` (~6 files).
- Full control over the request lifecycle and binding strategy.
- No auto-wiring, no magic — all behaviour is explicit and traceable.
- New contributors must read the framework source rather than consult external documentation.

---

## ADR-002: UUID v7 for all entity IDs

**Status:** Accepted

**Context:**
Auto-increment integer IDs leak row counts and insertion order, complicate horizontal sharding, and require a database round-trip before an entity can be fully constructed in memory. UUID v4 is random and therefore not sortable without an extra `created_at` column.

**Decision:**
Use UUID v7 (`Daems\Domain\Shared\ValueObject\Uuid7`) for all primary keys. UUID v7 embeds a millisecond-precision timestamp in the most significant bits, making values lexicographically sortable by creation time. IDs are generated in PHP before the INSERT, so entities are fully formed before they touch the database.

**Consequences:**
- Stored as `CHAR(36)` — 36 bytes vs. 8 bytes for a `BIGINT`. Acceptable for the expected data volumes.
- No external UUID library needed; the implementation is ~50 lines of PHP using `random_bytes()`.
- Typed ID wrapper classes (`UserId`, `EventId`, etc.) prevent accidental use of the wrong ID in application code.

---

## ADR-003: Custom DI container over an existing library

**Status:** Accepted

**Context:**
Existing DI containers (PHP-DI, Pimple, Symfony DI) are well-tested but add a dependency and support features (auto-wiring, annotations, compiled containers) that are not needed here.

**Decision:**
Implement a minimal container (`src/Infrastructure/Framework/Container/Container.php`, ~37 lines) with two methods — `bind()` for transient registrations and `singleton()` for shared instances. All bindings are declared explicitly in `bootstrap/app.php`.

**Consequences:**
- No auto-wiring; every binding must be registered manually. This is intentional — it makes the dependency graph entirely explicit and readable in one file.
- No support for constructor injection without a factory closure; controllers and use cases receive dependencies through explicit factory closures.
- If the project grows significantly, replacing this container with PHP-DI or similar would require rewriting `bootstrap/app.php` but would not touch any Domain or Application code.

---

## ADR-004: Session-based authentication over JWT

**Status:** Accepted

**Context:**
The API is consumed exclusively by the `daem-society` front-end, which runs on the same server (or within a trusted internal network). The client is a traditional web application, not a mobile app or third-party service.

**Decision:**
Use session-based authentication. The `login` endpoint validates credentials and returns the user record; the calling application (`daem-society`) is responsible for maintaining the session. There are no `Authorization: Bearer` headers or token issuance endpoints.

**Consequences:**
- Simpler implementation — no token signing, expiry, or refresh logic in the API.
- Tightly coupled to the `daem-society` front-end. The API is not suitable as a public or third-party API in its current form without adding token-based auth.
- If a mobile client or external integration is added in the future, a JWT or API-key layer will need to be introduced.

---

## ADR-005: Raw SQL over an ORM

**Status:** Accepted

**Context:**
The project uses MySQL 8.x with a straightforward relational schema. An ORM such as Doctrine or Eloquent would add significant complexity and abstraction for queries that are simple enough to write by hand.

**Decision:**
Use PDO with parameterised raw SQL statements. The `Connection` class (`src/Infrastructure/Framework/Database/Connection.php`) wraps PDO and exposes three methods: `query()` (multi-row SELECT), `queryOne()` (single-row SELECT), and `execute()` (INSERT / UPDATE / DELETE). SQL is written in the `Sql*Repository` classes.

**Consequences:**
- SQL is explicit, readable, and easily optimised without fighting ORM conventions.
- No query builder or migration runner is provided — migrations are plain `.sql` files applied manually.
- Type mapping from database rows to domain objects is done by hand in each repository. Adding new columns requires updating the repository mapping code in addition to the migration.
- Switching to a different RDBMS would require rewriting the repository SQL but would not affect Domain or Application code.

---

## ADR-006: Opaque bearer tokens hashed in DB (supersedes ADR-004)

**Status:** Accepted (2026-04-19)
**Supersedes:** ADR-004

**Context:**
SAST findings F-001..F-008 exploited the absence of any authentication abstraction. ADR-004's implicit session model had not been implemented — login returned user data but issued nothing the client could attach to subsequent requests.

**Decision:**
Issue opaque 32-byte random bearer tokens on login. Transport: `Authorization: Bearer <token>`. Storage: a new `auth_tokens` table; only `SHA-256(token)` is persisted. On each authenticated request, middleware hashes the incoming token, looks it up, checks expiry and revocation, and attaches an `ActingUser` to the `Request`.

**Consequences:**
- Revocation is a single `UPDATE` on `revoked_at` — no blocklist or crypto gymnastics.
- One extra primary-key SELECT per authenticated request. Acceptable.
- Tokens leaked through logs never reveal the server-side identifier.
- JWT/PASETO explicitly rejected: adds a crypto library dependency and makes revocation an architectural burden.

---

## ADR-007: Middleware pipeline on `Router`

**Status:** Accepted (2026-04-19)

**Context:**
The original `Router` was a bare `preg_match` dispatcher. Cross-cutting concerns (auth, rate-limiting, CSRF, CORS) had no hook point.

**Decision:**
Extend `Router::get` and `Router::post` to accept an optional middleware list. `Router::dispatch` composes middleware around the handler via `array_reduce`. Each middleware implements `MiddlewareInterface::process(Request, callable $next): Response` and may short-circuit by returning a Response or throwing a domain exception.

**Consequences:**
- Every protected route declares its middleware in `routes/api.php` — auth status is visible at registration time.
- Additional cross-cutting concerns (CORS, CSRF) can be added without touching the Kernel.
- Middleware resolution depends on the DI container; tests pass a trivial resolver.

---

## ADR-008: Authorization in Application use cases via `ActingUser`

**Status:** Accepted (2026-04-19)

**Context:**
Findings F-001, F-002, F-004, F-007 all failed the same way: business rules about *who can do what* were absent from the Application layer. Controllers called use cases with attacker-supplied identity fields.

**Decision:**
Every protected use case's `Input` DTO carries an `ActingUser` (UserId + role) as its first positional argument. The use case enforces ownership/role policy internally and throws `ForbiddenException` on mismatch. Kernel translates to HTTP 403. Policy lives in the Application layer, not in controllers or policy objects — this mirrors the existing pattern where `LoginUser` enforces password verification in the use case.

**Exception vs. Output DTO convention:**
- Business validation errors (e.g., invalid input, duplicate email) continue to use `Output.error` string.
- Authorization violations throw `ForbiddenException` and short-circuit execution.

**Consequences:**
- Every policy is unit-testable without HTTP.
- Request DTOs that used to accept `user_id`/`author_name`/`author_email` from the request body lose those fields — identity is derived server-side from the acting user's `users` row.
- Input DTOs grow by one field (`ActingUser $acting`).

---

## ADR-009: 7-day sliding expiry with 30-day hard cap

**Status:** Accepted (2026-04-19)

**Context:**
Token lifetime must balance security (inactive sessions should die) and UX (active users should not re-login constantly).

**Decision:**
On each authenticated request, update `last_used_at = NOW()` and `expires_at = LEAST(NOW() + INTERVAL 7 DAY, issued_at + INTERVAL 30 DAY)`. `POST /api/v1/auth/logout` sets `revoked_at = NOW()`.

**Consequences:**
- One `UPDATE` per authenticated request — primary-key lookup, negligible cost.
- Inactive sessions expire in 7 days; active users stay logged in up to 30 days without re-authentication.
- No refresh-token flow needed.

---

## ADR-010: DB-based fixed-window login rate limit

**Status:** Accepted (2026-04-19)

**Context:**
F-009: `/auth/login` had no throttle. No Redis in the stack. Credential-stuffing is the realistic attack.

**Decision:**
A new `auth_login_attempts` table with `id BIGINT AUTO_INCREMENT` as primary key and a secondary index on `(ip, email, attempted_at)` to support window queries. Middleware counts failures within a 15-minute window and returns HTTP 429 + `Retry-After: 900` after 5 failures for a given `(ip, email)` pair. Indexing on `(ip, email)` — not `email` alone — prevents an attacker from DoS-ing arbitrary accounts by typing the wrong password from any IP. A secondary per-IP budget (default 20 failures / 15 min) catches credential-stuffing that sprays many distinct emails from one source. Opportunistic cleanup (1-in-100 sweep of rows older than 24 h) avoids cron.

**Consequences:**
- Works on any MySQL install without new infrastructure.
- Observable: operators can query `auth_login_attempts` to see attack patterns.
- Sliding window could be added later if needed.
- Reverse-proxy deployments without a trusted-proxy allowlist see the proxy IP for every user — limitation documented.

---

## ADR-011: Sanitise Kernel error bodies

**Status:** Accepted (2026-04-19)

**Context:**
F-006: `Kernel::handle` caught every `Throwable` and passed `$e->getMessage()` unfiltered into the 500 response body. `PDOException` messages leaked SQL state and attacker-controlled input back to the caller, creating an email-enumeration oracle.

**Decision:**
`Kernel::handle` dispatches by exception family: `UnauthorizedException` → 401, `ForbiddenException` → 403, `NotFoundException` → 404, `ValidationException` → 400 (message we authored), `TooManyRequestsException` → 429 + `Retry-After`, any other `Throwable` → 500 with the literal string `"Internal server error."` Unhandled exceptions are logged via `LoggerInterface`. Dev override: `APP_DEBUG=true` re-enables verbose bodies during local development.

**Consequences:**
- Runtime exception details never surface to HTTP clients in production.
- Operators retain observability via `error_log` (default `LoggerInterface` adapter).
- Duplicate-key `PDOException` (SQLSTATE 23000) is caught in repositories and rethrown as `ValidationException('Invalid email.')` — same message whether syntactically bad or already taken, closing the enumeration oracle.

---

## ADR-012: Strip attacker-controlled identity fields from Input DTOs

**Status:** Accepted (2026-04-19)

**Context:**
F-005, F-007: forum and project DTOs accepted `user_id`, `author_name`, `author_email`, `role`, `role_class`, `joined_text` from the request body and persisted them verbatim. This enabled role-badge impersonation (post as "Administrator") and identity spoofing across comments, proposals, joins and leaves.

**Decision:**
Delete these fields from the Input DTOs. Derive server-side from the acting user's `users` row:
- `role`/`role_class`/`joined_text` from `users.role` and `users.created_at` via a shared `ForumIdentityDeriver`.
- `user_id`/`author_name`/`author_email` from `acting.id` and a `UserRepositoryInterface` lookup.

Database columns are unchanged (legacy rows retain their values).

**Consequences:**
- Compile-time guarantee that attacker input cannot impersonate another user.
- Input DTOs shrink from 10+ fields to 3–4.

---

## ADR-013: 72-byte password cap

**Status:** Accepted (2026-04-19)

**Context:**
F-010: bcrypt silently truncates inputs longer than 72 bytes. Two passwords sharing the first 72 bytes authenticate identically.

**Decision:**
Reject passwords where `strlen($pw) > 72` at the application layer in `RegisterUser`, `ChangePassword`, and `LoginUser` (the login rejection is to prevent confused logins after legacy rows were created by some other tool).

**Consequences:**
- No user-visible behaviour change for passwords under 72 bytes (typical range: 8–50 bytes).
- Argon2id migration is explicitly out of scope — would force a global re-login event, disproportionate for a Low-severity finding.

---

## ADR-015: PHPStan Level 9 Baseline

**Status:** Accepted (2026-04-19)

**Context:**
The project was at PHPStan level 6 — enforcing type hints on parameters, return types, and properties, but not null-safety (level 8) or strict `mixed` handling (level 9). As the codebase grew, null-chain bugs (chained method calls on `?Entity` without null checks) and untyped `mixed` usage from superglobals (`$_POST`, `$_GET`, `$_SESSION`, `json_decode()`) became recurring defect sources. These are exactly the categories of bug that static analysis catches for free.

**Decision:**
Raise the PHPStan baseline to **level 9** across the entire codebase. End state: 0 errors at level 9 with no `phpstan-baseline.neon` file.

Supporting changes:
- `Request::string()`/`int()`/`bool()`/`arrayValue()` — typed accessors that narrow `mixed` body/query values at the read site.
- `SessionInterface` + production `Session` + `ArraySession` test double — typed `$_SESSION` wrapper with the same accessor shape.
- Per-repository private hydration helpers (`str()`, `intCol()`, `boolCol()`) — narrow `array<string, mixed>` PDO rows to strongly-typed values, throwing `DomainException` on corrupt rows.
- Null-safety: `?? throw new <DomainException>()` pattern at lookup sites; `assert($x !== null, 'invariant …')` where a null is logically impossible but PHPStan cannot prove it.

**Consequences:**
- Static analysis catches null-chain bugs and mixed-shape misuse before runtime.
- All reads from superglobals / JSON payloads / DB rows now go through typed accessors or explicit narrowing.
- New code must be level-9 clean from the start — this is enforced automatically by the CI `PHPStan` step (reads `phpstan.neon`, now `level: 9`).
- Minimal behavior change: fixes preserve existing runtime behavior (e.g., `$req->string('key', '')` falls back to empty string exactly as `$req->input('key')` did when the key was missing).
