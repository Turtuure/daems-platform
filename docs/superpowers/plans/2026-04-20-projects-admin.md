# Projects Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/backstage/projects` — proposal moderation, project CRUD with `featured` toggle, comment moderation — and integrate pending proposals into the existing admin pending-toast stack.

**Architecture:** Migrations 044–046 add `projects.featured`, extend `admin_application_dismissals.app_type` enum with `project_proposal`, create `project_comment_moderation_audit`, and add decision metadata to `project_proposals`. Ten new Application-layer use cases under `src/Application/Backstage/` cover admin project CRUD, proposal approve/reject (transactional: creates `projects` row from proposal fields), and comment hard-delete with audit. `ListPendingApplicationsForAdmin` is extended to merge pending proposals into the existing admin inbox. Frontend adds one admin page with three tabs (proposals / projects / comments), two PHP proxies, and a one-line routing branch in `toasts.js`.

**Tech Stack:** PHP 8.1, Clean Architecture (Domain / Application / Infrastructure), PDO/MySQL 8, PHPStan level 9, PHPUnit. Frontend: daem-society PHP + vanilla JS.

**Spec:** `docs/superpowers/specs/2026-04-20-projects-admin-design.md`

**Commit identity (every commit):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No `Co-Authored-By`. Never stage `.claude/`. Never auto-push.

**Project conventions (critical):**
- PHPUnit testsuite names CAPITALISED: `Unit` / `Integration` / `E2E`. Lowercased silently returns "No tests executed!" — always verify the count.
- InMemory fakes at `tests/Support/Fake/` with namespace `Daems\Tests\Support\Fake`.
- DI bindings must exist in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php`. Missing bootstrap = live 500 while E2E stays green.
- SQL repo ctor types vary (Connection vs PDO). Match ctor signature; use `$c->make(Connection::class)->pdo()` when PDO is required. Live-smoke with `APP_DEBUG=true` + curl before declaring wiring done.
- Ignore the "PHPStan 2.x is available" banner — noise, not an error.

---

## File Inventory

### Backend — new

**Migrations:**
- `database/migrations/044_add_featured_to_projects.sql`
- `database/migrations/045_extend_dismissals_enum_and_comment_audit.sql`
- `database/migrations/046_add_decision_metadata_to_project_proposals.sql`

**Domain:**
- `src/Domain/Project/ProjectCommentModerationAudit.php`
- `src/Domain/Project/ProjectCommentModerationAuditRepositoryInterface.php`

**Application (use cases — one directory per, with Input/Output siblings):**
- `src/Application/Backstage/ListProjectsForAdmin/`
- `src/Application/Backstage/CreateProjectAsAdmin/`
- `src/Application/Backstage/AdminUpdateProject/`
- `src/Application/Backstage/ChangeProjectStatus/`
- `src/Application/Backstage/SetProjectFeatured/`
- `src/Application/Backstage/ListProposalsForAdmin/`
- `src/Application/Backstage/ApproveProjectProposal/`
- `src/Application/Backstage/RejectProjectProposal/`
- `src/Application/Backstage/ListProjectCommentsForAdmin/`
- `src/Application/Backstage/DeleteProjectCommentAsAdmin/`

**Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php`

**Test fakes:**
- `tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php`

**Tests:** one unit test file per use case under `tests/Unit/Application/Backstage/`, plus:
- `tests/Integration/Migration/Migration044Test.php`, `Migration045Test.php`, `Migration046Test.php`
- `tests/Integration/Application/ProjectsAdminIntegrationTest.php`
- `tests/Integration/Application/ProjectCommentModerationIntegrationTest.php`
- `tests/Isolation/ProjectsAdminTenantIsolationTest.php`
- `tests/E2E/Backstage/ProjectsAdminEndpointsTest.php`
- `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php`

### Backend — modified

- `src/Domain/Project/Project.php` — add `bool $featured` (default false) + `featured()` getter.
- `src/Domain/Project/ProjectProposal.php` — add `?string $decidedAt, $decidedBy, $decisionNote` (all defaults null) + getters.
- `src/Domain/Project/ProjectRepositoryInterface.php` — add 6 methods (see Task 3 for signatures).
- `src/Domain/Project/ProjectProposalRepositoryInterface.php` — add 3 methods (see Task 3).
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php` — implement new methods; public `listForTenant` ordering boosts `featured DESC`; hydrate + save cover `featured`.
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php` — implement `listPendingForTenant`, `findByIdForTenant`, `recordDecision`; hydrate new columns.
- `tests/Support/Fake/InMemoryProjectRepository.php` — mirror new methods; `listForTenant` orders by `featured DESC`.
- `tests/Support/Fake/InMemoryProjectProposalRepository.php` — mirror new methods.
- `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php` — merge project_proposals into items (new output item `type='project_proposal'`).
- `src/Application/Backstage/DismissApplication/DismissApplication.php` — accept `'project_proposal'` in input `appType` validation whitelist.
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add 11 admin methods.
- `routes/api.php` — register 11 new routes.
- `bootstrap/app.php` + `tests/Support/KernelHarness.php` — bind everything in both containers.
- `tests/Isolation/IsolationTestCase.php` — bump `runMigrationsUpTo(46)`.

### Frontend daem-society — new

- `public/pages/backstage/projects/index.php`
- `public/pages/backstage/projects/project-modal.js`
- `public/pages/backstage/projects/project-modal.css` (reuse `event-modal.css` tokens where possible)
- `public/api/backstage/projects.php` (JSON relay)
- `public/api/backstage/proposals.php` (JSON relay)

### Frontend daem-society — modified

- `public/pages/backstage/toasts.js` — click-handler routes `type==='project_proposal'` to `/backstage/projects?tab=proposals&highlight=...`.
- `public/pages/projects/grid.php` (or whichever renders the project card) — add a "Featured" badge when `project.featured === true`.
- Possibly `public/assets/css/daems.css` — small `.badge--featured` style.

---

## Task Order (dependency-correct)

1. Migrations + IsolationTestCase bump.
2. Domain + repo interface + SQL + InMemory updates.
3. Audit repo scaffolding (domain + SQL + InMemory).
4–13. Use cases one at a time (TDD).
14. Extend pending-applications use case + dismiss whitelist.
15. HTTP controllers + routes.
16. DI wiring (both containers) + live smoke.
17. Integration + Isolation + E2E tests.
18. Frontend admin page + proxies.
19. Frontend toast routing + public featured badge.
20. Final verification checklist.

---

### Task 1: Migrations 044, 045, 046 + IsolationTestCase bump

**Files:**
- Create: `database/migrations/044_add_featured_to_projects.sql`
- Create: `database/migrations/045_extend_dismissals_enum_and_comment_audit.sql`
- Create: `database/migrations/046_add_decision_metadata_to_project_proposals.sql`
- Create: `tests/Integration/Migration/Migration044Test.php`
- Create: `tests/Integration/Migration/Migration045Test.php`
- Create: `tests/Integration/Migration/Migration046Test.php`
- Modify: `tests/Isolation/IsolationTestCase.php`

- [ ] **Step 1: Write migration 044 + test (TDD)**

`tests/Integration/Migration/Migration044Test.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration044Test extends MigrationTestCase
{
    public function test_featured_column_added_as_tinyint_default_0(): void
    {
        $this->runMigrationsUpTo(43);
        $this->runMigration('044_add_featured_to_projects.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'projects'
               AND COLUMN_NAME = 'featured'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString('tinyint', strtolower((string) $row['COLUMN_TYPE']));
        self::assertSame('0', (string) $row['COLUMN_DEFAULT']);
        self::assertSame('NO', $row['IS_NULLABLE']);
    }
}
```

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration044Test.php` → FAIL (migration file missing).

Create `database/migrations/044_add_featured_to_projects.sql`:
```sql
ALTER TABLE projects
    ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0
        AFTER status;
```

Run test → PASS.

- [ ] **Step 2: Write migration 045 + test (TDD)**

`tests/Integration/Migration/Migration045Test.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration045Test extends MigrationTestCase
{
    public function test_dismissals_enum_now_includes_project_proposal(): void
    {
        $this->runMigrationsUpTo(44);
        $this->runMigration('045_extend_dismissals_enum_and_comment_audit.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_type'"
        );
        $type = (string) ($stmt?->fetchColumn() ?? '');
        self::assertStringContainsString("'project_proposal'", $type);
    }

    public function test_comment_moderation_audit_table_created(): void
    {
        $this->runMigrationsUpTo(44);
        $this->runMigration('045_extend_dismissals_enum_and_comment_audit.sql');

        $cols = $this->columnsOf('project_comment_moderation_audit');
        self::assertContains('id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('project_id', $cols);
        self::assertContains('comment_id', $cols);
        self::assertContains('action', $cols);
        self::assertContains('reason', $cols);
        self::assertContains('performed_by', $cols);
        self::assertContains('created_at', $cols);

        $fks = $this->foreignKeysOf('project_comment_moderation_audit');
        self::assertContains('fk_pcma_tenant', $fks);
    }
}
```

Run test → FAIL.

Create `database/migrations/045_extend_dismissals_enum_and_comment_audit.sql`:
```sql
ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal') NOT NULL;

CREATE TABLE project_comment_moderation_audit (
    id              CHAR(36) NOT NULL,
    tenant_id       CHAR(36) NOT NULL,
    project_id      CHAR(36) NOT NULL,
    comment_id      CHAR(36) NOT NULL,
    action          ENUM('deleted') NOT NULL,
    reason          VARCHAR(500) NULL,
    performed_by    CHAR(36) NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pcma_project (project_id),
    KEY idx_pcma_performer (performed_by),
    CONSTRAINT fk_pcma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Run → PASS.

- [ ] **Step 3: Write migration 046 + test (TDD)**

`tests/Integration/Migration/Migration046Test.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration046Test extends MigrationTestCase
{
    public function test_decision_columns_added_to_project_proposals(): void
    {
        $this->runMigrationsUpTo(45);
        $this->runMigration('046_add_decision_metadata_to_project_proposals.sql');

        $cols = $this->columnsOf('project_proposals');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }
}
```

Run → FAIL.

Create `database/migrations/046_add_decision_metadata_to_project_proposals.sql`:
```sql
ALTER TABLE project_proposals
    ADD COLUMN decided_at    DATETIME NULL,
    ADD COLUMN decided_by    CHAR(36) NULL,
    ADD COLUMN decision_note TEXT     NULL;
```

Run → PASS.

- [ ] **Step 4: Bump IsolationTestCase**

Edit `tests/Isolation/IsolationTestCase.php` line 18: change `runMigrationsUpTo(43)` → `runMigrationsUpTo(46)`.

- [ ] **Step 5: Apply all 3 migrations to dev DB**

```bash
for f in 044_add_featured_to_projects.sql 045_extend_dismissals_enum_and_comment_audit.sql 046_add_decision_metadata_to_project_proposals.sql; do
  C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -h 127.0.0.1 -u root -psalasana daems_db < "database/migrations/$f"
done
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -h 127.0.0.1 -u root -psalasana daems_db -e "SHOW COLUMNS FROM projects LIKE 'featured'; SHOW TABLES LIKE 'project_comment_moderation_audit'; SHOW COLUMNS FROM project_proposals LIKE 'decided_at';"
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/044_* database/migrations/045_* database/migrations/046_* tests/Integration/Migration/Migration04*Test.php tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): migrations 044-046 — featured flag, dismissals enum, comment audit, proposal decision metadata"
```

---

### Task 2: Project + ProjectProposal domain extensions + SQL/InMemory repos

**Files:**
- Modify: `src/Domain/Project/Project.php`
- Modify: `src/Domain/Project/ProjectProposal.php`
- Modify: `src/Domain/Project/ProjectRepositoryInterface.php`
- Modify: `src/Domain/Project/ProjectProposalRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php`
- Modify: `tests/Support/Fake/InMemoryProjectRepository.php`
- Modify: `tests/Support/Fake/InMemoryProjectProposalRepository.php`

- [ ] **Step 1: Add `featured` to Project entity**

In `src/Domain/Project/Project.php`, add as the last constructor arg:
```php
private readonly bool $featured = false,
```

Add getter:
```php
public function featured(): bool { return $this->featured; }
```

Existing call-sites (ctor without `featured`) remain valid due to default.

- [ ] **Step 2: Add decision metadata to ProjectProposal entity**

In `src/Domain/Project/ProjectProposal.php`, append three constructor args with null defaults:
```php
private readonly ?string $decidedAt = null,
private readonly ?string $decidedBy = null,
private readonly ?string $decisionNote = null,
```

Add getters:
```php
public function decidedAt(): ?string { return $this->decidedAt; }
public function decidedBy(): ?string { return $this->decidedBy; }
public function decisionNote(): ?string { return $this->decisionNote; }
```

- [ ] **Step 3: Extend ProjectRepositoryInterface**

Replace `src/Domain/Project/ProjectRepositoryInterface.php` with:
```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectRepositoryInterface
{
    /**
     * PUBLIC listing: only non-draft, non-archived-unless-asked projects, featured first.
     * @return Project[]
     */
    public function listForTenant(TenantId $tenantId, ?string $category = null, ?string $status = null, ?string $search = null): array;

    /**
     * ADMIN listing: all statuses, additional filters.
     * @param array{status?:string,category?:string,featured?:bool,q?:string} $filters
     * @return Project[]
     */
    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project;

    public function save(Project $project): void;

    /** @param array<string,mixed> $fields */
    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void;

    public function setStatusForTenant(string $id, TenantId $tenantId, string $status): void;

    public function setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void;

    public function deleteById(string $projectId): void;

    public function countParticipants(string $projectId): int;

    public function isParticipant(string $projectId, string $userId): bool;

    public function addParticipant(ProjectParticipant $participant): void;

    public function removeParticipant(string $projectId, string $userId): void;

    /** @return ProjectComment[] */
    public function findCommentsByProjectId(string $projectId): array;

    /** @return list<array{comment_id:string,project_id:string,project_title:string,author_name:string,content:string,created_at:string}> */
    public function listRecentCommentsForTenant(TenantId $tenantId, int $limit = 100): array;

    public function saveComment(ProjectComment $comment): void;

    public function deleteCommentForTenant(string $commentId, TenantId $tenantId): void;

    public function incrementCommentLikes(string $commentId): void;

    /** @return ProjectUpdate[] */
    public function findUpdatesByProjectId(string $projectId): array;

    public function saveUpdate(ProjectUpdate $update): void;
}
```

- [ ] **Step 4: Extend ProjectProposalRepositoryInterface**

Replace `src/Domain/Project/ProjectProposalRepositoryInterface.php` with:
```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectProposalRepositoryInterface
{
    public function save(ProjectProposal $proposal): void;

    /** @return ProjectProposal[] — pending only, newest first */
    public function listPendingForTenant(TenantId $tenantId): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void;
}
```

- [ ] **Step 5: Run PHPStan to surface breakage**

Run: `composer analyse`
Expected: failures in `SqlProjectRepository`, `InMemoryProjectRepository`, `SqlProjectProposalRepository`, `InMemoryProjectProposalRepository` — they don't satisfy the extended interfaces yet.

- [ ] **Step 6: Implement SqlProjectRepository additions**

Open `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php`.

In `listForTenant` (public), change the ORDER BY to `featured DESC, sort_order ASC, created_at DESC`.

Add `featured` to `save()` INSERT + `hydrate()` call.

Add these new methods at the end of the class (before private helpers):

```php
public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
{
    $sql = 'SELECT * FROM projects WHERE tenant_id = ?';
    $params = [$tenantId->value()];
    if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
        $sql .= ' AND status = ?';
        $params[] = $filters['status'];
    }
    if (isset($filters['category']) && is_string($filters['category']) && $filters['category'] !== '') {
        $sql .= ' AND category = ?';
        $params[] = $filters['category'];
    }
    if (isset($filters['featured']) && is_bool($filters['featured']) && $filters['featured'] === true) {
        $sql .= ' AND featured = 1';
    }
    if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== '') {
        $sql .= ' AND (title LIKE ? OR summary LIKE ?)';
        $params[] = '%' . $filters['q'] . '%';
        $params[] = '%' . $filters['q'] . '%';
    }
    $sql .= ' ORDER BY featured DESC, sort_order ASC, created_at DESC';
    return array_map($this->hydrate(...), $this->db->query($sql, $params));
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?Project
{
    $row = $this->db->queryOne(
        'SELECT * FROM projects WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
{
    if ($fields === []) return;
    $allowed = ['title','category','icon','summary','description','status','sort_order','featured'];
    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        if (!in_array($col, $allowed, true)) continue;
        $sets[] = "{$col} = ?";
        $params[] = $val;
    }
    if ($sets === []) return;
    $params[] = $id;
    $params[] = $tenantId->value();
    $this->db->execute(
        'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
        $params,
    );
}

public function setStatusForTenant(string $id, TenantId $tenantId, string $status): void
{
    if (!in_array($status, ['draft','active','archived'], true)) {
        throw new \DomainException('invalid_project_status');
    }
    $this->db->execute(
        'UPDATE projects SET status = ? WHERE id = ? AND tenant_id = ?',
        [$status, $id, $tenantId->value()],
    );
}

public function setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void
{
    $this->db->execute(
        'UPDATE projects SET featured = ? WHERE id = ? AND tenant_id = ?',
        [$featured ? 1 : 0, $id, $tenantId->value()],
    );
}

public function listRecentCommentsForTenant(TenantId $tenantId, int $limit = 100): array
{
    /** @var list<array{comment_id:string,project_id:string,project_title:string,author_name:string,content:string,created_at:string}> $rows */
    $rows = $this->db->query(
        'SELECT pc.id AS comment_id, pc.project_id AS project_id, p.title AS project_title,
                pc.author_name AS author_name, pc.content AS content,
                DATE_FORMAT(pc.created_at, "%Y-%m-%d %H:%i:%s") AS created_at
         FROM project_comments pc
         JOIN projects p ON p.id = pc.project_id
         WHERE p.tenant_id = ?
         ORDER BY pc.created_at DESC
         LIMIT ' . (int) $limit,
        [$tenantId->value()],
    );
    return $rows;
}

public function deleteCommentForTenant(string $commentId, TenantId $tenantId): void
{
    $this->db->execute(
        'DELETE pc FROM project_comments pc
         JOIN projects p ON p.id = pc.project_id
         WHERE pc.id = ? AND p.tenant_id = ?',
        [$commentId, $tenantId->value()],
    );
}
```

In `hydrate()`, add `featured: (bool) ($row['featured'] ?? false),` to the Project constructor call. Map the column correctly.

- [ ] **Step 7: Implement SqlProjectProposalRepository additions**

```php
public function listPendingForTenant(TenantId $tenantId): array
{
    $rows = $this->db->query(
        'SELECT * FROM project_proposals
         WHERE tenant_id = ? AND status = ?
         ORDER BY created_at DESC',
        [$tenantId->value(), 'pending'],
    );
    return array_map($this->hydrate(...), $rows);
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal
{
    $row = $this->db->queryOne(
        'SELECT * FROM project_proposals WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function recordDecision(string $id, TenantId $tenantId, string $decision, string $decidedBy, ?string $note, \DateTimeImmutable $now): void
{
    if (!in_array($decision, ['approved','rejected'], true)) {
        throw new \DomainException('invalid_decision');
    }
    $this->db->execute(
        'UPDATE project_proposals
         SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
         WHERE id = ? AND tenant_id = ?',
        [$decision, $now->format('Y-m-d H:i:s'), $decidedBy, $note, $id, $tenantId->value()],
    );
}

/** @param array<string,mixed> $row */
private function hydrate(array $row): ProjectProposal
{
    return new ProjectProposal(
        ProjectProposalId::fromString(self::str($row, 'id')),
        \Daems\Domain\Tenant\TenantId::fromString(self::str($row, 'tenant_id')),
        self::str($row, 'user_id'),
        self::str($row, 'author_name'),
        self::str($row, 'author_email'),
        self::str($row, 'title'),
        self::str($row, 'category'),
        self::str($row, 'summary'),
        self::str($row, 'description'),
        self::str($row, 'status'),
        self::str($row, 'created_at'),
        self::strOrNull($row, 'decided_at'),
        self::strOrNull($row, 'decided_by'),
        self::strOrNull($row, 'decision_note'),
    );
}

/** @param array<string,mixed> $row */
private static function str(array $row, string $key): string
{
    $v = $row[$key] ?? null;
    if (is_string($v)) return $v;
    throw new \DomainException("Missing or non-string column: {$key}");
}

/** @param array<string,mixed> $row */
private static function strOrNull(array $row, string $key): ?string
{
    $v = $row[$key] ?? null;
    return is_string($v) ? $v : null;
}
```

Also update existing `save()` INSERT to no longer be the only write path for reads — but the existing `save()` was write-only and is fine as-is.

- [ ] **Step 8: Implement InMemory fakes**

Mirror the changes in `tests/Support/Fake/InMemoryProjectRepository.php` and `tests/Support/Fake/InMemoryProjectProposalRepository.php`. Both fakes use a `$byId` array. For the project fake's `listAllStatusesForTenant`, apply the same filters + sort by `featured DESC, sort_order ASC, created_at DESC`. For `listForTenant` (public), additionally filter to non-draft status (drafts invisible to public).

For `InMemoryProjectProposalRepository`, add a `$byId` array and methods that match the new interface signatures. `recordDecision` replaces the stored `ProjectProposal` with a new entity carrying the updated status + decision fields.

- [ ] **Step 9: Run PHPStan + Unit tests**

```bash
composer analyse                    # must be [OK] No errors
vendor/bin/phpunit --testsuite Unit # must remain green, same count (326+ integration tests)
```

- [ ] **Step 10: Commit**

```bash
git add src/Domain/Project/ src/Infrastructure/Adapter/Persistence/Sql/SqlProject*.php tests/Support/Fake/InMemoryProject*Repository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): domain + repo admin methods — featured, tenant-scoped CRUD, comment moderation, proposal decisions"
```

---

### Task 3: `ProjectCommentModerationAudit` domain + SQL + InMemory

**Files:**
- Create: `src/Domain/Project/ProjectCommentModerationAudit.php`
- Create: `src/Domain/Project/ProjectCommentModerationAuditRepositoryInterface.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php`
- Create: `tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php`

- [ ] **Step 1: Domain entity**

`src/Domain/Project/ProjectCommentModerationAudit.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

final class ProjectCommentModerationAudit
{
    public function __construct(
        public readonly string $id,
        public readonly TenantId $tenantId,
        public readonly string $projectId,
        public readonly string $commentId,
        public readonly string $action,
        public readonly ?string $reason,
        public readonly string $performedBy,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

- [ ] **Step 2: Repo interface**

`src/Domain/Project/ProjectCommentModerationAuditRepositoryInterface.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectCommentModerationAuditRepositoryInterface
{
    public function save(ProjectCommentModerationAudit $audit): void;

    /** @return list<ProjectCommentModerationAudit> */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;
}
```

- [ ] **Step 3: SQL repo**

`src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectCommentModerationAuditRepository implements ProjectCommentModerationAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(ProjectCommentModerationAudit $a): void
    {
        $this->db->execute(
            'INSERT INTO project_comment_moderation_audit
                (id, tenant_id, project_id, comment_id, action, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $a->id, $a->tenantId->value(), $a->projectId, $a->commentId,
                $a->action, $a->reason, $a->performedBy,
                $a->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $rows = $this->db->query(
            'SELECT id, tenant_id, project_id, comment_id, action, reason, performed_by,
                    DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at
             FROM project_comment_moderation_audit
             WHERE tenant_id = ?
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit,
            [$tenantId->value()],
        );
        return array_map(function (array $row): ProjectCommentModerationAudit {
            return new ProjectCommentModerationAudit(
                (string) $row['id'],
                TenantId::fromString((string) $row['tenant_id']),
                (string) $row['project_id'],
                (string) $row['comment_id'],
                (string) $row['action'],
                is_string($row['reason'] ?? null) ? (string) $row['reason'] : null,
                (string) $row['performed_by'],
                new \DateTimeImmutable((string) $row['created_at']),
            );
        }, $rows);
    }
}
```

- [ ] **Step 4: InMemory fake**

`tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Support\Fake;

use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryProjectCommentModerationAuditRepository implements ProjectCommentModerationAuditRepositoryInterface
{
    /** @var list<ProjectCommentModerationAudit> */
    public array $rows = [];

    public function save(ProjectCommentModerationAudit $a): void { $this->rows[] = $a; }

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $filtered = array_values(array_filter(
            $this->rows,
            static fn (ProjectCommentModerationAudit $r): bool => $r->tenantId->equals($tenantId),
        ));
        usort($filtered, static fn ($a, $b) => $b->createdAt <=> $a->createdAt);
        return array_slice($filtered, 0, $limit);
    }
}
```

- [ ] **Step 5: PHPStan + Commit**

```bash
composer analyse   # must be green
git add src/Domain/Project/ProjectCommentModerationAudit*.php src/Infrastructure/Adapter/Persistence/Sql/SqlProjectCommentModerationAuditRepository.php tests/Support/Fake/InMemoryProjectCommentModerationAuditRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): ProjectCommentModerationAudit — domain entity, SQL + InMemory repos"
```

---

### Task 4: `ListProjectsForAdmin` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/ListProjectsForAdmin/{ListProjectsForAdmin.php, ListProjectsForAdminInput.php, ListProjectsForAdminOutput.php}`
- Create: `tests/Unit/Application/Backstage/ListProjectsForAdminTest.php`

- [ ] **Step 1: Failing test**

Cover: rejects non-admin (Forbidden), tenant-scoped, returns all statuses, filters (status/category/featured/q), output includes `participants_count` and `comments_count` (from repo helpers).

Test file structure mirrors `ListEventsForAdminTest` (look at that file's test methods for exact pattern). Use valid UUID7 IDs.

- [ ] **Step 2: Run — expect failure (class-not-found)**

- [ ] **Step 3: Implement Input + Output + use case**

`ListProjectsForAdminInput.php`: `ActingUser $acting, ?string $status, ?string $category, ?bool $featuredOnly, ?string $q`.

`ListProjectsForAdminOutput.php`: `items` list<array{id, slug, title, category, status, featured, owner_id, participants_count, comments_count, created_at}> + `toArray()`.

`ListProjectsForAdmin.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListProjectsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjectsForAdmin
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ListProjectsForAdminInput $input): ListProjectsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $filters = [];
        if ($input->status !== null && $input->status !== '')     $filters['status']   = $input->status;
        if ($input->category !== null && $input->category !== '') $filters['category'] = $input->category;
        if ($input->featuredOnly === true)                        $filters['featured'] = true;
        if ($input->q !== null && $input->q !== '')               $filters['q']        = $input->q;

        $items = [];
        foreach ($this->projects->listAllStatusesForTenant($tenantId, $filters) as $p) {
            $items[] = [
                'id'                 => $p->id()->value(),
                'slug'               => $p->slug(),
                'title'              => $p->title(),
                'category'           => $p->category(),
                'status'             => $p->status(),
                'featured'           => $p->featured(),
                'owner_id'           => $p->ownerId(),
                'participants_count' => $this->projects->countParticipants($p->id()->value()),
                'comments_count'     => count($this->projects->findCommentsByProjectId($p->id()->value())),
                'created_at'         => $p->createdAt(),
            ];
        }
        return new ListProjectsForAdminOutput($items);
    }
}
```

If `Project` doesn't have `ownerId()` or `createdAt()` accessors, grep the entity — add them if missing (low-risk). If comments count via `findCommentsByProjectId` is expensive (returns full objects), consider a `countCommentsByProjectId` helper and add to repo ifc + both impls — only if integration test shows slowness.

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListProjectsForAdmin/ tests/Unit/Application/Backstage/ListProjectsForAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): ListProjectsForAdmin use case (all statuses, filters, counts)"
```

---

### Task 5: `CreateProjectAsAdmin` + `AdminUpdateProject` use cases (TDD, combined)

**Files:**
- Create: `src/Application/Backstage/CreateProjectAsAdmin/{CreateProjectAsAdmin.php, CreateProjectAsAdminInput.php, CreateProjectAsAdminOutput.php}`
- Create: `src/Application/Backstage/AdminUpdateProject/{AdminUpdateProject.php, AdminUpdateProjectInput.php, AdminUpdateProjectOutput.php}`
- Create: `tests/Unit/Application/Backstage/CreateProjectAsAdminTest.php`
- Create: `tests/Unit/Application/Backstage/AdminUpdateProjectTest.php`

Both use cases bypass owner checks and accept admin-only fields like `owner_id` explicitly.

- [ ] **Step 1: Failing tests**

CreateProjectAsAdminTest: cover required-field validation (title, category, summary, description), slug auto-generation with collision fallback, default status=`draft`, `owner_id` assignment (nullable or acting->id), featured=false by default, admin authorization check.

AdminUpdateProjectTest: cover partial update (null = unchanged), validation errors, tenant-scoped 404, admin auth, does NOT change status or featured via this input (those have own endpoints).

- [ ] **Step 2: Run — expect failures**

- [ ] **Step 3: Implement CreateProjectAsAdmin**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\CreateProjectAsAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;

final class CreateProjectAsAdmin
{
    private const ALLOWED_CATEGORIES = null; // open — frontend constrains

    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(CreateProjectAsAdminInput $input): CreateProjectAsAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $errors = [];
        if (strlen($input->title) < 3 || strlen($input->title) > 200) {
            $errors['title'] = 'length_3_to_200';
        }
        if (trim($input->category) === '') {
            $errors['category'] = 'required';
        }
        if (strlen(trim($input->summary)) < 10) {
            $errors['summary'] = 'min_10_chars';
        }
        if (strlen(trim($input->description)) < 20) {
            $errors['description'] = 'min_20_chars';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $slug = $this->uniqueSlug($input->title, $tenantId);
        $projectId = ProjectId::fromString($this->ids->generate());
        $ownerId = $input->ownerId ?? $input->acting->id->value();

        $project = new Project(
            $projectId, $tenantId, $slug,
            $input->title, $input->category,
            $input->icon ?: 'bi-folder',
            $input->summary, $input->description,
            'draft', 0, $ownerId, false,
        );
        $this->projects->save($project);

        return new CreateProjectAsAdminOutput($projectId->value(), $slug);
    }

    private function uniqueSlug(string $title, \Daems\Domain\Tenant\TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'project';
        $base = trim((string) $base, '-');
        if ($base === '') $base = 'project';
        if ($this->projects->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $suffix = substr($this->ids->generate(), 0, 8);
            $cand = $base . '-' . $suffix;
            if ($this->projects->findBySlugForTenant($cand, $tenantId) === null) return $cand;
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
```

Input: `ActingUser $acting, string $title, string $category, ?string $icon, string $summary, string $description, ?string $ownerId = null`.

Output: `string $id, string $slug` + `toArray()`.

**Important:** The `Project` constructor signature (id, tenantId, slug, title, category, icon, summary, description, status, sortOrder, ownerId, featured) must match what Task 2 Step 1 produced. If Project's existing constructor order differs, adapt — read the file first.

- [ ] **Step 4: Implement AdminUpdateProject**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\AdminUpdateProject;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class AdminUpdateProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(AdminUpdateProjectInput $input): AdminUpdateProjectOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $project = $this->projects->findByIdForTenant($input->projectId, $tenantId)
            ?? throw new NotFoundException('project_not_found');

        $errors = [];
        $fields = [];
        if ($input->title !== null) {
            if (strlen($input->title) < 3 || strlen($input->title) > 200) $errors['title'] = 'length_3_to_200';
            else $fields['title'] = $input->title;
        }
        if ($input->category !== null) {
            if (trim($input->category) === '') $errors['category'] = 'required';
            else $fields['category'] = $input->category;
        }
        if ($input->icon !== null) $fields['icon'] = $input->icon;
        if ($input->summary !== null) {
            if (strlen(trim($input->summary)) < 10) $errors['summary'] = 'min_10_chars';
            else $fields['summary'] = $input->summary;
        }
        if ($input->description !== null) {
            if (strlen(trim($input->description)) < 20) $errors['description'] = 'min_20_chars';
            else $fields['description'] = $input->description;
        }
        if ($input->sortOrder !== null) $fields['sort_order'] = $input->sortOrder;

        if ($errors !== []) throw new ValidationException($errors);
        if ($fields !== []) $this->projects->updateForTenant($project->id()->value(), $tenantId, $fields);

        return new AdminUpdateProjectOutput($project->id()->value());
    }
}
```

Input: `ActingUser $acting, string $projectId, ?string $title, ?string $category, ?string $icon, ?string $summary, ?string $description, ?int $sortOrder`.

Output: `string $id` + `toArray()` returning `['id' => ..., 'updated' => true]`.

Note: this use case does NOT accept `status` or `featured` — those are separate endpoints (Task 6).

- [ ] **Step 5: Run tests — PASS**

- [ ] **Step 6: Commit**

```bash
git add src/Application/Backstage/CreateProjectAsAdmin/ src/Application/Backstage/AdminUpdateProject/ tests/Unit/Application/Backstage/CreateProjectAsAdminTest.php tests/Unit/Application/Backstage/AdminUpdateProjectTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): CreateProjectAsAdmin + AdminUpdateProject use cases"
```

---

### Task 6: `ChangeProjectStatus` + `SetProjectFeatured` use cases (TDD, combined)

**Files:**
- Create: `src/Application/Backstage/ChangeProjectStatus/{ChangeProjectStatus.php, ChangeProjectStatusInput.php}`
- Create: `src/Application/Backstage/SetProjectFeatured/{SetProjectFeatured.php, SetProjectFeaturedInput.php}`
- Create: `tests/Unit/Application/Backstage/ChangeProjectStatusTest.php`
- Create: `tests/Unit/Application/Backstage/SetProjectFeaturedTest.php`

- [ ] **Step 1: Failing tests**

Both use cases: admin check → findByIdForTenant → setStatusForTenant / setFeaturedForTenant. Cover: forbidden non-admin, not-found project in tenant (NotFoundException), successful transition asserted via a subsequent findByIdForTenant.

ChangeProjectStatus: invalid status throws ValidationException.

- [ ] **Step 2: Run — expect failures**

- [ ] **Step 3: Implement both**

```php
// ChangeProjectStatus.php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ChangeProjectStatus;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class ChangeProjectStatus
{
    private const ALLOWED = ['draft','active','archived'];

    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ChangeProjectStatusInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');
        if (!in_array($input->newStatus, self::ALLOWED, true)) {
            throw new ValidationException(['status' => 'invalid_value']);
        }
        if ($this->projects->findByIdForTenant($input->projectId, $tenantId) === null) {
            throw new NotFoundException('project_not_found');
        }
        $this->projects->setStatusForTenant($input->projectId, $tenantId, $input->newStatus);
    }
}
```

Input: `ActingUser $acting, string $projectId, string $newStatus`.

```php
// SetProjectFeatured.php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\SetProjectFeatured;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class SetProjectFeatured
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(SetProjectFeaturedInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');
        if ($this->projects->findByIdForTenant($input->projectId, $tenantId) === null) {
            throw new NotFoundException('project_not_found');
        }
        $this->projects->setFeaturedForTenant($input->projectId, $tenantId, $input->featured);
    }
}
```

Input: `ActingUser $acting, string $projectId, bool $featured`.

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ChangeProjectStatus/ src/Application/Backstage/SetProjectFeatured/ tests/Unit/Application/Backstage/ChangeProjectStatusTest.php tests/Unit/Application/Backstage/SetProjectFeaturedTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): ChangeProjectStatus + SetProjectFeatured use cases"
```

---

### Task 7: `ListProposalsForAdmin` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/ListProposalsForAdmin/{ListProposalsForAdmin.php, ListProposalsForAdminInput.php, ListProposalsForAdminOutput.php}`
- Create: `tests/Unit/Application/Backstage/ListProposalsForAdminTest.php`

- [ ] **Step 1: Failing test**

Covers: forbidden non-admin; lists pending proposals for acting tenant only; output items carry `id, user_id, author_name, author_email, title, category, summary, description, created_at, status`.

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListProposalsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

final class ListProposalsForAdmin
{
    public function __construct(private readonly ProjectProposalRepositoryInterface $proposals) {}

    public function execute(ListProposalsForAdminInput $input): ListProposalsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');

        $items = [];
        foreach ($this->proposals->listPendingForTenant($tenantId) as $p) {
            $items[] = [
                'id'           => $p->id()->value(),
                'user_id'      => $p->userId(),
                'author_name'  => $p->authorName(),
                'author_email' => $p->authorEmail(),
                'title'        => $p->title(),
                'category'     => $p->category(),
                'summary'      => $p->summary(),
                'description'  => $p->description(),
                'status'       => $p->status(),
                'created_at'   => $p->createdAt(),
            ];
        }
        return new ListProposalsForAdminOutput($items);
    }
}
```

Input: `ActingUser $acting`. Output: `array $items` + `toArray()` returning `['items' => $items, 'total' => count($items)]`.

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListProposalsForAdmin/ tests/Unit/Application/Backstage/ListProposalsForAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): ListProposalsForAdmin use case"
```

---

### Task 8: `ApproveProjectProposal` + `RejectProjectProposal` use cases (TDD)

**Files:**
- Create: `src/Application/Backstage/ApproveProjectProposal/{ApproveProjectProposal.php, ApproveProjectProposalInput.php, ApproveProjectProposalOutput.php}`
- Create: `src/Application/Backstage/RejectProjectProposal/{RejectProjectProposal.php, RejectProjectProposalInput.php}`
- Create: `tests/Unit/Application/Backstage/ApproveProjectProposalTest.php`
- Create: `tests/Unit/Application/Backstage/RejectProjectProposalTest.php`

Both are transactional. Use the existing `TransactionManagerInterface` (from approve-flow package).

- [ ] **Step 1: Failing tests**

ApproveProjectProposalTest covers:
1. Admin approves → `projects` row created (status=draft, owner_id = proposal.userId, fields copied), proposal row updated (status=approved, decided_at set, decided_by set, decision_note set from input), any `admin_application_dismissals` rows for this proposal id are cleared.
2. Non-admin → Forbidden.
3. Proposal not in tenant → NotFoundException.
4. Already-decided proposal → `ValidationException(['status' => 'already_decided'])`.
5. Output carries new project `id` + `slug`.

RejectProjectProposalTest covers:
1. Admin rejects → proposal status=rejected with decision metadata.
2. No project created.
3. Dismissals for this proposal id cleared.
4. Forbidden / NotFound / already-decided guards.

Use InMemory repos. Cover dismissal clearing via a seeded `InMemoryAdminApplicationDismissalRepository` row.

- [ ] **Step 2: Run — expect failures**

- [ ] **Step 3: Implement ApproveProjectProposal**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ApproveProjectProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;

final class ApproveProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly ProjectRepositoryInterface $projects,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(ApproveProjectProposalInput $input): ApproveProjectProposalOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');

        $proposal = $this->proposals->findByIdForTenant($input->proposalId, $tenantId)
            ?? throw new NotFoundException('proposal_not_found');

        if ($proposal->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        $now = $this->clock->now();

        return $this->tx->run(function () use ($proposal, $tenantId, $now, $input): ApproveProjectProposalOutput {
            // 1. Create project row from proposal fields
            $projectId = ProjectId::fromString($this->ids->generate());
            $slug = $this->uniqueSlug($proposal->title(), $tenantId);
            $project = new Project(
                $projectId, $tenantId, $slug,
                $proposal->title(), $proposal->category(),
                'bi-folder', // default icon
                $proposal->summary(), $proposal->description(),
                'draft', 0, $proposal->userId(), false,
            );
            $this->projects->save($project);

            // 2. Record proposal decision
            $this->proposals->recordDecision(
                $proposal->id()->value(), $tenantId, 'approved',
                $input->acting->id->value(), $input->note, $now,
            );

            // 3. Clear dismissals for this proposal id
            $this->dismissals->deleteByAppId($proposal->id()->value());

            return new ApproveProjectProposalOutput($projectId->value(), $slug);
        });
    }

    private function uniqueSlug(string $title, \Daems\Domain\Tenant\TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'project';
        $base = trim((string) $base, '-') ?: 'project';
        if ($this->projects->findBySlugForTenant($base, $tenantId) === null) return $base;
        for ($i = 0; $i < 5; $i++) {
            $cand = $base . '-' . substr($this->ids->generate(), 0, 8);
            if ($this->projects->findBySlugForTenant($cand, $tenantId) === null) return $cand;
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
```

Input: `ActingUser $acting, string $proposalId, ?string $note`.
Output: `string $projectId, string $slug` + `toArray()`.

- [ ] **Step 4: Implement RejectProjectProposal**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\RejectProjectProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;

final class RejectProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
    ) {}

    public function execute(RejectProjectProposalInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');

        $proposal = $this->proposals->findByIdForTenant($input->proposalId, $tenantId)
            ?? throw new NotFoundException('proposal_not_found');
        if ($proposal->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        $now = $this->clock->now();
        $this->tx->run(function () use ($proposal, $tenantId, $now, $input): void {
            $this->proposals->recordDecision(
                $proposal->id()->value(), $tenantId, 'rejected',
                $input->acting->id->value(), $input->note, $now,
            );
            $this->dismissals->deleteByAppId($proposal->id()->value());
        });
    }
}
```

Input: `ActingUser $acting, string $proposalId, ?string $note`.

- [ ] **Step 5: Run tests — PASS**

- [ ] **Step 6: Commit**

```bash
git add src/Application/Backstage/ApproveProjectProposal/ src/Application/Backstage/RejectProjectProposal/ tests/Unit/Application/Backstage/ApproveProjectProposalTest.php tests/Unit/Application/Backstage/RejectProjectProposalTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): Approve/Reject ProjectProposal use cases (transactional)"
```

---

### Task 9: `ListProjectCommentsForAdmin` + `DeleteProjectCommentAsAdmin` (TDD)

**Files:**
- Create: `src/Application/Backstage/ListProjectCommentsForAdmin/{ListProjectCommentsForAdmin.php, ListProjectCommentsForAdminInput.php, ListProjectCommentsForAdminOutput.php}`
- Create: `src/Application/Backstage/DeleteProjectCommentAsAdmin/{DeleteProjectCommentAsAdmin.php, DeleteProjectCommentAsAdminInput.php}`
- Create: `tests/Unit/Application/Backstage/ListProjectCommentsForAdminTest.php`
- Create: `tests/Unit/Application/Backstage/DeleteProjectCommentAsAdminTest.php`

- [ ] **Step 1: Failing tests**

ListProjectCommentsForAdminTest: admin gets recent comments across tenant, forbidden non-admin.

DeleteProjectCommentAsAdminTest: admin deletes a comment → it's gone from repo + audit row created. Forbidden non-admin. NotFoundException if comment doesn't exist in tenant — but since we don't have `findCommentById`, the simpler contract is: always attempt delete + always write audit (idempotent); if nothing was deleted we still audit (records admin intent). Preferred: only audit when a delete actually occurred. To do that, extend `ProjectRepositoryInterface::deleteCommentForTenant` to return an int (rows affected).

Adjust Task 2 if needed: `deleteCommentForTenant` returns `int` (0 if not found, 1 if deleted).

If that's too much rework: go with the idempotent variant — audit always written, no NotFound error. Simpler and still correct. **Choose: idempotent variant.**

- [ ] **Step 2: Run — expect failures**

- [ ] **Step 3: Implement**

```php
// ListProjectCommentsForAdmin.php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListProjectCommentsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjectCommentsForAdmin
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ListProjectCommentsForAdminInput $input): ListProjectCommentsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');
        $rows = $this->projects->listRecentCommentsForTenant($tenantId, $input->limit ?? 100);
        return new ListProjectCommentsForAdminOutput($rows);
    }
}
```

Input: `ActingUser $acting, ?int $limit = null`. Output: `array $items` + `toArray()` returning `['items' => $items, 'total' => count($items)]`.

```php
// DeleteProjectCommentAsAdmin.php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\DeleteProjectCommentAsAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;

final class DeleteProjectCommentAsAdmin
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly ProjectCommentModerationAuditRepositoryInterface $audit,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(DeleteProjectCommentAsAdminInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) throw new ForbiddenException('not_tenant_admin');

        // Idempotent delete (tenant-scoped).
        $this->projects->deleteCommentForTenant($input->commentId, $tenantId);

        $this->audit->save(new ProjectCommentModerationAudit(
            $this->ids->generate(),
            $tenantId,
            $input->projectId,
            $input->commentId,
            'deleted',
            $input->reason,
            $input->acting->id->value(),
            $this->clock->now(),
        ));
    }
}
```

Input: `ActingUser $acting, string $projectId, string $commentId, ?string $reason`.

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListProjectCommentsForAdmin/ src/Application/Backstage/DeleteProjectCommentAsAdmin/ tests/Unit/Application/Backstage/ListProjectCommentsForAdminTest.php tests/Unit/Application/Backstage/DeleteProjectCommentAsAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): ListProjectCommentsForAdmin + DeleteProjectCommentAsAdmin (idempotent + audit)"
```

---

### Task 10: Extend `ListPendingApplicationsForAdmin` + `DismissApplication` to include proposals

**Files:**
- Modify: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php`
- Modify: `src/Application/Backstage/DismissApplication/DismissApplication.php`
- Modify existing unit tests for these two use cases.

- [ ] **Step 1: Update `DismissApplication` input validation**

In `DismissApplication::execute`, find the `appType` whitelist and add `'project_proposal'`:
```php
if (!in_array($input->appType, ['member', 'supporter', 'project_proposal'], true)) {
    throw new ValidationException(['app_type' => 'invalid_value']);
}
```

- [ ] **Step 2: Update `ListPendingApplicationsForAdmin` to merge proposals**

Add `ProjectProposalRepositoryInterface $proposals` as a new constructor dependency (append after existing params — don't reorder).

In `execute()`, after the existing loops over member + supporter apps, add a third loop:
```php
foreach ($this->proposals->listPendingForTenant($tenantId) as $proposal) {
    if (isset($dismissed[$proposal->id()->value()])) continue;
    $items[] = [
        'id'         => $proposal->id()->value(),
        'type'       => 'project_proposal',
        'name'       => $proposal->title(),
        'created_at' => $proposal->createdAt(),
    ];
}
```

Keep the existing cap (slice to 50) and sort.

- [ ] **Step 3: Update existing unit test(s)**

The current `ListPendingApplicationsForAdminTest` (or similar) needs the new dependency wired via InMemoryProjectProposalRepository. Add a test method `test_output_includes_pending_project_proposals` — seed one proposal, assert an item with `type === 'project_proposal'` appears.

Update existing `DismissApplicationTest` to accept `project_proposal` as a valid `appType` (new test method or extended test matrix).

- [ ] **Step 4: Run `composer analyse` + Unit tests**

Expect: failures in KernelHarness / bootstrap because the `ListPendingApplicationsForAdmin` constructor grew. **Tolerate these until Task 12** — PHPStan may still be green (type-only analysis); the E2E break surfaces at DI wiring time.

Unit test suite should be green because InMemory wiring lives within the tests themselves.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListPendingApplications/ src/Application/Backstage/DismissApplication/ tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php tests/Unit/Application/Backstage/DismissApplicationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(toast): admin inbox includes pending project proposals; dismiss accepts project_proposal"
```

---

### Task 11: HTTP — 11 new BackstageController methods

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 1: Add constructor deps**

Append to the constructor param list (never reorder existing):
- `ListProjectsForAdmin $listProjects`
- `CreateProjectAsAdmin $createProject`
- `AdminUpdateProject $updateProject`
- `ChangeProjectStatus $changeProjectStatus`
- `SetProjectFeatured $setProjectFeatured`
- `ListProposalsForAdmin $listProposals`
- `ApproveProjectProposal $approveProposal`
- `RejectProjectProposal $rejectProposal`
- `ListProjectCommentsForAdmin $listProjectComments`
- `DeleteProjectCommentAsAdmin $deleteProjectComment`

- [ ] **Step 2: Add method handlers**

Pattern (mirror existing `listEvents` / `createEvent` methods):

```php
public function listProjects(Request $request): Response
{
    $acting = $request->requireActingUser();
    try {
        $out = $this->listProjects->execute(new ListProjectsForAdminInput(
            $acting,
            $request->string('status'),
            $request->string('category'),
            $request->input('featured') !== null ? (bool) $request->input('featured') : null,
            $request->string('q'),
        ));
        return Response::json($out->toArray());
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
}

public function createProjectAdmin(Request $request): Response
{
    $acting = $request->requireActingUser();
    try {
        $out = $this->createProject->execute(new CreateProjectAsAdminInput(
            $acting,
            (string) $request->string('title'),
            (string) $request->string('category'),
            $request->string('icon'),
            (string) $request->string('summary'),
            (string) $request->string('description'),
            $request->string('owner_id'),
        ));
        return Response::json(['data' => $out->toArray()], 201);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function updateProjectAdmin(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $out = $this->updateProject->execute(new AdminUpdateProjectInput(
            $acting, $id,
            $request->string('title'),
            $request->string('category'),
            $request->string('icon'),
            $request->string('summary'),
            $request->string('description'),
            $request->input('sort_order') !== null ? (int) $request->input('sort_order') : null,
        ));
        return Response::json(['data' => $out->toArray()]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function changeProjectStatus(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $this->changeProjectStatus->execute(new ChangeProjectStatusInput(
            $acting, $id, (string) $request->string('status'),
        ));
        return Response::json(['data' => ['id' => $id, 'status' => (string) $request->string('status')]]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function setProjectFeatured(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    $featured = (bool) $request->input('featured');
    try {
        $this->setProjectFeatured->execute(new SetProjectFeaturedInput($acting, $id, $featured));
        return Response::json(['data' => ['id' => $id, 'featured' => $featured]]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
}

public function listProposals(Request $request): Response
{
    $acting = $request->requireActingUser();
    try {
        $out = $this->listProposals->execute(new ListProposalsForAdminInput($acting));
        return Response::json($out->toArray());
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
}

public function approveProposal(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $out = $this->approveProposal->execute(new ApproveProjectProposalInput(
            $acting, $id, $request->string('note'),
        ));
        return Response::json(['data' => $out->toArray()], 201);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function rejectProposal(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $this->rejectProposal->execute(new RejectProjectProposalInput(
            $acting, $id, $request->string('note'),
        ));
        return Response::json(null, 204);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function listProjectComments(Request $request): Response
{
    $acting = $request->requireActingUser();
    $limit = $request->input('limit') !== null ? (int) $request->input('limit') : null;
    try {
        $out = $this->listProjectComments->execute(new ListProjectCommentsForAdminInput($acting, $limit));
        return Response::json($out->toArray());
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
}

public function deleteProjectComment(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $projectId = (string) ($params['id'] ?? '');
    $commentId = (string) ($params['comment_id'] ?? '');
    try {
        $this->deleteProjectComment->execute(new DeleteProjectCommentAsAdminInput(
            $acting, $projectId, $commentId, $request->string('reason'),
        ));
        return Response::json(null, 204);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
}
```

Add `use` statements for all the new Input/Output/UseCase classes at the top of `BackstageController.php`.

- [ ] **Step 3: Run `composer analyse`**

Must be `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): BackstageController — 10 admin handlers for projects, proposals, comments"
```

---

### Task 12: Routes — 11 new entries

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add routes**

In the Backstage block (after events routes):

```php
// Backstage — Projects (admin)
$router->get('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProjects($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->createProjectAdmin($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/projects/{id}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateProjectAdmin($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/projects/{id}/status', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->changeProjectStatus($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/projects/{id}/featured', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->setProjectFeatured($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/proposals', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProposals($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/proposals/{id}/approve', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->approveProposal($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/proposals/{id}/reject', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->rejectProposal($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/comments/recent', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProjectComments($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/projects/{id}/comments/{comment_id}/delete', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->deleteProjectComment($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 2: Commit**

```bash
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): register 10 backstage projects + proposals + comments routes"
```

---

### Task 13: DI wiring — BOTH containers

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

**CRITICAL:** wire BOTH. Missing bootstrap → live 500 while E2E stays green.

- [ ] **Step 1: bootstrap/app.php additions**

Bind audit repo:
```php
$container->singleton(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectCommentModerationAuditRepository(
        $c->make(Connection::class),
    ),
);
```

Bind each of the 10 new use cases. Example:
```php
$container->bind(\Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin(
        $c->make(ProjectRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject::class,
    static fn(Container $c) => new \Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject(
        $c->make(ProjectRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus(
        $c->make(ProjectRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured::class,
    static fn(Container $c) => new \Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured(
        $c->make(ProjectRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin(
        $c->make(ProjectProposalRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal(
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(ProjectRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal::class,
    static fn(Container $c) => new \Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal(
        $c->make(ProjectProposalRepositoryInterface::class),
        $c->make(AdminApplicationDismissalRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\TransactionManagerInterface::class),
        $c->make(Clock::class),
    ),
);

$container->bind(\Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin(
        $c->make(ProjectRepositoryInterface::class),
    ),
);

$container->bind(\Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin(
        $c->make(ProjectRepositoryInterface::class),
        $c->make(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class),
        $c->make(Clock::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
```

**Update `ListPendingApplicationsForAdmin` binding** — add `ProjectProposalRepositoryInterface` dependency in its `make()` call.

**Update `BackstageController` binding** — append the 10 new use cases to its `make()` arg list.

- [ ] **Step 2: KernelHarness.php — mirror bindings with InMemory**

Add new fields:
```php
public InMemoryProjectCommentModerationAuditRepository $commentAudit;
```

In constructor:
```php
$this->commentAudit = new InMemoryProjectCommentModerationAuditRepository();
```

In `buildKernel`:
```php
$container->singleton(\Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface::class, fn() => $this->commentAudit);
```

Then 10 use-case bindings mirroring bootstrap, but with InMemory dependencies. Also update existing `ListPendingApplicationsForAdmin` binding to include the proposal repo.

Update the existing `BackstageController` binding with the 10 new args appended.

- [ ] **Step 3: Grep sanity**

```bash
for sym in ListProjectsForAdmin CreateProjectAsAdmin AdminUpdateProject ChangeProjectStatus SetProjectFeatured ListProposalsForAdmin ApproveProjectProposal RejectProjectProposal ListProjectCommentsForAdmin DeleteProjectCommentAsAdmin ProjectCommentModerationAuditRepositoryInterface; do
  grep -c "$sym" bootstrap/app.php tests/Support/KernelHarness.php
done
```
Each should report ≥1 in each file.

- [ ] **Step 4: Live smoke**

```bash
echo "APP_DEBUG=true" >> .env
php -S 127.0.0.1:8090 -t public public/index.php > /tmp/srv-p.log 2>&1 &
sleep 2
curl -i http://127.0.0.1:8090/api/v1/backstage/projects -H "Host: daems-platform.local"
kill %1
sed -i '/^APP_DEBUG=true$/d' .env
```
Expected: HTTP 401. 500 = binding mismatch — fix before committing.

- [ ] **Step 5: Run analyse + all test suites**

```bash
composer analyse
vendor/bin/phpunit --testsuite Unit       # 326 + new test count
vendor/bin/phpunit --testsuite E2E        # 67 unchanged
```

- [ ] **Step 6: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire: bind 10 projects admin use cases + comment-audit repo in BOTH containers"
```

---

### Task 14: Integration + Isolation + E2E tests

**Files:**
- Create: `tests/Integration/Application/ProjectsAdminIntegrationTest.php`
- Create: `tests/Integration/Application/ProjectCommentModerationIntegrationTest.php`
- Create: `tests/Isolation/ProjectsAdminTenantIsolationTest.php`
- Create: `tests/E2E/Backstage/ProjectsAdminEndpointsTest.php`
- Create: `tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php`

- [ ] **Step 1: Integration — full lifecycle**

`ProjectsAdminIntegrationTest` extends `MigrationTestCase`. `setUp` runs migrations to 46, seeds tenant + admin user + user_tenants admin.

Test 1 `test_proposal_approve_creates_project_and_clears_dismissals`:
- Seed a pending proposal in DB.
- Call real `ApproveProjectProposal` via Sql-backed repos + `PdoTransactionManager`.
- Assert: `projects` row exists with status=`draft`, `owner_id` = proposal.user_id, fields copied.
- Assert: proposal row has status=`approved`, `decided_at`/`decided_by`/`decision_note` populated.

Test 2 `test_admin_list_sees_drafts_but_public_list_does_not`:
- Seed one project with each status (draft/active/archived).
- Call `ListProjectsForAdmin` → all 3 visible.
- Call public `ListProjects` → draft hidden, archived status depends on repo logic (verify against Task 2 Step 6 where `listForTenant` filters to non-draft; check if archived also hidden from public — spec says `published` only equivalent for projects is "non-draft-non-archived", i.e. `status = 'active'` for public. Confirm with implementation).

Test 3 `test_featured_projects_surface_first_in_public_list`:
- Seed two active projects, mark one featured.
- Public `ListProjects` output: featured first.

`ProjectCommentModerationIntegrationTest`:
- Seed project + user + comment.
- Call `DeleteProjectCommentAsAdmin`.
- Assert: row gone from `project_comments`, audit row present in `project_comment_moderation_audit`.

- [ ] **Step 2: Isolation — cross-tenant guard**

`ProjectsAdminTenantIsolationTest` extends `IsolationTestCase`. Seeds two tenants + admin in each.

- Admin-A tries operations on tenant-B's project: listAllStatusesForTenant (scoped → no cross), findByIdForTenant (returns null → AdminUpdateProject throws NotFound), setStatusForTenant, setFeaturedForTenant.
- Admin-A tries to approve a tenant-B proposal → NotFoundException.
- Admin-A tries to delete a tenant-B comment → no rows deleted + no audit written **for tenant B** (check: we could allow the audit row since the admin tried; but spec+security says: tenant-scoped, so no audit row for wrong tenant). Adjust `DeleteProjectCommentAsAdmin` to check `findByIdForTenant` for the project before writing audit if needed — **defer this to integration test; if the test shows a leak, add the guard in a follow-up commit**.

- [ ] **Step 3: E2E — endpoints**

`ProjectsAdminEndpointsTest`: mirrors `EventAdminEndpointsTest`. Cover happy paths + error paths for each of the 10 new routes. Total ~12 test methods.

`AdminInboxIncludesProposalsTest`:
- Seed a pending proposal via the harness (`$h->proposals->save(...)`).
- `GET /backstage/applications/pending-count` → assert `items` contains `{type: 'project_proposal', ...}`.
- Dismiss it: `POST /backstage/applications/project_proposal/{id}/dismiss` → 204.
- Second GET → no proposal in items.

- [ ] **Step 4: Run**

```bash
vendor/bin/phpunit tests/Integration/Application/ProjectsAdminIntegrationTest.php
vendor/bin/phpunit tests/Integration/Application/ProjectCommentModerationIntegrationTest.php
vendor/bin/phpunit tests/Isolation/ProjectsAdminTenantIsolationTest.php
vendor/bin/phpunit --testsuite E2E
```
Each file must report specific pass counts (not "No tests executed!"). E2E total must grow by ~13.

- [ ] **Step 5: Commit (one per file is fine; alternative: single commit)**

```bash
git add tests/Integration/Application/ProjectsAdminIntegrationTest.php tests/Integration/Application/ProjectCommentModerationIntegrationTest.php tests/Isolation/ProjectsAdminTenantIsolationTest.php tests/E2E/Backstage/ProjectsAdminEndpointsTest.php tests/E2E/Backstage/AdminInboxIncludesProposalsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(projects): integration + isolation + E2E coverage for projects admin + admin inbox proposals"
```

---

### Task 15: Frontend proxies (daem-society)

**Files (`C:\laragon\www\sites\daem-society`):**
- Create: `public/api/backstage/projects.php`
- Create: `public/api/backstage/proposals.php`

- [ ] **Step 1: `public/api/backstage/projects.php`**

Follow the `events.php` proxy pattern. Ops: `list, create, update, status, featured, comments_recent, delete_comment`.

```php
<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin']) && ($u['role'] ?? '') !== 'admin')) {
    http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit;
}

$op = $_GET['op'] ?? '';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

try {
    switch ($op) {
        case 'list': {
            $qs = http_build_query(array_filter([
                'status' => $_GET['status'] ?? null,
                'category' => $_GET['category'] ?? null,
                'featured' => isset($_GET['featured']) && $_GET['featured'] === '1' ? '1' : null,
                'q' => $_GET['q'] ?? null,
            ], fn($v) => $v !== null && $v !== ''));
            $r = ApiClient::get('/backstage/projects' . ($qs ? "?{$qs}" : ''));
            echo json_encode($r);
            return;
        }
        case 'create':
            $r = ApiClient::post('/backstage/projects', $body);
            http_response_code(201);
            echo json_encode($r);
            return;
        case 'update': {
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/projects/{$id}", $body);
            echo json_encode($r);
            return;
        }
        case 'status': {
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/projects/{$id}/status", ['status' => (string) ($body['status'] ?? '')]);
            echo json_encode($r);
            return;
        }
        case 'featured': {
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/projects/{$id}/featured", ['featured' => (bool) ($body['featured'] ?? false)]);
            echo json_encode($r);
            return;
        }
        case 'comments_recent': {
            $qs = isset($_GET['limit']) ? '?limit=' . (int) $_GET['limit'] : '';
            $r = ApiClient::get('/backstage/comments/recent' . $qs);
            echo json_encode($r);
            return;
        }
        case 'delete_comment': {
            $id = (string) ($_GET['id'] ?? '');
            $commentId = (string) ($_GET['comment_id'] ?? '');
            ApiClient::post("/backstage/projects/{$id}/comments/{$commentId}/delete", ['reason' => (string) ($body['reason'] ?? '')]);
            http_response_code(204);
            return;
        }
        default:
            http_response_code(400); echo json_encode(['error' => 'bad_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'proxy_failed']);
}
```

- [ ] **Step 2: `public/api/backstage/proposals.php`**

```php
<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin']) && ($u['role'] ?? '') !== 'admin')) {
    http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit;
}

$op = $_GET['op'] ?? '';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

try {
    switch ($op) {
        case 'list':
            $r = ApiClient::get('/backstage/proposals');
            echo json_encode($r);
            return;
        case 'approve': {
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/proposals/{$id}/approve", ['note' => (string) ($body['note'] ?? '')]);
            http_response_code(201);
            echo json_encode($r);
            return;
        }
        case 'reject': {
            $id = (string) ($_GET['id'] ?? '');
            ApiClient::post("/backstage/proposals/{$id}/reject", ['note' => (string) ($body['note'] ?? '')]);
            http_response_code(204);
            return;
        }
        default:
            http_response_code(400); echo json_encode(['error' => 'bad_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'proxy_failed']);
}
```

- [ ] **Step 3: Front controller routes**

Check `public/index.php` — add clean-URL routes for `/api/backstage/projects` and `/api/backstage/proposals` if the front controller requires explicit mapping (Events-admin did this in Task 17 per the events plan).

- [ ] **Step 4: Commit (daem-society repo)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/api/backstage/projects.php public/api/backstage/proposals.php public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): proxy endpoints for projects + proposals admin"
```

---

### Task 16: Frontend admin page + modal (daem-society)

**Files:**
- Create: `public/pages/backstage/projects/index.php`
- Create: `public/pages/backstage/projects/project-modal.js`
- Create: `public/pages/backstage/projects/project-modal.css`

- [ ] **Step 1: `index.php`**

PHP-side: admin guard (same as other backstage pages). Parse `?tab=proposals|projects|comments` from URL (default `proposals`). Server-side initial fetches:
- Proposals list: `ApiClient::get('/backstage/proposals') ?? ['items' => [], 'total' => 0]`
- Projects list: `ApiClient::get('/backstage/projects') ?? ['items' => [], 'total' => 0]`
- Comments list: `ApiClient::get('/backstage/comments/recent') ?? ['items' => [], 'total' => 0]`

Pass all three as `window.DAEMS_PROJECTS_TAB = { proposals: ..., projects: ..., comments: ... }`.

Render:
- Tab nav: three buttons with `data-tab="proposals|projects|comments"`. Active tab gets `is-active` class.
- Each tab content in its own `<section data-tab-content="...">`, hidden when not active.
- Proposals section: iteration over items — name, email, title, category, summary, expandable description, Approve/Reject buttons with note textarea.
- Projects section: filter bar + table with action buttons (edit modal, status dropdown, ★ toggle). "+ New project" button opens same modal as edit.
- Comments section: filter by project dropdown + table with Delete button + reason input.

Include `project-modal.css`, `project-modal.js`.

- [ ] **Step 2: `project-modal.js`**

Vanilla JS `window.ProjectModal.open(mode, project?)`. Form fields: title, category, icon (text input for Bootstrap Icons class), summary, description. No file upload in MVP (projects don't have images per current schema). Save:
- Create: `fetch('/api/backstage/projects?op=create', { body: JSON })` → reload page.
- Update: `fetch('/api/backstage/projects?op=update&id={id}', { body: JSON })` → reload.

Also the file handles:
- Tab switching (`data-tab` click).
- Proposal approve/reject inline (uses `fetch('/api/backstage/proposals?op=approve|reject&id={id}', { body: {note} })`).
- Status change (row-level dropdown).
- Featured toggle (row-level `fetch('/api/backstage/projects?op=featured&id={id}', { body: {featured} })`).
- Comment delete (row-level).

Use `location.reload()` after any mutation as an MVP simplification. (Events admin does the same.)

- [ ] **Step 3: `project-modal.css`**

Reuse `event-modal.css` tokens: modal layout, form grid, status pills (draft=gray, active=green, archived=amber). Add `.featured-star { color: #f59e0b; cursor: pointer; }` + `.featured-star--off { color: #9ca3af; }`.

- [ ] **Step 4: Sidebar link**

Verify `public/pages/backstage/layout.php` has `<a href="/backstage/projects">Projects</a>`. Add if missing (likely already present — Events-admin plan had to check for its own link too).

- [ ] **Step 5: Commit (daem-society)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/projects/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): projects admin page — tabs for proposals, projects, comments; modal-driven CRUD"
```

---

### Task 17: Toast routing + public featured badge

**Files (daem-society):**
- Modify: `public/pages/backstage/toasts.js`
- Modify: `public/pages/projects/grid.php` (or wherever project cards render)
- Modify (possibly): `public/assets/css/daems.css`

- [ ] **Step 1: `toasts.js` — route proposal clicks**

Find the click handler that sets `window.location.href`. Replace the single-line href with:
```js
if (item.type === 'project_proposal') {
    window.location.href = '/backstage/projects?tab=proposals&highlight=' + encodeURIComponent(item.id);
} else {
    window.location.href = '/backstage/applications?highlight=' + encodeURIComponent(item.id);
}
```

Also update the toast title generator:
```js
var title = item.type === 'project_proposal'
    ? 'New Project Proposal'
    : item.type === 'supporter'
        ? 'New Supporter Application'
        : 'New Member Application';
```

- [ ] **Step 2: Public featured badge**

Find where project cards render (`public/pages/projects/grid.php` most likely). Inside the card markup, just after the title, add:
```php
<?php if (!empty($project['featured'])): ?>
    <span class="badge badge--featured">Featured</span>
<?php endif; ?>
```

Add CSS (in `daems.css` or a nearby project-specific stylesheet):
```css
.badge--featured {
    display: inline-block;
    padding: 2px 8px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    border-radius: 10px;
    margin-left: 8px;
    vertical-align: middle;
}
```

- [ ] **Step 3: Commit (daem-society)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/toasts.js public/pages/projects/ public/assets/css/daems.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(projects): toasts route proposal clicks to /backstage/projects; public cards show Featured badge"
```

---

### Task 18: Final verification checklist

- [ ] `composer analyse` → `[OK] No errors`
- [ ] `vendor/bin/phpunit --testsuite Unit` → 326 + ~32 new ≈ 358 tests (10 new test files × ~3 methods each, give or take)
- [ ] `vendor/bin/phpunit --testsuite E2E` → 67 + ~13 new ≈ 80 tests
- [ ] Migration 044–046 applied to dev DB (verify via SHOW COLUMNS on `projects`, `project_proposals` + SHOW TABLES)
- [ ] Live smoke: `curl http://127.0.0.1:8090/api/v1/backstage/projects -H "Host: daems-platform.local"` → 401
- [ ] Manual UAT (any subset the environment supports):
  - Submit a proposal as a regular user → toast appears on any backstage page.
  - Click toast → `/backstage/projects?tab=proposals` with that proposal highlighted.
  - Approve with note → "Approved — project created as draft" banner, new project in Projects tab.
  - Edit the draft, mark featured, save → draft + ★ visible.
  - Change status → active. Visit public `/projects` → project with "Featured" badge surfaces first.
  - Archive → disappears from public list but still in admin list.
  - User comments on the project → comment visible.
  - Admin deletes the comment from Comments tab → row gone; DB has audit entry.
  - Dismiss proposal toast in session, refresh → gone. Logout/login → reappears if still pending.
- [ ] `git status` clean on both repos (except `.claude/`)
- [ ] All commits authored `Dev Team <dev@daems.org>`, no `Co-Authored-By`

Report the full commit-SHA list + all test counts. Do NOT push without explicit instruction.
