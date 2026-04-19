# Tenant Data Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `tenant_id` column to every per-tenant table (migrations 025–033), update every SQL repository and use case to scope queries by `TenantId`, and add tenant-isolation regression tests proving that tenant A data never leaks to tenant B.

**Architecture:** 9 per-table migrations following an identical 3-step pattern (add nullable column → backfill to `daems` tenant → enforce NOT NULL + FK). Each repository method gains a `TenantId` parameter. Each use case pulls tenant from `ActingUser::activeTenant`. 7 new `*TenantIsolationTest` classes prove the invariants.

**Tech Stack:** PHP 8.1+ at PHPStan level 9, MySQL 8.x, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-04-19-DS_UPGRADE.md` sections 5–7, 10.1.

**Prerequisite:** PR 2 (tenant infrastructure) merged to `dev`.

**PR target:** PR 3 of 3.

---

## File Structure

**Created — Database migrations:**
- `database/migrations/025_add_tenant_id_to_events.sql`
- `database/migrations/026_add_tenant_id_to_insights.sql`
- `database/migrations/027_add_tenant_id_to_projects.sql`
- `database/migrations/028_add_tenant_id_to_member_applications.sql`
- `database/migrations/029_add_tenant_id_to_supporter_applications.sql`
- `database/migrations/030_add_tenant_id_to_forum_tables.sql`
- `database/migrations/031_add_tenant_id_to_project_extras.sql`
- `database/migrations/032_add_tenant_id_to_event_registrations.sql`
- `database/migrations/033_add_tenant_id_to_member_register_audit.sql`

**Created — Isolation tests:**
- `tests/Isolation/ProjectTenantIsolationTest.php`
- `tests/Isolation/EventTenantIsolationTest.php`
- `tests/Isolation/MemberApplicationTenantIsolationTest.php`
- `tests/Isolation/SupporterApplicationTenantIsolationTest.php`
- `tests/Isolation/ForumTenantIsolationTest.php`
- `tests/Isolation/InsightTenantIsolationTest.php`
- `tests/Isolation/UserTenantIsolationTest.php`
- `tests/Isolation/IsolationTestCase.php` (shared fixtures)

**Modified — SQL repositories:** all `src/Infrastructure/Adapter/Persistence/Sql/Sql*Repository.php` files
**Modified — Use cases:** all `src/Application/UseCase/**/*.php` files that access per-tenant data
**Modified — Controllers:** all `src/Infrastructure/Adapter/Api/Controller/*.php` files that pass ActingUser forward
**Modified — Existing repository tests:** each `Sql*RepositoryTest.php` (add `TenantId` parameter)

---

## Canonical Patterns (reference for all tasks)

### Migration Pattern (used 9 times)

Each migration 025–033 follows this three-step template. Replace `<tbl>`, `<index-cols>` per table:

```sql
-- Migration NNN: add tenant_id to <tbl>

-- Step 1: add nullable column
ALTER TABLE <tbl>
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

-- Step 2: backfill to 'daems' tenant
UPDATE <tbl>
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

-- Step 3: enforce NOT NULL + FK + index
ALTER TABLE <tbl>
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_<tbl>_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX <tbl>_tenant_idx (tenant_id, <index-cols>);
```

### Repository Pattern

```php
// Before (Sql*Repository.php):
public function findBySlug(string $slug): ?Project
{
    $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = ?');
    $stmt->execute([$slug]);
    // ...
}

public function list(int $limit, int $offset): array
{
    // ...
}

// After:
public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project
{
    $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = ? AND tenant_id = ?');
    $stmt->execute([$slug, $tenantId->value()]);
    // ...
}

public function listForTenant(TenantId $tenantId, int $limit, int $offset): array
{
    $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$tenantId->value(), $limit, $offset]);
    // ...
}

// Cross-tenant variant (only where genuinely needed for GSA aggregates):
public function listAllTenantsForPlatformAdmin(int $limit, int $offset): array
{
    $stmt = $this->pdo->prepare('SELECT * FROM projects ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    // ...
}
```

Repository interface update mirror: every Domain-layer `*RepositoryInterface` gets the corresponding signature change.

### Use Case Pattern

```php
// Before:
public function execute(AddProjectCommentInput $input, ActingUser $actor): AddProjectCommentOutput
{
    $project = $this->projectRepo->findBySlug($input->slug)
        ?? throw new ProjectNotFoundException();
    // ...
}

// After:
public function execute(AddProjectCommentInput $input, ActingUser $actor): AddProjectCommentOutput
{
    $project = $this->projectRepo->findBySlugForTenant($input->slug, $actor->activeTenant)
        ?? throw new ProjectNotFoundException();

    if (!$actor->isPlatformAdmin && $actor->roleInActiveTenant === null) {
        throw new ForbiddenException('not_a_member');
    }
    // ...
}
```

Also: `Project` entities that are inserted via use case must receive the tenant_id — update entity constructor to take `TenantId` and update `SqlProjectRepository::insert()` to persist it.

### Insert Pattern (when creating new per-tenant rows)

```php
// In Sql*Repository::insert():
$stmt = $this->pdo->prepare(
    'INSERT INTO projects (id, tenant_id, slug, title, ...) VALUES (?, ?, ?, ?, ...)'
);
$stmt->execute([
    $project->id->value(),
    $project->tenantId->value(),    // NEW
    $project->slug,
    $project->title,
    // ...
]);
```

### Isolation Test Pattern (used 7 times)

```php
// tests/Isolation/ProjectTenantIsolationTest.php
final class ProjectTenantIsolationTest extends IsolationTestCase
{
    public function test_admin_of_daems_cannot_see_sahegroup_projects(): void
    {
        $this->seedProject(tenantSlug: 'sahegroup', slug: 'secret', title: 'Top Secret');
        $this->seedProject(tenantSlug: 'daems', slug: 'public', title: 'Public');

        $daemsAdmin = $this->makeActingUser(tenantSlug: 'daems', role: UserTenantRole::Admin, isPlatformAdmin: false);

        $results = $this->projectRepo->listForTenant($daemsAdmin->activeTenant, limit: 100, offset: 0);

        $titles = array_map(fn ($p) => $p->title, $results);
        self::assertContains('Public', $titles);
        self::assertNotContains('Top Secret', $titles);
    }

    public function test_repository_has_no_legacy_non_scoped_methods(): void
    {
        self::assertFalse(method_exists(SqlProjectRepository::class, 'list'), 'legacy list() must be removed');
        self::assertFalse(method_exists(SqlProjectRepository::class, 'findBySlug'), 'legacy findBySlug() must be removed');
        self::assertTrue(method_exists(SqlProjectRepository::class, 'listForTenant'));
        self::assertTrue(method_exists(SqlProjectRepository::class, 'findBySlugForTenant'));
    }

    public function test_gsa_override_header_returns_target_tenant_data(): void
    {
        $this->seedProject(tenantSlug: 'sahegroup', slug: 'sg-1', title: 'SG 1');

        $gsa = $this->makeActingUser(tenantSlug: 'sahegroup', role: null, isPlatformAdmin: true);   // after override

        $results = $this->projectRepo->listForTenant($gsa->activeTenant, limit: 100, offset: 0);

        $titles = array_map(fn ($p) => $p->title, $results);
        self::assertContains('SG 1', $titles);
    }
}
```

---

## Task 1: Create IsolationTestCase fixture helper

Shared base class for all 7 isolation tests. Seeds 2 tenants, helper to create tenant-scoped data, helper to build an ActingUser.

**Files:**
- Create: `tests/Isolation/IsolationTestCase.php`

- [ ] **Step 1.1: Write IsolationTestCase**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Integration\MigrationTestCase;

abstract class IsolationTestCase extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(33);   // entire schema
        $this->seedTenants();
    }

    protected function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = (string) $stmt->fetchColumn();
        return TenantId::fromString($id);
    }

    protected function seedTenants(): void
    {
        // daems + sahegroup are already seeded by migration 019.
    }

    protected function seedUser(string $id, string $email, bool $isPlatformAdmin = false): UserId
    {
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES ('{$id}', 'User', '{$email}', 'x', '1990-01-01', " . ($isPlatformAdmin ? '1' : '0') . ")"
        );
        return UserId::fromString($id);
    }

    protected function attachUserToTenant(UserId $userId, string $tenantSlug, UserTenantRole $role): void
    {
        $this->pdo()->prepare(
            "INSERT INTO user_tenants (user_id, tenant_id, role) VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?)"
        )->execute([$userId->value(), $tenantSlug, $role->value]);
    }

    protected function makeActingUser(
        string $tenantSlug,
        ?UserTenantRole $role,
        bool $isPlatformAdmin = false,
        string $userId = '01958000-0000-7000-8000-00000000aaaa',
        string $email = 'actor@test',
    ): ActingUser {
        // ensure user exists
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->seedUser($userId, $email, $isPlatformAdmin);
        }
        if ($role !== null) {
            $this->attachUserToTenant(UserId::fromString($userId), $tenantSlug, $role);
        }

        return new ActingUser(
            id:                 UserId::fromString($userId),
            email:              $email,
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId($tenantSlug),
            roleInActiveTenant: $role,
        );
    }
}
```

- [ ] **Step 1.2: Commit**

```bash
git add tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Tests: add IsolationTestCase fixture helper"
```

---

## Task 2: Migration 025 — tenant_id for events

**Files:**
- Create: `database/migrations/025_add_tenant_id_to_events.sql`
- Test: `tests/Integration/Migration/Migration025Test.php`

- [ ] **Step 2.1: Write the migration**

```sql
-- Migration 025: add tenant_id to events

ALTER TABLE events ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE events SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems') WHERE tenant_id IS NULL;

ALTER TABLE events
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX events_tenant_start_idx (tenant_id, start_at);
```

- [ ] **Step 2.2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration025Test extends MigrationTestCase
{
    public function test_tenant_id_column_added_with_fk(): void
    {
        $this->runMigrationsUpTo(24);
        // seed existing event
        $this->pdo()->exec("INSERT INTO events (id, slug, title, start_at) VALUES ('e1', 'x', 'Test', '2026-05-01')");

        $this->runMigration('025_add_tenant_id_to_events.sql');

        self::assertContains('tenant_id', $this->columnsOf('events'));
        self::assertContains('fk_events_tenant', $this->foreignKeysOf('events'));

        // backfill verified
        $row = $this->pdo()->query("SELECT tenant_id FROM events WHERE id = 'e1'")->fetch(\PDO::FETCH_ASSOC);
        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        self::assertSame($daemsId, $row['tenant_id']);
    }

    public function test_tenant_id_is_not_null_after_migration(): void
    {
        $this->runMigrationsUpTo(24);
        $this->runMigration('025_add_tenant_id_to_events.sql');

        $col = $this->pdo()->query("SHOW COLUMNS FROM events LIKE 'tenant_id'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('NO', $col['Null']);
    }
}
```

Note: adjust column names (`start_at`, `slug`, `title`) per actual events schema — inspect `database/migrations/001_create_events_table.sql`.

- [ ] **Step 2.3: Run tests, apply, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration025Test.php
mysql -u root daems_db < database/migrations/025_add_tenant_id_to_events.sql
git add database/migrations/025_add_tenant_id_to_events.sql tests/Integration/Migration/Migration025Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 025 — add tenant_id to events"
```

---

## Tasks 3–10: Migrations 026–033

For each remaining migration, follow the same pattern. The table below lists per-migration specifics; apply the migration pattern from "Canonical Patterns" above.

| Task | Migration # | Table(s)                                   | `AFTER` column (for clarity) | Extra index cols                   |
|------|-------------|--------------------------------------------|------------------------------|------------------------------------|
| 3    | 026         | `insights`                                 | `id`                         | `published_at`                     |
| 4    | 027         | `projects`                                 | `id`                         | `created_at`                       |
| 5    | 028         | `member_applications`                      | `id`                         | `status`                           |
| 6    | 029         | `supporter_applications`                   | `id`                         | `status`                           |
| 7    | 030         | `forum_categories`, `forum_topics`, `forum_posts` (one migration file) | `id` | per-table: `sort_order`, `created_at`, `created_at` |
| 8    | 031         | `project_comments`, `project_updates`, `project_participants`, `project_proposals` (one file) | `id` | `created_at` |
| 9    | 032         | `event_registrations`                      | `id`                         | `event_id`                         |
| 10   | 033         | `member_register_audit`                    | `id`                         | `created_at`                       |

For each task:

- [ ] **Step N.1:** Write migration SQL following the canonical pattern (3 steps per table). For multi-table migrations (030, 031), concatenate all tables' 3-step blocks into one file.
- [ ] **Step N.2:** Write test: one `Migration<NNN>Test` class per migration, with these assertions for each affected table:
  - `tenant_id` column exists
  - `fk_<tbl>_tenant` FK exists
  - column is NOT NULL
  - pre-existing seed data backfilled to `daems` tenant
- [ ] **Step N.3:** Run test, verify pass
- [ ] **Step N.4:** Apply migration to dev DB
- [ ] **Step N.5:** Commit

**Example commit message format:**
```
Feat(db): migration 030 — add tenant_id to forum_categories, forum_topics, forum_posts
```

Once all 9 migrations are committed, the schema is fully tenant-scoped. The code layer still calls non-scoped repository methods — that's what Tasks 11+ fix.

---

## Task 11: Update SqlProjectRepository + interface

Move all non-scoped methods to `*ForTenant` variants. Keep `*AllTenants` variants only where GSA aggregate endpoints truly need them (check each use case).

**Files:**
- Modify: `src/Domain/Project/ProjectRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php`
- Modify: `tests/Integration/Persistence/Sql/SqlProjectRepositoryTest.php` (rename tests, add tenant_id to fixtures)
- Modify: `src/Domain/Project/Project.php` (add `tenantId` field)

- [ ] **Step 11.1: Update Project entity**

Add `TenantId $tenantId` to `src/Domain/Project/Project.php` constructor. Update all direct constructions (search `grep -rn "new Project(" src/ tests/`).

- [ ] **Step 11.2: Update ProjectRepositoryInterface**

Every method that touches `projects` or `project_*` tables gets a `TenantId` parameter or gets renamed `*AllTenants`/`*CrossTenant` if it legitimately needs cross-tenant reach.

Example:
```php
interface ProjectRepositoryInterface
{
    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project;
    public function listForTenant(TenantId $tenantId, int $limit, int $offset): array;
    public function insert(Project $project): void;   // project already carries tenant_id
    public function update(Project $project): void;
    // ...
}
```

- [ ] **Step 11.3: Update SqlProjectRepository**

Apply the Repository Pattern from Canonical Patterns. Every query gets `AND tenant_id = ?`. Inserts include `tenant_id` from the entity.

- [ ] **Step 11.4: Update ProjectRepositoryTest**

Seed rows in two tenants, verify `listForTenant(daems)` returns only daems rows. PHPStan level 9 fixes needed in test code as well.

- [ ] **Step 11.5: Write ProjectTenantIsolationTest**

Create `tests/Isolation/ProjectTenantIsolationTest.php` using the Isolation Test Pattern above. Minimum 3 tests:
- `test_admin_of_daems_cannot_see_sahegroup_projects`
- `test_repository_has_no_legacy_non_scoped_methods`
- `test_gsa_can_query_any_tenant`

- [ ] **Step 11.6: Run tests, commit**

```bash
composer analyse
composer test
git add src/Domain/Project/ src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php tests/Integration/Persistence/Sql/SqlProjectRepositoryTest.php tests/Isolation/ProjectTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Refactor: SqlProjectRepository scoped by TenantId + isolation test"
```

---

## Tasks 12–17: Repository updates for the other 6 aggregates

Repeat Task 11's pattern for each of these:

| Task | Aggregate                        | Repository + Interface                                                                                      | Isolation test name                              |
|------|----------------------------------|-------------------------------------------------------------------------------------------------------------|--------------------------------------------------|
| 12   | Event + EventRegistration        | `SqlEventRepository.php`                                                                                    | `EventTenantIsolationTest`                       |
| 13   | Insight                          | `SqlInsightRepository.php`                                                                                  | `InsightTenantIsolationTest`                     |
| 14   | MemberApplication                | `SqlMemberApplicationRepository.php`                                                                        | `MemberApplicationTenantIsolationTest`           |
| 15   | SupporterApplication             | `SqlSupporterApplicationRepository.php`                                                                     | `SupporterApplicationTenantIsolationTest`        |
| 16   | Forum (Category, Topic, Post)    | `SqlForumRepository.php`                                                                                    | `ForumTenantIsolationTest`                       |
| 17   | User (via `user_tenants`)        | `SqlUserRepository.php`; admin-list queries need tenant filter                                              | `UserTenantIsolationTest`                        |

For each task:

- [ ] **Step N.1:** Update Domain entity (add `TenantId $tenantId` field if entity represents a per-tenant row)
- [ ] **Step N.2:** Update RepositoryInterface (add `TenantId` to every tenant-scoped method; rename cross-tenant variants)
- [ ] **Step N.3:** Update Sql repository
- [ ] **Step N.4:** Update repository integration test
- [ ] **Step N.5:** Write isolation test (3+ assertions)
- [ ] **Step N.6:** Run `composer analyse && composer test`
- [ ] **Step N.7:** Commit

**Commit message template:**
```
Refactor: Sql<Aggregate>Repository scoped by TenantId + isolation test
```

---

## Task 18: Update SqlAdminRepository

`AdminStats` aggregates across the tenant. Decide per-method whether it's tenant-scoped (most admin stats) or cross-tenant (GSA-only global view).

**Files:**
- Modify: `src/Domain/Admin/AdminStatsRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminRepository.php`
- Modify: `src/Application/UseCase/Admin/GetAdminStats/GetAdminStats.php`
- Modify: `tests/` (admin tests)

- [ ] **Step 18.1: Update interface**

```php
interface AdminStatsRepositoryInterface
{
    public function statsForTenant(TenantId $tenantId): AdminStats;
    public function memberGrowthForTenant(TenantId $tenantId, string $period): array;
    public function statsAcrossAllTenants(): AdminStats;   // GSA only
}
```

- [ ] **Step 18.2: Update SqlAdminRepository**

Add `WHERE tenant_id = ?` to every query in tenant-scoped methods. For `statsAcrossAllTenants`, omit the filter.

- [ ] **Step 18.3: Update GetAdminStats use case**

```php
public function execute(GetAdminStatsInput $input, ActingUser $actor): GetAdminStatsOutput
{
    if (!$actor->isAdminIn($actor->activeTenant)) {
        throw new ForbiddenException('insufficient_role');
    }

    // Per-tenant by default; cross-tenant only for GSA with explicit global scope
    $stats = ($input->scope === 'all' && $actor->isPlatformAdmin)
        ? $this->repo->statsAcrossAllTenants()
        : $this->repo->statsForTenant($actor->activeTenant);

    return GetAdminStatsOutput::fromStats($stats);
}
```

Add `scope: ?string` to `GetAdminStatsInput` (default null → tenant-scoped).

- [ ] **Step 18.4: Update admin controller**

Pass `$req->query('scope')` through as the input's scope.

- [ ] **Step 18.5: Update controller routes**

The `/api/v1/backstage/stats` and `/api/v1/admin/stats` routes are already AuthMiddleware-gated. No route changes — middleware populates ActingUser correctly.

- [ ] **Step 18.6: Run tests, commit**

```bash
composer test
git add src/Domain/Admin/ src/Infrastructure/Adapter/Persistence/Sql/SqlAdminRepository.php src/Application/UseCase/Admin/ tests/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Refactor: AdminStats scoped by TenantId + GSA cross-tenant scope"
```

---

## Task 19: Update every use case to use ActingUser.activeTenant

There are ~50 use cases across Auth, User, Project, Event, Forum, Insight, Admin, Applications. Each that reads or writes per-tenant data must:
1. Pull `$actor->activeTenant` and pass to repository methods
2. Validate tenant membership or platform-admin status before mutations
3. On insert of new aggregate, set `tenantId = $actor->activeTenant` on the entity

**Files:**
- Modify: every file under `src/Application/UseCase/**/*.php` that reads or writes per-tenant tables

- [ ] **Step 19.1: Enumerate use cases**

```bash
find /c/laragon/www/daems-platform/src/Application/UseCase -name "*.php" -not -name "*Input.php" -not -name "*Output.php" | sort
```

Work through each file systematically.

- [ ] **Step 19.2: For each use case — apply Use Case Pattern from Canonical Patterns section**

Checklist per use case:
- Does it read a per-tenant repo? → change to `*ForTenant($actor->activeTenant)` variant
- Does it create a new per-tenant entity? → pass `$actor->activeTenant` to entity constructor
- Does it mutate an entity? → verify the entity's tenantId matches `$actor->activeTenant` (or actor is platform admin)
- Does it gate on role? → use `$actor->isAdminIn(...)` / `$actor->roleIn(...) === UserTenantRole::X`

- [ ] **Step 19.3: Commit in batches per aggregate**

Don't commit all 50 at once — batch per aggregate area:
- Project use cases (8+ files) → one commit
- Event use cases → one commit
- Forum use cases → one commit
- etc.

Commit message: `Refactor: <aggregate> use cases scoped by TenantId`

- [ ] **Step 19.4: Keep PHPStan + tests green throughout**

After each batch: `composer analyse && composer test`.

---

## Task 20: Write remaining isolation tests (overview table audit)

By end of Task 17, 7 isolation tests should exist. Audit:

- [ ] **Step 20.1: Verify 7 isolation tests present**

```bash
ls /c/laragon/www/daems-platform/tests/Isolation/
```

Expected files:
- `IsolationTestCase.php` (from Task 1)
- `ProjectTenantIsolationTest.php`
- `EventTenantIsolationTest.php`
- `MemberApplicationTenantIsolationTest.php`
- `SupporterApplicationTenantIsolationTest.php`
- `ForumTenantIsolationTest.php`
- `InsightTenantIsolationTest.php`
- `UserTenantIsolationTest.php`

- [ ] **Step 20.2: Each must have at least 3 tests**

Minimum assertions:
1. Admin of tenant A cannot see tenant B data
2. Legacy non-scoped repo methods have been removed (static `method_exists()` check)
3. GSA with `X-Daems-Tenant` override reaches target tenant data

- [ ] **Step 20.3: Run all isolation tests**

```bash
vendor/bin/phpunit tests/Isolation/
```

Expected: all green.

---

## Task 21: E2E tenant isolation tests

**Files:**
- Create: `tests/E2E/TenantIsolation/daems_admin_cannot_see_sahegroup_data.spec.ts`
- Create: `tests/E2E/TenantIsolation/gsa_can_switch_tenants_via_api_header.spec.ts`
- Create: `tests/E2E/TenantIsolation/registered_user_without_membership_gets_403.spec.ts`
- Create: `tests/E2E/TenantIsolation/login_from_unknown_host_returns_404.spec.ts`

Use Playwright following existing E2E test patterns in `tests/E2E/`.

- [ ] **Step 21.1: Adapt existing E2E base class**

If `tests/E2E/` uses PHPUnit (not Playwright), use PHPUnit HTTP assertions. Inspect existing `F-001`…`F-010` tests and mirror their style.

- [ ] **Step 21.2: Implement each of the 4 tests**

For each:
1. Set up data in DB (two tenants, users with roles)
2. Issue real HTTP call with correct Host header and Bearer token
3. Assert response code and body shape

- [ ] **Step 21.3: Run, commit**

```bash
composer test:e2e
git add tests/E2E/TenantIsolation/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Tests(e2e): tenant isolation — cross-tenant access denial and GSA override"
```

---

## Task 22: Update docs (database schema, api.md, ADR-014)

**Files:**
- Modify: `docs/database.md`
- Modify: `docs/api.md`

- [ ] **Step 22.1: Update database.md**

Add tenant_id column description for every per-tenant table. Add "Multi-tenant invariant" callout at the top of the schema section explaining that per-tenant tables carry `tenant_id NOT NULL` with FK to `tenants(id) ON DELETE RESTRICT`.

- [ ] **Step 22.2: Update api.md**

Note which endpoints are tenant-scoped (all `/projects`, `/events`, `/forum`, `/insights`, `/applications`, `/backstage/*`), explicitly call out that responses only include data from the tenant resolved from Host or `X-Daems-Tenant` override.

Add new error responses where not yet present:
- `403 not_a_member` — returned when user has no `user_tenants` row for the active tenant
- `403 tenant_override_forbidden`
- `404 unknown_tenant` (override slug not found)

- [ ] **Step 22.3: Commit**

```bash
git add docs/database.md docs/api.md
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Docs: database.md + api.md for tenant-scoped tables and endpoints"
```

---

## Task 23: Final verification

- [ ] **Step 23.1: Full test suite**

```bash
composer test
composer test:e2e
composer analyse
composer test:mutation
```

All green. MSI ≥ 85 %.

- [ ] **Step 23.2: Fresh database migration dry-run**

On a fresh `daems_db_test`, apply migrations 001..033 in order and verify no errors and all tests pass against the fresh schema:

```bash
mysql -u root -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in $(ls /c/laragon/www/daems-platform/database/migrations/*.sql | sort); do
    echo "=> Applying $f"
    mysql -u root daems_db_test < "$f" || { echo "FAIL at $f"; exit 1; }
done
composer test
```

Expected: clean migration, green tests.

- [ ] **Step 23.3: Manual smoke against dev database**

Ensure existing flows still work:
```bash
# Login
curl -s -X POST http://daem-society.local/api/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"<existing>","password":"<pw>"}'
# List events (tenant-scoped)
curl -s http://daem-society.local/api/v1/events
# /auth/me
curl -s http://daem-society.local/api/v1/auth/me -H "Authorization: Bearer <token>"
```

- [ ] **Step 23.4: Git log review**

```bash
git log --oneline origin/dev..HEAD
```

Expected: ~25–30 commits covering migrations 025–033, repository updates, use case updates, isolation tests, docs.

- [ ] **Step 23.5: Ask user for push approval**

> "PR 3 (tenant data migration) valmis. X committia paikallisesti. Pushaanko origin/dev:iin?"

---

## Completion criteria

- [ ] Migrations 025–033 applied; every per-tenant table has `tenant_id NOT NULL` with FK
- [ ] Every SQL repository scopes queries by `TenantId`; legacy non-scoped methods removed
- [ ] Every per-tenant Domain entity has `tenantId: TenantId` field
- [ ] Every tenant-scoped use case reads `$actor->activeTenant` and passes it to the repository
- [ ] Admin stats endpoints support `scope=all` for GSA (cross-tenant)
- [ ] 7 `*TenantIsolationTest` classes each with ≥ 3 tests, all green
- [ ] 4 E2E tests for cross-tenant denial, GSA override, missing membership, unknown host
- [ ] PHPStan level 9, 0 errors (no baseline)
- [ ] `composer test:mutation` MSI ≥ 85 %
- [ ] Fresh migration dry-run (001..033) succeeds against an empty database
- [ ] `docs/database.md`, `docs/api.md` updated
- [ ] Cross-tenant data leakage is impossible: admin of daems running any API call (without `X-Daems-Tenant`) never gets sahegroup data — verified by isolation tests
