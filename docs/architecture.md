# Architecture

## Clean Architecture diagram

```
+----------------------------------------------------------+
|  Infrastructure                                          |
|  +----------------------------------------------------+  |
|  |  Application                                       |  |
|  |  +----------------------------------------------+  |  |
|  |  |  Domain                                      |  |  |
|  |  |  Entities · Value Objects · Repository       |  |  |
|  |  |  Interfaces                                  |  |  |
|  |  +----------------------------------------------+  |  |
|  |  Use Cases (one class per operation)               |  |
|  +----------------------------------------------------+  |
|  HTTP Kernel · Router · DI Container                     |
|  SQL Repositories · API Controllers                      |
+----------------------------------------------------------+
```

Dependencies only point inward. Domain knows nothing about application or infrastructure. Application knows nothing about infrastructure.

## Layer descriptions

### Domain (`Daems\Domain\`)

Pure PHP classes with no dependencies on any framework or library.

- **Entities** — `User`, `Event`, `Project`, `ForumTopic`, `ForumPost`, etc. Immutable value holders constructed via named constructors or plain constructors. All fields are `readonly`.
- **Value objects** — `Uuid7` (see below), typed ID wrappers (`UserId`, `EventId`, `ProjectId`, …). Each ID class wraps a `Uuid7` and enforces its own type.
- **Repository interfaces** — one interface per aggregate root (`UserRepositoryInterface`, `EventRepositoryInterface`, …). Methods are defined in terms of domain types only.

### Application (`Daems\Application\`)

One subdirectory per feature, one class per use case. Each use case follows the pattern:

```
Daems\Application\{Domain}\{UseCaseName}\
  {UseCaseName}.php        — execute(Input): Output
  {UseCaseName}Input.php   — readonly DTO
  {UseCaseName}Output.php  — readonly DTO
```

Use cases receive repository interfaces via constructor injection, execute domain logic, and return a plain output DTO. They never touch HTTP, sessions, or SQL.

### Infrastructure (`Daems\Infrastructure\`)

Three sub-namespaces:

| Sub-namespace                                   | Contents                                            |
| ----------------------------------------------- | --------------------------------------------------- |
| `Daems\Infrastructure\Framework\`               | HTTP kernel, router, request, response, DI container, PDO connection, middleware pipeline (`TenantContextMiddleware`, `AuthMiddleware`) |
| `Daems\Infrastructure\Adapter\Api\Controller\`  | HTTP controllers — translate HTTP ↔ use-case DTOs   |
| `Daems\Infrastructure\Adapter\Persistence\Sql\` | SQL repository implementations (raw PDO, no ORM)    |

## Dependency Inversion Rule

Application use cases depend only on repository interfaces defined in the Domain layer. The concrete `Sql*Repository` classes that implement those interfaces live in Infrastructure. The DI container wires them together in `bootstrap/app.php`, which is the only place the dependency graph is assembled.

## DI container

`Container` (`src/Infrastructure/Framework/Container/Container.php`) is a minimal service locator with two registration modes:

```php
// New instance on every call
$container->bind(SomeClass::class, static fn(Container $c) => new SomeClass(...));

// Shared instance (lazy singleton)
$container->singleton(SomeClass::class, static fn(Container $c) => new SomeClass(...));
```

`$container->make(SomeClass::class)` resolves the binding. If no binding is registered it throws `RuntimeException` — there is no auto-wiring. All bindings are declared explicitly in `bootstrap/app.php`. Repository bindings are always singletons (one PDO connection per request); use-case and controller bindings use `bind` (transient).

## How the router works

`Router` stores an ordered list of `[method, pattern, handler]` tuples. On each request, `dispatch()` iterates the list and calls `match()` against the incoming URI. `match()` converts `{param}` placeholders into named capture groups:

```
/api/v1/events/{slug}  →  #^/api/v1/events/(?P<slug>[^/]+)$#
```

The first matching route wins. Named captures are returned as the `$params` array and forwarded to the handler closure. If no route matches, `Response::notFound()` is returned.

Routes are registered in `routes/api.php`, which receives the `Router` and `Container` as arguments and is required once during container bootstrap.

## Request lifecycle

```
public/index.php
  └─ require bootstrap/app.php          → builds Container, returns Kernel
  └─ Request::fromGlobals()             → parses $_SERVER, $_GET, php://input
  └─ Kernel::handle(Request)
       └─ Container::make(Router)       → Router singleton (routes already loaded)
       └─ Router::dispatch(Request)
            └─ match route pattern
            └─ middleware pipeline (every route)
                 └─ TenantContextMiddleware
                 |    └─ reads Host header (or X-Daems-Tenant for platform admins)
                 |    └─ HostTenantResolver: DB lookup → tenant-fallback.php fallback
                 |    └─ attaches resolved Tenant to Request; returns 404 if unknown
                 └─ AuthMiddleware  (protected routes)
                 |    └─ validates Bearer token (hash lookup, expiry, revocation)
                 |    └─ loads user's role from user_tenants for the active tenant
                 |    └─ builds ActingUser(id, email, isPlatformAdmin,
                 |                        activeTenant, roleInActiveTenant)
                 |    └─ attaches ActingUser to Request
                 └─ handler closure
                      └─ Container::make(Controller)
                           └─ Controller::action(Request, params)
                                └─ validate input
                                └─ build Input DTO
                                └─ UseCase::execute(Input)
                                     └─ Repository::method(...)   [SQL via PDO]
                                     └─ return Output DTO
                                └─ Response::json([...])
  └─ Kernel::send(Response)             → http_response_code() + echo
```

Exceptions thrown anywhere inside `Kernel::handle()` are caught and converted to `500 Internal Server Error` JSON responses.

**Tenant override.** A platform admin (`users.is_platform_admin = TRUE`) may include `X-Daems-Tenant: <slug>` on any request. `TenantContextMiddleware` detects the header, resolves the named slug instead of the `Host`, and proceeds normally. Non-admins sending the header receive `403 tenant_override_forbidden`.

## Namespace structure

```
Daems\
  Domain\
    Shared\ValueObject\Uuid7
    User\           User, UserId, UserRepositoryInterface
    Tenant\         TenantId, TenantSlug, TenantDomain, Tenant,
                    UserTenantRole, TenantRepositoryInterface,
                    UserTenantRepositoryInterface
    Event\          Event, EventId, EventRegistration, EventRepositoryInterface
    Project\        Project, ProjectId, ProjectComment, ProjectParticipant,
                    ProjectUpdate, ProjectProposal, ProjectRepositoryInterface,
                    ProjectProposalRepositoryInterface
    Forum\          ForumCategory, ForumTopic, ForumPost, ForumRepositoryInterface
    Insight\        Insight, InsightId, InsightRepositoryInterface
    Membership\     MemberApplication, SupporterApplication, (Repository interfaces)
  Application\
    Auth\           LoginUser, RegisterUser
    User\           GetProfile, UpdateProfile, ChangePassword, DeleteAccount,
                    GetUserActivity
    Event\          ListEvents, GetEvent, RegisterForEvent, UnregisterFromEvent
    Project\        ListProjects, GetProject, CreateProject, UpdateProject,
                    ArchiveProject, AddProjectComment, LikeProjectComment,
                    JoinProject, LeaveProject, AddProjectUpdate,
                    SubmitProjectProposal
    Forum\          ListForumCategories, GetForumCategory, GetForumThread,
                    CreateForumTopic, CreateForumPost, LikeForumPost,
                    IncrementTopicView
    Insight\        ListInsights, GetInsight
    Membership\     SubmitMemberApplication, SubmitSupporterApplication
  Infrastructure\
    Framework\
      Container\    Container
      Database\     Connection
      Http\         Kernel, Router, Request, Response
    Adapter\
      Api\Controller\
                    AuthController, UserController, EventController,
                    ProjectController, ForumController, InsightController,
                    ApplicationController
      Persistence\Sql\
                    SqlUserRepository, SqlEventRepository, SqlProjectRepository,
                    SqlForumRepository, SqlInsightRepository,
                    SqlMemberApplicationRepository,
                    SqlSupporterApplicationRepository,
                    SqlProjectProposalRepository
```

## Value objects

### Uuid7

`Daems\Domain\Shared\ValueObject\Uuid7` generates time-ordered UUID v7 values without any external library. Generation:

1. Take the current Unix timestamp in milliseconds (48 bits) as the first 12 hex characters.
2. Append `7` as the version nibble followed by 12 random bits.
3. Set the RFC 4122 variant bits in the next group.
4. Fill the remaining 48 bits with random bytes.

All entity ID types (`UserId`, `EventId`, etc.) wrap a `Uuid7` instance and expose it as a `CHAR(36)` string for persistence.

## Repository pattern

Each aggregate root has an interface in the Domain layer (e.g. `UserRepositoryInterface`) and a concrete implementation in `Infrastructure\Adapter\Persistence\Sql\`. Implementations receive a `Connection` instance, execute raw parameterised SQL statements via `Connection::query()`, `queryOne()`, or `execute()`, and map result arrays back to domain objects. There is no query builder or ORM.

Repositories are registered as singletons in the container so a single PDO connection is reused for the lifetime of the request.
