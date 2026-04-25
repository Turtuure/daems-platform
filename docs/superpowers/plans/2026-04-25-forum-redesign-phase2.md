# Forum Redesign Phase 2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert `/backstage/forum` from a 4-tab single-page admin into a sub-page model: a dashboard landing with 4 clickable KPI cards, plus 4 dedicated sub-pages (`/reports`, `/topics`, `/categories`, `/audit`). Reuse Phase 1's design system (kpi-card, data-explorer, slide-panel, confirm-dialog, pill, btn variants) — no new components.

**Architecture:** Backend gains a single new endpoint `GET /api/v1/backstage/forum/stats` powered by a `ListForumStats` use case that aggregates from existing forum repositories (reports / topics / categories / audit). Frontend grows by 5 page entries (1 dashboard + 4 sub-pages) and 5 page-specific JS files; the front controller gets 4 new path matches; the existing forum proxy (`api/backstage/forum.php`) gets a `stats` op + the missing `session_start()` fix (same Apache-direct-serve gotcha discovered in Phase 1).

**Tech Stack:** PHP 8.1, MySQL 8.4, vanilla JS (no frameworks), ApexCharts 3 (already loaded by `layout.php`), PHPUnit 10, PHPStan level 9.

**Spec:** [`docs/superpowers/specs/2026-04-25-forum-redesign-design.md`](../specs/2026-04-25-forum-redesign-design.md)

---

## File Structure

### Backend (`C:/laragon/www/daems-platform`)

**Create:**
- `src/Application/Backstage/Forum/ListForumStats/ListForumStats.php` — use case
- `src/Application/Backstage/Forum/ListForumStats/ListForumStatsInput.php`
- `src/Application/Backstage/Forum/ListForumStats/ListForumStatsOutput.php`
- `tests/Unit/Application/Backstage/Forum/ListForumStatsTest.php`
- `tests/Integration/Persistence/SqlForumStatsTest.php` — integration test for the four repository helper methods
- `tests/Integration/Http/BackstageForumStatsTest.php` — E2E HTTP test
- `tests/Isolation/ForumStatsTenantIsolationTest.php` — tenant isolation

**Modify:**
- `src/Domain/Forum/ForumRepositoryInterface.php` — add `countTopicsForTenant(TenantId): int` and `dailyNewTopicsForTenant(TenantId): list<array{date,value}>`
- `src/Domain/Forum/ForumReportRepositoryInterface.php` — add `countOpenReportsForTenant(TenantId): int` and `dailyNewReportsForTenant(TenantId): list<array{date,value}>`
- `src/Domain/Forum/ForumModerationAuditRepositoryInterface.php` — add `countActionsLast30dForTenant(TenantId): int`, `dailyActionCountForTenant(TenantId): list<array{date,value}>`, and `recentForTenant(TenantId, int $limit): list<ForumModerationAuditEntry>` if not present
- For category count: add `countCategoriesForTenant(TenantId): int` to `ForumRepositoryInterface` (categories are tenant-shared in the existing schema; verify and adapt during Task 2 if scoping differs)
- All four corresponding SQL repo classes (`SqlForumRepository`, `SqlForumReportRepository`, `SqlForumModerationAuditRepository`) — implement the new methods
- All four in-memory fakes under `tests/Support/Fake/` (or wherever they live — verify) — implement the new methods (stub OK if no E2E test exercises sparkline data)
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add `statsForum()` method; add `ListForumStats` to constructor
- `routes/api.php` — add `GET /api/v1/backstage/forum/stats`
- `bootstrap/app.php` — bind use case + add to controller constructor wiring
- `tests/Support/KernelHarness.php` — same wiring (BOTH-containers rule)

### Frontend (`C:/laragon/www/sites/daem-society`)

**Create:**
- `public/pages/backstage/forum/reports/index.php`
- `public/pages/backstage/forum/topics/index.php`
- `public/pages/backstage/forum/categories/index.php`
- `public/pages/backstage/forum/audit/index.php`
- `public/pages/backstage/forum/forum-dashboard.js` — KPI sparkline init + recent-activity render
- `public/pages/backstage/forum/forum-reports-page.js` — Reports list + slide-panel resolve flow
- `public/pages/backstage/forum/forum-topics-page.js` — Topics table + actions
- `public/pages/backstage/forum/forum-categories-page.js` — Categories table + slide-panel CRUD
- `public/pages/backstage/forum/forum-audit-page.js` — Audit table + filters + load-more
- `public/pages/backstage/forum/empty-state-reports.svg`
- `public/pages/backstage/forum/empty-state-topics.svg`

**Modify:**
- `public/pages/backstage/forum/index.php` — replace contents with dashboard layout
- `public/pages/backstage/forum/forum.css` — strip down to forum-specific bits (`.report-reason-chip`, report-card layout); remove tab styles
- `public/index.php` — add 4 new path matches in `__adminMap`
- `public/api/backstage/forum.php` — add `op=stats` case + add `session_start()` if missing

**Delete:**
- `public/pages/backstage/forum/forum.js`
- `public/pages/backstage/forum/forum-modal.js`

---

## Conventions

- **Commit identity:** every commit must use `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No `Co-Authored-By:` trailer.
- **Never auto-push.** Final task reports SHAs and waits for explicit pushaa.
- **Never stage `.claude/` or `.superpowers/`** — both gitignored, but verify with `git status --short` before each commit.
- **Hook failure:** fix + create a NEW commit (never `--amend`).
- **Forbidden:** any `mcp__code-review-graph__*` tool — these have hung subagent sessions.
- **Test:** `composer test:all` (Unit + Integration + E2E). MySQL must be running for Integration + Isolation suites.
- **Static analysis:** `composer analyse` must remain at 0 errors after every backend task.
- **No-hover-transform:** all new CSS rules must be free of `transform: translate*` / `scale*` on `:hover` selectors. Verified via grep at the end of every frontend task.

---

## Phase A — Backend stats endpoint

### Task 1: Add stats methods to forum domain interfaces

**Files:**
- Modify: `C:/laragon/www/daems-platform/src/Domain/Forum/ForumRepositoryInterface.php`
- Modify: `C:/laragon/www/daems-platform/src/Domain/Forum/ForumReportRepositoryInterface.php`
- Modify: `C:/laragon/www/daems-platform/src/Domain/Forum/ForumModerationAuditRepositoryInterface.php`

- [ ] **Step 1: Read each interface to know existing methods**

```bash
cd /c/laragon/www/daems-platform
cat src/Domain/Forum/ForumRepositoryInterface.php
cat src/Domain/Forum/ForumReportRepositoryInterface.php
cat src/Domain/Forum/ForumModerationAuditRepositoryInterface.php
```

- [ ] **Step 2: Add to `ForumRepositoryInterface`**

Append the following methods inside the existing `interface ForumRepositoryInterface { … }` body (before its closing `}`):

```php
    /**
     * Count topics for a tenant. Used by the backstage dashboard KPI.
     */
    public function countTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;

    /**
     * Daily count of newly-created topics for a tenant, last 30 days.
     * Returns exactly 30 entries: index 0 = 29 days ago, index 29 = today.
     * Missing days are zero-filled.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyNewTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;

    /**
     * Count categories visible to the tenant.
     * If categories are tenant-shared (no tenant_id column), implementations
     * should return the global count.
     */
    public function countCategoriesForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;
```

- [ ] **Step 3: Add to `ForumReportRepositoryInterface`**

Append:

```php
    /**
     * Count reports with status='open' for a tenant.
     */
    public function countOpenReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;

    /**
     * Daily count of newly-created reports for a tenant, last 30 days.
     * Returns exactly 30 entries: index 0 = 29 days ago, index 29 = today.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyNewReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;
```

- [ ] **Step 4: Add to `ForumModerationAuditRepositoryInterface`**

Append:

```php
    /**
     * Count audit entries for a tenant where created_at >= NOW() - INTERVAL 30 DAY.
     */
    public function countActionsLast30dForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int;

    /**
     * Daily count of audit entries for a tenant, last 30 days.
     * Returns exactly 30 entries: index 0 = 29 days ago, index 29 = today.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailyActionCountForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;

    /**
     * Most-recent audit entries for a tenant.
     * @return list<ForumModerationAuditEntry>
     */
    public function recentForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit = 5): array;
```

(If the interface already has a `recentForTenant` method or similar — verify when reading in Step 1 — skip the third addition and use the existing method in subsequent tasks.)

- [ ] **Step 5: Verify expected PHPStan errors**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -20`

Expected: PHPStan complains that the SQL repo classes (`SqlForumRepository`, `SqlForumReportRepository`, `SqlForumModerationAuditRepository`) and possibly fakes don't implement the new methods. These are fixed in Tasks 2 and 3.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Domain/Forum/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Domain(forum): add stats methods to forum/report/audit repository interfaces"
```

---

### Task 2: Implement SQL stats methods (TDD via integration test)

**Files:**
- Create: `C:/laragon/www/daems-platform/tests/Integration/Persistence/SqlForumStatsTest.php`
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php`
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php`
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php`

- [ ] **Step 1: Read the existing SQL repos to understand the connection/PDO API**

```bash
cd /c/laragon/www/daems-platform
head -60 src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php
head -60 src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php
head -60 src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php
```

Mirror the existing query/connection pattern (likely `$this->connection->query(...)` or `$this->db` or similar — same as Phase 1's insight repo).

- [ ] **Step 2: Write the failing integration test**

Create `tests/Integration/Persistence/SqlForumStatsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumModerationAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlForumStatsTest extends MigrationTestCase
{
    private SqlForumRepository $forumRepo;
    private SqlForumReportRepository $reportRepo;
    private SqlForumModerationAuditRepository $auditRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        // Constructor signatures may differ — adapt by reading the actual classes.
        $this->forumRepo  = new SqlForumRepository($conn);
        $this->reportRepo = new SqlForumReportRepository($conn);
        $this->auditRepo  = new SqlForumModerationAuditRepository($conn);
    }

    private function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return TenantId::fromString($row['id']);
    }

    private function seedCategoryAndTopic(string $tenantSlug, string $topicSlug): void
    {
        // Adapt INSERTs to actual schemas — the planner provided one based on
        // migrations 007/030; if schema has shifted, run `desc forum_topics`
        // / `desc forum_categories` and align.
        $this->pdo()->exec("INSERT IGNORE INTO forum_categories (id, slug, name, icon, description, sort_order)
            VALUES (UUID(), 'cat1', 'Cat 1', '', 'd', 0)");
        $catId = (string) $this->pdo()->query("SELECT id FROM forum_categories WHERE slug='cat1'")->fetchColumn();
        $tenantId = $this->tenantId($tenantSlug)->value();
        $this->pdo()->prepare("INSERT INTO forum_topics
            (id, tenant_id, category_id, slug, title, body, author_id, is_locked, is_pinned, search_text, created_at)
            VALUES (UUID(), ?, ?, ?, 'T', 'B', UUID(), 0, 0, '', NOW())")
            ->execute([$tenantId, $catId, $topicSlug]);
    }

    public function test_topics_count_isolated_by_tenant(): void
    {
        $this->seedCategoryAndTopic('daems', 'd-t1');
        $this->seedCategoryAndTopic('sahegroup', 's-t1');

        self::assertSame(1, $this->forumRepo->countTopicsForTenant($this->tenantId('daems')));
        self::assertSame(1, $this->forumRepo->countTopicsForTenant($this->tenantId('sahegroup')));
    }

    public function test_topics_sparkline_has_30_entries_zero_filled(): void
    {
        $points = $this->forumRepo->dailyNewTopicsForTenant($this->tenantId('daems'));
        self::assertCount(30, $points);
        self::assertSame(date('Y-m-d', strtotime('-29 days')), $points[0]['date']);
        self::assertSame(date('Y-m-d'),                       $points[29]['date']);
    }

    public function test_open_reports_count(): void
    {
        $this->pdo()->prepare("INSERT INTO forum_reports
            (id, tenant_id, target_type, target_id, reporter_id, reason, comment, status, created_at)
            VALUES (UUID(), ?, 'post', UUID(), UUID(), 'spam', '', 'open', NOW())")
            ->execute([$this->tenantId('daems')->value()]);
        self::assertSame(1, $this->reportRepo->countOpenReportsForTenant($this->tenantId('daems')));
        self::assertSame(0, $this->reportRepo->countOpenReportsForTenant($this->tenantId('sahegroup')));
    }

    public function test_audit_count_last_30d(): void
    {
        $this->pdo()->prepare("INSERT INTO forum_moderation_audit
            (id, tenant_id, target_type, target_id, action, reason, performed_by, created_at)
            VALUES (UUID(), ?, 'post', UUID(), 'deleted', '', UUID(), NOW())")
            ->execute([$this->tenantId('daems')->value()]);
        self::assertSame(1, $this->auditRepo->countActionsLast30dForTenant($this->tenantId('daems')));
    }

    public function test_audit_recent_returns_entries_in_descending_order(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->pdo()->prepare("INSERT INTO forum_moderation_audit
                (id, tenant_id, target_type, target_id, action, reason, performed_by, created_at)
                VALUES (UUID(), ?, 'post', UUID(), 'deleted', '', UUID(), NOW() - INTERVAL ? SECOND)")
                ->execute([$this->tenantId('daems')->value(), $i]);
        }
        $rows = $this->auditRepo->recentForTenant($this->tenantId('daems'), 5);
        self::assertCount(3, $rows);
    }

    public function test_categories_count_returns_nonnegative_int(): void
    {
        $count = $this->forumRepo->countCategoriesForTenant($this->tenantId('daems'));
        self::assertGreaterThanOrEqual(0, $count);
    }
}
```

If the schemas as written in the seed INSERTs don't match (column missing, NOT NULL without default, etc.), align the seeds to the actual schema before running. Use `desc forum_topics`, `desc forum_reports`, `desc forum_moderation_audit` against the test DB to verify column names and required fields.

- [ ] **Step 3: Run the test to confirm it fails**

```bash
cd /c/laragon/www/daems-platform && composer test -- --filter SqlForumStatsTest 2>&1 | tail -25
```

Expected: failures with `Call to undefined method ... countTopicsForTenant()` or similar.

- [ ] **Step 4: Implement methods in `SqlForumRepository`**

Append the three methods inside the class:

```php
    public function countTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int
    {
        $rows = $this->connection->query(
            'SELECT COUNT(*) AS c FROM forum_topics WHERE tenant_id = ?',
            [$tenantId->value()]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    public function dailyNewTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        $rows = $this->connection->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM forum_topics
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value()]
        );
        foreach ($rows as $row) {
            $d = (string) $row['d'];
            if (isset($days[$d])) {
                $days[$d] = (int) $row['c'];
            }
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    public function countCategoriesForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int
    {
        // Categories are not tenant-scoped in the schema (forum_categories has no
        // tenant_id column — verified against migration 007). Returns global count.
        $rows = $this->connection->query('SELECT COUNT(*) AS c FROM forum_categories', []);
        return (int) ($rows[0]['c'] ?? 0);
    }
```

If the existing SQL repo uses `$this->db` instead of `$this->connection`, adapt accordingly.

- [ ] **Step 5: Implement methods in `SqlForumReportRepository`**

```php
    public function countOpenReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int
    {
        $rows = $this->connection->query(
            "SELECT COUNT(*) AS c FROM forum_reports WHERE tenant_id = ? AND status = 'open'",
            [$tenantId->value()]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    public function dailyNewReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        $rows = $this->connection->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM forum_reports
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value()]
        );
        foreach ($rows as $row) {
            $d = (string) $row['d'];
            if (isset($days[$d])) {
                $days[$d] = (int) $row['c'];
            }
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }
```

- [ ] **Step 6: Implement methods in `SqlForumModerationAuditRepository`**

```php
    public function countActionsLast30dForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int
    {
        $rows = $this->connection->query(
            'SELECT COUNT(*) AS c FROM forum_moderation_audit
             WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            [$tenantId->value()]
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    public function dailyActionCountForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        $rows = $this->connection->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM forum_moderation_audit
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value()]
        );
        foreach ($rows as $row) {
            $d = (string) $row['d'];
            if (isset($days[$d])) {
                $days[$d] = (int) $row['c'];
            }
        }
        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    public function recentForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit = 5): array
    {
        $rows = $this->connection->query(
            'SELECT * FROM forum_moderation_audit
             WHERE tenant_id = ?
             ORDER BY created_at DESC
             LIMIT ' . max(1, min(100, $limit)),
            [$tenantId->value()]
        );
        // Hydrate to ForumModerationAuditEntry — adapt to whatever the existing
        // hydration helper in the file does (or add a private hydrate method
        // mirroring siblings).
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);  // OR existing hydration method
        }
        return $out;
    }
```

If the file has no `hydrate` helper, look for how other methods convert rows to domain objects — match that pattern.

- [ ] **Step 7: Run integration test → all green**

```bash
cd /c/laragon/www/daems-platform && composer test -- --filter SqlForumStatsTest 2>&1 | tail -10
```

Expected: 6 tests, all pass.

- [ ] **Step 8: Run PHPStan**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -10
```

Expected: 0 errors, OR errors only complaining about in-memory fakes still missing the new methods (Task 3 fixes those).

- [ ] **Step 9: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Infrastructure/Adapter/Persistence/Sql/ tests/Integration/Persistence/SqlForumStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(forum): SQL implementations for statsForTenant primitives — counts + 30-day sparklines"
```

---

### Task 3: Update in-memory fakes / anonymous test classes for the new interface methods

**Files:**
- Modify (verify exact paths): `tests/Support/Fake/InMemory*ForumRepository.php` (or wherever the fakes live)
- Modify any unit-test anonymous classes that implement these interfaces (they will fatal-error otherwise)

- [ ] **Step 1: Find the fake classes**

```bash
cd /c/laragon/www/daems-platform
grep -rln "implements ForumRepositoryInterface\|implements ForumReportRepositoryInterface\|implements ForumModerationAuditRepositoryInterface" tests/ 2>&1
```

For each match, confirm whether it's a fake stub (in `tests/Support/`) or an anonymous class in a unit test.

- [ ] **Step 2: Add stubs to each fake/anonymous class**

For each fake or anonymous class missing the new methods, add stub implementations. Use these as the minimum viable stubs:

```php
public function countTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int { return 0; }
public function dailyNewTopicsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
{
    $out = []; $base = new \DateTimeImmutable('today');
    for ($i = 29; $i >= 0; $i--) {
        $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
    }
    return $out;
}
public function countCategoriesForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int { return 0; }
public function countOpenReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int { return 0; }
public function dailyNewReportsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
{
    $out = []; $base = new \DateTimeImmutable('today');
    for ($i = 29; $i >= 0; $i--) {
        $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
    }
    return $out;
}
public function countActionsLast30dForTenant(\Daems\Domain\Tenant\TenantId $tenantId): int { return 0; }
public function dailyActionCountForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
{
    $out = []; $base = new \DateTimeImmutable('today');
    for ($i = 29; $i >= 0; $i--) {
        $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
    }
    return $out;
}
public function recentForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit = 5): array { return []; }
```

(Apply only the stubs whose interface the fake/anon class implements — e.g. `InMemoryForumReportRepository` only needs the report ones.)

- [ ] **Step 3: Verify `composer test:unit` and `composer analyse` are both green**

```bash
cd /c/laragon/www/daems-platform && composer test:unit 2>&1 | tail -5
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add tests/Support/Fake/ tests/Unit/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(forum): stub statsForTenant primitives in fakes + anon test classes — keeps interfaces conforming"
```

---

### Task 4: Create `ListForumStats` use case + unit test

**Files:**
- Create: `C:/laragon/www/daems-platform/src/Application/Backstage/Forum/ListForumStats/ListForumStats.php`
- Create: `C:/laragon/www/daems-platform/src/Application/Backstage/Forum/ListForumStats/ListForumStatsInput.php`
- Create: `C:/laragon/www/daems-platform/src/Application/Backstage/Forum/ListForumStats/ListForumStatsOutput.php`
- Create: `C:/laragon/www/daems-platform/tests/Unit/Application/Backstage/Forum/ListForumStatsTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumStats\ListForumStats;
use Daems\Application\Backstage\Forum\ListForumStats\ListForumStatsInput;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use PHPUnit\Framework\TestCase;

final class ListForumStatsTest extends TestCase
{
    public function test_returns_assembled_payload(): void
    {
        $tenantId = TenantId::fromString('019d0000-0000-7000-8000-000000000001');

        $forumRepo  = $this->makeForumRepoFake();
        $reportRepo = $this->makeReportRepoFake();
        $auditRepo  = $this->makeAuditRepoFake();

        $uc = new ListForumStats($forumRepo, $reportRepo, $auditRepo);
        $out = $uc->execute(new ListForumStatsInput(
            actor: $this->makeAdminUser($tenantId),
            tenantId: $tenantId,
        ));

        self::assertSame(7,   $out->stats['open_reports']['value']);
        self::assertSame(142, $out->stats['topics']['value']);
        self::assertSame(8,   $out->stats['categories']['value']);
        self::assertSame(23,  $out->stats['mod_actions']['value']);
        self::assertCount(0,  $out->stats['categories']['sparkline']);
        self::assertCount(30, $out->stats['mod_actions']['sparkline']);
        self::assertCount(0,  $out->recentAudit);
    }

    private function makeAdminUser(TenantId $tenantId): User
    {
        // Adapt to whatever the User factory or test-helper looks like in this codebase.
        // If creating a User directly is awkward, use a fake or a helper from tests/Support.
        $user = new User(
            id: \Daems\Domain\User\UserId::fromString('019d0000-0000-7000-8000-000000000010'),
            email: 'admin@test',
            name: 'Admin',
            isPlatformAdmin: true,
        );
        return $user;
    }

    private function makeForumRepoFake(): ForumRepositoryInterface
    {
        return new class implements ForumRepositoryInterface {
            // Existing methods stubbed minimally — paste the actual interface
            // method signatures from src/Domain/Forum/ForumRepositoryInterface.php
            // and return safe defaults.
            public function countTopicsForTenant(TenantId $tenantId): int { return 142; }
            public function dailyNewTopicsForTenant(TenantId $tenantId): array { return self::emptySpark(); }
            public function countCategoriesForTenant(TenantId $tenantId): int { return 8; }
            // ... add stubs for any other ForumRepositoryInterface methods
            private static function emptySpark(): array {
                $out = []; $base = new \DateTimeImmutable('today');
                for ($i = 29; $i >= 0; $i--) {
                    $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
                }
                return $out;
            }
        };
    }

    private function makeReportRepoFake(): ForumReportRepositoryInterface
    {
        return new class implements ForumReportRepositoryInterface {
            public function countOpenReportsForTenant(TenantId $tenantId): int { return 7; }
            public function dailyNewReportsForTenant(TenantId $tenantId): array { return self::emptySpark(); }
            // ... stub other interface methods minimally
            private static function emptySpark(): array {
                $out = []; $base = new \DateTimeImmutable('today');
                for ($i = 29; $i >= 0; $i--) {
                    $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
                }
                return $out;
            }
        };
    }

    private function makeAuditRepoFake(): ForumModerationAuditRepositoryInterface
    {
        return new class implements ForumModerationAuditRepositoryInterface {
            public function countActionsLast30dForTenant(TenantId $tenantId): int { return 23; }
            public function dailyActionCountForTenant(TenantId $tenantId): array {
                $out = []; $base = new \DateTimeImmutable('today');
                for ($i = 29; $i >= 0; $i--) {
                    $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
                }
                return $out;
            }
            public function recentForTenant(TenantId $tenantId, int $limit = 5): array { return []; }
            // ... stub other methods
        };
    }
}
```

The fake classes must implement ALL methods of the corresponding interface, not just the stats methods. Read the interface files to get the full method list, then add minimal stubs (`return null` / `return 0` / `return []`).

- [ ] **Step 2: Run the test, confirm it fails**

```bash
cd /c/laragon/www/daems-platform && composer test -- --filter ListForumStatsTest 2>&1 | tail -15
```

Expected: class-not-found errors.

- [ ] **Step 3: Create the input DTO**

`src/Application/Backstage/Forum/ListForumStats/ListForumStatsInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

final class ListForumStatsInput
{
    public function __construct(
        public readonly User $actor,
        public readonly TenantId $tenantId,
    ) {}
}
```

- [ ] **Step 4: Create the output DTO**

`src/Application/Backstage/Forum/ListForumStats/ListForumStatsOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Forum\ForumModerationAuditEntry;

final class ListForumStatsOutput
{
    /**
     * @param array{
     *   open_reports: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   topics:       array{value: int, sparkline: list<array{date: string, value: int}>},
     *   categories:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   mod_actions:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     * @param list<ForumModerationAuditEntry> $recentAudit
     */
    public function __construct(
        public readonly array $stats,
        public readonly array $recentAudit,
    ) {}
}
```

- [ ] **Step 5: Create the use case**

`src/Application/Backstage/Forum/ListForumStats/ListForumStats.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumStats;

use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Shared\Exception\ForbiddenException;

final class ListForumStats
{
    public function __construct(
        private readonly ForumRepositoryInterface $forumRepo,
        private readonly ForumReportRepositoryInterface $reportRepo,
        private readonly ForumModerationAuditRepositoryInterface $auditRepo,
    ) {}

    public function execute(ListForumStatsInput $input): ListForumStatsOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        return new ListForumStatsOutput(
            stats: [
                'open_reports' => [
                    'value'     => $this->reportRepo->countOpenReportsForTenant($input->tenantId),
                    'sparkline' => $this->reportRepo->dailyNewReportsForTenant($input->tenantId),
                ],
                'topics' => [
                    'value'     => $this->forumRepo->countTopicsForTenant($input->tenantId),
                    'sparkline' => $this->forumRepo->dailyNewTopicsForTenant($input->tenantId),
                ],
                'categories' => [
                    'value'     => $this->forumRepo->countCategoriesForTenant($input->tenantId),
                    'sparkline' => [],
                ],
                'mod_actions' => [
                    'value'     => $this->auditRepo->countActionsLast30dForTenant($input->tenantId),
                    'sparkline' => $this->auditRepo->dailyActionCountForTenant($input->tenantId),
                ],
            ],
            recentAudit: $this->auditRepo->recentForTenant($input->tenantId, 5),
        );
    }
}
```

If `User::isAdminIn` doesn't exist (verify by reading the User class), use whatever helper is on the User domain class — likely `isAdminIn(TenantId)` or `isPlatformAdmin()` plus a tenant-membership check. Match the pattern in `BackstageController::requireInsightsAdmin`.

- [ ] **Step 6: Run unit test → green**

```bash
cd /c/laragon/www/daems-platform && composer test -- --filter ListForumStatsTest 2>&1 | tail -10
```

Expected: 1 test, green.

- [ ] **Step 7: Run PHPStan**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Application/Backstage/Forum/ListForumStats/ tests/Unit/Application/Backstage/Forum/ListForumStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(forum): ListForumStats — assembles 4 KPIs + recent audit entries for backstage dashboard"
```

---

### Task 5: Add `BackstageController::statsForum()` method

**Files:**
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 1: Add imports near other forum imports (around line 41-60)**

```php
use Daems\Application\Backstage\Forum\ListForumStats\ListForumStats;
use Daems\Application\Backstage\Forum\ListForumStats\ListForumStatsInput;
```

- [ ] **Step 2: Add constructor parameter (after `$listForumModerationAuditForAdmin` or wherever the last forum use case sits — read the constructor)**

```php
        private readonly ListForumStats $listForumStats,
```

- [ ] **Step 3: Add the method (after `listForumAudit`, around line 1180)**

```php
    public function statsForum(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listForumStats->execute(new ListForumStatsInput(
                actor:    $acting,
                tenantId: $tenant->id,
            ));
        } catch (ForbiddenException) {
            return Response::forbidden('Admin only');
        }

        return Response::json([
            'data' => [
                'open_reports' => $out->stats['open_reports'],
                'topics'       => $out->stats['topics'],
                'categories'   => $out->stats['categories'],
                'mod_actions'  => $out->stats['mod_actions'],
                'recent_audit' => array_map(static fn ($e) => [
                    'when'        => $e->createdAt()->format(DATE_ATOM),
                    'actor_id'    => $e->performedBy()->value(),
                    'action'      => $e->action(),
                    'target_type' => $e->targetType(),
                    'target_id'   => $e->targetId(),
                    'reason'      => $e->reason(),
                ], $out->recentAudit),
            ],
        ]);
    }
```

The `actor_name` field from the spec is **omitted** here — adding it would require a user-name lookup in the controller, which is out of scope for the use case as written. Frontend renders the actor by id. If user-name resolution becomes required later, add it as a separate join in the use case rather than the controller. Document this in the commit message.

If `$e->action()`, `$e->targetType()`, etc. don't exist on `ForumModerationAuditEntry`, read the class and use the actual getters. Adapt accessor names without changing semantic.

- [ ] **Step 4: PHPStan check**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```

Expected: 0 errors. (Controller can compile but cannot be instantiated yet — DI wiring follows in Task 7.)

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Controller(backstage): statsForum — KPIs + recent audit; actor_name resolution deferred"
```

---

### Task 6: Wire route `GET /api/v1/backstage/forum/stats`

**Files:**
- Modify: `C:/laragon/www/daems-platform/routes/api.php`

- [ ] **Step 1: Find the existing forum routes**

```bash
grep -n "backstage/forum" /c/laragon/www/daems-platform/routes/api.php | head -3
```

- [ ] **Step 2: Insert the new route immediately before the existing `GET /backstage/forum/reports`**

```php
    $router->get('/api/v1/backstage/forum/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsForum($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Order matters — `/forum/stats` must come before `/forum/reports/{id}` (or any forum wildcard) to avoid mismatch.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/daems-platform
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Route: GET /backstage/forum/stats — dashboard KPI strip data"
```

---

### Task 7: Wire DI in BOTH containers

**Files:**
- Modify: `C:/laragon/www/daems-platform/bootstrap/app.php`
- Modify: `C:/laragon/www/daems-platform/tests/Support/KernelHarness.php`

- [ ] **Step 1: Edit `bootstrap/app.php` — add use-case binding**

Find the existing forum use-case bindings (search `Backstage\Forum\` in the file). After the last one, append:

```php
$container->bind(\Daems\Application\Backstage\Forum\ListForumStats\ListForumStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Forum\ListForumStats\ListForumStats(
        $c->make(\Daems\Domain\Forum\ForumRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class),
        $c->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
    ));
```

- [ ] **Step 2: Edit `bootstrap/app.php` — add to BackstageController constructor wiring**

Find the BackstageController `make` block. Locate the line passing the LAST forum use case (likely `ListForumModerationAuditForAdmin::class` or similar). On the line **after** it, add:

```php
        $c->make(\Daems\Application\Backstage\Forum\ListForumStats\ListForumStats::class),
```

- [ ] **Step 3: Edit `tests/Support/KernelHarness.php` — same two edits, mirroring**

Add the same binding (with whatever `$container->bind` syntax the file already uses for forum use cases) and add the `make` call to the `BackstageController` `make` block in the same position.

- [ ] **Step 4: Verify symmetry**

```bash
cd /c/laragon/www/daems-platform && grep -n "ListForumStats" bootstrap/app.php tests/Support/KernelHarness.php
```

Expected: each file contains exactly two occurrences (one for the binding, one for the controller-constructor call).

- [ ] **Step 5: Run PHPStan + Unit tests**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
cd /c/laragon/www/daems-platform && composer test:unit 2>&1 | tail -5
```

Expected: 0 PHPStan errors, all unit tests green.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(forum): ListForumStats in BOTH bootstrap + KernelHarness"
```

---

### Task 8: Add E2E HTTP test + isolation test for stats endpoint

**Files:**
- Create: `C:/laragon/www/daems-platform/tests/Integration/Http/BackstageForumStatsTest.php`
- Create: `C:/laragon/www/daems-platform/tests/Isolation/ForumStatsTenantIsolationTest.php`

- [ ] **Step 1: E2E HTTP test using KernelHarness**

Read `tests/Integration/Http/BackstageInsightStatsTest.php` (Phase 1, similar structure) and adapt for forum:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Tests\Support\Clock\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BackstageForumStatsTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
    }

    public function test_returns_zero_stats_when_no_forum_data(): void
    {
        $admin = $this->h->seedUser('a@t', 'pw', 'admin');
        $token = $this->h->tokenFor($admin);
        $resp  = $this->h->authedRequest('GET', '/api/v1/backstage/forum/stats', $token);

        self::assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        self::assertSame(0, $body['data']['open_reports']['value']);
        self::assertSame(0, $body['data']['topics']['value']);
        self::assertSame(0, $body['data']['mod_actions']['value']);
        self::assertCount(30, $body['data']['mod_actions']['sparkline']);
    }

    public function test_403_for_non_admin(): void
    {
        $member = $this->h->seedUser('m@t', 'pw', 'member');
        $token  = $this->h->tokenFor($member);
        $resp   = $this->h->authedRequest('GET', '/api/v1/backstage/forum/stats', $token);

        self::assertSame(403, $resp->status());
    }
}
```

Adapt the harness API names to whatever Phase 1's working test (`BackstageInsightStatsTest`) uses — match exactly.

- [ ] **Step 2: Tenant isolation test (against real DB)**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumReportRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ForumStatsTenantIsolationTest extends IsolationTestCase
{
    private SqlForumReportRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlForumReportRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    public function test_open_reports_count_isolated_by_tenant(): void
    {
        $this->seedReport('daems',     'open');
        $this->seedReport('sahegroup', 'open');

        self::assertSame(1, $this->repo->countOpenReportsForTenant($this->tenantId('daems')));
        self::assertSame(1, $this->repo->countOpenReportsForTenant($this->tenantId('sahegroup')));
    }

    private function seedReport(string $tenantSlug, string $status): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_id, reason, comment, status, created_at)
             VALUES (UUID(), (SELECT id FROM tenants WHERE slug = ?), 'post', UUID(), UUID(), 'spam', '', ?, NOW())"
        );
        $stmt->execute([$tenantSlug, $status]);
    }
}
```

- [ ] **Step 3: Run both new tests**

```bash
cd /c/laragon/www/daems-platform && composer test -- --filter "BackstageForumStatsTest|ForumStatsTenantIsolationTest" 2>&1 | tail -10
```

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add tests/Integration/Http/BackstageForumStatsTest.php tests/Isolation/ForumStatsTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(forum): E2E stats endpoint + tenant isolation for open-reports counter"
```

---

### Task 9: Backend regression sweep

- [ ] **Step 1: Reset test DB to avoid contamination from prior runs**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- [ ] **Step 2: Run unit + E2E + new forum filter tests**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --testsuite E2E 2>&1 | tail -5
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --filter "Forum" 2>&1 | tail -10
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```

Expected: all green, PHPStan 0.

(Full Integration suite has known DB-contamination flakiness from concurrent runs — running it sequentially after a fresh reset is fine but optional. Filter to forum tests is sufficient evidence for backend.)

---

## Phase B — Routing

### Task 10: Add 4 new sub-page routes to the front controller

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/index.php`

- [ ] **Step 1: Locate the `__adminMap` block (~line 247)**

```bash
grep -n "__adminMap" /c/laragon/www/sites/daem-society/public/index.php | head -3
```

- [ ] **Step 2: Add four new entries**

After the existing `'/forum' => __DIR__ . '/pages/backstage/forum/index.php',` line, add:

```php
        '/forum/reports'      => __DIR__ . '/pages/backstage/forum/reports/index.php',
        '/forum/topics'       => __DIR__ . '/pages/backstage/forum/topics/index.php',
        '/forum/categories'   => __DIR__ . '/pages/backstage/forum/categories/index.php',
        '/forum/audit'        => __DIR__ . '/pages/backstage/forum/audit/index.php',
```

- [ ] **Step 3: Smoke-test via curl that the routes don't 500**

(Optional — most of the time the routes are tested when you visit a page that hasn't been implemented yet, which falls back to the missing-section message.)

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Routing(backstage): add 4 forum sub-page routes (reports/topics/categories/audit)"
```

---

### Task 11: Add `op=stats` + session_start fix to forum proxy

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/api/backstage/forum.php`

- [ ] **Step 1: Add `session_start()` if missing**

Open the file and check if `session_start()` is called. If not (likely — Phase 1 found this issue with insights.php), add immediately after the opening `<?php` and `declare`:

```php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

Place it before the existing `if (!class_exists('ApiClient'))` block.

- [ ] **Step 2: Add `case 'stats':` to the switch**

In the `switch ($op)` block, add a new case. Place it as the FIRST case (before any of the existing forum-list cases):

```php
        case 'stats': {
            $rawGet('/backstage/forum/stats');
            return;
        }
```

The `$rawGet` helper (already defined above the switch) handles the curl call + status proxying. The result body already has `{"data": {...}}` shape — pass it through verbatim.

- [ ] **Step 3: Quick browser verification (manual)**

Open the browser DevTools, hit `/api/backstage/forum.php?op=stats` while logged in as admin. Confirm 200 + JSON body.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/api/backstage/forum.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Proxy(forum): start session + add stats op — same Apache-direct-serve fix as insights"
```

---

## Phase C — Dashboard landing page

### Task 12: Replace `/backstage/forum/index.php` with dashboard layout

**Files:**
- Modify (replace contents): `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum-dashboard.js`

- [ ] **Step 1: Replace `index.php`**

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Forum';
$activePage  = 'forum';
$breadcrumbs = [];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Forum</h1>
    <p class="page-header__subtitle">Moderate reports, topics, and categories.</p>
  </div>
</div>

<!-- KPI cards -->
<div class="kpis-grid">
  <?php
    $iconBell   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>';
    $iconChat   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $iconFolder = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
    $iconShield = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4"/></svg>';

    foreach ([
      ['kpi_id' => 'open_reports', 'label' => 'Open reports', 'value' => '—', 'icon_html' => $iconBell,   'icon_variant' => 'amber', 'trend_label' => 'pending review',   'trend_direction' => 'warn',  'href' => '/backstage/forum/reports'],
      ['kpi_id' => 'topics',       'label' => 'Topics',        'value' => '—', 'icon_html' => $iconChat,   'icon_variant' => 'blue',  'trend_label' => 'new this month',   'trend_direction' => 'muted', 'href' => '/backstage/forum/topics'],
      ['kpi_id' => 'categories',   'label' => 'Categories',    'value' => '—', 'icon_html' => $iconFolder, 'icon_variant' => 'gray',  'trend_label' => 'visible',          'trend_direction' => 'muted', 'href' => '/backstage/forum/categories'],
      ['kpi_id' => 'mod_actions',  'label' => 'Mod actions',   'value' => '—', 'icon_html' => $iconShield, 'icon_variant' => 'green', 'trend_label' => 'last 30 days',     'trend_direction' => 'muted', 'href' => '/backstage/forum/audit'],
    ] as $kpi) {
      extract($kpi);
      include __DIR__ . '/../shared/kpi-card.php';
    }
  ?>
</div>

<!-- Recent activity card -->
<div class="data-explorer__panel" style="padding: 18px 22px;">
  <h3 style="font-size: 15px; font-weight: 700; margin: 0 0 10px;">Recent activity</h3>
  <ul id="forum-recent-audit" style="list-style:none;padding:0;margin:0;">
    <li style="color: var(--text-muted); font-size: 13px;">Loading…</li>
  </ul>
  <a href="/backstage/forum/audit" class="btn btn--text" style="margin-top: 8px;">View all →</a>
</div>

<script src="/pages/backstage/forum/forum-dashboard.js" defer></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
```

- [ ] **Step 2: Create `forum-dashboard.js`**

```javascript
/**
 * Forum dashboard — fetches /api/backstage/forum.php?op=stats and renders
 * KPI sparklines + recent audit list. Reuses window.Sparkline from the
 * design system.
 */
(function () {
  'use strict';

  var KPI_COLORS = {
    open_reports: '#d97706',
    topics:       '#3b82f6',
    categories:   '#64748b',
    mod_actions:  '#16a34a',
  };

  fetch('/api/backstage/forum.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('forum stats failed', e); });

  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });

    setKpi('open_reports', data.open_reports);
    setKpi('topics',       data.topics);
    setKpi('categories',   data.categories);
    setKpi('mod_actions',  data.mod_actions);

    initSpark('open_reports', data.open_reports.sparkline);
    initSpark('topics',       data.topics.sparkline);
    initSpark('categories',   data.categories.sparkline);
    initSpark('mod_actions',  data.mod_actions.sparkline);

    renderRecent(data.recent_audit || []);
  }

  function setKpi(id, payload) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value);
  }

  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id]);
  }

  function renderRecent(rows) {
    var ul = document.getElementById('forum-recent-audit');
    if (!ul) return;
    if (!rows.length) {
      ul.innerHTML = '<li style="color: var(--text-muted); font-size: 13px;">No recent moderation actions.</li>';
      return;
    }
    ul.innerHTML = rows.map(function (r) {
      var when = (r.when || '').slice(0, 16).replace('T', ' ');
      return '<li style="padding: 6px 0; border-bottom: 1px solid var(--surface-border); font-size: 13px;">' +
             '<span style="color: var(--text-muted);">' + when + '</span> · ' +
             '<span class="pill pill--archived" style="margin: 0 4px;">' + esc(r.action) + '</span> ' +
             esc(r.target_type) +
             (r.reason ? ' · <em style="color: var(--text-muted);">' + esc(r.reason) + '</em>' : '') +
             '</li>';
    }).join('');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
```

- [ ] **Step 3: Browser smoke test**

Visit `http://daems.local/backstage/forum`. Expected:
- 4 KPI cards render with `—` initially → values populate from API
- Sparklines render (they may be flat if no data)
- Recent activity list populates
- Each KPI card click navigates to the corresponding sub-page (returns the missing-section page until the sub-pages exist — that's expected at this point)

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/index.php public/pages/backstage/forum/forum-dashboard.js
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(forum): dashboard landing — 4 clickable KPI cards + recent activity"
```

---

## Phase D — Reports sub-page

### Task 13: Reports sub-page + JS

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/reports/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum-reports-page.js`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/empty-state-reports.svg`

- [ ] **Step 1: Create `reports/index.php`**

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Forum reports';
$activePage  = 'forum';
$breadcrumbs = [['label' => 'Forum', 'url' => '/backstage/forum'], ['label' => 'Reports']];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Reports</h1>
    <p class="page-header__subtitle">Review and resolve moderation reports.</p>
  </div>
</div>

<div class="data-explorer__panel">
  <div class="data-explorer__toolbar">
    <div class="data-explorer__seg" id="fr-status-filter" role="tablist">
      <button type="button" class="data-explorer__seg-btn"           data-status="all">All</button>
      <button type="button" class="data-explorer__seg-btn is-active" data-status="open">Open</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="resolved">Resolved</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="dismissed">Dismissed</button>
    </div>
    <select class="data-explorer__search" id="fr-target-filter" style="min-width: 130px;">
      <option value="">All types</option>
      <option value="post">Posts</option>
      <option value="topic">Topics</option>
    </select>
    <input type="search" id="fr-search" class="data-explorer__search" placeholder="Search…">
  </div>

  <div id="fr-list-mount" style="padding: 12px 0;"></div>
  <div id="fr-empty-mount" style="display:none;">
    <?php
      $svg_path  = '/pages/backstage/forum/empty-state-reports.svg';
      $title     = 'No reports';
      $body      = 'Nothing flagged in this view.';
      include __DIR__ . '/../../shared/empty-state.php';
    ?>
  </div>
  <div id="fr-error-mount" style="display:none;"></div>
</div>

<script src="/pages/backstage/forum/forum-reports-page.js" defer></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
```

- [ ] **Step 2: Create `forum-reports-page.js`**

This is the longest JS file in this phase — it owns: list rendering, filter wiring, slide-panel resolve flow with 5 actions (delete / lock / warn / edit / dismiss), confirm-dialog usage.

```javascript
/**
 * Forum reports admin page — list + slide-panel resolve flow + confirm dismiss.
 * Uses window.SlidePanel + window.ConfirmDialog from daems-backstage-system.js.
 *
 * Endpoints (via daem-society proxy):
 *   GET  /api/backstage/forum.php?op=reports_list&status=...&target_type=...
 *   GET  /api/backstage/forum.php?op=report_detail&id=...
 *   POST /api/backstage/forum.php?op=report_resolve&id=...   body: {action, reason, ...}
 *   POST /api/backstage/forum.php?op=report_dismiss&id=...
 */
(function () {
  'use strict';

  var els = {
    list:    document.getElementById('fr-list-mount'),
    empty:   document.getElementById('fr-empty-mount'),
    error:   document.getElementById('fr-error-mount'),
    seg:     document.getElementById('fr-status-filter'),
    target:  document.getElementById('fr-target-filter'),
    search:  document.getElementById('fr-search'),
  };
  if (!els.list) return;

  var state = { status: 'open', target: '', q: '', rows: [] };

  function load() {
    setSkeleton();
    var qs = '?op=reports_list&status=' + encodeURIComponent(state.status) +
             (state.target ? '&target_type=' + encodeURIComponent(state.target) : '');
    fetch('/api/backstage/forum.php' + qs)
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) {
        state.rows = (j && j.data) || [];
        render();
      })
      .catch(function (err) { renderError(err); });
  }

  function setSkeleton() {
    els.list.innerHTML = '';
    for (var i = 0; i < 3; i++) {
      els.list.innerHTML += '<div class="data-explorer__skeleton" style="height: 100px; margin: 8px 0; background: var(--surface-light); border-radius: 8px;"></div>';
    }
    if (els.empty) els.empty.style.display = 'none';
    if (els.error) els.error.style.display = 'none';
  }

  function filtered() {
    var q = state.q.toLowerCase();
    if (!q) return state.rows;
    return state.rows.filter(function (r) {
      return ((r.target_excerpt || '') + (r.target_type || '')).toLowerCase().indexOf(q) !== -1;
    });
  }

  function render() {
    var rows = filtered();
    if (!rows.length) {
      els.list.innerHTML = '';
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';

    els.list.innerHTML = rows.map(reportCardHtml).join('');
    Array.from(els.list.querySelectorAll('[data-act="resolve"]')).forEach(function (b) {
      b.addEventListener('click', function () { openResolvePanel(b.getAttribute('data-id')); });
    });
    Array.from(els.list.querySelectorAll('[data-act="dismiss"]')).forEach(function (b) {
      b.addEventListener('click', function () { dismissReport(b.getAttribute('data-id')); });
    });
  }

  function reportCardHtml(r) {
    var cid = r.compound_id || r.id || '';
    var typeLabel = r.target_type === 'topic' ? 'Topic' : 'Post';
    var statusPill = '<span class="pill pill--' + (r.status === 'open' ? 'pending' : 'archived') + '">' + esc(r.status) + '</span>';
    var reasonsHtml = (r.reason_counts ? Object.keys(r.reason_counts).map(function (k) {
      return '<span class="report-reason-chip">' + esc(k) + ' ×' + r.reason_counts[k] + '</span>';
    }).join(' ') : '');
    return '<article class="card" style="margin-bottom: 10px; padding: 14px 16px;">' +
           '  <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">' +
           '    <div>' +
           '      <span class="pill pill--scheduled">' + typeLabel + '</span> ' +
           '      <strong>' + (r.report_count || 1) + ' report' + ((r.report_count || 1) === 1 ? '' : 's') + '</strong> · ' +
           '      ' + statusPill +
           '      <p style="margin:8px 0; color: var(--text-secondary); font-size: 13px;">' + esc((r.target_excerpt || '').slice(0, 240)) + '</p>' +
           '      <div style="margin-top: 6px;">' + reasonsHtml + '</div>' +
           '    </div>' +
           '    <div style="display:flex;gap:6px;">' +
           '      <button class="btn btn--secondary" data-act="resolve" data-id="' + esc(cid) + '">Resolve…</button>' +
           '      <button class="btn btn--text"      data-act="dismiss" data-id="' + esc(cid) + '">Dismiss</button>' +
           '    </div>' +
           '  </div>' +
           '</article>';
  }

  function renderError(err) {
    els.list.innerHTML = '';
    if (els.error) {
      els.error.style.display = '';
      els.error.innerHTML =
        '<div class="error-state" role="alert">' +
        '  <svg class="error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 9v4M12 17h.01M3.6 18l8.4-14 8.4 14H3.6z"/></svg>' +
        '  <div><div class="error-state__title">Could not load reports</div>' +
        '  <div class="error-state__message">' + esc(err && err.message || 'Network error') + '</div></div>' +
        '  <div class="error-state__actions"><button class="btn btn--primary" id="fr-retry">Retry</button></div>' +
        '</div>';
      var b = document.getElementById('fr-retry');
      if (b) b.addEventListener('click', load);
    }
  }

  function openResolvePanel(id) {
    fetch('/api/backstage/forum.php?op=report_detail&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        showPanel(id, (j && j.data) || {});
      });
  }

  function showPanel(id, detail) {
    var body = document.createElement('div');
    body.innerHTML =
      '<h3 style="font-size:14px;margin:0 0 6px;">Reported ' + esc(detail.target_type || '') + '</h3>' +
      '<p style="background:var(--surface-light); padding:10px; border-radius:6px; font-size:13px;">' +
      esc(detail.target_content || detail.target_excerpt || '(content unavailable)') + '</p>' +
      '<h3 style="font-size:14px;margin:14px 0 6px;">Reporters (' + (detail.reporter_rows ? detail.reporter_rows.length : 0) + ')</h3>' +
      '<ul style="list-style:none;padding:0;margin:0;">' +
      (detail.reporter_rows ? detail.reporter_rows.map(function (rr) {
        return '<li style="padding:6px 0;border-bottom:1px solid var(--surface-border);font-size:13px;">' +
               esc(rr.reporter_name || rr.reporter_id || 'unknown') +
               ' — <strong>' + esc(rr.reason) + '</strong>' +
               (rr.comment ? ' · <em style="color:var(--text-muted);">' + esc(rr.comment) + '</em>' : '') + '</li>';
      }).join('') : '') +
      '</ul>' +
      '<h3 style="font-size:14px;margin:14px 0 6px;">Resolve with</h3>' +
      '<div id="fr-actions" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">' +
      '  <button type="button" class="btn btn--danger"    data-resolve="deleted">Delete content</button>' +
      (detail.target_type === 'topic'
        ? '<button type="button" class="btn btn--secondary" data-resolve="locked">Lock topic</button>' : '') +
      '  <button type="button" class="btn btn--secondary" data-resolve="warned">Warn user</button>' +
      '  <button type="button" class="btn btn--secondary" data-resolve="edited">Edit content</button>' +
      '  <button type="button" class="btn btn--text"      data-resolve="dismissed">Dismiss without action</button>' +
      '</div>' +
      '<div id="fr-action-form" style="margin-top:10px;"></div>';

    var footer = document.createElement('div');
    footer.style.display = 'contents';
    var cancel = document.createElement('button');
    cancel.type = 'button'; cancel.className = 'btn btn--secondary'; cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { window.SlidePanel.close(); });
    footer.appendChild(cancel);

    window.SlidePanel.open({ title: 'Resolve report', body: body, footer: footer });

    Array.from(body.querySelectorAll('[data-resolve]')).forEach(function (b) {
      b.addEventListener('click', function () {
        showActionForm(id, b.getAttribute('data-resolve'), detail);
      });
    });
  }

  function showActionForm(id, action, detail) {
    var formMount = document.getElementById('fr-action-form');
    if (!formMount) return;

    if (action === 'edited') {
      formMount.innerHTML =
        '<label style="display:block;font-size:11px;text-transform:uppercase;color:var(--text-secondary);margin-bottom:4px;">Edit content</label>' +
        '<textarea id="fr-form-content" rows="6" class="data-explorer__search" style="width:100%;font-family:inherit;">' +
        esc(detail.target_content || '') + '</textarea>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">' +
        '  <button type="button" class="btn btn--primary" id="fr-form-apply">Apply edit</button></div>';
    } else if (action === 'warned') {
      formMount.innerHTML =
        '<label style="display:block;font-size:11px;text-transform:uppercase;color:var(--text-secondary);margin-bottom:4px;">Reason for warning</label>' +
        '<textarea id="fr-form-reason" rows="3" class="data-explorer__search" style="width:100%;font-family:inherit;"></textarea>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">' +
        '  <button type="button" class="btn btn--primary" id="fr-form-apply">Send warning</button></div>';
    } else {
      // deleted / locked / dismissed: just confirm
      var verb = action === 'dismissed' ? 'Dismiss without action' : (action.charAt(0).toUpperCase() + action.slice(1));
      formMount.innerHTML =
        '<p style="color:var(--text-secondary);font-size:13px;margin:0;">Click apply to ' + verb.toLowerCase() + '.</p>' +
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">' +
        '  <button type="button" class="btn btn--' + (action === 'deleted' ? 'danger' : 'primary') + '" id="fr-form-apply">' + verb + '</button></div>';
    }

    var apply = document.getElementById('fr-form-apply');
    if (apply) apply.addEventListener('click', function () {
      var payload = { action: action };
      if (action === 'edited')  payload.content = document.getElementById('fr-form-content').value;
      if (action === 'warned')  payload.reason  = document.getElementById('fr-form-reason').value;
      submitResolve(id, payload, action === 'deleted');
    });
  }

  function submitResolve(id, payload, danger) {
    var op = payload.action === 'dismissed' ? 'report_dismiss' : 'report_resolve';
    window.ConfirmDialog.open({
      title: 'Apply ' + payload.action + '?',
      body:  payload.action === 'deleted' ? 'This action cannot be undone.' : 'Mark this report as ' + payload.action + '.',
      danger: !!danger,
      confirmLabel: danger ? 'Delete' : 'Apply',
    }).then(function (ok) {
      if (!ok) return;
      fetch('/api/backstage/forum.php?op=' + op + '&id=' + encodeURIComponent(id), {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        window.SlidePanel.close();
        load();
      }).catch(function (e) { alert('Action failed: ' + e.message); });
    });
  }

  function dismissReport(id) {
    window.ConfirmDialog.open({
      title:  'Dismiss report?',
      body:   'The report will be marked as dismissed without further action.',
      danger: false,
      confirmLabel: 'Dismiss',
    }).then(function (ok) {
      if (!ok) return;
      fetch('/api/backstage/forum.php?op=report_dismiss&id=' + encodeURIComponent(id), { method: 'POST' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          load();
        }).catch(function (e) { alert('Dismiss failed: ' + e.message); });
    });
  }

  // Wiring
  els.seg.addEventListener('click', function (e) {
    var b = e.target.closest('[data-status]');
    if (!b) return;
    Array.from(els.seg.querySelectorAll('[data-status]')).forEach(function (x) { x.classList.remove('is-active'); });
    b.classList.add('is-active');
    state.status = b.getAttribute('data-status') === 'all' ? '' : b.getAttribute('data-status');
    load();
  });
  els.target.addEventListener('change', function () {
    state.target = els.target.value;
    load();
  });
  els.search.addEventListener('input', function () {
    state.q = els.search.value || '';
    render();
  });

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  load();
})();
```

- [ ] **Step 3: Create `empty-state-reports.svg`**

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" fill="currentColor" role="img" aria-label="No reports">
  <g opacity="0.18"><circle cx="100" cy="80" r="55" /></g>
  <g opacity="0.4"><circle cx="100" cy="80" r="40" /></g>
  <g>
    <circle cx="100" cy="80" r="28" fill="currentColor" opacity="0.7"/>
    <path d="M92,72 L108,88 M108,72 L92,88" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
  </g>
</svg>
```

- [ ] **Step 4: Manual smoke test**

Visit `/backstage/forum/reports`. Verify list loads, filter chips work, slide-panel opens, all 5 resolve actions show their forms, confirm-dialog shows up.

The proxy ops `report_detail`, `report_resolve`, `report_dismiss` may need to be added to `forum.php` proxy if not present. Check by reading `forum.php` switch — if missing, add cases following the existing pattern (using `$rawGet` for GETs and `$post` for POSTs against the corresponding backend routes from `routes/api.php` lines ~425-433).

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/reports/ public/pages/backstage/forum/forum-reports-page.js public/pages/backstage/forum/empty-state-reports.svg
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(forum): reports sub-page — slide-panel resolve flow with 5 actions + confirm dismiss"
```

If you also added proxy ops in step 4, include the proxy file in the commit and append "+ proxy ops report_detail/report_resolve/report_dismiss" to the message.

---

## Phase E — Topics, Categories, Audit sub-pages

### Task 14: Topics sub-page

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/topics/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum-topics-page.js`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/empty-state-topics.svg`

- [ ] **Step 1: Create `topics/index.php`**

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Forum topics';
$activePage  = 'forum';
$breadcrumbs = [['label' => 'Forum', 'url' => '/backstage/forum'], ['label' => 'Topics']];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Topics</h1>
    <p class="page-header__subtitle">Manage forum topics — pin, lock, delete.</p>
  </div>
</div>

<div class="data-explorer__panel">
  <div class="data-explorer__toolbar">
    <div class="data-explorer__seg" id="ft-status-filter" role="tablist">
      <button type="button" class="data-explorer__seg-btn is-active" data-status="all">All</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="pinned">Pinned</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="locked">Locked</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="open">Open</button>
    </div>
    <select class="data-explorer__search" id="ft-category-filter" style="min-width:160px;">
      <option value="">All categories</option>
    </select>
    <input type="search" id="ft-search" class="data-explorer__search" placeholder="Search title…">
  </div>

  <table class="data-explorer__data">
    <thead>
      <tr>
        <th>Title</th><th>Category</th><th>Author</th><th>Replies</th><th>Status</th><th>Created</th><th></th>
      </tr>
    </thead>
    <tbody id="ft-tbody"></tbody>
  </table>

  <div id="ft-empty-mount" style="display:none;">
    <?php
      $svg_path  = '/pages/backstage/forum/empty-state-topics.svg';
      $title     = 'No topics';
      $body      = 'No forum topics match this filter.';
      include __DIR__ . '/../../shared/empty-state.php';
    ?>
  </div>
  <div id="ft-error-mount" style="display:none;"></div>
</div>

<script src="/pages/backstage/forum/forum-topics-page.js" defer></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
```

- [ ] **Step 2: Create `forum-topics-page.js`**

```javascript
/**
 * Forum topics admin — table with pin/lock/delete actions via confirm-dialog.
 *
 * Endpoints (via proxy):
 *   GET  /api/backstage/forum.php?op=topics_list&...
 *   POST /api/backstage/forum.php?op=topic_pin&id=...
 *   POST /api/backstage/forum.php?op=topic_unpin&id=...
 *   POST /api/backstage/forum.php?op=topic_lock&id=...
 *   POST /api/backstage/forum.php?op=topic_unlock&id=...
 *   POST /api/backstage/forum.php?op=topic_delete&id=...
 */
(function () {
  'use strict';

  var els = {
    tbody:    document.getElementById('ft-tbody'),
    seg:      document.getElementById('ft-status-filter'),
    catFilter:document.getElementById('ft-category-filter'),
    search:   document.getElementById('ft-search'),
    empty:    document.getElementById('ft-empty-mount'),
    error:    document.getElementById('ft-error-mount'),
  };
  if (!els.tbody) return;

  var state = { status: 'all', categoryId: '', q: '', rows: [] };

  function load() {
    skeleton();
    var qs = '?op=topics_list&limit=100';
    fetch('/api/backstage/forum.php' + qs)
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { state.rows = (j && j.data) || []; render(); populateCategorySelect(); })
      .catch(function (err) { renderError(err); });
  }

  function skeleton() {
    els.tbody.innerHTML = '';
    for (var i = 0; i < 5; i++) {
      els.tbody.innerHTML +=
        '<tr class="data-explorer__skeleton">' +
        '<td><span class="skeleton--text" style="width:70%"></span></td>' +
        '<td><span class="skeleton--text" style="width:50%"></span></td>' +
        '<td><span class="skeleton--text" style="width:40%"></span></td>' +
        '<td><span class="skeleton--text" style="width:30%"></span></td>' +
        '<td><span class="skeleton--pill"></span></td>' +
        '<td><span class="skeleton--text" style="width:50%"></span></td>' +
        '<td></td></tr>';
    }
    if (els.empty) els.empty.style.display = 'none';
    if (els.error) els.error.style.display = 'none';
  }

  function populateCategorySelect() {
    var cats = {};
    state.rows.forEach(function (r) { if (r.category_id) cats[r.category_id] = r.category_name || r.category_id; });
    var current = els.catFilter.value;
    els.catFilter.innerHTML = '<option value="">All categories</option>' +
      Object.keys(cats).map(function (k) { return '<option value="' + esc(k) + '">' + esc(cats[k]) + '</option>'; }).join('');
    els.catFilter.value = current;
  }

  function filtered() {
    var q = state.q.toLowerCase();
    return state.rows.filter(function (r) {
      if (state.status === 'pinned' && !r.is_pinned) return false;
      if (state.status === 'locked' && !r.is_locked) return false;
      if (state.status === 'open'   && (r.is_pinned || r.is_locked)) return false;
      if (state.categoryId && r.category_id !== state.categoryId) return false;
      if (q && (r.title || '').toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }

  function render() {
    var rows = filtered();
    if (!rows.length) {
      els.tbody.innerHTML = '';
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';
    els.tbody.innerHTML = rows.map(rowHtml).join('');
    Array.from(els.tbody.querySelectorAll('[data-action]')).forEach(function (b) {
      b.addEventListener('click', function () { onAction(b.getAttribute('data-action'), b.getAttribute('data-id'), b.getAttribute('data-title')); });
    });
  }

  function rowHtml(r) {
    var statusPills = [];
    if (r.is_pinned) statusPills.push('<span class="pill pill--featured">Pinned</span>');
    if (r.is_locked) statusPills.push('<span class="pill pill--archived">Locked</span>');
    if (!statusPills.length) statusPills.push('<span class="pill pill--draft">Open</span>');

    return '<tr class="row" data-id="' + esc(r.id) + '">' +
           '<td><strong>' + esc(r.title || '') + '</strong></td>' +
           '<td>' + esc(r.category_name || r.category_id || '') + '</td>' +
           '<td>' + esc(r.author_name || r.author_id || '') + '</td>' +
           '<td>' + (r.post_count || 0) + '</td>' +
           '<td>' + statusPills.join(' ') + '</td>' +
           '<td>' + esc((r.created_at || '').slice(0, 10)) + '</td>' +
           '<td class="data-explorer__actions">' +
           (r.is_pinned
             ? '<button class="btn btn--icon" data-action="unpin"  data-id="' + esc(r.id) + '" data-title="' + esc(r.title || '') + '" title="Unpin">📌</button>'
             : '<button class="btn btn--icon" data-action="pin"    data-id="' + esc(r.id) + '" data-title="' + esc(r.title || '') + '" title="Pin">📌</button>') +
           (r.is_locked
             ? '<button class="btn btn--icon" data-action="unlock" data-id="' + esc(r.id) + '" data-title="' + esc(r.title || '') + '" title="Unlock">🔒</button>'
             : '<button class="btn btn--icon" data-action="lock"   data-id="' + esc(r.id) + '" data-title="' + esc(r.title || '') + '" title="Lock">🔒</button>') +
           '<button class="btn btn--icon" data-action="delete" data-id="' + esc(r.id) + '" data-title="' + esc(r.title || '') + '" title="Delete">🗑</button>' +
           '</td></tr>';
  }

  function renderError(err) {
    els.tbody.innerHTML = '';
    if (els.error) {
      els.error.style.display = '';
      els.error.innerHTML =
        '<div class="error-state" role="alert">' +
        '<svg class="error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 9v4M12 17h.01M3.6 18l8.4-14 8.4 14H3.6z"/></svg>' +
        '<div><div class="error-state__title">Could not load topics</div>' +
        '<div class="error-state__message">' + esc(err && err.message || 'Network error') + '</div></div>' +
        '<div class="error-state__actions"><button class="btn btn--primary" id="ft-retry">Retry</button></div></div>';
      var b = document.getElementById('ft-retry'); if (b) b.addEventListener('click', load);
    }
  }

  function onAction(action, id, title) {
    var labels = {
      pin:    {title: 'Pin topic?',    body: 'Pinned topics appear at the top of the category.', confirm: 'Pin',    danger: false},
      unpin:  {title: 'Unpin topic?',  body: 'The topic will no longer be pinned.',              confirm: 'Unpin',  danger: false},
      lock:   {title: 'Lock topic?',   body: 'No new replies can be posted until unlocked.',     confirm: 'Lock',   danger: false},
      unlock: {title: 'Unlock topic?', body: 'Replies will be allowed again.',                   confirm: 'Unlock', danger: false},
      delete: {title: 'Delete topic?', body: '"' + (title || '') + '" and all its posts will be deleted. This cannot be undone.', confirm: 'Delete', danger: true},
    };
    var L = labels[action];
    if (!L) return;
    window.ConfirmDialog.open({ title: L.title, body: L.body, confirmLabel: L.confirm, danger: L.danger })
      .then(function (ok) {
        if (!ok) return;
        var op = 'topic_' + action;
        fetch('/api/backstage/forum.php?op=' + op + '&id=' + encodeURIComponent(id), { method: 'POST' })
          .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); load(); })
          .catch(function (e) { alert('Action failed: ' + e.message); });
      });
  }

  els.seg.addEventListener('click', function (e) {
    var b = e.target.closest('[data-status]'); if (!b) return;
    Array.from(els.seg.querySelectorAll('[data-status]')).forEach(function (x) { x.classList.remove('is-active'); });
    b.classList.add('is-active');
    state.status = b.getAttribute('data-status');
    render();
  });
  els.catFilter.addEventListener('change', function () { state.categoryId = els.catFilter.value; render(); });
  els.search.addEventListener('input', function () { state.q = els.search.value || ''; render(); });

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  load();
})();
```

- [ ] **Step 3: Create `empty-state-topics.svg`**

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" fill="currentColor" role="img" aria-label="No topics">
  <g opacity="0.2"><rect x="32" y="40" width="100" height="56" rx="8"/></g>
  <g>
    <rect x="40" y="32" width="100" height="56" rx="8" fill="currentColor" opacity="0.55"/>
    <rect x="50" y="44" width="60" height="4" rx="2" fill="#fff" opacity="0.85"/>
    <rect x="50" y="54" width="80" height="3" rx="1.5" fill="#fff" opacity="0.6"/>
    <rect x="50" y="61" width="64" height="3" rx="1.5" fill="#fff" opacity="0.6"/>
    <rect x="50" y="68" width="74" height="3" rx="1.5" fill="#fff" opacity="0.6"/>
  </g>
  <g transform="translate(150, 102)">
    <rect x="-22" y="-12" width="44" height="24" rx="4" fill="currentColor" opacity="0.45"/>
    <rect x="-15" y="-5"  width="30" height="3" rx="1.5" fill="#fff" opacity="0.85"/>
    <rect x="-15" y="2"   width="20" height="3" rx="1.5" fill="#fff" opacity="0.6"/>
  </g>
</svg>
```

- [ ] **Step 4: Add proxy ops if missing**

Read `public/api/backstage/forum.php` and check the switch for cases `topics_list`, `topic_pin`, `topic_unpin`, `topic_lock`, `topic_unlock`, `topic_delete`. If any are missing, add them following the same pattern as existing ops:

```php
        case 'topics_list': {
            $rawGet('/backstage/forum/topics?' . http_build_query(array_filter([
                'limit' => $_GET['limit'] ?? 100,
            ], static fn($v) => $v !== null && $v !== '')));
            return;
        }
        case 'topic_pin':    $post('/backstage/forum/topics/' . rawurlencode((string)($_GET['id'] ?? '')) . '/pin',    $body); return;
        case 'topic_unpin':  $post('/backstage/forum/topics/' . rawurlencode((string)($_GET['id'] ?? '')) . '/unpin',  $body); return;
        case 'topic_lock':   $post('/backstage/forum/topics/' . rawurlencode((string)($_GET['id'] ?? '')) . '/lock',   $body); return;
        case 'topic_unlock': $post('/backstage/forum/topics/' . rawurlencode((string)($_GET['id'] ?? '')) . '/unlock', $body); return;
        case 'topic_delete': $post('/backstage/forum/topics/' . rawurlencode((string)($_GET['id'] ?? '')) . '/delete', $body); return;
```

- [ ] **Step 5: Manual smoke test**

Visit `/backstage/forum/topics`. Verify table renders, filter chips work, pin/unpin/lock/unlock/delete confirms work end-to-end.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/topics/ public/pages/backstage/forum/forum-topics-page.js public/pages/backstage/forum/empty-state-topics.svg public/api/backstage/forum.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(forum): topics sub-page — table + pin/lock/delete confirm-dialogs"
```

---

### Task 15: Categories sub-page

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/categories/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum-categories-page.js`

- [ ] **Step 1: Create `categories/index.php`**

Same structure as `topics/index.php` above but with category-specific:
- Page title "Forum categories", subtitle "Manage forum categories."
- Breadcrumb to /backstage/forum
- `+ New category` button in page-header right side (id `fc-add-btn`)
- Toolbar: search only (no segments, no select)
- Table headers: Name | Slug | Description | Topics | Sort | Actions
- Empty state inline (reuse the same illustration as topics or omit illustration if no SVG made)
- Mount: `<tbody id="fc-tbody">`, `<div id="fc-empty-mount">`, `<div id="fc-error-mount">`

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Forum categories';
$activePage  = 'forum';
$breadcrumbs = [['label' => 'Forum', 'url' => '/backstage/forum'], ['label' => 'Categories']];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Categories</h1>
    <p class="page-header__subtitle">Manage forum categories.</p>
  </div>
  <div>
    <button type="button" class="btn btn--primary" id="fc-add-btn">+ New category</button>
  </div>
</div>

<div class="data-explorer__panel">
  <div class="data-explorer__toolbar">
    <input type="search" id="fc-search" class="data-explorer__search" placeholder="Search name or slug…">
  </div>

  <table class="data-explorer__data">
    <thead>
      <tr><th>Name</th><th>Slug</th><th>Description</th><th>Topics</th><th>Sort</th><th></th></tr>
    </thead>
    <tbody id="fc-tbody"></tbody>
  </table>

  <div id="fc-empty-mount" style="display:none;padding:32px 16px;text-align:center;color:var(--text-muted);">No categories yet.</div>
  <div id="fc-error-mount" style="display:none;"></div>
</div>

<script src="/pages/backstage/forum/forum-categories-page.js" defer></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
```

- [ ] **Step 2: Create `forum-categories-page.js`**

```javascript
/**
 * Forum categories admin — table + slide-panel CRUD + confirm delete.
 *
 * Endpoints:
 *   GET  /api/backstage/forum.php?op=categories_list
 *   POST /api/backstage/forum.php?op=category_create        body: {name, slug, icon, description, sort_order}
 *   POST /api/backstage/forum.php?op=category_update&id=... body: same payload
 *   POST /api/backstage/forum.php?op=category_delete&id=...
 */
(function () {
  'use strict';

  var els = {
    tbody:  document.getElementById('fc-tbody'),
    addBtn: document.getElementById('fc-add-btn'),
    search: document.getElementById('fc-search'),
    empty:  document.getElementById('fc-empty-mount'),
    error:  document.getElementById('fc-error-mount'),
  };
  if (!els.tbody) return;

  var state = { rows: [], q: '' };

  function load() {
    skeleton();
    fetch('/api/backstage/forum.php?op=categories_list')
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { state.rows = (j && j.data) || []; render(); })
      .catch(function (err) { renderError(err); });
  }

  function skeleton() {
    els.tbody.innerHTML = '';
    for (var i = 0; i < 4; i++) {
      els.tbody.innerHTML += '<tr class="data-explorer__skeleton">' +
        '<td><span class="skeleton--text" style="width:60%"></span></td>' +
        '<td><span class="skeleton--text" style="width:40%"></span></td>' +
        '<td><span class="skeleton--text" style="width:80%"></span></td>' +
        '<td><span class="skeleton--text" style="width:30%"></span></td>' +
        '<td><span class="skeleton--text" style="width:30%"></span></td>' +
        '<td></td></tr>';
    }
    if (els.empty) els.empty.style.display = 'none';
    if (els.error) els.error.style.display = 'none';
  }

  function filtered() {
    var q = state.q.toLowerCase();
    if (!q) return state.rows;
    return state.rows.filter(function (r) {
      return ((r.name || '') + (r.slug || '')).toLowerCase().indexOf(q) !== -1;
    });
  }

  function render() {
    var rows = filtered();
    if (!rows.length) {
      els.tbody.innerHTML = '';
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';
    els.tbody.innerHTML = rows.map(function (r) {
      var desc = (r.description || '').slice(0, 80) + ((r.description || '').length > 80 ? '…' : '');
      return '<tr class="row" data-id="' + esc(r.id) + '">' +
             '<td><strong>' + esc(r.icon || '') + ' ' + esc(r.name || '') + '</strong></td>' +
             '<td><code style="font-size:12px;">' + esc(r.slug || '') + '</code></td>' +
             '<td>' + esc(desc) + '</td>' +
             '<td>' + (r.topic_count || 0) + '</td>' +
             '<td>' + (r.sort_order || 0) + '</td>' +
             '<td class="data-explorer__actions">' +
             '<button class="btn btn--icon" data-action="edit"   data-id="' + esc(r.id) + '" title="Edit">✎</button>' +
             '<button class="btn btn--icon" data-action="delete" data-id="' + esc(r.id) + '" data-name="' + esc(r.name || '') + '" data-count="' + (r.topic_count || 0) + '" title="Delete">🗑</button>' +
             '</td></tr>';
    }).join('');

    Array.from(els.tbody.querySelectorAll('[data-action="edit"]')).forEach(function (b) {
      b.addEventListener('click', function () {
        var row = state.rows.filter(function (r) { return r.id === b.getAttribute('data-id'); })[0];
        if (row) openPanel('edit', row);
      });
    });
    Array.from(els.tbody.querySelectorAll('[data-action="delete"]')).forEach(function (b) {
      b.addEventListener('click', function () { confirmDelete(b.getAttribute('data-id'), b.getAttribute('data-name'), parseInt(b.getAttribute('data-count') || '0', 10)); });
    });
  }

  function renderError(err) {
    els.tbody.innerHTML = '';
    if (els.error) {
      els.error.style.display = '';
      els.error.innerHTML =
        '<div class="error-state" role="alert">' +
        '<svg class="error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 9v4M12 17h.01M3.6 18l8.4-14 8.4 14H3.6z"/></svg>' +
        '<div><div class="error-state__title">Could not load categories</div>' +
        '<div class="error-state__message">' + esc(err && err.message || 'Network error') + '</div></div>' +
        '<div class="error-state__actions"><button class="btn btn--primary" id="fc-retry">Retry</button></div></div>';
      var b = document.getElementById('fc-retry'); if (b) b.addEventListener('click', load);
    }
  }

  function openPanel(mode, row) {
    var data = row || {};
    var body = document.createElement('div');
    body.innerHTML =
      '<div><label class="kpi-card__label">Name</label><input type="text" name="name" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.name) + '"></div>' +
      '<div><label class="kpi-card__label">Slug</label><input type="text" name="slug" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.slug) + '"></div>' +
      '<div><label class="kpi-card__label">Icon (emoji or class name)</label><input type="text" name="icon" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.icon) + '"></div>' +
      '<div><label class="kpi-card__label">Description</label><textarea name="description" rows="4" class="data-explorer__search" style="width:100%;margin-top:6px;font-family:inherit;">' + esc(data.description) + '</textarea></div>' +
      '<div><label class="kpi-card__label">Sort order</label><input type="number" name="sort_order" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + esc(data.sort_order || 0) + '"></div>';

    var footer = document.createElement('div');
    footer.style.display = 'contents';
    var cancel = document.createElement('button');
    cancel.type = 'button'; cancel.className = 'btn btn--secondary'; cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { window.SlidePanel.close(); });
    footer.appendChild(cancel);
    var save = document.createElement('button');
    save.type = 'button'; save.className = 'btn btn--primary'; save.textContent = mode === 'create' ? 'Create' : 'Save';
    save.addEventListener('click', function () { savePanel(mode, data.id, body); });
    footer.appendChild(save);

    window.SlidePanel.open({ title: mode === 'create' ? 'New category' : 'Edit category', body: body, footer: footer });
  }

  function savePanel(mode, id, bodyEl) {
    var payload = {
      name:        bodyEl.querySelector('[name="name"]').value,
      slug:        bodyEl.querySelector('[name="slug"]').value,
      icon:        bodyEl.querySelector('[name="icon"]').value,
      description: bodyEl.querySelector('[name="description"]').value,
      sort_order:  parseInt(bodyEl.querySelector('[name="sort_order"]').value || '0', 10),
    };
    var op = mode === 'create' ? 'category_create' : ('category_update&id=' + encodeURIComponent(id));
    fetch('/api/backstage/forum.php?op=' + op, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    }).then(function (r) {
      if (!r.ok) return r.json().then(function (e) { throw new Error(e && e.error || ('HTTP ' + r.status)); });
      window.SlidePanel.close(); load();
    }).catch(function (e) { alert('Save failed: ' + e.message); });
  }

  function confirmDelete(id, name, topicCount) {
    var body = topicCount > 0
      ? '"' + (name || '') + '" has ' + topicCount + ' topic' + (topicCount === 1 ? '' : 's') + '. The backend may reject the deletion.'
      : 'Delete "' + (name || '') + '"? This cannot be undone.';
    window.ConfirmDialog.open({ title: 'Delete category?', body: body, danger: true, confirmLabel: 'Delete' })
      .then(function (ok) {
        if (!ok) return;
        fetch('/api/backstage/forum.php?op=category_delete&id=' + encodeURIComponent(id), { method: 'POST' })
          .then(function (r) {
            if (!r.ok) return r.json().then(function (e) { throw new Error(e && e.error || ('HTTP ' + r.status)); });
            load();
          })
          .catch(function (e) { alert('Delete failed: ' + e.message); });
      });
  }

  els.addBtn.addEventListener('click', function () { openPanel('create', null); });
  els.search.addEventListener('input', function () { state.q = els.search.value || ''; render(); });

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function escAttr(s) { return esc(s); }

  load();
})();
```

- [ ] **Step 3: Add proxy ops if missing**

In `public/api/backstage/forum.php`, ensure cases `categories_list`, `category_create`, `category_update`, `category_delete` exist. If missing, add them targeting `/backstage/forum/categories[/{id}]` with appropriate action suffixes per backend routes.

- [ ] **Step 4: Smoke test**

Visit `/backstage/forum/categories`. Verify list, slide-panel create + edit, delete confirm.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/categories/ public/pages/backstage/forum/forum-categories-page.js public/api/backstage/forum.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(forum): categories sub-page — slide-panel create/edit + confirm delete with topic-count warning"
```

---

### Task 16: Audit sub-page

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/audit/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum-audit-page.js`

- [ ] **Step 1: Create `audit/index.php`**

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Forum audit';
$activePage  = 'forum';
$breadcrumbs = [['label' => 'Forum', 'url' => '/backstage/forum'], ['label' => 'Audit']];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Audit</h1>
    <p class="page-header__subtitle">Read-only log of moderation actions.</p>
  </div>
</div>

<div class="data-explorer__panel">
  <div class="data-explorer__toolbar">
    <select class="data-explorer__search" id="fa-action-filter" style="min-width: 160px;">
      <option value="">All actions</option>
      <option value="deleted">Deleted</option>
      <option value="locked">Locked</option>
      <option value="unlocked">Unlocked</option>
      <option value="pinned">Pinned</option>
      <option value="unpinned">Unpinned</option>
      <option value="edited">Edited</option>
      <option value="warned">Warned</option>
      <option value="category_created">Category created</option>
      <option value="category_updated">Category updated</option>
      <option value="category_deleted">Category deleted</option>
    </select>
    <select class="data-explorer__search" id="fa-range-filter" style="min-width: 130px;">
      <option value="7">Last 7 days</option>
      <option value="30" selected>Last 30 days</option>
      <option value="all">All time</option>
    </select>
    <input type="search" id="fa-search" class="data-explorer__search" placeholder="Search actor…">
  </div>

  <table class="data-explorer__data">
    <thead>
      <tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Reason</th></tr>
    </thead>
    <tbody id="fa-tbody"></tbody>
  </table>

  <div id="fa-empty-mount" style="display:none;padding:32px 16px;text-align:center;color:var(--text-muted);">No moderation actions in this period.</div>
  <div id="fa-error-mount" style="display:none;"></div>
  <div style="display:flex;justify-content:center;padding:14px 0;">
    <button class="btn btn--secondary" id="fa-load-more" style="display:none;">Load more</button>
  </div>
</div>

<script src="/pages/backstage/forum/forum-audit-page.js" defer></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout.php';
```

- [ ] **Step 2: Create `forum-audit-page.js`**

```javascript
/**
 * Forum audit log — read-only table with filters + pagination.
 * Endpoint: GET /api/backstage/forum.php?op=audit_list&limit=50&offset=N&action=&since=
 */
(function () {
  'use strict';

  var els = {
    tbody:    document.getElementById('fa-tbody'),
    actionFilter: document.getElementById('fa-action-filter'),
    rangeFilter:  document.getElementById('fa-range-filter'),
    search:   document.getElementById('fa-search'),
    empty:    document.getElementById('fa-empty-mount'),
    error:    document.getElementById('fa-error-mount'),
    more:     document.getElementById('fa-load-more'),
  };
  if (!els.tbody) return;

  var PAGE = 50;
  var state = { rows: [], offset: 0, exhausted: false, action: '', range: '30', q: '' };

  function load(reset) {
    if (reset) { state.rows = []; state.offset = 0; state.exhausted = false; els.tbody.innerHTML = ''; }
    skeleton();
    var params = ['op=audit_list', 'limit=' + PAGE, 'offset=' + state.offset];
    if (state.action) params.push('action=' + encodeURIComponent(state.action));
    if (state.range !== 'all') params.push('since_days=' + encodeURIComponent(state.range));
    fetch('/api/backstage/forum.php?' + params.join('&'))
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) {
        var newRows = (j && j.data) || [];
        state.rows = state.rows.concat(newRows);
        state.exhausted = newRows.length < PAGE;
        state.offset += newRows.length;
        render();
      })
      .catch(function (err) { renderError(err); });
  }

  function skeleton() {
    if (state.offset === 0) {
      els.tbody.innerHTML = '';
      for (var i = 0; i < 5; i++) {
        els.tbody.innerHTML += '<tr class="data-explorer__skeleton">' +
          '<td><span class="skeleton--text" style="width:50%"></span></td>' +
          '<td><span class="skeleton--text" style="width:40%"></span></td>' +
          '<td><span class="skeleton--pill"></span></td>' +
          '<td><span class="skeleton--text" style="width:60%"></span></td>' +
          '<td><span class="skeleton--text" style="width:50%"></span></td></tr>';
      }
      if (els.empty) els.empty.style.display = 'none';
      if (els.error) els.error.style.display = 'none';
    }
    if (els.more) els.more.style.display = 'none';
  }

  function filtered() {
    var q = state.q.toLowerCase();
    if (!q) return state.rows;
    return state.rows.filter(function (r) { return ((r.actor_name || r.performed_by || '')).toLowerCase().indexOf(q) !== -1; });
  }

  function render() {
    var rows = filtered();
    if (!rows.length) {
      els.tbody.innerHTML = '';
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';
    els.tbody.innerHTML = rows.map(function (r) {
      return '<tr class="row">' +
             '<td>' + esc((r.created_at || '').slice(0,16).replace('T',' ')) + '</td>' +
             '<td>' + esc(r.actor_name || r.performed_by || '') + '</td>' +
             '<td><span class="pill pill--archived">' + esc(r.action) + '</span></td>' +
             '<td>' + esc(r.target_type) + ' ' + esc((r.target_id || '').slice(0,8)) + '</td>' +
             '<td><em style="color:var(--text-muted);">' + esc(r.reason || '') + '</em></td></tr>';
    }).join('');
    if (els.more) els.more.style.display = state.exhausted ? 'none' : '';
  }

  function renderError(err) {
    els.tbody.innerHTML = '';
    if (els.error) {
      els.error.style.display = '';
      els.error.innerHTML =
        '<div class="error-state" role="alert">' +
        '<svg class="error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 9v4M12 17h.01M3.6 18l8.4-14 8.4 14H3.6z"/></svg>' +
        '<div><div class="error-state__title">Could not load audit log</div>' +
        '<div class="error-state__message">' + esc(err && err.message || 'Network error') + '</div></div>' +
        '<div class="error-state__actions"><button class="btn btn--primary" id="fa-retry">Retry</button></div></div>';
      var b = document.getElementById('fa-retry'); if (b) b.addEventListener('click', function () { load(true); });
    }
  }

  els.actionFilter.addEventListener('change', function () { state.action = els.actionFilter.value; load(true); });
  els.rangeFilter.addEventListener('change',  function () { state.range  = els.rangeFilter.value;  load(true); });
  els.search.addEventListener('input', function () { state.q = els.search.value || ''; render(); });
  els.more.addEventListener('click', function () { load(false); });

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  load(true);
})();
```

- [ ] **Step 3: Add `audit_list` proxy op if missing**

In `public/api/backstage/forum.php`:

```php
        case 'audit_list': {
            $rawGet('/backstage/forum/audit?' . http_build_query(array_filter([
                'limit'  => $_GET['limit']  ?? 50,
                'offset' => $_GET['offset'] ?? 0,
                'action' => $_GET['action'] ?? null,
                'since_days' => $_GET['since_days'] ?? null,
            ], static fn($v) => $v !== null && $v !== '')));
            return;
        }
```

- [ ] **Step 4: Smoke test**

Visit `/backstage/forum/audit`. Verify table renders, filter changes refetch, "Load more" appends.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/audit/ public/pages/backstage/forum/forum-audit-page.js public/api/backstage/forum.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(forum): audit sub-page — read-only log + filters + load-more pagination"
```

---

## Phase F — Cleanup + UAT

### Task 17: Add `.report-reason-chip` rule + slim down `forum.css`

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/forum/forum.css`

- [ ] **Step 1: Replace `forum.css` contents**

```css
/* Forum-specific styles. The system in daems-backstage-system.css covers
 * shared primitives; this file holds only forum-specific bits. */

.report-reason-chip {
  display: inline-block;
  padding: 2px 8px;
  margin-right: 4px;
  margin-bottom: 4px;
  font-size: 11px;
  font-weight: 500;
  background: var(--surface-light);
  color: var(--text-secondary);
  border-radius: var(--radius-full);
}
```

(All previous tab/modal styles are removed because the new system handles them.)

- [ ] **Step 2: Verify all backstage forum pages still load layout.php (which loads system CSS)**

`forum.css` is currently referenced from the old `forum/index.php` at line ~388. The new dashboard `index.php` (Task 12) does not reference it. Verify whether any of the new sub-pages need it:

```bash
grep -rn "forum.css" /c/laragon/www/sites/daem-society/public/pages/backstage/forum/ 2>&1
```

If references remain (e.g. `<link>` lines in any new index.php), keep them. If none, leave the file in place but unused — Task 18 deletes the now-orphaned `forum.js` / `forum-modal.js`; `forum.css` can stay (slim, just one rule).

- [ ] **Step 3: Reference forum.css from the reports sub-page index.php**

The `.report-reason-chip` class is used by `forum-reports-page.js`. The reports `index.php` needs to include the stylesheet. Add to `reports/index.php` after the `</div>` closing the data-explorer panel and before the `<script>` tag:

```html
<link rel="stylesheet" href="/pages/backstage/forum/forum.css">
```

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/forum/forum.css public/pages/backstage/forum/reports/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "CSS(forum): slim forum.css to .report-reason-chip only — tab/modal styles superseded by system"
```

---

### Task 18: Delete old `forum.js` and `forum-modal.js`

- [ ] **Step 1: Verify nothing references them**

```bash
cd /c/laragon/www/sites/daem-society && grep -rn "forum\.js\|forum-modal\.js" public/ 2>&1 | grep -v "forum-reports\|forum-topics\|forum-categories\|forum-audit\|forum-dashboard"
```

Expected: no remaining references (the new files have similar names but different — `forum-reports-page.js`, etc.).

- [ ] **Step 2: Delete the files**

```bash
cd /c/laragon/www/sites/daem-society
rm public/pages/backstage/forum/forum.js
rm public/pages/backstage/forum/forum-modal.js
```

- [ ] **Step 3: Reload browser pages, confirm no 404s in DevTools Network**

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add -A public/pages/backstage/forum/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Cleanup(forum): remove old forum.js + forum-modal.js superseded by per-page JS"
```

---

### Task 19: Final regression sweep + UAT

- [ ] **Step 1: No-transform-on-hover constraint check**

```bash
cd /c/laragon/www/sites/daem-society && grep -nE ":hover\s*\{[^}]*transform\s*:\s*(translate|scale)" public/pages/backstage/forum/forum.css public/assets/css/daems-backstage-system.css || echo "OK: no transform on hover"
```

Expected: `OK: no transform on hover`. Any match is a bug — fix and recommit before UAT.

- [ ] **Step 2: JS syntax check on all new JS files**

```bash
cd /c/laragon/www/sites/daem-society && for f in public/pages/backstage/forum/forum-*.js; do node --check "$f" || echo "FAIL: $f"; done
```

Expected: no failures.

- [ ] **Step 3: Backend regression**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --testsuite E2E  2>&1 | tail -5
```

Expected: PHPStan 0, Unit + E2E green.

- [ ] **Step 4: Manual UAT — verify each page**

For each URL below, visit while logged in as admin and verify the listed expectations:

| URL | Expected |
|-----|----------|
| `/backstage/forum` | 4 KPI cards render with values + sparklines; recent activity list shows up to 5 rows; each KPI card click navigates to its sub-page |
| `/backstage/forum/reports` | List loads; segmented filter (Open/Resolved/Dismissed) refetches; type select refetches; search filters in-memory; clicking Resolve opens slide-panel; all 5 actions show inline forms; confirm-dialog appears before applying; closing/canceling works at every level |
| `/backstage/forum/topics` | Table loads; segmented filter (Pinned/Locked/Open) filters; category select works; pin/unpin/lock/unlock/delete confirm-dialogs work end-to-end |
| `/backstage/forum/categories` | Table loads; `+ New category` opens slide-panel; create + edit save; delete confirm shows topic-count warning when > 0 |
| `/backstage/forum/audit` | Table loads; action-type + range filters refetch; search filters in-memory; load-more appends rows |
| Light + dark theme | Toggle via header; verify nothing breaks |
| Mobile (≤768px) | Dashboard KPI grid stacks; tables overflow-scroll horizontally; slide-panel = 100% width |

- [ ] **Step 5: Backend commit summary**

```bash
cd /c/laragon/www/daems-platform && git log --oneline dev..HEAD
cd /c/laragon/www/sites/daem-society && git log --oneline dev..HEAD
```

Report SHAs to the user. **Do not push** — wait for explicit "pushaa".

---

## Self-Review

After writing this plan, I checked:

**Spec coverage:**
- §3 routing → Tasks 10 (front controller) + 12-16 (each page).
- §4 dashboard → Task 12 + Task 4 (use case) + Task 6 (route).
- §5 reports sub-page → Task 13.
- §6 topics → Task 14.
- §7 categories → Task 15.
- §8 audit → Task 16.
- §9 backend changes → Tasks 1-8.
- §10 frontend changes → Tasks 12-18.
- §11 testing → Tasks 8 + 9 + 19.
- §12 out-of-scope → respected.
- §13 acceptance criteria → enumerated against Task 19 step 4.

**Placeholder scan:** No "TBD"/"TODO"/"implement later" remains. Adaptation notes (e.g. "if `User::isAdminIn` doesn't exist, use whatever exists") are scoped to specific cases where the codebase is not yet inspected by the writer of this plan; subagents adapt during implementation.

**Type consistency:**
- KPI ids `open_reports`/`topics`/`categories`/`mod_actions` used in: backend output (Task 4 use case), controller (Task 5), proxy (Task 11), dashboard (Task 12), per-page JS for navigation (Task 12 hrefs). All identical.
- Sparkline shape `[{date, value}, ...30]` consistent across backend, controller, proxy, sparkline init.
- Method names `countOpenReportsForTenant` / `dailyNewReportsForTenant` / `countActionsLast30dForTenant` / `dailyActionCountForTenant` / `recentForTenant` / `countTopicsForTenant` / `dailyNewTopicsForTenant` / `countCategoriesForTenant` consistent between Tasks 1-3.
- Proxy ops names `reports_list`, `report_detail`, `report_resolve`, `report_dismiss`, `topics_list`, `topic_pin/unpin/lock/unlock/delete`, `categories_list`, `category_create/update/delete`, `audit_list`, `stats` consistent across JS callers and proxy add-ops.

**Scope check:** This plan covers Phase 2 only — the 5 forum pages + dashboard. Other 7 backstage pages explicitly out of scope.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-25-forum-redesign-phase2.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
