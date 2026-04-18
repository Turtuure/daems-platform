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
