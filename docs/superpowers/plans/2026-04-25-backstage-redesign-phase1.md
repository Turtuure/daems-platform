# Backstage Redesign Phase 1 — Design System + Insights Pilot

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shared backstage design system primitives (kpi-card, data-explorer, slide-panel, confirm-dialog, empty-state, skeleton-row, error-state, pill, btn variants) and apply them end-to-end to `/backstage/insights` as the iterative pilot, including a new `GET /api/v1/backstage/insights/stats` endpoint that powers 3 KPI cards (Published / Scheduled / Featured).

**Architecture:** Two-repo work. Backend (`daems-platform`, PHP 8.1 Clean Architecture) gains a `ListInsightStats` use case, `InsightRepositoryInterface::statsForTenant()` method, and a new `BackstageController::statsInsights` HTTP method. Frontend (`daem-society`, vanilla PHP/JS, Bootstrap utilities) gains two new shared assets (`daems-backstage-system.css/.js`) with hover affordances restricted to non-transform CSS, four reusable PHP partials, an empty-state SVG, and a rebuilt `insights/index.php` that uses the new components. The dashboard's existing ApexCharts dependency is reused for KPI sparklines.

**Tech Stack:** PHP 8.1, MySQL 8.4, vanilla JS (no frameworks), ApexCharts 3 (already loaded by `layout.php`), Bootstrap Icons, PHPUnit 10, PHPStan level 9.

**Spec:** [`docs/superpowers/specs/2026-04-25-backstage-redesign-design.md`](../specs/2026-04-25-backstage-redesign-design.md)

---

## File Structure

### Backend (`C:/laragon/www/daems-platform`)

**Create:**
- `src/Application/Insight/ListInsightStats/ListInsightStats.php` — use case
- `src/Application/Insight/ListInsightStats/ListInsightStatsInput.php`
- `src/Application/Insight/ListInsightStats/ListInsightStatsOutput.php`
- `tests/Unit/Application/Insight/ListInsightStatsTest.php`
- `tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php`
- `tests/Integration/Http/BackstageInsightStatsTest.php`
- `tests/Isolation/InsightStatsTenantIsolationTest.php`

**Modify:**
- `src/Domain/Insight/InsightRepositoryInterface.php` — add `statsForTenant()`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` — implement
- `tests/Support/Fake/InMemoryInsightRepository.php` — implement (for E2E)
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add `statsInsights()` method, add `ListInsightStats` to constructor
- `routes/api.php` — add `GET /api/v1/backstage/insights/stats` route
- `bootstrap/app.php` — bind `ListInsightStats`, add to `BackstageController` constructor wiring
- `tests/Support/KernelHarness.php` — same wiring (BOTH-containers rule)

### Frontend (`C:/laragon/www/sites/daem-society`)

**Create:**
- `public/assets/css/daems-backstage-system.css` — design system primitives
- `public/assets/js/daems-backstage-system.js` — Panel, ConfirmDialog, Sparkline helpers
- `public/pages/backstage/shared/kpi-card.php` — partial
- `public/pages/backstage/shared/slide-panel.php` — partial mount point
- `public/pages/backstage/shared/confirm-dialog.php` — partial mount point
- `public/pages/backstage/shared/empty-state.php` — partial
- `public/pages/backstage/insights/empty-state.svg`
- `public/pages/backstage/insights/insight-panel.js` — replaces `insight-modal.js`

**Modify:**
- `public/pages/backstage/layout.php` — load new CSS + JS
- `public/pages/backstage/insights/index.php` — full redesign using new patterns
- `public/api/backstage/insights.php` — proxy: add `stats` op

**Delete:**
- `public/pages/backstage/insights/insight-modal.css`
- `public/pages/backstage/insights/insight-modal.js`

---

## Conventions

- **Commit identity (every commit):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No `Co-Authored-By:` trailer.
- **Never auto-push.** Final task reports SHAs and waits for explicit pushaa.
- **Never stage `.claude/` or `.superpowers/`** — both are gitignored; double-check `git status --short` before each commit.
- **Hook failure:** fix + create a NEW commit (never `--amend`).
- **Forbidden:** `mcp__code-review-graph__*` tools — they have hung subagent sessions.
- **Test command:** `composer test:all` (Unit + Integration + E2E). MySQL must be running on `127.0.0.1:3306` for Integration + Isolation suites.
- **Static analysis:** `composer analyse` must remain at 0 errors after every backend task.

---

## Phase A — Backend stats endpoint

### Task 1: Add `statsForTenant()` to `InsightRepositoryInterface`

**Files:**
- Modify: `C:/laragon/www/daems-platform/src/Domain/Insight/InsightRepositoryInterface.php`

- [ ] **Step 1: Add the method signature**

Replace the file's contents with:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Insight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

interface InsightRepositoryInterface
{
    /**
     * @param TenantId $tenantId
     * @param string|null $category           Filter by category slug; null for all
     * @param bool $includeUnpublished        When false (default = public view), filters
     *                                        rows with published_date > CURDATE() out.
     *                                        Admin backstage path passes true.
     * @return Insight[]
     */
    public function listForTenant(TenantId $tenantId, ?string $category = null, bool $includeUnpublished = false): array;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Insight;

    public function findByIdForTenant(InsightId $id, TenantId $tenantId): ?Insight;

    public function save(Insight $insight): void;

    public function delete(InsightId $id, TenantId $tenantId): void;

    /**
     * Aggregate stats for the backstage dashboard.
     *
     * @return array{
     *   published: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   scheduled: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   featured:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     *
     * Sparkline arrays are exactly 30 entries:
     *   - published: count of insights whose published_date falls on each of the
     *     last 30 days (today = entry 29, 29 days ago = entry 0).
     *   - scheduled: count of insights whose published_date falls on each of the
     *     next 30 days (today+1 = entry 0, today+30 = entry 29).
     *   - featured:  same window as published, but only featured = 1.
     *
     * Missing days are zero-filled. Date strings are 'YYYY-MM-DD'.
     */
    public function statsForTenant(TenantId $tenantId): array;
}
```

- [ ] **Step 2: Verify no compile error**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -20`

Expected: Errors complaining that `SqlInsightRepository` and `InMemoryInsightRepository` don't implement the new method. We fix those next.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Domain/Insight/InsightRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Domain(insight): add statsForTenant() interface method"
```

---

### Task 2: Implement `SqlInsightRepository::statsForTenant()` with TDD

**Files:**
- Create: `C:/laragon/www/daems-platform/tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php`
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php`

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Insight\InsightId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlInsightRepositoryStatsTest extends MigrationTestCase
{
    private SqlInsightRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlInsightRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedInsight(string $tenantSlug, string $slug, string $publishedDate, bool $featured = false): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, content)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'c', 'C', ?, ?, 'a', 1, 'x', '<p>y</p>')"
        );
        $stmt->execute([
            InsightId::generate()->value(),
            $tenantSlug,
            $slug,
            'T-' . $slug,
            $featured ? 1 : 0,
            $publishedDate,
        ]);
    }

    private function tenantId(string $slug): \Daems\Domain\Tenant\TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return \Daems\Domain\Tenant\TenantId::fromString($row['id']);
    }

    public function test_published_count_includes_today_and_past_only(): void
    {
        $today      = date('Y-m-d');
        $yesterday  = date('Y-m-d', strtotime('-1 day'));
        $tomorrow   = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('daems', 'p1', $yesterday);
        $this->seedInsight('daems', 'p2', $today);
        $this->seedInsight('daems', 's1', $tomorrow);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(2, $stats['published']['value']);
        self::assertSame(1, $stats['scheduled']['value']);
    }

    public function test_featured_only_counts_already_published_featured(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('daems', 'f1', $yesterday, true);
        $this->seedInsight('daems', 'f2', $tomorrow,  true);  // future-dated featured does NOT count
        $this->seedInsight('daems', 'np', $yesterday, false);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(1, $stats['featured']['value']);
    }

    public function test_published_sparkline_has_exactly_30_entries_zero_filled(): void
    {
        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertCount(30, $stats['published']['sparkline']);
        self::assertCount(30, $stats['scheduled']['sparkline']);
        self::assertCount(30, $stats['featured']['sparkline']);

        // First entry = 29 days ago, last entry = today
        $expectedFirst = date('Y-m-d', strtotime('-29 days'));
        $expectedLast  = date('Y-m-d');
        self::assertSame($expectedFirst, $stats['published']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['published']['sparkline'][29]['date']);
    }

    public function test_scheduled_sparkline_starts_tomorrow(): void
    {
        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        $expectedFirst = date('Y-m-d', strtotime('+1 day'));
        $expectedLast  = date('Y-m-d', strtotime('+30 days'));
        self::assertSame($expectedFirst, $stats['scheduled']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['scheduled']['sparkline'][29]['date']);
    }

    public function test_published_sparkline_records_correct_day(): void
    {
        $threeDaysAgo = date('Y-m-d', strtotime('-3 days'));
        $this->seedInsight('daems', 'd', $threeDaysAgo);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));
        // Index 26 = 29 - 3 (first entry is 29 days ago)
        self::assertSame($threeDaysAgo, $stats['published']['sparkline'][26]['date']);
        self::assertSame(1,             $stats['published']['sparkline'][26]['value']);
    }

    public function test_other_tenant_rows_do_not_leak(): void
    {
        $today = date('Y-m-d');
        $this->seedInsight('sahegroup', 'sg', $today);

        $stats = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(0, $stats['published']['value']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter SqlInsightRepositoryStatsTest 2>&1 | tail -30`

Expected: FAIL with `Error: Call to undefined method ... statsForTenant()` or similar.

- [ ] **Step 3: Add the implementation to `SqlInsightRepository`**

Open `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` and append a new method **before** the closing `}` of the class:

```php
    public function statsForTenant(TenantId $tenantId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Build zero-filled date templates: 30 entries each
        $publishedDays = [];
        $scheduledDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $publishedDays[(new \DateTimeImmutable("-{$i} days"))->format('Y-m-d')] = 0;
        }
        for ($i = 1; $i <= 30; $i++) {
            $scheduledDays[(new \DateTimeImmutable("+{$i} days"))->format('Y-m-d')] = 0;
        }
        $featuredDays = $publishedDays; // same key range

        // Aggregate counts
        $sql = <<<SQL
            SELECT
                published_date,
                featured,
                COUNT(*) AS cnt
            FROM insights
            WHERE tenant_id = ?
              AND (
                   (published_date BETWEEN DATE_SUB(?, INTERVAL 29 DAY) AND ?)
                OR (published_date BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 30 DAY))
              )
            GROUP BY published_date, featured
        SQL;
        $rows = $this->connection->query(
            $sql,
            [$tenantId->value(), $today, $today, $today, $today]
        );

        $publishedTotal = 0;
        $scheduledTotal = 0;
        $featuredTotal  = 0;

        foreach ($rows as $row) {
            $date     = (string) $row['published_date'];
            $featured = (int) $row['featured'] === 1;
            $cnt      = (int) $row['cnt'];

            if ($date <= $today) {
                if (isset($publishedDays[$date])) {
                    $publishedDays[$date] += $cnt;
                }
                $publishedTotal += $cnt;
                if ($featured) {
                    if (isset($featuredDays[$date])) {
                        $featuredDays[$date] += $cnt;
                    }
                    $featuredTotal += $cnt;
                }
            } else {
                if (isset($scheduledDays[$date])) {
                    $scheduledDays[$date] += $cnt;
                }
                $scheduledTotal += $cnt;
            }
        }

        // Totals (ignoring date window) — re-query for accurate total counts
        $totals = $this->connection->query(
            'SELECT
                SUM(CASE WHEN published_date <= ? THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN published_date >  ? THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN published_date <= ? AND featured = 1 THEN 1 ELSE 0 END) AS featured
             FROM insights
             WHERE tenant_id = ?',
            [$today, $today, $today, $tenantId->value()]
        );
        $totalRow = $totals[0] ?? ['published' => 0, 'scheduled' => 0, 'featured' => 0];

        return [
            'published' => [
                'value'     => (int) $totalRow['published'],
                'sparkline' => self::seriesFromMap($publishedDays),
            ],
            'scheduled' => [
                'value'     => (int) $totalRow['scheduled'],
                'sparkline' => self::seriesFromMap($scheduledDays),
            ],
            'featured' => [
                'value'     => (int) $totalRow['featured'],
                'sparkline' => self::seriesFromMap($featuredDays),
            ],
        ];
    }

    /**
     * @param array<string, int> $map
     * @return list<array{date: string, value: int}>
     */
    private static function seriesFromMap(array $map): array
    {
        $out = [];
        foreach ($map as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }
```

Note: this assumes `Connection::query(string, array): array` returns an associative-array list. Confirm by reading the existing `listForTenant` implementation in the same file — it should follow the same pattern (`$this->connection->query(...)` or `$this->connection->fetch(...)`).

If the existing methods use a different shape (e.g. `$this->pdo`, `$this->connection->prepare(...)->execute(...)->fetchAll()`), adapt the SQL calls above to match. Do not invent a new API.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter SqlInsightRepositoryStatsTest 2>&1 | tail -30`

Expected: All 6 test methods pass.

- [ ] **Step 5: Run full Integration suite to check for regressions**

Run: `cd /c/laragon/www/daems-platform && composer test:integration 2>&1 | tail -10`

Expected: All green.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Domain/Insight/InsightRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php \
        tests/Integration/Persistence/SqlInsightRepositoryStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(insight): SqlInsightRepository::statsForTenant — published/scheduled/featured + 30-day sparklines"
```

---

### Task 3: Implement `InMemoryInsightRepository::statsForTenant()`

**Files:**
- Modify: `C:/laragon/www/daems-platform/tests/Support/Fake/InMemoryInsightRepository.php`

- [ ] **Step 1: Read the existing fake repository**

Run: `cat /c/laragon/www/daems-platform/tests/Support/Fake/InMemoryInsightRepository.php` and confirm it implements `listForTenant`, `findByIdForTenant`, etc., on a `private array $rows = []` (or similar) backing store.

- [ ] **Step 2: Append the new method to the fake**

Add the following method **before** the closing `}` of the class:

```php
    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $publishedDays = [];
        $scheduledDays = [];
        $featuredDays  = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d');
            $publishedDays[$d] = 0;
            $featuredDays[$d]  = 0;
        }
        for ($i = 1; $i <= 30; $i++) {
            $scheduledDays[(new \DateTimeImmutable("+{$i} days"))->format('Y-m-d')] = 0;
        }

        $publishedTotal = 0;
        $scheduledTotal = 0;
        $featuredTotal  = 0;

        foreach ($this->rows as $insight) {
            if ($insight->tenantId()->value() !== $tenantId->value()) {
                continue;
            }
            $date = $insight->date();   // 'YYYY-MM-DD'
            if ($date <= $today) {
                $publishedTotal++;
                if (isset($publishedDays[$date])) $publishedDays[$date]++;
                if ($insight->featured()) {
                    $featuredTotal++;
                    if (isset($featuredDays[$date])) $featuredDays[$date]++;
                }
            } else {
                $scheduledTotal++;
                if (isset($scheduledDays[$date])) $scheduledDays[$date]++;
            }
        }

        $toSeries = static function (array $map): array {
            $out = [];
            foreach ($map as $date => $value) {
                $out[] = ['date' => $date, 'value' => $value];
            }
            return $out;
        };

        return [
            'published' => ['value' => $publishedTotal, 'sparkline' => $toSeries($publishedDays)],
            'scheduled' => ['value' => $scheduledTotal, 'sparkline' => $toSeries($scheduledDays)],
            'featured'  => ['value' => $featuredTotal,  'sparkline' => $toSeries($featuredDays)],
        ];
    }
```

If the backing store uses a different field name than `$this->rows` (read the file to verify), adapt the foreach.

- [ ] **Step 3: Run PHPStan to confirm interface compliance**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -10`

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add tests/Support/Fake/InMemoryInsightRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(insight): InMemoryInsightRepository::statsForTenant — mirrors SQL behavior"
```

---

### Task 4: Create `ListInsightStats` use case + unit test

**Files:**
- Create: `C:/laragon/www/daems-platform/src/Application/Insight/ListInsightStats/ListInsightStats.php`
- Create: `C:/laragon/www/daems-platform/src/Application/Insight/ListInsightStats/ListInsightStatsInput.php`
- Create: `C:/laragon/www/daems-platform/src/Application/Insight/ListInsightStats/ListInsightStatsOutput.php`
- Create: `C:/laragon/www/daems-platform/tests/Unit/Application/Insight/ListInsightStatsTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Application/Insight/ListInsightStatsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\ListInsightStats\ListInsightStats;
use Daems\Application\Insight\ListInsightStats\ListInsightStatsInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ListInsightStatsTest extends TestCase
{
    public function test_passes_tenant_id_through_to_repository(): void
    {
        $repo = new class implements InsightRepositoryInterface {
            public ?TenantId $captured = null;
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array
            {
                $this->captured = $t;
                return [
                    'published' => ['value' => 0, 'sparkline' => []],
                    'scheduled' => ['value' => 0, 'sparkline' => []],
                    'featured'  => ['value' => 0, 'sparkline' => []],
                ];
            }
        };

        $tid = TenantId::fromString('019d0000-0000-7000-8000-000000000001');
        $uc  = new ListInsightStats($repo);
        $uc->execute(new ListInsightStatsInput($tid));

        self::assertNotNull($repo->captured);
        self::assertSame($tid->value(), $repo->captured->value());
    }

    public function test_returns_repository_payload_unchanged(): void
    {
        $payload = [
            'published' => ['value' => 5, 'sparkline' => [['date' => '2026-04-25', 'value' => 1]]],
            'scheduled' => ['value' => 2, 'sparkline' => []],
            'featured'  => ['value' => 1, 'sparkline' => []],
        ];
        $repo = new class($payload) implements InsightRepositoryInterface {
            public function __construct(private array $payload) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array { return $this->payload; }
        };

        $uc  = new ListInsightStats($repo);
        $out = $uc->execute(new ListInsightStatsInput(
            TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
        ));

        self::assertSame(5, $out->stats['published']['value']);
        self::assertSame(1, $out->stats['featured']['value']);
        self::assertCount(1, $out->stats['published']['sparkline']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter ListInsightStatsTest 2>&1 | tail -10`

Expected: FAIL with class-not-found errors for `ListInsightStats`, `ListInsightStatsInput`.

- [ ] **Step 3: Create the input DTO**

Create `src/Application/Insight/ListInsightStats/ListInsightStatsInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsightStats;

use Daems\Domain\Tenant\TenantId;

final class ListInsightStatsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
    ) {}
}
```

- [ ] **Step 4: Create the output DTO**

Create `src/Application/Insight/ListInsightStats/ListInsightStatsOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsightStats;

final class ListInsightStatsOutput
{
    /**
     * @param array{
     *   published: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   scheduled: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   featured:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(
        public readonly array $stats,
    ) {}
}
```

- [ ] **Step 5: Create the use case**

Create `src/Application/Insight/ListInsightStats/ListInsightStats.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsightStats;

use Daems\Domain\Insight\InsightRepositoryInterface;

final class ListInsightStats
{
    public function __construct(
        private readonly InsightRepositoryInterface $repo,
    ) {}

    public function execute(ListInsightStatsInput $input): ListInsightStatsOutput
    {
        return new ListInsightStatsOutput(
            stats: $this->repo->statsForTenant($input->tenantId),
        );
    }
}
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter ListInsightStatsTest 2>&1 | tail -10`

Expected: 2 tests, all green.

- [ ] **Step 7: Run PHPStan**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5`

Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Application/Insight/ListInsightStats/ tests/Unit/Application/Insight/ListInsightStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(insight): ListInsightStats — wraps statsForTenant for backstage controller"
```

---

### Task 5: Add `BackstageController::statsInsights()` method

**Files:**
- Modify: `C:/laragon/www/daems-platform/src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 1: Add the use-case import + constructor parameter**

Open `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`. Near the existing insight imports (around line 195), add:

```php
use Daems\Application\Insight\ListInsightStats\ListInsightStats;
use Daems\Application\Insight\ListInsightStats\ListInsightStatsInput;
```

Then in the constructor parameter list (around line 195–199), add `$listInsightStats` after `$listInsights`:

```php
        private readonly ListInsights $listInsights,
        private readonly ListInsightStats $listInsightStats,
```

- [ ] **Step 2: Add the controller method**

Find the existing `listInsights` method in the same file (around line 1381). **After** the `deleteInsight` method (around line 1497) and **before** `requireInsightsAdmin`, add:

```php
    public function statsInsights(Request $request): Response
    {
        $tenant = $this->requireTenant($request);
        $this->requireInsightsAdmin($request, $tenant);

        $out = $this->listInsightStats->execute(new ListInsightStatsInput(
            tenantId: $tenant->id,
        ));

        return Response::json(['data' => $out->stats]);
    }
```

- [ ] **Step 3: Verify PHPStan still passes (without route binding yet)**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -10`

Expected: 0 errors. Note: the controller compiles but cannot be instantiated yet — DI wiring follows in Task 7.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Controller(backstage): statsInsights — guarded by requireInsightsAdmin"
```

---

### Task 6: Wire route `GET /api/v1/backstage/insights/stats`

**Files:**
- Modify: `C:/laragon/www/daems-platform/routes/api.php`

- [ ] **Step 1: Add the route**

Open `routes/api.php`. Find the existing insights routes (around line 350). **After** the existing `GET /api/v1/backstage/insights` route (around line 351–353) and **before** the `POST /api/v1/backstage/insights` route, insert:

```php
    $router->get('/api/v1/backstage/insights/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsInsights($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Order matters in many routers — `/insights/stats` must come before `/insights/{id}` so `stats` doesn't get matched as an `{id}`. Place the `stats` route immediately after the `GET /insights` route.

- [ ] **Step 2: Commit (route only — DI wiring next)**

```bash
cd /c/laragon/www/daems-platform
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Route: GET /backstage/insights/stats — KPI strip data"
```

---

### Task 7: Wire DI in BOTH containers

**Files:**
- Modify: `C:/laragon/www/daems-platform/bootstrap/app.php`
- Modify: `C:/laragon/www/daems-platform/tests/Support/KernelHarness.php`

This task implements the BOTH-wiring rule from `feedback_bootstrap_and_harness_must_both_wire.md`. If only one container is wired, E2E tests pass but the live server breaks. **Both** files must be edited in this single task.

- [ ] **Step 1: Edit `bootstrap/app.php` — add use-case binding**

Find the existing insight bindings (around line 469–481). After `DeleteInsight` binding, append:

```php
$container->bind(\Daems\Application\Insight\ListInsightStats\ListInsightStats::class,
    static fn(Container $c) => new \Daems\Application\Insight\ListInsightStats\ListInsightStats(
        $c->make(\Daems\Domain\Insight\InsightRepositoryInterface::class),
    ));
```

- [ ] **Step 2: Edit `bootstrap/app.php` — add to BackstageController constructor wiring**

Find the existing `BackstageController` `make` block. Locate the line that passes `\Daems\Application\Insight\ListInsights\ListInsights::class` (e.g. `$c->make(\Daems\Application\Insight\ListInsights\ListInsights::class)`). On the line **after** that, add:

```php
        $c->make(\Daems\Application\Insight\ListInsightStats\ListInsightStats::class),
```

- [ ] **Step 3: Edit `tests/Support/KernelHarness.php` — same two edits**

Find the corresponding bindings (around lines 715–726). After `DeleteInsight` binding (~line 723–726), append:

```php
        $container->bind(\Daems\Application\Insight\ListInsightStats\ListInsightStats::class,
            static fn(Container $c) => new \Daems\Application\Insight\ListInsightStats\ListInsightStats(
                $c->make(\Daems\Domain\Insight\InsightRepositoryInterface::class),
            ));
```

In the `BackstageController::class` `make` block (around line 654–714), find the `ListInsights::class` line (~712) and **immediately after** it, add:

```php
            $c->make(\Daems\Application\Insight\ListInsightStats\ListInsightStats::class),
```

- [ ] **Step 4: Verify the wire is identical in both files**

Run: `cd /c/laragon/www/daems-platform && grep -n "ListInsightStats" bootstrap/app.php tests/Support/KernelHarness.php`

Expected: each file contains exactly two occurrences (one for the binding, one for the controller constructor call). If either has only one or three, recheck step 1–3.

- [ ] **Step 5: Run PHPStan + Unit tests**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5`

Expected: 0 errors.

Run: `cd /c/laragon/www/daems-platform && composer test:unit 2>&1 | tail -5`

Expected: all green.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(insight): ListInsightStats in BOTH bootstrap + KernelHarness"
```

---

### Task 8: Add E2E HTTP test

**Files:**
- Create: `C:/laragon/www/daems-platform/tests/Integration/Http/BackstageInsightStatsTest.php`

This goes in `Integration/Http/` (not `E2E/`) because the project's HTTP-flow tests live there, hitting the kernel via `KernelHarness` (no live server, no DB — uses InMemory fakes).

- [ ] **Step 1: Read an existing similar test to match the harness pattern**

Run: `ls /c/laragon/www/daems-platform/tests/Integration/Http/ 2>/dev/null` and pick the most recent backstage-insights test if one exists, or any backstage HTTP test (e.g. listing/CRUD), to match its structure.

```bash
cd /c/laragon/www/daems-platform && ls tests/Integration/Http/ | grep -i insight
```

If none exists, use any backstage admin HTTP test as the template (e.g. `BackstageEventsTest.php` or similar).

- [ ] **Step 2: Write the E2E test**

Create `tests/Integration/Http/BackstageInsightStatsTest.php`. Adapt the imports/setup to match the template you copied. Replace the test methods with:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BackstageInsightStatsTest extends TestCase
{
    private KernelHarness $harness;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->harness  = new KernelHarness();
        $this->tenantId = $this->harness->tenants->daems->id;  // adapt to harness API
    }

    public function test_returns_zero_stats_when_no_insights(): void
    {
        $admin = $this->harness->loginAdmin();   // adapt to harness API

        $response = $this->harness->request('GET', '/api/v1/backstage/insights/stats', [], $admin);

        self::assertSame(200, $response['status']);
        self::assertSame(0, $response['body']['data']['published']['value']);
        self::assertSame(0, $response['body']['data']['scheduled']['value']);
        self::assertSame(0, $response['body']['data']['featured']['value']);
        self::assertCount(30, $response['body']['data']['published']['sparkline']);
    }

    public function test_counts_published_and_scheduled_correctly(): void
    {
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->harness->insights->add($this->makeInsight('p', $today));
        $this->harness->insights->add($this->makeInsight('s', $tomorrow));

        $admin    = $this->harness->loginAdmin();
        $response = $this->harness->request('GET', '/api/v1/backstage/insights/stats', [], $admin);

        self::assertSame(1, $response['body']['data']['published']['value']);
        self::assertSame(1, $response['body']['data']['scheduled']['value']);
    }

    public function test_403_for_non_admin(): void
    {
        $member   = $this->harness->loginMember();   // adapt
        $response = $this->harness->request('GET', '/api/v1/backstage/insights/stats', [], $member);

        self::assertSame(403, $response['status']);
    }

    private function makeInsight(string $slug, string $publishedDate): Insight
    {
        return Insight::create(
            id:            InsightId::generate(),
            tenantId:      $this->tenantId,
            slug:          $slug,
            title:         'T-' . $slug,
            category:      'c',
            categoryLabel: 'C',
            featured:      false,
            date:          $publishedDate,
            author:        'a',
            readingTime:   1,
            excerpt:       'x',
            heroImage:     null,
            tags:          [],
            content:       '<p>y</p>',
        );
    }
}
```

**Important:** The `KernelHarness` API differs project-by-project. Read the file before writing the test:

```bash
cd /c/laragon/www/daems-platform && grep -n "function login\|public function request\|public.*tenants\|public.*insights" tests/Support/KernelHarness.php
```

Adapt the test to match the actual harness interface (e.g. `loginAs(...)`, `dispatch(...)`, `addInsight(...)`).

- [ ] **Step 3: Run the test**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter BackstageInsightStatsTest 2>&1 | tail -20`

Expected: 3 tests, all green. If the harness API was guessed wrong, the failures will say "method does not exist" — read the harness, fix, rerun.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add tests/Integration/Http/BackstageInsightStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(insight): E2E stats endpoint — totals + sparkline shape + admin gate"
```

---

### Task 9: Add tenant isolation test

**Files:**
- Create: `C:/laragon/www/daems-platform/tests/Isolation/InsightStatsTenantIsolationTest.php`

- [ ] **Step 1: Use the existing `InsightTenantIsolationTest` as a template**

```bash
cat /c/laragon/www/daems-platform/tests/Isolation/InsightTenantIsolationTest.php
```

It seeds rows for two tenants (`daems` and `sahegroup`), then asserts that `listForTenant('daems')` returns only daems rows.

- [ ] **Step 2: Write the isolation test**

Create `tests/Isolation/InsightStatsTenantIsolationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Insight\InsightId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class InsightStatsTenantIsolationTest extends IsolationTestCase
{
    private SqlInsightRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlInsightRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedInsight(string $tenantSlug, string $slug, string $publishedDate, bool $featured): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, content)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'c', 'C', ?, ?, 'a', 1, 'x', '<p>y</p>')"
        );
        $stmt->execute([
            InsightId::generate()->value(),
            $tenantSlug,
            $slug,
            'T-' . $slug,
            $featured ? 1 : 0,
            $publishedDate,
        ]);
    }

    public function test_stats_isolate_published_count_by_tenant(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->seedInsight('sahegroup', 'sg-p', $yesterday, false);
        $this->seedInsight('daems',     'd-p',  $yesterday, false);

        $daems  = $this->repo->statsForTenant($this->tenantId('daems'));
        $sahe   = $this->repo->statsForTenant($this->tenantId('sahegroup'));

        self::assertSame(1, $daems['published']['value']);
        self::assertSame(1, $sahe['published']['value']);
    }

    public function test_stats_isolate_featured_count_by_tenant(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->seedInsight('sahegroup', 'sg-f', $yesterday, true);
        $this->seedInsight('daems',     'd-p',  $yesterday, false);

        $daems = $this->repo->statsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->statsForTenant($this->tenantId('sahegroup'));

        self::assertSame(0, $daems['featured']['value']);
        self::assertSame(1, $sahe['featured']['value']);
    }

    public function test_stats_isolate_scheduled_count_by_tenant(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->seedInsight('sahegroup', 'sg-s', $tomorrow, false);
        $this->seedInsight('daems',     'd-s',  $tomorrow, false);

        $daems = $this->repo->statsForTenant($this->tenantId('daems'));

        self::assertSame(1, $daems['scheduled']['value']);
        // Sahegroup row must not appear in daems sparkline
        $tomorrowEntry = array_values(array_filter(
            $daems['scheduled']['sparkline'],
            static fn($e) => $e['date'] === $tomorrow
        ))[0];
        self::assertSame(1, $tomorrowEntry['value']);
    }
}
```

- [ ] **Step 3: Run the test**

Run: `cd /c/laragon/www/daems-platform && composer test -- --filter InsightStatsTenantIsolationTest 2>&1 | tail -10`

Expected: 3 tests, all green.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform
git add tests/Isolation/InsightStatsTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(isolation): InsightStatsTenantIsolation — published/scheduled/featured tenant-scoped"
```

---

### Task 10: Run full test suite + PHPStan, then push backend

- [ ] **Step 1: Run full backend test suite**

Run: `cd /c/laragon/www/daems-platform && composer test:all 2>&1 | tail -20`

Expected: All green.

- [ ] **Step 2: Run PHPStan level 9**

Run: `cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5`

Expected: 0 errors.

- [ ] **Step 3: Show backend commit summary (no push)**

Run: `cd /c/laragon/www/daems-platform && git log --oneline dev..HEAD`

Expected: ~9 new commits from Tasks 1–9. Report SHAs to the user. Wait for explicit "pushaa" before pushing.

---

## Phase B — Frontend design system

### Task 11: Create `daems-backstage-system.css`

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css`

This file ships ALL primitives (kpi-card, kpis-grid, data-explorer, pill, slide-panel, confirm-dialog, empty-state, skeleton-row, error-state, btn variants). Subsequent Phase 2-9 PRs add only the variants they need (e.g. new pill colors); core primitives are stable.

- [ ] **Step 1: Read the existing token vocabulary**

```bash
cd /c/laragon/www/sites/daem-society && grep -n "^  --" public/assets/css/daems-backstage.css | head -40
```

Confirm: tokens like `--surface-dark`, `--surface-medium`, `--surface-border`, `--text-primary`, `--text-secondary`, `--text-muted`, `--brand-primary`, `--accent`, `--status-success`, `--status-success-subtle`, `--status-warning`, `--status-warning-subtle`, `--status-error`, `--space-1..12`, `--radius-sm/md/lg/xl/full`, `--shadow-sm/md/lg/xl`, `--transition-fast/normal`, `--text-h1/h2/h3/body/small/xs`, `--weight-regular/medium/semibold/bold`, `--font-sans` exist.

- [ ] **Step 2: Create the file**

Create `public/assets/css/daems-backstage-system.css`:

```css
/* ==========================================================================
   Daems Backstage Design System (Phase 1)
   --------------------------------------------------------------------------
   Loaded after daems-backstage.css. Provides shared components:
   - .kpi-card / .kpis-grid
   - .data-explorer
   - .pill (status badges)
   - .slide-panel
   - .confirm-dialog
   - .empty-state
   - .skeleton-row
   - .error-state
   - .btn variants (primary / secondary / danger / text)

   HARD CONSTRAINT: no transform: translate*/scale* on hover for any
   tile/row/card. Use border-color, background-color, opacity instead.
   ========================================================================== */

/* ----- Buttons (extends existing .btn--primary/--secondary) -------------- */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
       font-size: 13px; font-weight: var(--weight-semibold);
       border-radius: var(--radius-md); cursor: pointer; border: 1px solid transparent;
       transition: background-color var(--transition-fast), color var(--transition-fast),
                   border-color var(--transition-fast); }
.btn:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: 2px; }
.btn--primary  { background: var(--brand-primary); color: var(--text-inverse); }
.btn--primary:hover  { background: var(--brand-primary-dim); }
.btn--secondary { background: var(--surface-light); color: var(--text-secondary); }
.btn--secondary:hover { background: var(--surface-lighter); color: var(--text-primary); }
.btn--danger   { background: var(--status-error); color: var(--text-inverse); }
.btn--danger:hover   { background: var(--status-error-hover); }
.btn--text     { background: transparent; color: var(--brand-primary); padding: 4px 8px; }
.btn--text:hover { background: var(--brand-primary-subtle); }
.btn--icon     { padding: 6px; min-width: 28px; min-height: 28px; justify-content: center;
                 background: transparent; color: var(--text-secondary); }
.btn--icon:hover { background: rgba(0,0,0,.05); color: var(--text-primary); }
[data-theme="dark"] .btn--icon:hover { background: rgba(255,255,255,.08); }

/* ----- Pill (status badges) ---------------------------------------------- */
.pill { display: inline-flex; align-items: center; padding: 2px 9px;
        font-size: var(--text-xs); font-weight: var(--weight-semibold);
        border-radius: var(--radius-full); line-height: 18px;
        white-space: nowrap; }
.pill--published { background: var(--status-success-subtle); color: var(--status-success); }
.pill--scheduled { background: var(--brand-primary-subtle);  color: var(--brand-primary); }
.pill--draft     { background: rgba(148,163,184,.15);        color: var(--text-secondary); }
.pill--archived  { background: var(--status-warning-subtle); color: var(--status-warning); }
.pill--pending   { background: var(--status-warning-subtle); color: var(--status-warning); }
.pill--featured  { background: var(--accent-subtle);         color: var(--accent); }

/* ----- KPI cards --------------------------------------------------------- */
.kpis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
             gap: var(--space-3); margin-bottom: var(--space-4); }

.kpi-card { background: var(--surface-dark); border: 1px solid var(--surface-border);
            border-radius: var(--radius-lg); padding: 14px 16px 0;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; min-height: 120px;
            transition: border-color var(--transition-fast); }
.kpi-card--clickable { cursor: pointer; }
.kpi-card--clickable:hover { border-color: var(--brand-primary); }
.kpi-card--clickable:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: -2px; }
.kpi-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.kpi-card__label { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.04em;
                   color: var(--text-secondary); font-weight: var(--weight-semibold); }
.kpi-card__value { font-size: 24px; font-weight: var(--weight-bold); margin-top: 4px;
                   letter-spacing: -0.01em; line-height: 1; color: var(--text-primary); }
.kpi-card__trend { font-size: var(--text-xs); margin-top: 2px;
                   color: var(--status-success); display: inline-flex; align-items: center; gap: 3px; }
.kpi-card__trend--down  { color: var(--status-error); }
.kpi-card__trend--warn  { color: var(--status-warning); }
.kpi-card__trend--muted { color: var(--text-muted); }
.kpi-card__icon { width: 28px; height: 28px; border-radius: var(--radius-md);
                  display: inline-flex; align-items: center; justify-content: center;
                  font-size: 14px; flex-shrink: 0; }
.kpi-card__icon--blue   { background: var(--brand-primary-muted); color: var(--brand-primary); }
.kpi-card__icon--green  { background: var(--status-success-subtle); color: var(--status-success); }
.kpi-card__icon--amber  { background: var(--status-warning-subtle); color: var(--status-warning); }
.kpi-card__icon--purple { background: var(--accent-subtle); color: var(--accent); }
.kpi-card__icon--gray   { background: rgba(148,163,184,.15); color: var(--text-secondary); }
.kpi-card__arrow { font-size: 14px; color: var(--text-muted); }
.kpi-card__spark { margin: 8px -16px 0; height: 36px; }
.kpi-card__spark > * { display: block; }

/* ----- Data explorer ----------------------------------------------------- */
.data-explorer__panel { background: var(--surface-dark); border: 1px solid var(--surface-border);
                        border-radius: var(--radius-lg); padding: 6px 18px 18px; }
.data-explorer__toolbar { display: flex; gap: var(--space-2); align-items: center;
                          padding: 12px 0; border-bottom: 1px solid var(--surface-border); }
.data-explorer__seg { display: inline-flex; background: var(--surface-light);
                      border-radius: var(--radius-md); padding: 3px; }
.data-explorer__seg-btn { padding: 5px 12px; font-size: var(--text-small);
                          font-weight: var(--weight-medium); color: var(--text-secondary);
                          border: none; background: none; border-radius: calc(var(--radius-md) - 2px);
                          cursor: pointer; transition: background var(--transition-fast),
                                                       color var(--transition-fast); }
.data-explorer__seg-btn:hover { color: var(--text-primary); }
.data-explorer__seg-btn.is-active { background: var(--surface-dark); color: var(--text-primary);
                                    box-shadow: var(--shadow-sm); }
.data-explorer__search { margin-left: auto; padding: 6px 10px;
                         border-radius: var(--radius-md); border: 1px solid var(--surface-border);
                         background: var(--surface-medium); font-size: var(--text-small);
                         min-width: 180px; color: var(--text-primary); }
.data-explorer__search:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: 1px; }
.data-explorer__data { width: 100%; border-collapse: collapse; margin-top: 4px; }
.data-explorer__data th { text-align: left; padding: 12px 12px 10px;
                          font-size: var(--text-xs); font-weight: var(--weight-medium);
                          color: var(--text-secondary); text-transform: uppercase;
                          letter-spacing: 0.04em; }
.data-explorer__data td { padding: 12px; font-size: var(--text-small);
                          color: var(--text-primary); vertical-align: middle; }
.data-explorer__data tr.row { border-top: 1px solid var(--surface-border); }
.data-explorer__data tr.row:hover { background: rgba(0,0,0,.02); }
[data-theme="dark"] .data-explorer__data tr.row:hover { background: rgba(255,255,255,.03); }
.data-explorer__actions { display: flex; gap: 4px; opacity: 0;
                          transition: opacity var(--transition-fast); }
.data-explorer__data tr.row:hover .data-explorer__actions,
.data-explorer__data tr.row:focus-within .data-explorer__actions { opacity: 1; }

/* ----- Slide panel (right edge) ------------------------------------------ */
.slide-panel { position: fixed; inset: 0; z-index: var(--z-modal); pointer-events: none; }
.slide-panel.is-open { pointer-events: auto; }
.slide-panel__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.45);
                         opacity: 0; transition: opacity 250ms cubic-bezier(0.4, 0, 0.2, 1); }
.slide-panel.is-open .slide-panel__backdrop { opacity: 1; }
.slide-panel__panel { position: absolute; right: 0; top: 0; bottom: 0; width: 56%;
                      max-width: 720px; background: var(--surface-dark);
                      border-left: 1px solid var(--surface-border);
                      box-shadow: var(--shadow-xl);
                      transform: translateX(100%);
                      transition: transform 250ms cubic-bezier(0.4, 0, 0.2, 1);
                      display: flex; flex-direction: column; }
.slide-panel.is-open .slide-panel__panel { transform: translateX(0); }
.slide-panel__header { display: flex; justify-content: space-between; align-items: center;
                       padding: 16px 20px; border-bottom: 1px solid var(--surface-border); }
.slide-panel__title { font-size: var(--text-h3); font-weight: var(--weight-bold);
                      color: var(--text-primary); }
.slide-panel__close { background: none; border: none; cursor: pointer;
                      width: 32px; height: 32px; border-radius: var(--radius-md);
                      color: var(--text-secondary); font-size: 20px; line-height: 1; }
.slide-panel__close:hover { background: rgba(0,0,0,.05); color: var(--text-primary); }
[data-theme="dark"] .slide-panel__close:hover { background: rgba(255,255,255,.08); }
.slide-panel__body { flex: 1; overflow-y: auto; padding: 20px;
                     display: flex; flex-direction: column; gap: var(--space-4); }
.slide-panel__footer { padding: 12px 20px; border-top: 1px solid var(--surface-border);
                       display: flex; gap: var(--space-2); justify-content: flex-end; }

@media (max-width: 1024px) {
  .slide-panel__panel { width: 80%; }
}
@media (max-width: 768px) {
  .slide-panel__panel { width: 100%; }
}

/* ----- Confirm dialog ---------------------------------------------------- */
.confirm-dialog { position: fixed; inset: 0; z-index: var(--z-modal); pointer-events: none;
                  display: flex; align-items: center; justify-content: center; }
.confirm-dialog.is-open { pointer-events: auto; }
.confirm-dialog__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.45);
                            opacity: 0; transition: opacity 200ms ease; }
.confirm-dialog.is-open .confirm-dialog__backdrop { opacity: 1; }
.confirm-dialog__panel { position: relative; width: 320px; max-width: calc(100vw - 32px);
                         background: var(--surface-dark); border-radius: var(--radius-lg);
                         box-shadow: var(--shadow-xl); padding: 18px;
                         display: flex; flex-direction: column; gap: 10px;
                         transform: scale(0.94); opacity: 0;
                         transition: transform 200ms ease, opacity 200ms ease; }
.confirm-dialog.is-open .confirm-dialog__panel { transform: scale(1); opacity: 1; }
.confirm-dialog__title { font-size: 15px; font-weight: var(--weight-bold);
                         color: var(--text-primary); }
.confirm-dialog__body  { font-size: var(--text-small); color: var(--text-secondary);
                         line-height: 1.5; }
.confirm-dialog__footer { display: flex; gap: var(--space-2); justify-content: flex-end; }

/* ----- Empty state ------------------------------------------------------- */
.empty-state { padding: 48px 24px; text-align: center;
               display: flex; flex-direction: column; align-items: center;
               gap: var(--space-3); }
.empty-state__illustration { width: 160px; height: auto; color: var(--text-muted); }
.empty-state__illustration svg { width: 100%; height: auto; fill: currentColor; }
.empty-state__title { font-size: var(--text-h3); font-weight: var(--weight-bold);
                      color: var(--text-primary); margin-top: 8px; }
.empty-state__body  { font-size: var(--text-small); color: var(--text-secondary);
                      max-width: 420px; }

/* ----- Skeleton row ------------------------------------------------------ */
.skeleton--text { display: inline-block; height: 14px; border-radius: var(--radius-sm);
                  background: linear-gradient(90deg, var(--surface-light) 25%,
                                                     var(--surface-lighter) 50%,
                                                     var(--surface-light) 75%);
                  background-size: 200% 100%;
                  animation: skeleton-shimmer 1.5s infinite; }
.skeleton--pill { display: inline-block; height: 18px; width: 64px;
                  border-radius: var(--radius-full);
                  background: linear-gradient(90deg, var(--surface-light) 25%,
                                                     var(--surface-lighter) 50%,
                                                     var(--surface-light) 75%);
                  background-size: 200% 100%;
                  animation: skeleton-shimmer 1.5s infinite; }
.kpi-card.is-loading .kpi-card__value { width: 60px; }
.kpi-card.is-loading .kpi-card__label { opacity: 0.6; }
.kpi-card.is-loading .kpi-card__spark { background: linear-gradient(90deg,
                                                       var(--surface-light) 25%,
                                                       var(--surface-lighter) 50%,
                                                       var(--surface-light) 75%);
                                        background-size: 200% 100%;
                                        animation: skeleton-shimmer 1.5s infinite; }

/* ----- Error state ------------------------------------------------------- */
.error-state { background: var(--status-error-subtle);
               border-left: 4px solid var(--status-error);
               border-radius: var(--radius-md);
               padding: 16px 20px;
               display: grid; grid-template-columns: auto 1fr auto;
               gap: var(--space-3); align-items: center; }
.error-state__icon { width: 24px; height: 24px; color: var(--status-error); }
.error-state__title { font-weight: var(--weight-bold); color: var(--text-primary); }
.error-state__message { font-size: var(--text-small); color: var(--text-secondary); }
.error-state__actions { display: flex; gap: var(--space-2); }
```

- [ ] **Step 3: Visual smoke test in browser**

Visit `http://daem-society.local/backstage/insights` (the page won't yet use these classes — but loading the file must not break the existing page).

Expected: `/backstage/insights` still loads with its current Bootstrap-table look (because the new CSS file isn't loaded yet — that happens in Task 16). No regressions.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/assets/css/daems-backstage-system.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "CSS(backstage): design system primitives — kpi-card, data-explorer, slide-panel, confirm-dialog, empty-state, skeleton, error-state, btn variants"
```

---

### Task 12: Create `daems-backstage-system.js`

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-system.js`

This file exposes `window.SlidePanel`, `window.ConfirmDialog`, and `window.Sparkline` for per-page scripts.

- [ ] **Step 1: Create the file**

```javascript
/**
 * Daems Backstage Design System — JS controllers
 *
 * Exposes:
 *   window.SlidePanel.open({ title, body, footer, onClose })
 *   window.SlidePanel.close()
 *   window.ConfirmDialog.open({ title, body, danger, confirmLabel, cancelLabel }) -> Promise<boolean>
 *   window.Sparkline.init(el, dataPoints, color)         // dataPoints: [{date, value}, ...]
 *
 * No transform-based animations (per design system rule).
 *   - SlidePanel: translateX (one-axis, geometric layout, not a hover effect)
 *   - ConfirmDialog: opacity + scale on the dialog itself (open animation, not hover)
 *   - No tile/row/card has a hover transform anywhere in this file.
 */
(function () {
  'use strict';

  // ── SlidePanel ──────────────────────────────────────────────────────────
  var slidePanelEl     = null;
  var slidePanelTrigger = null;
  var slidePanelOnClose = null;
  var slidePanelLastFocus = null;

  function buildSlidePanel() {
    var el = document.createElement('div');
    el.className = 'slide-panel';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML =
      '<div class="slide-panel__backdrop" data-act="close"></div>' +
      '<aside class="slide-panel__panel" tabindex="-1">' +
      '  <header class="slide-panel__header">' +
      '    <h2 class="slide-panel__title" id="slide-panel-title"></h2>' +
      '    <button class="slide-panel__close" aria-label="Close" data-act="close">×</button>' +
      '  </header>' +
      '  <div class="slide-panel__body"></div>' +
      '  <footer class="slide-panel__footer"></footer>' +
      '</aside>';
    document.body.appendChild(el);
    return el;
  }

  function openSlidePanel(opts) {
    if (!slidePanelEl) slidePanelEl = buildSlidePanel();
    slidePanelLastFocus = document.activeElement;

    var title  = slidePanelEl.querySelector('.slide-panel__title');
    var body   = slidePanelEl.querySelector('.slide-panel__body');
    var footer = slidePanelEl.querySelector('.slide-panel__footer');

    title.textContent = opts.title || '';
    body.innerHTML    = '';
    footer.innerHTML  = '';
    if (opts.body)   { body.appendChild(opts.body instanceof Node ? opts.body : asNode(opts.body)); }
    if (opts.footer) { footer.appendChild(opts.footer instanceof Node ? opts.footer : asNode(opts.footer)); }

    slidePanelOnClose = opts.onClose || null;

    // Click backdrop / X
    slidePanelEl.addEventListener('click', slidePanelClickHandler);
    document.addEventListener('keydown', slidePanelKeyHandler);

    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
      slidePanelEl.classList.add('is-open');
      var panel = slidePanelEl.querySelector('.slide-panel__panel');
      panel.focus();
    });
  }

  function slidePanelClickHandler(e) {
    if (e.target.getAttribute('data-act') === 'close') closeSlidePanel();
  }
  function slidePanelKeyHandler(e) {
    if (e.key === 'Escape') closeSlidePanel();
  }
  function closeSlidePanel() {
    if (!slidePanelEl) return;
    slidePanelEl.classList.remove('is-open');
    slidePanelEl.removeEventListener('click', slidePanelClickHandler);
    document.removeEventListener('keydown', slidePanelKeyHandler);
    document.body.style.overflow = '';
    var cb = slidePanelOnClose; slidePanelOnClose = null;
    if (slidePanelLastFocus) slidePanelLastFocus.focus();
    slidePanelLastFocus = null;
    if (cb) cb();
  }

  function asNode(html) {
    var t = document.createElement('template');
    t.innerHTML = String(html).trim();
    return t.content;
  }

  window.SlidePanel = { open: openSlidePanel, close: closeSlidePanel };

  // ── ConfirmDialog ───────────────────────────────────────────────────────
  var confirmEl = null;

  function buildConfirmDialog() {
    var el = document.createElement('div');
    el.className = 'confirm-dialog';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML =
      '<div class="confirm-dialog__backdrop" data-act="close"></div>' +
      '<div class="confirm-dialog__panel">' +
      '  <h2 class="confirm-dialog__title"></h2>' +
      '  <p  class="confirm-dialog__body"></p>' +
      '  <footer class="confirm-dialog__footer">' +
      '    <button class="btn btn--secondary" data-act="cancel"></button>' +
      '    <button class="btn" data-act="confirm"></button>' +
      '  </footer>' +
      '</div>';
    document.body.appendChild(el);
    return el;
  }

  function openConfirmDialog(opts) {
    if (!confirmEl) confirmEl = buildConfirmDialog();
    var title   = confirmEl.querySelector('.confirm-dialog__title');
    var body    = confirmEl.querySelector('.confirm-dialog__body');
    var cancel  = confirmEl.querySelector('[data-act="cancel"]');
    var confirm = confirmEl.querySelector('[data-act="confirm"]');

    title.textContent  = opts.title || 'Confirm?';
    body.textContent   = opts.body  || '';
    cancel.textContent = opts.cancelLabel  || 'Cancel';
    confirm.textContent = opts.confirmLabel || 'Confirm';
    confirm.className = 'btn ' + (opts.danger ? 'btn--danger' : 'btn--primary');

    return new Promise(function (resolve) {
      function done(result) {
        confirmEl.classList.remove('is-open');
        confirmEl.removeEventListener('click', clickHandler);
        document.removeEventListener('keydown', keyHandler);
        setTimeout(function () { resolve(result); }, 200);
      }
      function clickHandler(e) {
        var act = e.target.getAttribute('data-act');
        if (act === 'confirm') done(true);
        else if (act === 'cancel' || act === 'close') done(false);
      }
      function keyHandler(e) {
        if (e.key === 'Escape') done(false);
        else if (e.key === 'Enter') { done(true); }
      }
      confirmEl.addEventListener('click', clickHandler);
      document.addEventListener('keydown', keyHandler);

      requestAnimationFrame(function () { confirmEl.classList.add('is-open'); });
    });
  }

  window.ConfirmDialog = { open: openConfirmDialog };

  // ── Sparkline (ApexCharts) ──────────────────────────────────────────────
  function initSparkline(el, dataPoints, color) {
    if (typeof ApexCharts === 'undefined') return;
    if (!el || !dataPoints || !dataPoints.length) return;

    var values = dataPoints.map(function (p) { return p.value; });
    var dates  = dataPoints.map(function (p) { return p.date; });
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

    var opts = {
      chart: {
        type: 'area', height: 36,
        sparkline: { enabled: true },
        animations: { enabled: true, easing: 'easeinout', speed: 800 },
      },
      series: [{ name: 'Daily', data: values }],
      xaxis:  { categories: dates },
      colors: [color || '#3b82f6'],
      stroke: { curve: 'smooth', width: 1.6 },
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] } },
      tooltip: {
        theme: isDark ? 'dark' : 'light',
        fixed: { enabled: false },
        x: { show: true, formatter: function (_v, op) {
          var idx = op && op.dataPointIndex;
          var d   = dates[idx] || '';
          return d;
        } },
        marker: { show: true },
        y: { formatter: function (v) { return String(v); } },
      },
    };

    new ApexCharts(el, opts).render();
  }

  window.Sparkline = { init: initSparkline };
})();
```

- [ ] **Step 2: Smoke test (file syntax only — not loaded yet)**

Run a JS syntax check:

```bash
cd /c/laragon/www/sites/daem-society && node --check public/assets/js/daems-backstage-system.js
```

Expected: no output (success). If `node` is not available on the system, skip — Task 16 will load this file in the browser.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/assets/js/daems-backstage-system.js
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "JS(backstage): design system controllers — SlidePanel, ConfirmDialog, Sparkline"
```

---

### Task 13: Create shared PHP partials

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/shared/kpi-card.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/shared/slide-panel.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/shared/confirm-dialog.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/shared/empty-state.php`

- [ ] **Step 1: Create `kpi-card.php`**

```php
<?php
/**
 * Backstage KPI Card partial.
 *
 * Variables (set by including page before include):
 * @var string  $kpi_id          Required. Used for the sparkline mount-point id.
 * @var string  $label           Uppercase label, e.g. 'Published'.
 * @var string  $value           Number or string value, e.g. '12'.
 * @var string  $icon_html       Inline SVG or emoji for the icon-pill (top right).
 * @var string  $icon_variant    Color modifier: blue|gray|green|amber|purple.
 * @var string  $trend_label     Optional caption beneath value.
 * @var string  $trend_direction up|down|warn|muted (default: up).
 * @var string  $href            Optional. If set, the card becomes an <a>.
 *
 * Sparkline data is rendered into <div id="spark-{kpi_id}"> by per-page JS via
 * window.Sparkline.init(el, sparklineArray, hexColor).
 */
declare(strict_types=1);

$kpi_id          = (string) ($kpi_id          ?? '');
$label           = (string) ($label           ?? '');
$value           = (string) ($value           ?? '0');
$icon_html       = (string) ($icon_html       ?? '');
$icon_variant    = (string) ($icon_variant    ?? 'blue');
$trend_label     = (string) ($trend_label     ?? '');
$trend_direction = (string) ($trend_direction ?? 'up');
$href            = isset($href) ? (string) $href : null;

$variantClass = 'kpi-card__icon--' . $icon_variant;
$trendClass   = $trend_direction === 'up'     ? '' : ('kpi-card__trend--' . $trend_direction);

$tag        = $href !== null ? 'a' : 'div';
$cardClass  = 'kpi-card' . ($href !== null ? ' kpi-card--clickable' : '');
$cardAttr   = $href !== null ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '';
?>
<<?= $tag ?> class="<?= $cardClass ?>"<?= $cardAttr ?>>
  <div class="kpi-card__head">
    <div>
      <div class="kpi-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="kpi-card__value"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($trend_label !== ''): ?>
        <div class="kpi-card__trend <?= $trendClass ?>"><?= htmlspecialchars($trend_label, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
    <?php if ($icon_html !== ''): ?>
      <span class="kpi-card__icon <?= $variantClass ?>"><?= $icon_html /* trusted inline SVG/emoji */ ?></span>
    <?php elseif ($href !== null): ?>
      <span class="kpi-card__arrow">&rarr;</span>
    <?php endif; ?>
  </div>
  <div class="kpi-card__spark" id="spark-<?= htmlspecialchars($kpi_id, ENT_QUOTES, 'UTF-8') ?>"></div>
</<?= $tag ?>>
```

- [ ] **Step 2: Create `slide-panel.php`**

The slide-panel is JS-built (one element appended to body via `SlidePanel.open()`) — no per-page partial is needed. Skip this file. (Spec called for it as a mount point but we discovered it's unnecessary; document this in the commit.)

- [ ] **Step 3: Create `confirm-dialog.php`**

Same as slide-panel — JS-built. Skip this file.

- [ ] **Step 4: Create `empty-state.php`**

```php
<?php
/**
 * Backstage Empty State partial.
 *
 * Variables (set by including page before include):
 * @var string $svg_path     Required. Absolute URL path to the empty-state SVG, e.g. '/pages/backstage/insights/empty-state.svg'.
 * @var string $title        Heading.
 * @var string $body         Description.
 * @var string $cta_label    Optional CTA button label.
 * @var string $cta_id       Optional CTA button id (so per-page JS can wire onclick).
 * @var string $cta_href     Optional. If set, CTA renders as <a href>.
 */
declare(strict_types=1);

$svg_path  = (string) ($svg_path  ?? '');
$title     = (string) ($title     ?? '');
$body      = (string) ($body      ?? '');
$cta_label = (string) ($cta_label ?? '');
$cta_id    = (string) ($cta_id    ?? '');
$cta_href  = isset($cta_href) ? (string) $cta_href : null;
?>
<div class="empty-state">
  <?php if ($svg_path !== ''): ?>
    <img class="empty-state__illustration" src="<?= htmlspecialchars($svg_path, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
  <?php endif; ?>
  <h3 class="empty-state__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
  <p class="empty-state__body"><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($cta_label !== ''): ?>
    <?php if ($cta_href !== null): ?>
      <a class="btn btn--primary" href="<?= htmlspecialchars($cta_href, ENT_QUOTES, 'UTF-8') ?>"<?= $cta_id !== '' ? ' id="' . htmlspecialchars($cta_id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?= htmlspecialchars($cta_label, ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php else: ?>
      <button type="button" class="btn btn--primary"<?= $cta_id !== '' ? ' id="' . htmlspecialchars($cta_id, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <?= htmlspecialchars($cta_label, ENT_QUOTES, 'UTF-8') ?>
      </button>
    <?php endif; ?>
  <?php endif; ?>
</div>
```

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/shared/kpi-card.php public/pages/backstage/shared/empty-state.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Partials(backstage): kpi-card + empty-state — slide-panel/confirm-dialog are JS-built so no partials needed"
```

---

### Task 14: Create insights empty-state SVG

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/insights/empty-state.svg`

- [ ] **Step 1: Create the SVG**

A simple single-color illustration (so it themes via `currentColor`). Matches "insights / news" semantically.

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 160" fill="currentColor" role="img" aria-label="No insights">
  <!-- Document stack -->
  <g opacity="0.18">
    <rect x="46" y="36" width="100" height="92" rx="6" />
  </g>
  <g opacity="0.34">
    <rect x="40" y="30" width="100" height="92" rx="6" />
  </g>
  <g>
    <rect x="34" y="24" width="100" height="92" rx="6" fill="currentColor" opacity="0.6" />
    <rect x="44" y="38" width="60" height="6" rx="2" fill="#fff" opacity="0.85" />
    <rect x="44" y="50" width="80" height="4" rx="2" fill="#fff" opacity="0.55" />
    <rect x="44" y="58" width="74" height="4" rx="2" fill="#fff" opacity="0.55" />
    <rect x="44" y="66" width="56" height="4" rx="2" fill="#fff" opacity="0.55" />
  </g>
  <!-- Plus icon top-right -->
  <g transform="translate(150, 36)">
    <circle r="14" fill="currentColor" opacity="0.85"/>
    <rect x="-8" y="-1.5" width="16" height="3" rx="1.5" fill="#fff"/>
    <rect x="-1.5" y="-8" width="3" height="16" rx="1.5" fill="#fff"/>
  </g>
</svg>
```

- [ ] **Step 2: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/insights/empty-state.svg
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Asset(insights): empty-state SVG — themable via currentColor"
```

---

### Task 15: Update `layout.php` to load new CSS + JS

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/layout.php`

- [ ] **Step 1: Add the CSS link**

Open `public/pages/backstage/layout.php`. Find the existing `<link rel="stylesheet" href="/assets/css/daems-backstage.css">` line (~line 90). **After** that line, add:

```html
    <link rel="stylesheet" href="/assets/css/daems-backstage-system.css">
```

(Order matters — system CSS must load AFTER the base CSS so it can reference the same tokens and override patterns when needed.)

- [ ] **Step 2: Add the JS script tag**

Find the existing `<script src="/assets/js/daems-backstage.js" defer>` line (~line 478). **After** that line, add:

```html
<script src="/assets/js/daems-backstage-system.js" defer></script>
```

- [ ] **Step 3: Smoke test in browser**

Visit `http://daem-society.local/backstage`. The dashboard should still render normally. Open DevTools → Network → confirm both new files load with 200.

In DevTools console, type:

```javascript
window.SlidePanel
window.ConfirmDialog
window.Sparkline
```

Expected: each should return an object (`{open: ƒ, ...}`).

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Layout(backstage): load daems-backstage-system CSS + JS"
```

---

## Phase C — Insights page redesign

### Task 16: Add `stats` op to insights proxy

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/api/backstage/insights.php`

- [ ] **Step 1: Add a new `stats` case**

Open `public/api/backstage/insights.php`. Find the `switch ($op)` block. After the `case 'list':` block (which currently ends with `return;`), add a new case:

```php
        case 'stats':
            $r = ApiClient::get('/backstage/insights/stats');
            echo json_encode(['data' => $r ?? []]);
            return;
```

Place it immediately after `case 'list':`'s `return;` so the structure stays sorted.

- [ ] **Step 2: Verify proxy round-trip with curl**

Make sure the platform server is running (`http://daems-platform.local`) and you have a valid admin session in `daem-society`. Then:

```bash
curl -s -b "session=...your-session-cookie..." \
  http://daem-society.local/api/backstage/insights.php?op=stats | head -c 400
```

Expected: a JSON document with `{"data":{"published":{"value":...,"sparkline":[...]},...}}`.

If you don't have a cookie handy, skip this and verify in Task 21 manual UAT.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/api/backstage/insights.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Proxy(insights): add stats op — proxies GET /backstage/insights/stats"
```

---

### Task 17: Create `insights/insight-panel.js`

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/insights/insight-panel.js`

This file replaces the old `insight-modal.js`. It owns the page lifecycle: load list + stats, render KPI sparklines and table rows, open SlidePanel for create/edit, open ConfirmDialog for delete.

- [ ] **Step 1: Create the file**

```javascript
/**
 * Insights backstage page — list, KPIs (sparklines), edit (slide-panel), delete (confirm-dialog).
 *
 * Replaces the deleted insight-modal.js. Uses:
 *   window.SlidePanel    — from daems-backstage-system.js
 *   window.ConfirmDialog — same
 *   window.Sparkline     — same (uses ApexCharts under the hood)
 *
 * Endpoints (via daem-society proxy):
 *   GET  /api/backstage/insights.php?op=list
 *   GET  /api/backstage/insights.php?op=stats
 *   GET  /api/backstage/insights.php?op=get&id=...
 *   POST /api/backstage/insights.php?op=create        body: insight payload
 *   POST /api/backstage/insights.php?op=update&id=... body: insight payload
 *   POST /api/backstage/insights.php?op=delete&id=...
 */
(function () {
  'use strict';

  var KPI_COLORS = {
    published: '#16a34a',
    scheduled: '#3b82f6',
    featured:  '#8b5cf6',
  };

  var els = {
    tbody:       document.getElementById('insights-tbody'),
    addBtn:      document.getElementById('insight-add-btn'),
    filterInput: document.getElementById('insight-filter-input'),
    seg:         document.getElementById('insight-status-filter'),
    emptyMount:  document.getElementById('insights-empty-mount'),
    errorMount:  document.getElementById('insights-error-mount'),
  };
  if (!els.tbody || !els.addBtn) return;

  var state = {
    rows:    [],
    filter:  '',
    status:  'all',  // all | published | scheduled
  };

  // ── Bootstrap: parallel load list + stats ────────────────────────────────
  function bootstrap() {
    renderSkeletonRows(5);
    Promise.all([fetchList(), fetchStats()])
      .then(function (results) {
        state.rows = results[0] || [];
        renderTable();
        renderKpis(results[1]);
      })
      .catch(function (err) { renderError(err); });
  }

  function fetchList() {
    return fetch('/api/backstage/insights.php?op=list')
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { return (j && j.data) || []; });
  }
  function fetchStats() {
    return fetch('/api/backstage/insights.php?op=stats')
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { return (j && j.data) || null; });
  }

  // ── KPI rendering ────────────────────────────────────────────────────────
  function renderKpis(stats) {
    if (!stats) return;
    document.querySelectorAll('.kpi-card').forEach(function (el) { el.classList.remove('is-loading'); });

    setKpi('published', stats.published);
    setKpi('scheduled', stats.scheduled);
    setKpi('featured',  stats.featured);

    initSpark('published', stats.published.sparkline);
    initSpark('scheduled', stats.scheduled.sparkline);
    initSpark('featured',  stats.featured.sparkline);
  }
  function setKpi(id, payload) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el) el.textContent = String(payload.value);
  }
  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id]);
  }

  // ── Table rendering ──────────────────────────────────────────────────────
  function renderSkeletonRows(n) {
    var html = '';
    for (var i = 0; i < n; i++) {
      html += '<tr class="data-explorer__skeleton">' +
              '  <td><span class="skeleton--text" style="width:60%"></span></td>' +
              '  <td><span class="skeleton--text" style="width:80%"></span></td>' +
              '  <td><span class="skeleton--text" style="width:40%"></span></td>' +
              '  <td><span class="skeleton--pill"></span></td>' +
              '  <td><span class="skeleton--text" style="width:30%"></span></td>' +
              '  <td></td>' +
              '</tr>';
    }
    els.tbody.innerHTML = html;
    if (els.emptyMount) els.emptyMount.style.display = 'none';
    if (els.errorMount) els.errorMount.style.display = 'none';
  }

  function filteredRows() {
    var today = new Date().toISOString().slice(0, 10);
    return state.rows.filter(function (r) {
      if (state.filter && (r.title || '').toLowerCase().indexOf(state.filter) === -1) return false;
      if (state.status === 'published' && r.published_date >  today) return false;
      if (state.status === 'scheduled' && r.published_date <= today) return false;
      return true;
    });
  }

  function renderTable() {
    var rows  = filteredRows();
    var today = new Date().toISOString().slice(0, 10);

    if (rows.length === 0) {
      els.tbody.innerHTML = '';
      if (els.emptyMount) els.emptyMount.style.display = '';
      return;
    }
    if (els.emptyMount) els.emptyMount.style.display = 'none';

    els.tbody.innerHTML = rows.map(function (i) {
      var pillClass = i.published_date > today ? 'pill--scheduled' : 'pill--published';
      var pillText  = i.published_date > today ? 'Scheduled' : 'Published';
      var feat      = i.featured ? '<span class="pill pill--featured" style="margin-left:6px;">Featured</span>' : '';
      return '<tr class="row" data-id="' + esc(i.id) + '">' +
             '  <td><strong>' + esc(i.title) + '</strong></td>' +
             '  <td>' + esc(i.category_label || i.category) + '</td>' +
             '  <td>' + esc(i.author) + '</td>' +
             '  <td><span class="pill ' + pillClass + '">' + pillText + '</span>' + feat + '</td>' +
             '  <td>' + esc(i.published_date) + '</td>' +
             '  <td class="data-explorer__actions">' +
             '    <button class="btn btn--icon js-edit" title="Edit" aria-label="Edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm17.71-10.21a1 1 0 0 0 0-1.42l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.82z" fill="currentColor"/></svg></button>' +
             '    <button class="btn btn--icon js-del" title="Delete" aria-label="Delete"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 7h12M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m-7 0v13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V7"/></svg></button>' +
             '  </td>' +
             '</tr>';
    }).join('');

    Array.from(els.tbody.querySelectorAll('.js-edit')).forEach(function (b) {
      b.addEventListener('click', function () { openEditPanel(rowIdFor(b)); });
    });
    Array.from(els.tbody.querySelectorAll('.js-del')).forEach(function (b) {
      b.addEventListener('click', function () { confirmDelete(rowIdFor(b)); });
    });
  }
  function rowIdFor(btn) { return btn.closest('tr').getAttribute('data-id'); }

  // ── Error rendering ──────────────────────────────────────────────────────
  function renderError(err) {
    els.tbody.innerHTML = '';
    if (els.errorMount) {
      els.errorMount.style.display = '';
      els.errorMount.innerHTML =
        '<div class="error-state" role="alert">' +
        '  <svg class="error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">' +
        '    <path d="M12 9v4M12 17h.01M3.6 18l8.4-14 8.4 14H3.6z"/></svg>' +
        '  <div>' +
        '    <div class="error-state__title">Could not load insights</div>' +
        '    <div class="error-state__message">' + esc(err && err.message || 'Network error') + '</div>' +
        '  </div>' +
        '  <div class="error-state__actions">' +
        '    <button class="btn btn--primary" id="insights-retry-btn">Retry</button>' +
        '  </div>' +
        '</div>';
      var btn = document.getElementById('insights-retry-btn');
      if (btn) btn.addEventListener('click', bootstrap);
    }
  }

  // ── Edit / Create panel ──────────────────────────────────────────────────
  function openEditPanel(id) {
    if (!id) return;
    fetch('/api/backstage/insights.php?op=get&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (j) { showPanel('edit', (j && j.data) || null); });
  }

  function openCreatePanel() {
    showPanel('create', null);
  }

  function showPanel(mode, insight) {
    var data = insight || {};
    var body = document.createElement('div');
    body.innerHTML =
      '<div><label class="kpi-card__label">Title</label>' +
      '  <input type="text" name="title" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.title) + '"></div>' +
      '<div><label class="kpi-card__label">Slug</label>' +
      '  <input type="text" name="slug" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.slug) + '"></div>' +
      '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">' +
      '  <div><label class="kpi-card__label">Category</label>' +
      '    <input type="text" name="category" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.category) + '"></div>' +
      '  <div><label class="kpi-card__label">Category label</label>' +
      '    <input type="text" name="category_label" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.category_label) + '"></div>' +
      '</div>' +
      '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">' +
      '  <div><label class="kpi-card__label">Author</label>' +
      '    <input type="text" name="author" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.author) + '"></div>' +
      '  <div><label class="kpi-card__label">Published date</label>' +
      '    <input type="date" name="published_date" class="data-explorer__search" style="width:100%;margin-top:6px;" value="' + escAttr(data.published_date) + '"></div>' +
      '</div>' +
      '<div><label class="kpi-card__label">Excerpt</label>' +
      '  <textarea name="excerpt" rows="3" class="data-explorer__search" style="width:100%;margin-top:6px;font-family:inherit;">' + esc(data.excerpt) + '</textarea></div>' +
      '<div><label class="kpi-card__label">Body (HTML)</label>' +
      '  <textarea name="content" rows="10" class="data-explorer__search" style="width:100%;margin-top:6px;font-family:inherit;">' + esc(data.content) + '</textarea></div>' +
      '<div><label style="display:inline-flex;gap:6px;align-items:center;">' +
      '  <input type="checkbox" name="featured"' + (data.featured ? ' checked' : '') + '> Featured</label></div>';

    var footer = document.createElement('div');
    footer.style.display = 'contents';
    if (mode === 'edit') {
      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn--text';
      delBtn.style.color = 'var(--status-error)';
      delBtn.style.marginRight = 'auto';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function () {
        confirmDelete(data.id, true);
      });
      footer.appendChild(delBtn);
    }
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn--secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { window.SlidePanel.close(); });
    footer.appendChild(cancelBtn);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn--primary';
    saveBtn.textContent = mode === 'create' ? 'Create' : 'Save';
    saveBtn.addEventListener('click', function () { saveFromPanel(mode, data.id, body); });
    footer.appendChild(saveBtn);

    window.SlidePanel.open({
      title: mode === 'create' ? 'New insight' : 'Edit insight',
      body:  body,
      footer: footer,
    });
  }

  function saveFromPanel(mode, id, bodyEl) {
    var payload = {
      title:          val(bodyEl, 'title'),
      slug:           val(bodyEl, 'slug'),
      category:       val(bodyEl, 'category'),
      category_label: val(bodyEl, 'category_label'),
      author:         val(bodyEl, 'author'),
      published_date: val(bodyEl, 'published_date'),
      excerpt:        val(bodyEl, 'excerpt'),
      content:        val(bodyEl, 'content'),
      featured:       chk(bodyEl, 'featured'),
      hero_image:     null,
      tags:           [],
    };
    var url = mode === 'create'
      ? '/api/backstage/insights.php?op=create'
      : '/api/backstage/insights.php?op=update&id=' + encodeURIComponent(id);
    fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function () { window.SlidePanel.close(); bootstrap(); })
      .catch(function (e) { alert('Save failed: ' + e.message); });
  }
  function val(parent, name) {
    var el = parent.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  }
  function chk(parent, name) {
    var el = parent.querySelector('[name="' + name + '"]');
    return !!(el && el.checked);
  }

  function confirmDelete(id, fromPanel) {
    if (!id) return;
    window.ConfirmDialog.open({
      title:        'Delete insight?',
      body:         'This action cannot be undone.',
      danger:       true,
      confirmLabel: 'Delete',
    }).then(function (ok) {
      if (!ok) return;
      fetch('/api/backstage/insights.php?op=delete&id=' + encodeURIComponent(id), { method: 'POST' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          if (fromPanel) window.SlidePanel.close();
          bootstrap();
        })
        .catch(function (e) { alert('Delete failed: ' + e.message); });
    });
  }

  // ── Toolbar wiring ───────────────────────────────────────────────────────
  els.addBtn.addEventListener('click', openCreatePanel);
  if (els.filterInput) {
    els.filterInput.addEventListener('input', function () {
      state.filter = (els.filterInput.value || '').toLowerCase();
      renderTable();
    });
  }
  if (els.seg) {
    els.seg.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-status]');
      if (!btn) return;
      Array.from(els.seg.querySelectorAll('[data-status]')).forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      state.status = btn.getAttribute('data-status');
      renderTable();
    });
  }

  // ── Helpers ──────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }

  // ── Boot ─────────────────────────────────────────────────────────────────
  bootstrap();
})();
```

- [ ] **Step 2: JS syntax check**

```bash
cd /c/laragon/www/sites/daem-society && node --check public/pages/backstage/insights/insight-panel.js
```

Expected: no output. Skip if `node` not available.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/insights/insight-panel.js
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "JS(insights): insight-panel — slide-panel editor + confirm-delete + KPI sparklines"
```

---

### Task 18: Rebuild `insights/index.php` with new patterns

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/insights/index.php`

- [ ] **Step 1: Replace the file**

Replace the entire contents of `public/pages/backstage/insights/index.php` with:

```php
<?php
declare(strict_types=1);

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || ($u['role'] ?? '') === 'admin'
               || ($u['role'] ?? '') === 'global_system_administrator');
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'Insights';
$activePage  = 'insights';
$breadcrumbs = [];

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-header__title">Insights</h1>
    <p class="page-header__subtitle">Create, edit, publish, and delete insights.</p>
  </div>
  <div>
    <button type="button" class="btn btn--primary" id="insight-add-btn">
      <i class="bi bi-plus-lg" aria-hidden="true"></i>&nbsp;Add insight
    </button>
  </div>
</div>

<!-- KPI cards -->
<div class="kpis-grid">
  <?php
    $iconNews = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>';
    $iconClock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
    $iconStar = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l2.9 6.9L22 10l-5 4.8L18.2 22 12 18.5 5.8 22 7 14.8 2 10l7.1-1.1z"/></svg>';

    foreach ([
      ['kpi_id' => 'published', 'label' => 'Published',  'value' => '—', 'icon_html' => $iconNews,  'icon_variant' => 'green',  'trend_label' => 'last 30 days', 'trend_direction' => 'muted'],
      ['kpi_id' => 'scheduled', 'label' => 'Scheduled',  'value' => '—', 'icon_html' => $iconClock, 'icon_variant' => 'blue',   'trend_label' => 'next 30 days', 'trend_direction' => 'muted'],
      ['kpi_id' => 'featured',  'label' => 'Featured',   'value' => '—', 'icon_html' => $iconStar,  'icon_variant' => 'purple', 'trend_label' => 'highlighted',  'trend_direction' => 'muted'],
    ] as $kpi) {
      extract($kpi);
      include __DIR__ . '/../shared/kpi-card.php';
    }
  ?>
</div>

<!-- Data explorer -->
<div class="data-explorer__panel">
  <div class="data-explorer__toolbar">
    <div class="data-explorer__seg" id="insight-status-filter" role="tablist">
      <button type="button" class="data-explorer__seg-btn is-active" data-status="all">All</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="published">Published</button>
      <button type="button" class="data-explorer__seg-btn"           data-status="scheduled">Scheduled</button>
    </div>
    <input type="search" id="insight-filter-input" class="data-explorer__search" placeholder="Search by title…">
  </div>

  <table class="data-explorer__data">
    <thead>
      <tr>
        <th>Title</th>
        <th>Category</th>
        <th>Author</th>
        <th>Status</th>
        <th>Published date</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="insights-tbody"></tbody>
  </table>

  <!-- Empty / Error mount points -->
  <div id="insights-empty-mount" style="display:none;">
    <?php
      $svg_path  = '/pages/backstage/insights/empty-state.svg';
      $title     = 'No insights yet';
      $body      = 'Create your first insight to share news with members.';
      $cta_label = '+ Add insight';
      $cta_id    = 'insight-empty-cta';
      include __DIR__ . '/../shared/empty-state.php';
    ?>
  </div>
  <div id="insights-error-mount" style="display:none;"></div>
</div>

<script src="/pages/backstage/insights/insight-panel.js" defer></script>
<script>
  // Wire empty-state CTA → same handler as toolbar add button
  document.addEventListener('DOMContentLoaded', function () {
    var c = document.getElementById('insight-empty-cta');
    if (c) c.addEventListener('click', function () {
      var add = document.getElementById('insight-add-btn');
      if (add) add.click();
    });
  });
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
```

- [ ] **Step 2: Manual smoke check**

Visit `http://daem-society.local/backstage/insights`. Expected:
- Header renders with new typography.
- Three KPI cards render: Published, Scheduled, Featured. Initially loading state, then values + sparklines.
- Toolbar: All / Published / Scheduled segmented switch, search box.
- Table renders existing rows with the new pill styles. Hover a row → action icons appear.
- Click `+ Add insight` → slide-panel opens from the right with form fields.
- ESC closes the panel.
- Click row's pencil icon → slide-panel opens with the row prefilled.
- Click trash icon → confirm-dialog appears, click Cancel → dialog closes.
- Light theme + dark theme: check both look correct.

If any step breaks, fix before commit.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add public/pages/backstage/insights/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Page(insights): redesign — KPIs + data-explorer + slide-panel editor + confirm delete"
```

---

### Task 19: Delete old `insight-modal.css` and `insight-modal.js`

**Files:**
- Delete: `C:/laragon/www/sites/daem-society/public/pages/backstage/insights/insight-modal.css`
- Delete: `C:/laragon/www/sites/daem-society/public/pages/backstage/insights/insight-modal.js`

- [ ] **Step 1: Verify nothing else still references these files**

```bash
cd /c/laragon/www/sites/daem-society && grep -rn "insight-modal" public/ 2>/dev/null
```

Expected: zero matches. If anything matches, the redesign in Task 18 missed a reference — fix before deleting.

- [ ] **Step 2: Delete the files**

```bash
cd /c/laragon/www/sites/daem-society
rm public/pages/backstage/insights/insight-modal.css
rm public/pages/backstage/insights/insight-modal.js
```

- [ ] **Step 3: Reload `/backstage/insights` to confirm no 404 in DevTools Network**

Expected: page works exactly as in Task 18.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society
git add -A public/pages/backstage/insights/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Cleanup(insights): remove old insight-modal.css/.js superseded by slide-panel"
```

---

### Task 20: Manual UAT + final regression sweep

- [ ] **Step 1: Verify all states on `/backstage/insights`**

Run `daems-platform.local` server. Visit `http://daem-society.local/backstage/insights` while logged in as admin. Verify:

| State | How to trigger | Expected |
|-------|---------------|----------|
| Loading | Reload with throttled DevTools network (slow 3G) | Skeleton rows + KPI cards in `.is-loading` shimmer until data arrives |
| Populated | Have ≥1 insight | Rows render with pills, hover reveals actions |
| Empty | Truncate insights for tenant `daems` (or rename them) | Empty state SVG + "+ Add insight" CTA |
| Error | Stop the platform server, reload | Inline error card with Retry button. Click Retry after restart → recovers |
| Slide-panel — open | Click `+ Add insight` | Slides in from right (250ms), backdrop fades, list visible behind |
| Slide-panel — ESC | Press ESC | Closes |
| Slide-panel — backdrop click | Click outside panel | Closes |
| Slide-panel — close X | Click X | Closes |
| Edit row | Click pencil icon on row | Panel opens with row prefilled |
| Save edit | Edit a field, click Save | Panel closes, table reflects change, KPIs recompute |
| Confirm delete | Click trash icon → Delete in dialog | Row removed, KPIs recompute |
| Confirm delete (Cancel) | Click trash icon → Cancel in dialog | Nothing changes |
| Theme parity | Toggle theme via header button | Light + Dark both look correct, no broken colors |
| Mobile | DevTools → device toolbar → 375 wide | Slide-panel = 100% width, KPI grid stacks to 1 column, search bar still functional |
| Keyboard | Tab through table → Tab into open panel | Focus visible, ESC closes, Enter submits in confirm dialog |

- [ ] **Step 2: No-transform-hover check**

```bash
cd /c/laragon/www/sites/daem-society && \
  grep -nE ":hover\s*\{[^}]*transform\s*:\s*(translate|scale)" public/assets/css/daems-backstage-system.css || echo "OK: no transform on hover"
```

Expected: `OK: no transform on hover`. Any match is a bug — fix and recommit before continuing.

- [ ] **Step 3: Backend full test suite from clean state**

```bash
cd /c/laragon/www/daems-platform && composer test:all 2>&1 | tail -20
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | tail -5
```

Expected: tests green, PHPStan 0 errors.

- [ ] **Step 4: Show commit summary across both repos**

```bash
cd /c/laragon/www/daems-platform && echo "PLATFORM:" && git log --oneline dev..HEAD
cd /c/laragon/www/sites/daem-society && echo && echo "SOCIETY:" && git log --oneline dev..HEAD
```

Report each list of SHAs to the user. Wait for explicit "pushaa" before pushing.

---

## Self-Review

After writing this plan, I checked:

**Spec coverage:**
- §3 visual direction (Linear/Notion table + Stripe-style KPI cards w/ sparklines, slide-in edit, centered confirm) → Tasks 11–18.
- §3 hard constraint on hover animations → CSS in Task 11 has no transform-on-hover; Task 20 step 2 verifies via grep.
- §4.1 `.kpi-card` → Task 11 (CSS) + Task 13 (partial) + Task 17 (sparkline init via Sparkline helper) + Task 18 (page uses partial).
- §4.2 `.data-explorer` → Task 11 (CSS) + Task 18 (page applies layout).
- §4.3 `.pill` (all variants) → Task 11.
- §4.3a `.btn` variants → Task 11.
- §4.4 `.slide-panel` (with ESC, backdrop close, focus trap, body scroll lock, mobile width) → Task 11 (CSS) + Task 12 (JS controller). Mobile 100% width covered in CSS media queries; Task 20 verifies.
- §4.5 `.confirm-dialog` (Promise return, ESC cancel, Enter confirm) → Task 12 (JS); animation = scale + opacity, not on hover.
- §4.6 `.empty-state` → Task 11 (CSS) + Task 13 (partial) + Task 14 (SVG) + Task 18 (page mount + CTA wiring).
- §4.7 `.skeleton-row` → Task 11 (CSS variants) + Task 17 (rendering).
- §4.8 `.error-state` → Task 11 (CSS) + Task 17 (rendering with Retry).
- §5.1/5.2/5.3 file changes → all enumerated tasks above plus Task 19 deletes.
- §6 KPI definitions (Published/Scheduled/Featured, 3 cards, 30-day sparklines) → Task 1–9 backend, Task 17–18 frontend.
- §7 page-flow behaviors → Task 17 (insight-panel.js) covers all rows; Task 20 step 1 verifies.
- §10 testing → Task 20 step 1.
- §11 acceptance criteria → covered task-by-task.

**Placeholder scan:**
- Searched for "TBD", "TODO", "implement later", "fill in details", "Add appropriate error handling", "Similar to Task". None found.
- Every code step contains the actual code; every test step contains the actual test.
- Step "If the harness API was guessed wrong" in Task 8 acknowledges the fallback path explicitly with a `grep` to read the harness.

**Type consistency:**
- `statsForTenant(TenantId): array` used identically in interface (Task 1), SQL impl (Task 2), in-memory impl (Task 3), use case (Task 4), controller (Task 5).
- Sparkline data shape `[{date: string, value: int}, ...]` consistent across backend, controller JSON, frontend `Sparkline.init`.
- KPI shape `{value: int, sparkline: [...]}` consistent across all three KPIs.
- KPI ids `published`/`scheduled`/`featured` used identically in CSS (Task 11 doesn't hard-code; uses data-attrs), partial parameters (Task 13), backend (Task 1), and frontend (Task 17 `KPI_COLORS` map + `data-kpi` attribute on cards in Task 18).

**Scope check:** This plan covers Phase 1 only — the system foundation + insights page. Other 8 backstage pages explicitly out of scope (§8 of spec). No accidental scope creep.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-25-backstage-redesign-phase1.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
