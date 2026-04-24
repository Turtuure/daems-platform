# Global Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a single search bar that finds content across events, projects, forum topics, insights, and (admin-only) members on both public and backstage surfaces.

**Architecture:** Two PRs. Platform PR adds `Daems\Domain\Search` + `Daems\Application\Search\Search` + `SqlSearchRepository` + `SearchController` + 3 FULLTEXT migrations + write-path sync hooks on insight/forum repos. Society PR adds 4 routes + 2 proxies + shared typeahead JS/CSS/partial + public top-nav overlay + backstage top-bar input + 2 dedicated result pages.

**Tech Stack:** PHP 8.x clean architecture, MySQL 8.4 InnoDB FULLTEXT (NATURAL LANGUAGE MODE), vanilla JS, Playwright E2E.

**Spec:** `docs/superpowers/specs/2026-04-24-global-search-design.md`

**Branches:** `global-search` in both `daems-platform` and `daem-society`.

---

## File structure

### daems-platform (create)

| Path | Role |
|---|---|
| `database/migrations/059_fulltext_events_projects_i18n.sql` | FULLTEXT on events_i18n + projects_i18n |
| `database/migrations/060_search_text_insights.sql` | Add `insights.search_text` + FULLTEXT + backfill |
| `database/migrations/061_search_text_forum_topics.sql` | Add `forum_topics.first_post_search_text` + FULLTEXT + backfill |
| `src/Domain/Search/SearchHit.php` | Unified result VO |
| `src/Domain/Search/SearchRepositoryInterface.php` | Port |
| `src/Application/Search/Search/Search.php` | Use case |
| `src/Application/Search/Search/SearchInput.php` | Input DTO |
| `src/Application/Search/Search/SearchOutput.php` | Output DTO |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlSearchRepository.php` | MySQL adapter |
| `src/Infrastructure/Adapter/Api/Controller/SearchController.php` | HTTP adapter (`public`, `backstage` methods) |
| `tests/Support/Fake/InMemorySearchRepository.php` | Test double |
| `tests/Unit/Application/Search/SearchTest.php` | Use case unit tests |
| `tests/Integration/Application/SearchIntegrationTest.php` | SQL repo integration tests |

### daems-platform (modify)

| Path | Action |
|---|---|
| `routes/api.php` | Add 2 routes (`/api/v1/search`, `/api/v1/backstage/search`) |
| `bootstrap/app.php` | Wire SearchController + Search + SqlSearchRepository |
| `tests/Support/KernelHarness.php` | Wire SearchController + Search + InMemorySearchRepository |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` | `save()` also writes `search_text` |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php` | `savePost()` updates `forum_topics.first_post_search_text` when post is first-by-sort_order |
| `tests/Integration/Application/SqlInsightRepositoryTest.php` (if it exists; else create) | Cover search_text sync |
| `tests/Integration/Application/SqlForumRepositoryTest.php` (if it exists; else create) | Cover first_post_search_text sync |

### daem-society (create)

| Path | Role |
|---|---|
| `public/pages/search/index.php` | Public `/search?q=…` results page |
| `public/pages/backstage/search/index.php` | Backstage `/backstage/search?q=…` page |
| `public/api/search.php` | Public proxy → platform `/api/v1/search` |
| `public/api/backstage/search.php` | Authed proxy → platform `/api/v1/backstage/search` |
| `public/partials/search-typeahead.php` | Shared input + dropdown markup |
| `public/assets/js/daems-search.js` | Shared debounce/fetch/render |
| `public/assets/css/daems-search.css` | Dropdown + page styles |
| `tests/e2e/global-search.spec.ts` | 404-safe smoke tests |

### daem-society (modify)

| Path | Action |
|---|---|
| `public/index.php` | Add 4 routes |
| `public/partials/top-nav.php` | Add search icon + overlay container |
| Backstage top-bar template (locate in task 19) | Add typeahead input |

---

## Conventions

- **Commit identity:** every commit `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. NO `Co-Authored-By:`. Never push without explicit instruction.
- **Working directory:** tasks 1–16 in `C:\laragon\www\daems-platform`, 17–22 in `C:\laragon\www\sites\daem-society`. Bash `cd` resets between tool calls — prefix every shell command with `cd /c/laragon/www/<repo>/ && …`.
- **Never stage `.claude/`** or `.superpowers/`.
- **DI BOTH-wire** + **route-in-api.php**: every new controller method binds in `bootstrap/app.php` AND `tests/Support/KernelHarness.php` AND has a route in `routes/api.php` (the fix-missing-route lesson from `/me/privacy`).
- **PHPStan stays at level 9 = 0 errors. PHPUnit Unit + Integration + E2E stay green.**
- **Forbidden tool:** never invoke `mcp__code-review-graph__*`.

---

## PLATFORM PR — schema + backend

### Task 1: Migration 059 — FULLTEXT on events_i18n + projects_i18n

**Files:** Create `database/migrations/059_fulltext_events_projects_i18n.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Migration 059: add FULLTEXT indexes to i18n content tables for global search.
-- No data changes; indexes are built from existing rows.

ALTER TABLE events_i18n   ADD FULLTEXT INDEX ft_title_body (title, location, description);
ALTER TABLE projects_i18n ADD FULLTEXT INDEX ft_title_body (title, summary, description);
```

- [ ] **Step 2: Apply to dev DB**

```bash
cd /c/laragon/www/daems-platform && \
  "C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
    < database/migrations/059_fulltext_events_projects_i18n.sql
```

Expected: no output; both indexes created.

- [ ] **Step 3: Verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  -e "SHOW INDEXES FROM events_i18n WHERE Key_name='ft_title_body'; SHOW INDEXES FROM projects_i18n WHERE Key_name='ft_title_body';"
```

Expected: 3 + 3 rows (one per indexed column).

- [ ] **Step 4: Record in schema_migrations**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  -e "INSERT INTO schema_migrations (version) VALUES ('059_fulltext_events_projects_i18n');"
```

- [ ] **Step 5: Commit**

```bash
git checkout -b global-search dev 2>/dev/null || git checkout global-search
git add database/migrations/059_fulltext_events_projects_i18n.sql
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Mig(059): FULLTEXT on events_i18n + projects_i18n (title+location+description, title+summary+description)"
```

### Task 2: Migration 060 — insights.search_text + FULLTEXT

**Files:** Create `database/migrations/060_search_text_insights.sql`

Insights store HTML in `content`. Search needs plain text; we denormalise into `search_text` on write (task 15) and FULLTEXT-index it alongside the title.

- [ ] **Step 1: Write the migration**

```sql
-- Migration 060: add search_text to insights + FULLTEXT index.
-- search_text is the stripped-tags version of content, populated by
-- SqlInsightRepository::save() at write time. Backfill with
-- a PHP one-off in step 3 below.

ALTER TABLE insights
  ADD COLUMN search_text MEDIUMTEXT NULL AFTER content,
  ADD FULLTEXT INDEX ft_title_body (title, search_text);
```

- [ ] **Step 2: Apply**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  < database/migrations/060_search_text_insights.sql
```

- [ ] **Step 3: Backfill search_text**

Create `database/migrations/060_search_text_insights_backfill.php` (runs once):

```php
<?php
// One-off backfill — strips HTML from insights.content into insights.search_text.
// Runtime writes go through SqlInsightRepository::save() instead (task 15).
declare(strict_types=1);

$pdo = new PDO('mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4', 'root', 'salasana', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rows = $pdo->query('SELECT id, content FROM insights WHERE search_text IS NULL')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('UPDATE insights SET search_text = ? WHERE id = ?');
foreach ($rows as $r) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $r['content'])) ?? '');
    $stmt->execute([$plain, $r['id']]);
}
echo "Backfilled " . count($rows) . " rows\n";
```

Run it:

```bash
cd /c/laragon/www/daems-platform && php database/migrations/060_search_text_insights_backfill.php
```

Expected: `Backfilled N rows` where N = current insight count.

- [ ] **Step 4: Record in schema_migrations + commit**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  -e "INSERT INTO schema_migrations (version) VALUES ('060_search_text_insights');"

git add database/migrations/060_search_text_insights.sql database/migrations/060_search_text_insights_backfill.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Mig(060): insights.search_text (MEDIUMTEXT) + FULLTEXT(title, search_text) + one-off strip-tags backfill"
```

### Task 3: Migration 061 — forum_topics.first_post_search_text + FULLTEXT

Forum search matches topic title + first-post body only. Denormalise the first post's `content` (plain text in forum_posts, no HTML) into a new column on forum_topics.

- [ ] **Step 1: Write the migration**

Create `database/migrations/061_search_text_forum_topics.sql`:

```sql
-- Migration 061: add first_post_search_text to forum_topics + FULLTEXT index.
-- first_post_search_text is synced by SqlForumRepository::savePost() when
-- the post being saved is the first-by-sort_order post in its topic.

ALTER TABLE forum_topics
  ADD COLUMN first_post_search_text MEDIUMTEXT NULL,
  ADD FULLTEXT INDEX ft_title_body (title, first_post_search_text);
```

- [ ] **Step 2: Apply**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  < database/migrations/061_search_text_forum_topics.sql
```

- [ ] **Step 3: Backfill**

Create `database/migrations/061_search_text_forum_topics_backfill.php`:

```php
<?php
declare(strict_types=1);

$pdo = new PDO('mysql:host=127.0.0.1;dbname=daems_db;charset=utf8mb4', 'root', 'salasana', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = 'UPDATE forum_topics ft
           SET first_post_search_text = (
             SELECT content FROM forum_posts p
              WHERE p.topic_id = ft.id
              ORDER BY p.sort_order ASC, p.created_at ASC
              LIMIT 1
           )
         WHERE ft.first_post_search_text IS NULL';
$affected = $pdo->exec($sql);
echo "Backfilled {$affected} topics\n";
```

Run: `php database/migrations/061_search_text_forum_topics_backfill.php`

- [ ] **Step 4: Record in schema_migrations + commit**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db \
  -e "INSERT INTO schema_migrations (version) VALUES ('061_search_text_forum_topics');"

git add database/migrations/061_search_text_forum_topics.sql database/migrations/061_search_text_forum_topics_backfill.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Mig(061): forum_topics.first_post_search_text (MEDIUMTEXT) + FULLTEXT(title, first_post_search_text) + backfill"
```

### Task 4: SearchHit VO

**Files:**
- Create `src/Domain/Search/SearchHit.php`
- Create `tests/Unit/Domain/Search/SearchHitTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Search;

use Daems\Domain\Search\SearchHit;
use PHPUnit\Framework\TestCase;

final class SearchHitTest extends TestCase
{
    public function test_to_array_produces_flat_json_shape(): void
    {
        $hit = new SearchHit(
            entityType: 'event',
            entityId: '019d-uuid-xyz',
            title: 'Summer Meetup',
            snippet: '...a summer event for members...',
            url: '/events/summer-2026',
            localeCode: null,
            status: 'published',
            publishedAt: '2026-06-01',
            relevance: 3.14,
        );

        self::assertSame([
            'entity_type' => 'event',
            'entity_id' => '019d-uuid-xyz',
            'title' => 'Summer Meetup',
            'snippet' => '...a summer event for members...',
            'url' => '/events/summer-2026',
            'locale_code' => null,
            'status' => 'published',
            'published_at' => '2026-06-01',
            'relevance' => 3.14,
        ], $hit->toArray());
    }

    public function test_locale_code_present_when_fallback_used(): void
    {
        $hit = new SearchHit(
            'project', 'id', 'Title', 'snip', '/projects/p', 'en_GB', 'published', null, 1.0,
        );
        self::assertSame('en_GB', $hit->toArray()['locale_code']);
    }
}
```

- [ ] **Step 2: Run test — should fail with "class not found"**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Domain/Search/SearchHitTest.php 2>&1 | tail -5
```

Expected: error about `Daems\Domain\Search\SearchHit`.

- [ ] **Step 3: Implement SearchHit**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Search;

final class SearchHit
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $title,
        public readonly string $snippet,
        public readonly string $url,
        public readonly ?string $localeCode,
        public readonly string $status,
        public readonly ?string $publishedAt,
        public readonly float $relevance,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'url' => $this->url,
            'locale_code' => $this->localeCode,
            'status' => $this->status,
            'published_at' => $this->publishedAt,
            'relevance' => $this->relevance,
        ];
    }
}
```

- [ ] **Step 4: Test passes**

```bash
vendor/bin/phpunit tests/Unit/Domain/Search/SearchHitTest.php 2>&1 | tail -5
```

Expected: `OK (2 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Search/SearchHit.php tests/Unit/Domain/Search/SearchHitTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): SearchHit value object (unified result shape for all domains)"
```

### Task 5: SearchRepositoryInterface

**Files:** Create `src/Domain/Search/SearchRepositoryInterface.php`

- [ ] **Step 1: Implement the port**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Search;

interface SearchRepositoryInterface
{
    /**
     * Find matching entities within a single tenant.
     *
     * @param string $tenantId
     * @param string $query           trimmed, ≥2 chars (caller must validate)
     * @param string|null $type       null or 'all' = every domain the actor may see;
     *                                specific value = only that domain
     * @param bool $includeUnpublished true on backstage path (drafts etc. returned)
     * @param bool $actingUserIsAdmin  controls whether 'members' domain runs
     * @param int $limitPerDomain     5 for typeahead, 20 for dedicated page
     * @param string $currentLocale   for i18n fallback resolution (events/projects)
     * @return SearchHit[]
     */
    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array;
}
```

- [ ] **Step 2: PHPStan clean**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

Expected: `[OK] No errors`.

- [ ] **Step 3: Commit**

```bash
git add src/Domain/Search/SearchRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): SearchRepositoryInterface — single search() port for all domains"
```

### Task 6: InMemorySearchRepository test fake

**Files:** Create `tests/Support/Fake/InMemorySearchRepository.php`

Consumed by: KernelHarness (E2E) and SearchTest unit tests. Keeps a pre-seeded hit list; returns a case-insensitive substring-match filter.

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Search\SearchHit;
use Daems\Domain\Search\SearchRepositoryInterface;

final class InMemorySearchRepository implements SearchRepositoryInterface
{
    /** @var SearchHit[] */
    private array $hits = [];

    /** @param SearchHit[] $hits */
    public function seed(array $hits): void { $this->hits = $hits; }

    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array {
        $typeFilter = ($type === null || $type === 'all') ? null : $type;
        $needle = mb_strtolower($query);
        $perDomainCount = [];

        $out = [];
        foreach ($this->hits as $h) {
            if ($typeFilter !== null && $h->entityType !== $typeFilter) continue;
            if ($h->entityType === 'member' && !$actingUserIsAdmin) continue;
            if (!$includeUnpublished && $h->status !== 'published') continue;
            if (mb_stripos($h->title, $needle) === false
                && mb_stripos($h->snippet, $needle) === false) continue;

            $perDomainCount[$h->entityType] = ($perDomainCount[$h->entityType] ?? 0) + 1;
            if ($perDomainCount[$h->entityType] > $limitPerDomain) continue;

            $out[] = $h;
        }
        return $out;
    }
}
```

- [ ] **Step 2: PHPStan**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

Expected: `[OK] No errors`.

- [ ] **Step 3: Commit**

```bash
git add tests/Support/Fake/InMemorySearchRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(search): InMemorySearchRepository fake for unit tests + KernelHarness"
```

### Task 7: Search use case + Input + Output + unit tests

**Files:**
- Create `src/Application/Search/Search/SearchInput.php`
- Create `src/Application/Search/Search/SearchOutput.php`
- Create `src/Application/Search/Search/Search.php`
- Create `tests/Unit/Application/Search/SearchTest.php`

- [ ] **Step 1: Implement Input**

`SearchInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

final class SearchInput
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $rawQuery,
        public readonly ?string $type,
        public readonly bool $includeUnpublished,
        public readonly bool $actingUserIsAdmin,
        public readonly int $limitPerDomain,
        public readonly string $currentLocale,
    ) {}
}
```

- [ ] **Step 2: Implement Output**

`SearchOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

use Daems\Domain\Search\SearchHit;

final class SearchOutput
{
    /** @param SearchHit[] $hits */
    public function __construct(
        public readonly array $hits,
        public readonly int $count,
        public readonly string $query,
        public readonly ?string $type,
        public readonly bool $partial,
        public readonly ?string $reason,
    ) {}
}
```

- [ ] **Step 3: Write the failing unit tests**

`tests/Unit/Application/Search/SearchTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Search;

use Daems\Application\Search\Search\Search;
use Daems\Application\Search\Search\SearchInput;
use Daems\Domain\Search\SearchHit;
use Daems\Tests\Support\Fake\InMemorySearchRepository;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    private const T = '019d-tenant';

    public function test_rejects_query_below_min_length(): void
    {
        $uc = new Search(new InMemorySearchRepository());
        $out = $uc->execute(new SearchInput(self::T, 'a', null, false, false, 5, 'fi_FI'));
        self::assertSame(0, $out->count);
        self::assertSame('query_too_short', $out->reason);
    }

    public function test_invalid_type_falls_back_to_all(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([$this->hit('event', 'Summer', 'published')]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', 'bogus', false, false, 5, 'fi_FI'));
        self::assertNull($out->type);  // normalised
        self::assertSame(1, $out->count);
    }

    public function test_members_excluded_when_not_admin_on_type_all(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([
            $this->hit('event', 'Summer', 'published'),
            $this->hit('member', 'Summer Smith', 'published'),
        ]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', null, false, false, 5, 'fi_FI'));
        $types = array_map(fn($h) => $h->entityType, $out->hits);
        self::assertSame(['event'], $types);
    }

    public function test_includes_drafts_when_include_unpublished_true(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([
            $this->hit('event', 'Summer 1', 'draft'),
            $this->hit('event', 'Summer 2', 'published'),
        ]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', null, true, false, 5, 'fi_FI'));
        self::assertSame(2, $out->count);
    }

    private function hit(string $type, string $title, string $status): SearchHit
    {
        return new SearchHit($type, 'id-' . uniqid(), $title, $title, "/$type", null, $status, null, 1.0);
    }
}
```

- [ ] **Step 4: Run — should fail with "class Search not found"**

```bash
vendor/bin/phpunit tests/Unit/Application/Search/SearchTest.php 2>&1 | tail -5
```

- [ ] **Step 5: Implement Search use case**

`Search.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

use Daems\Domain\Search\SearchRepositoryInterface;

final class Search
{
    private const MIN_QUERY_LEN = 2;
    private const ALLOWED_TYPES = ['all', 'events', 'projects', 'forum', 'insights', 'members'];
    /** Map use-case type names to SearchHit entity_type values. */
    private const TYPE_TO_ENTITY = [
        'events' => 'event',
        'projects' => 'project',
        'forum' => 'forum_topic',
        'insights' => 'insight',
        'members' => 'member',
    ];

    public function __construct(private readonly SearchRepositoryInterface $repo) {}

    public function execute(SearchInput $in): SearchOutput
    {
        $query = trim($in->rawQuery);
        if (mb_strlen($query) < self::MIN_QUERY_LEN) {
            return new SearchOutput([], 0, $query, null, false, 'query_too_short');
        }

        $type = in_array($in->type, self::ALLOWED_TYPES, true) ? $in->type : null;
        $type = ($type === 'all') ? null : $type;

        $entityType = $type === null ? null : (self::TYPE_TO_ENTITY[$type] ?? null);

        $hits = $this->repo->search(
            tenantId: $in->tenantId,
            query: $query,
            type: $entityType,
            includeUnpublished: $in->includeUnpublished,
            actingUserIsAdmin: $in->actingUserIsAdmin,
            limitPerDomain: $in->limitPerDomain,
            currentLocale: $in->currentLocale,
        );

        return new SearchOutput($hits, count($hits), $query, $type, false, null);
    }
}
```

- [ ] **Step 6: Run tests — all pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Search/SearchTest.php 2>&1 | tail -5
```

Expected: `OK (4 tests)`.

- [ ] **Step 7: Commit**

```bash
git add src/Application/Search/Search/ tests/Unit/Application/Search/SearchTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): Search use case (min-length guard, type normalisation, member gating)"
```

### Task 8: Integration test skeleton + searchEvents SQL

**Files:**
- Create `tests/Integration/Application/SearchIntegrationTest.php`
- Create `src/Infrastructure/Adapter/Persistence/Sql/SqlSearchRepository.php` (partial)

- [ ] **Step 1: Integration test skeleton with seed data for events**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSearchRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SearchIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $otherTenantId;
    private string $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);

        $this->conn = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->otherTenantId = Uuid7::generate()->value();
        $this->adminUserId = Uuid7::generate()->value();

        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantId, 'daems-st', 'Daems ST']);
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->otherTenantId, 'sahegroup-st', 'SaheGroup ST']);

        $this->seedEvents();
    }

    private function seedEvents(): void
    {
        $pdo = $this->pdo();
        $e1 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, status, start_at, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$e1, $this->tenantId, 'summer-2026', 'published', '2026-06-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'fi_FI', 'Kesätapaaminen 2026', 'Helsinki', 'Vuoden tärkein tapahtuma']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'en_GB', 'Summer Meetup 2026', 'Helsinki', 'The highlight of the year']);

        // Draft event (should be excluded from public search, visible to admin)
        $e2 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, status, start_at, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$e2, $this->tenantId, 'draft-summer', 'draft', '2026-07-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e2, 'fi_FI', 'Luonnos kesällä', '', '']);

        // Other-tenant event (must not leak into tenant-scoped results)
        $e3 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, status, start_at, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$e3, $this->otherTenantId, 'sahegroup-summer', 'published', '2026-06-15']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e3, 'fi_FI', 'Summer Dar es Salaam', 'Dar es Salaam', 'SaheGroup event']);
    }

    public function test_finds_event_in_current_locale(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'kesätapaaminen', 'event', false, false, 5, 'fi_FI');
        self::assertCount(1, $hits);
        self::assertSame('Kesätapaaminen 2026', $hits[0]->title);
        self::assertNull($hits[0]->localeCode);
    }

    public function test_tenant_scope_isolates_results(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'Dar es Salaam', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $hits, 'Other-tenant events must not leak');
    }

    public function test_excludes_draft_events_for_public_but_returns_for_admin(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $public = $repo->search($this->tenantId, 'Luonnos', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $public);

        $admin = $repo->search($this->tenantId, 'Luonnos', 'event', true, true, 5, 'fi_FI');
        self::assertCount(1, $admin);
        self::assertSame('draft', $admin[0]->status);
    }
}
```

- [ ] **Step 2: Run — fail with "class SqlSearchRepository not found"**

```bash
vendor/bin/phpunit tests/Integration/Application/SearchIntegrationTest.php 2>&1 | tail -5
```

- [ ] **Step 3: Implement SqlSearchRepository::search — events branch**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Search\SearchHit;
use Daems\Domain\Search\SearchRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlSearchRepository implements SearchRepositoryInterface
{
    private const SNIPPET_WINDOW = 80;

    public function __construct(private readonly Connection $db) {}

    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array {
        $wantAll = ($type === null);
        $out = [];
        if ($wantAll || $type === 'event')       $out = array_merge($out, $this->searchEvents($tenantId, $query, $includeUnpublished, $limitPerDomain, $currentLocale));
        // project/insight/forum_topic/member branches added in later tasks
        return $out;
    }

    /** @return SearchHit[] */
    private function searchEvents(string $tenantId, string $query, bool $includeUnpublished, int $limit, string $locale): array
    {
        $statusFilter = $includeUnpublished ? '' : " AND e.status='published'";
        $sql = "SELECT e.id, e.slug, e.status, e.start_at,
                       COALESCE(i_cur.title, i_en.title) AS title,
                       COALESCE(i_cur.description, i_en.description) AS description,
                       COALESCE(i_cur.location, i_en.location) AS location,
                       IF(i_cur.title IS NOT NULL, NULL, 'en_GB') AS locale_code,
                       GREATEST(
                           IFNULL(MATCH(i_fi.title, i_fi.location, i_fi.description) AGAINST (:q), 0),
                           IFNULL(MATCH(i_en.title, i_en.location, i_en.description) AGAINST (:q), 0),
                           IFNULL(MATCH(i_sw.title, i_sw.location, i_sw.description) AGAINST (:q), 0)
                       ) AS relevance
                FROM events e
                LEFT JOIN events_i18n i_cur ON i_cur.event_id = e.id AND i_cur.locale = :locale
                LEFT JOIN events_i18n i_en  ON i_en.event_id  = e.id AND i_en.locale  = 'en_GB'
                LEFT JOIN events_i18n i_fi  ON i_fi.event_id  = e.id AND i_fi.locale  = 'fi_FI'
                LEFT JOIN events_i18n i_sw  ON i_sw.event_id  = e.id AND i_sw.locale  = 'sw_TZ'
                WHERE e.tenant_id = :t {$statusFilter}
                  AND (
                      MATCH(i_fi.title, i_fi.location, i_fi.description) AGAINST (:q)
                   OR MATCH(i_en.title, i_en.location, i_en.description) AGAINST (:q)
                   OR MATCH(i_sw.title, i_sw.location, i_sw.description) AGAINST (:q)
                  )
                ORDER BY relevance DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [':t' => $tenantId, ':q' => $query, ':locale' => $locale]);

        $hits = [];
        foreach ($rows as $r) {
            $title = (string) ($r['title'] ?? '');
            $description = (string) ($r['description'] ?? '');
            $hits[] = new SearchHit(
                entityType: 'event',
                entityId: (string) $r['id'],
                title: $title,
                snippet: self::snippet($description . ' ' . (string) ($r['location'] ?? ''), $query),
                url: '/events/' . (string) $r['slug'],
                localeCode: ($r['locale_code'] === null) ? null : (string) $r['locale_code'],
                status: (string) $r['status'],
                publishedAt: ($r['start_at'] ?? null) !== null ? substr((string) $r['start_at'], 0, 10) : null,
                relevance: (float) $r['relevance'],
            );
        }
        return $hits;
    }

    private static function snippet(string $haystack, string $needle): string
    {
        $pos = mb_stripos($haystack, $needle);
        if ($pos === false) return mb_substr($haystack, 0, self::SNIPPET_WINDOW);
        $start = max(0, $pos - 30);
        return ($start > 0 ? '…' : '') . mb_substr($haystack, $start, self::SNIPPET_WINDOW);
    }
}
```

- [ ] **Step 4: Run integration tests — all 3 event tests pass**

```bash
vendor/bin/phpunit tests/Integration/Application/SearchIntegrationTest.php 2>&1 | tail -5
```

Expected: `OK (3 tests)`.

- [ ] **Step 5: PHPStan clean**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlSearchRepository.php tests/Integration/Application/SearchIntegrationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): SqlSearchRepository::searchEvents with i18n fallback + integration tests"
```

### Task 9: searchProjects branch

**Files:** Modify `SqlSearchRepository.php`, extend `SearchIntegrationTest.php`.

- [ ] **Step 1: Add seed + failing test for projects**

Inside `SearchIntegrationTest::setUp` append `$this->seedProjects();`, add:

```php
private function seedProjects(): void
{
    $pdo = $this->pdo();
    $p1 = Uuid7::generate()->value();
    $pdo->prepare("INSERT INTO projects (id, tenant_id, slug, status, created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$p1, $this->tenantId, 'solar-roof', 'published']);
    $pdo->prepare('INSERT INTO projects_i18n (project_id, locale, title, summary, description) VALUES (?,?,?,?,?)')
        ->execute([$p1, 'fi_FI', 'Aurinkokatto', 'Kerrostalon aurinkopaneelit', 'Tämä projekti asentaa paneeleita']);
}

public function test_finds_project_in_current_locale(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $hits = $repo->search($this->tenantId, 'aurinkokatto', 'project', false, false, 5, 'fi_FI');
    self::assertCount(1, $hits);
    self::assertSame('project', $hits[0]->entityType);
}
```

- [ ] **Step 2: Run — fail (no project branch in repo)**

- [ ] **Step 3: Add `searchProjects` private method to SqlSearchRepository**

Mirror `searchEvents` structure but over `projects` + `projects_i18n`. Columns: `title, summary, description`. Status filter: `p.status='published'` + `p.archived_at IS NULL` when not includeUnpublished. URL: `'/projects/' . $row['slug']`. Snippet: `description . ' ' . summary`.

Call site inside `search()`:

```php
if ($wantAll || $type === 'project') $out = array_merge($out, $this->searchProjects($tenantId, $query, $includeUnpublished, $limitPerDomain, $currentLocale));
```

- [ ] **Step 4: Run — passes**

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlSearchRepository.php tests/Integration/Application/SearchIntegrationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): searchProjects branch (projects_i18n title+summary+description)"
```

### Task 10: searchInsights branch

**Files:** Modify `SqlSearchRepository.php`, extend `SearchIntegrationTest.php`.

- [ ] **Step 1: Seed + failing test**

```php
private function seedInsights(): void
{
    $pdo = $this->pdo();
    $i1 = Uuid7::generate()->value();
    $pdo->prepare("INSERT INTO insights
        (id, tenant_id, slug, title, category, category_label, featured, published_date, author, reading_time, excerpt, tags_json, content, search_text, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$i1, $this->tenantId, 'solar-future', 'Solar Future', 'tech', 'Tech', 0,
            '2026-01-15', 'Sam', 5, 'Solar panels are booming', '[]',
            '<p>Long form HTML <strong>solar</strong> content</p>',
            'Long form HTML solar content',
        ]);
    // future-dated insight (must not appear in public search)
    $i2 = Uuid7::generate()->value();
    $pdo->prepare("INSERT INTO insights
        (id, tenant_id, slug, title, category, category_label, featured, published_date, author, reading_time, excerpt, tags_json, content, search_text, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$i2, $this->tenantId, 'future-post', 'Future Solar Post', 'tech', 'Tech', 0,
            '2099-01-01', 'Sam', 3, 'x', '[]', 'body', 'body about solar']);
}

public function test_finds_insight_by_search_text(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $hits = $repo->search($this->tenantId, 'solar', 'insight', false, false, 5, 'fi_FI');
    $titles = array_map(fn($h) => $h->title, $hits);
    self::assertContains('Solar Future', $titles);
    self::assertNotContains('Future Solar Post', $titles, 'Future-dated insights must be hidden in public search');
}

public function test_admin_sees_future_dated_insight(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $hits = $repo->search($this->tenantId, 'solar', 'insight', true, true, 5, 'fi_FI');
    $titles = array_map(fn($h) => $h->title, $hits);
    self::assertContains('Future Solar Post', $titles);
}
```

Call `$this->seedInsights();` from `setUp`.

- [ ] **Step 2: Implement `searchInsights`**

Single-table query over `insights`: `FULLTEXT(title, search_text) AGAINST (:q)`. Public path adds `AND published_date <= CURDATE()`. URL: `/insights/{slug}`. publishedAt = `published_date`. Title + excerpt used for snippet.

- [ ] **Step 3: Run — passes. Commit.**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -am \
  "Feat(search): searchInsights branch (published_date gating for public, FULLTEXT on title+search_text)"
```

### Task 11: searchForum branch

**Files:** Modify `SqlSearchRepository.php`, extend `SearchIntegrationTest.php`.

- [ ] **Step 1: Seed + failing test**

```php
private function seedForum(): void
{
    $pdo = $this->pdo();
    $catId = Uuid7::generate()->value();
    $pdo->prepare('INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES (?,?,?,?,?,?,?)')
        ->execute([$catId, $this->tenantId, 'general', 'General', 'chat', '', 1]);
    $topicId = Uuid7::generate()->value();
    $pdo->prepare("INSERT INTO forum_topics
        (id, tenant_id, category_id, slug, title, author_name, avatar_initials, avatar_color, first_post_search_text, last_activity_at, last_activity_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW(),'anon',NOW())")
        ->execute([$topicId, $this->tenantId, $catId, 'welcome', 'Welcome to the forum',
            'anon', 'AN', 'blue', 'Please discuss climate action here']);
}

public function test_forum_body_match_returns_hit(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $hits = $repo->search($this->tenantId, 'climate', 'forum_topic', false, false, 5, 'fi_FI');
    self::assertCount(1, $hits);
    self::assertSame('forum_topic', $hits[0]->entityType);
}
```

- [ ] **Step 2: Implement `searchForum`**

Single-table query over `forum_topics`: `FULLTEXT(title, first_post_search_text) AGAINST (:q)`. No status filter (forum topics always visible if tenant matches). URL: `/forums/{category_slug}/{slug}` — JOIN forum_categories to resolve slug.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -am \
  "Feat(search): searchForum branch (FULLTEXT on title+first_post_search_text)"
```

### Task 12: searchMembers branch (admin-only, LIKE-based)

**Files:** Modify `SqlSearchRepository.php`, extend `SearchIntegrationTest.php`.

- [ ] **Step 1: Seed + failing test**

```php
private function seedMembers(): void
{
    $pdo = $this->pdo();
    $pdo->prepare("INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, member_number, public_avatar_visible, membership_type, created_at)
                   VALUES (?,?,?,?,?,0,?,1,?,NOW())")
        ->execute([$this->adminUserId, 'Kimi Räikkönen', 'kimi@daems.local',
            password_hash('x', PASSWORD_BCRYPT), '1979-10-17', '000777', 'full']);
    $pdo->prepare("INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?,?,?,NOW())")
        ->execute([$this->adminUserId, $this->tenantId, 'member']);
}

public function test_members_search_is_admin_only(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $nonAdmin = $repo->search($this->tenantId, 'Kimi', 'member', true, false, 5, 'fi_FI');
    self::assertCount(0, $nonAdmin);

    $admin = $repo->search($this->tenantId, 'Kimi', 'member', true, true, 5, 'fi_FI');
    self::assertCount(1, $admin);
    self::assertSame('member', $admin[0]->entityType);
}

public function test_members_search_matches_email(): void
{
    $repo = new SqlSearchRepository($this->conn);
    $hits = $repo->search($this->tenantId, 'kimi@daems.local', 'member', true, true, 5, 'fi_FI');
    self::assertCount(1, $hits);
}
```

- [ ] **Step 2: Implement `searchMembers`**

LIKE-based (FULLTEXT min-token is unreliable for short names):

```sql
SELECT u.id, u.name, u.email, u.member_number, u.membership_type, ut.role
FROM users u
JOIN user_tenants ut ON ut.user_id = u.id
WHERE ut.tenant_id = :t
  AND u.deleted_at IS NULL
  AND (u.name LIKE :pat OR u.email LIKE :pat)
LIMIT {$limit}
```

Bind `:pat = '%' . $query . '%'` (escape SQL wildcards in query: `addcslashes($query, "%_\\")`). Relevance is synthetic: 1.0 if exact name prefix match, 0.8 otherwise. URL: `'/backstage/members?q=' . urlencode($query)`. Snippet: `name . ' · ' . member_number . ' · ' . role`.

Gate in the main `search()`:

```php
if ($actingUserIsAdmin && ($wantAll || $type === 'member')) {
    $out = array_merge($out, $this->searchMembers(...));
}
```

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -am \
  "Feat(search): searchMembers branch (admin-only, LIKE on name+email; FULLTEXT min-token unreliable for short names)"
```

### Task 13: SearchController (public + backstage methods)

**Files:**
- Create `src/Infrastructure/Adapter/Api/Controller/SearchController.php`

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Search\Search\Search;
use Daems\Application\Search\Search\SearchInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class SearchController
{
    public function __construct(private readonly Search $search) {}

    public function public(Request $request): Response
    {
        $tenantId = $request->tenantId() ?? '';
        $q = (string) ($request->query('q') ?? '');
        $type = $request->query('type');
        $type = is_string($type) ? $type : null;
        $limit = (int) ($request->query('limit') ?? 5);
        $locale = $request->locale() ?? 'fi_FI';

        if ($type === 'members') {
            return Response::json(['error' => 'invalid_type'], 422);
        }

        $out = $this->search->execute(new SearchInput(
            tenantId: $tenantId,
            rawQuery: $q,
            type: $type,
            includeUnpublished: false,
            actingUserIsAdmin: false,
            limitPerDomain: max(1, min(50, $limit)),
            currentLocale: $locale,
        ));

        return self::respond($out);
    }

    public function backstage(Request $request): Response
    {
        $actor = $request->requireActingUser();
        $tenantId = $request->tenantId() ?? '';
        $q = (string) ($request->query('q') ?? '');
        $type = $request->query('type');
        $type = is_string($type) ? $type : null;
        $limit = (int) ($request->query('limit') ?? 20);
        $locale = $request->locale() ?? 'fi_FI';

        $isAdmin = $actor->isPlatformAdmin() || $actor->isAdminIn($tenantId) || $actor->isModeratorIn($tenantId);

        if ($type === 'members' && !$isAdmin) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $out = $this->search->execute(new SearchInput(
            tenantId: $tenantId,
            rawQuery: $q,
            type: $type,
            includeUnpublished: true,
            actingUserIsAdmin: $isAdmin,
            limitPerDomain: max(1, min(50, $limit)),
            currentLocale: $locale,
        ));

        return self::respond($out);
    }

    private static function respond(\Daems\Application\Search\Search\SearchOutput $out): Response
    {
        return Response::json([
            'data' => array_map(static fn($h) => $h->toArray(), $out->hits),
            'meta' => [
                'count' => $out->count,
                'query' => $out->query,
                'type' => $out->type,
                'partial' => $out->partial,
                'reason' => $out->reason,
            ],
        ]);
    }
}
```

Note: `$actor->isAdminIn()` / `$actor->isModeratorIn()` mirror existing helpers elsewhere in the codebase; verify exact method names against `ActingUser` VO and adjust if different.

- [ ] **Step 2: PHPStan**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

- [ ] **Step 3: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/SearchController.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): SearchController::public + ::backstage (422 on public members, 403 on backstage non-admin members)"
```

### Task 14: Routes + DI wiring (BOTH containers)

**Files:**
- Modify `routes/api.php`
- Modify `bootstrap/app.php`
- Modify `tests/Support/KernelHarness.php`

- [ ] **Step 1: Routes**

Add in `routes/api.php` near the public verification route:

```php
// Public search (no auth). Locale middleware for i18n fallback resolution.
$router->get('/api/v1/search', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class)->public($req);
}, [TenantContextMiddleware::class, LocaleMiddleware::class]);

// Backstage search (auth required; members domain gated inside controller).
$router->get('/api/v1/backstage/search', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class)->backstage($req);
}, [TenantContextMiddleware::class, LocaleMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 2: DI — bootstrap/app.php**

Bind `SqlSearchRepository` to `SearchRepositoryInterface`, bind `Search` use case with the repo, bind `SearchController` with the use case. Pattern:

```php
$container->bind(\Daems\Domain\Search\SearchRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlSearchRepository(
        $c->make(Connection::class),
    ));
$container->bind(\Daems\Application\Search\Search\Search::class,
    static fn(Container $c) => new \Daems\Application\Search\Search\Search(
        $c->make(\Daems\Domain\Search\SearchRepositoryInterface::class),
    ));
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\SearchController(
        $c->make(\Daems\Application\Search\Search\Search::class),
    ));
```

- [ ] **Step 3: DI — KernelHarness.php**

Bind the same three but with `InMemorySearchRepository` instead of SQL:

```php
$container->bind(\Daems\Domain\Search\SearchRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Tests\Support\Fake\InMemorySearchRepository());
$container->bind(\Daems\Application\Search\Search\Search::class,
    static fn(Container $c) => new \Daems\Application\Search\Search\Search(
        $c->make(\Daems\Domain\Search\SearchRepositoryInterface::class),
    ));
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\SearchController(
        $c->make(\Daems\Application\Search\Search\Search::class),
    ));
```

- [ ] **Step 4: Sanity — search both files for the new class names**

```bash
grep -rn "SearchController" bootstrap/app.php tests/Support/KernelHarness.php routes/api.php
```

Expected: at least 2 matches in bootstrap, 2 in harness, 2 in routes. BOTH-wire rule satisfied.

- [ ] **Step 5: PHPStan + Unit + E2E suites stay green**

```bash
composer analyse && vendor/bin/phpunit --testsuite Unit && vendor/bin/phpunit --testsuite E2E
```

- [ ] **Step 6: Commit**

```bash
git add routes/api.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Wire(search): routes /api/v1/search + /backstage/search + DI in BOTH containers"
```

### Task 15: Write-path sync — SqlInsightRepository + SqlForumRepository

**Files:**
- Modify `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php`
- Modify `src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php`
- Create (or extend) integration tests for both

- [ ] **Step 1: Write failing tests**

Create `tests/Integration/Application/SearchSyncTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SearchSyncTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);
        $this->conn = new Connection([/* same as SearchIntegrationTest */]);
        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantId, 'daems-sync', 'Daems Sync']);
    }

    public function test_insight_save_populates_search_text_from_stripped_content(): void
    {
        $repo = new SqlInsightRepository($this->conn);
        $id = Uuid7::generate()->value();
        $insight = new \Daems\Domain\Insight\Insight(
            id: new \Daems\Domain\Insight\InsightId($id),
            tenantId: new \Daems\Domain\Tenant\TenantId($this->tenantId),
            slug: 'sync-check-' . $id,
            title: 'Sync Check',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            date: '2026-04-24',
            author: 'Sam',
            readingTime: 3,
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: '<p>plain text here and <strong>bold bits</strong></p>',
        );
        $repo->save($insight);

        $row = $this->pdo()->query("SELECT search_text FROM insights WHERE id = '{$id}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString('plain text here', (string) $row['search_text']);
        self::assertStringNotContainsString('<strong>', (string) $row['search_text']);
    }

    public function test_forum_first_post_save_updates_topic_first_post_search_text(): void
    {
        $pdo = $this->pdo();
        $catId   = Uuid7::generate()->value();
        $topicId = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$catId, $this->tenantId, 'gen', 'Gen', 'chat', '', 1]);
        $pdo->prepare("INSERT INTO forum_topics
            (id, tenant_id, category_id, slug, title, author_name, avatar_initials, avatar_color,
             last_activity_at, last_activity_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),'anon',NOW())")
            ->execute([$topicId, $this->tenantId, $catId, 'first-sync', 'Hi', 'anon', 'AN', 'blue']);

        $repo = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository($this->conn);
        $postId = Uuid7::generate()->value();
        $firstPost = new \Daems\Domain\Forum\ForumPost(
            id: new \Daems\Domain\Forum\ForumPostId($postId),
            tenantId: new \Daems\Domain\Tenant\TenantId($this->tenantId),
            topicId: $topicId,
            userId: null,
            authorName: 'anon',
            avatarInitials: 'AN',
            avatarColor: 'blue',
            role: '',
            roleClass: '',
            joinedText: '',
            content: 'Climate action starts here',
            likes: 0,
            createdAt: date('Y-m-d H:i:s'),
            sortOrder: 0,
        );
        $repo->savePost($firstPost);

        $row = $pdo->query("SELECT first_post_search_text FROM forum_topics WHERE id = '{$topicId}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Climate action starts here', (string) $row['first_post_search_text']);
    }
}
```

Flesh out test bodies using the real `Insight` and `ForumPost` domain constructors — inspect them in the current repos to get the right signature.

- [ ] **Step 2: Run — tests fail (no sync yet)**

- [ ] **Step 3: Modify `SqlInsightRepository::save`**

Locate the INSERT/UPDATE statement at approximately `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php:47`. Add `search_text` to both the INSERT column list and the `ON DUPLICATE KEY UPDATE` list. Compute it inline:

```php
$searchText = trim((string) preg_replace('/\s+/', ' ', strip_tags($insight->content())));
// …
$this->db->execute(
    'INSERT INTO insights (… , content, search_text, …)
     VALUES (…, ?, ?, …)
     ON DUPLICATE KEY UPDATE
       content = VALUES(content),
       search_text = VALUES(search_text),
       …',
    [… , $insight->content(), $searchText, … ],
);
```

- [ ] **Step 4: Modify `SqlForumRepository::savePost`**

After the existing INSERT/UPDATE at ~line 171, add a follow-up query that updates `forum_topics.first_post_search_text` IF this post is the first-by-sort_order for its topic:

```php
$this->db->execute(
    'UPDATE forum_topics ft
        SET ft.first_post_search_text = ?
      WHERE ft.id = ?
        AND ? = (
          SELECT sort_order FROM (
            SELECT MIN(sort_order) AS sort_order FROM forum_posts WHERE topic_id = ?
          ) AS s
        )',
    [$post->content(), $post->topicId(), $post->sortOrder(), $post->topicId()],
);
```

Simpler alternative if your dialect dislikes correlated subqueries:

```php
$minSort = $this->db->queryValue('SELECT MIN(sort_order) FROM forum_posts WHERE topic_id = ?', [$post->topicId()]);
if ((int) $minSort === (int) $post->sortOrder()) {
    $this->db->execute(
        'UPDATE forum_topics SET first_post_search_text = ? WHERE id = ?',
        [$post->content(), $post->topicId()],
    );
}
```

Use whichever matches the `Connection` API of this codebase.

- [ ] **Step 5: Run tests — all pass**

```bash
vendor/bin/phpunit tests/Integration/Application/SearchSyncTest.php 2>&1 | tail -5
```

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php \
        tests/Integration/Application/SearchSyncTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Sync(search): SqlInsightRepository + SqlForumRepository write search_text on save (no DB triggers)"
```

### Task 16: Push platform branch + CI + PR + merge

- [ ] **Step 1: Full local suite green**

```bash
cd /c/laragon/www/daems-platform && composer analyse && \
  vendor/bin/phpunit --testsuite Unit && \
  vendor/bin/phpunit --testsuite Integration --filter "Search" && \
  vendor/bin/phpunit --testsuite E2E
```

Expected: PHPStan OK, Unit all green, Search-filtered Integration all green, E2E all green.

- [ ] **Step 2: Push**

```bash
git push -u origin global-search 2>&1 | tail -3
```

- [ ] **Step 3: Open PR — draft to trigger CI first**

```bash
gh pr create --draft --base dev --head global-search \
  --title "WIP: Global search backend (API + FULLTEXT + sync hooks)" \
  --body "Draft to trigger CI."
```

- [ ] **Step 4: Watch CI**

```bash
RUN_ID=$(gh run list --branch global-search --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

Expected: PHP 8.1, PHP 8.3 green.

- [ ] **Step 5: Move PR to ready + report URL**

```bash
PR_NUM=$(gh pr list --head global-search --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Global search backend (API + FULLTEXT indexes + write-path sync)" \
    --body "$(cat <<'EOF'
## Summary
- 3 migrations: FULLTEXT on events_i18n + projects_i18n; insights.search_text; forum_topics.first_post_search_text
- Clean-architecture Search module (Domain/Application/Infrastructure)
- Two routes: GET /api/v1/search (public) + /api/v1/backstage/search (authed)
- Write-path sync: SqlInsightRepository + SqlForumRepository populate denormalised columns on save
- Unit + Integration + E2E green

## Spec / Plan
- Spec: docs/superpowers/specs/2026-04-24-global-search-design.md
- Plan: docs/superpowers/plans/2026-04-24-global-search.md

Depends on: none. Pairs with society branch `global-search` (separate PR) which delivers the UI.
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Platform PR #$PR_NUM ready"
```

- [ ] **Step 6: Wait for explicit "mergaa platform" before merging**

```bash
gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

---

## SOCIETY PR — frontend

Work in `C:\laragon\www\sites\daem-society`. Assumes platform routes are live on `http://daems-platform.local/api/v1/...`.

### Task 17: Branch + routes + proxies

**Files:**
- Create `public/api/search.php`
- Create `public/api/backstage/search.php`
- Modify `public/index.php` (add 4 routes)

- [ ] **Step 1: Branch**

```bash
cd /c/laragon/www/sites/daem-society && \
  git checkout -b global-search dev 2>/dev/null || git checkout global-search
```

- [ ] **Step 2: Public proxy**

`public/api/search.php`:

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json');

$qs = http_build_query(array_filter([
    'q'     => $_GET['q']     ?? null,
    'type'  => $_GET['type']  ?? null,
    'limit' => $_GET['limit'] ?? null,
], static fn($v) => $v !== null && $v !== ''));

$ch = curl_init('http://daems-platform.local/api/v1/search' . ($qs !== '' ? "?{$qs}" : ''));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Accept-Language: ' . I18n::locale(),
        'Host: daems-platform.local',
    ],
]);
$json = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($code >= 100 ? $code : 502);
echo (string) $json;
```

- [ ] **Step 3: Backstage proxy**

`public/api/backstage/search.php`:

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$token = (string) ($_SESSION['token'] ?? '');
$qs = http_build_query(array_filter([
    'q'     => $_GET['q']     ?? null,
    'type'  => $_GET['type']  ?? null,
    'limit' => $_GET['limit'] ?? null,
], static fn($v) => $v !== null && $v !== ''));

$ch = curl_init('http://daems-platform.local/api/v1/backstage/search' . ($qs !== '' ? "?{$qs}" : ''));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => array_filter([
        'Accept: application/json',
        'Accept-Language: ' . I18n::locale(),
        'Authorization: Bearer ' . $token,
        'Host: daems-platform.local',
    ]),
]);
$json = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($code >= 100 ? $code : 502);
echo (string) $json;
```

- [ ] **Step 4: Routes in `public/index.php`**

Find the existing "GET API routes" block (after the POST block). Add:

```php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $uri === '/api/search') {
    require __DIR__ . '/api/search.php'; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $uri === '/api/backstage/search') {
    require __DIR__ . '/api/backstage/search.php'; exit;
}
```

Then add page routes near the existing `/search`-less areas:

```php
if ($uri === '/search') {
    require __DIR__ . '/pages/search/index.php'; exit;
}
if ($uri === '/backstage/search') {
    require __DIR__ . '/pages/backstage/search/index.php'; exit;
}
```

- [ ] **Step 5: PHP lint + commit**

```bash
php -l public/index.php && php -l public/api/search.php && php -l public/api/backstage/search.php
git add public/index.php public/api/search.php public/api/backstage/search.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): proxies + routes — /api/search, /api/backstage/search, /search, /backstage/search"
```

### Task 18: Shared typeahead component (partial + JS + CSS)

**Files:**
- Create `public/partials/search-typeahead.php`
- Create `public/assets/js/daems-search.js`
- Create `public/assets/css/daems-search.css`

- [ ] **Step 1: Partial markup**

`public/partials/search-typeahead.php`:

```php
<?php
/**
 * Shared typeahead input + dropdown for top-nav and backstage top-bar.
 *
 * Caller sets one variable before including:
 *   $searchEndpoint — '/api/search' or '/api/backstage/search'
 *   $searchBaseUrl  — '/search' or '/backstage/search' (for "View all →")
 */
$endpoint = $searchEndpoint ?? '/api/search';
$baseUrl  = $searchBaseUrl  ?? '/search';
?>
<div class="daems-search" data-endpoint="<?= htmlspecialchars($endpoint) ?>" data-base-url="<?= htmlspecialchars($baseUrl) ?>">
    <input type="search" class="daems-search__input" placeholder="Hae…" aria-label="Search" autocomplete="off" />
    <div class="daems-search__dropdown" role="listbox" hidden>
        <div class="daems-search__results"></div>
        <a class="daems-search__view-all" href="#">View all results →</a>
    </div>
</div>
```

- [ ] **Step 2: JS**

`public/assets/js/daems-search.js`:

```javascript
/* Global search typeahead — shared by public top-nav and backstage top-bar. */
(function () {
    var containers = document.querySelectorAll('.daems-search');
    if (!containers.length) return;

    containers.forEach(function (root) {
        var input      = root.querySelector('.daems-search__input');
        var dropdown   = root.querySelector('.daems-search__dropdown');
        var resultsEl  = root.querySelector('.daems-search__results');
        var viewAllEl  = root.querySelector('.daems-search__view-all');
        var endpoint   = root.getAttribute('data-endpoint') || '/api/search';
        var baseUrl    = root.getAttribute('data-base-url') || '/search';
        var timer      = null;
        var lastQuery  = '';

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { fetchAndRender(input.value.trim()); }, 300);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hide(); input.blur(); }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) hide();
        });

        function fetchAndRender(q) {
            if (q.length < 2) { hide(); return; }
            if (q === lastQuery) return;
            lastQuery = q;
            fetch(endpoint + '?q=' + encodeURIComponent(q) + '&limit=5')
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var items = (j && j.data) || [];
                    if (!items.length) { hide(); return; }
                    render(items, q);
                    show();
                })
                .catch(function () { hide(); });
        }

        function render(items, q) {
            resultsEl.innerHTML = '';
            var grouped = {};
            items.forEach(function (h) {
                grouped[h.entity_type] = grouped[h.entity_type] || [];
                grouped[h.entity_type].push(h);
            });
            Object.keys(grouped).forEach(function (type) {
                var header = document.createElement('div');
                header.className = 'daems-search__group-header';
                header.textContent = typeLabel(type);
                resultsEl.appendChild(header);
                grouped[type].forEach(function (h) { resultsEl.appendChild(row(h)); });
            });
            viewAllEl.setAttribute('href', baseUrl + '?q=' + encodeURIComponent(q));
        }

        function row(h) {
            var a = document.createElement('a');
            a.className = 'daems-search__result';
            a.setAttribute('href', h.url);
            var title = document.createElement('div');
            title.className = 'daems-search__result-title';
            title.textContent = h.title + (h.locale_code ? ' (' + h.locale_code.slice(0,2) + ')' : '');
            var snippet = document.createElement('div');
            snippet.className = 'daems-search__result-snippet';
            snippet.textContent = h.snippet;
            a.appendChild(title); a.appendChild(snippet);
            return a;
        }

        function typeLabel(t) {
            return { event: 'Events', project: 'Projects', insight: 'Insights', forum_topic: 'Forum', member: 'Members' }[t] || t;
        }

        function show() { dropdown.hidden = false; }
        function hide() { dropdown.hidden = true; }
    });
}());
```

- [ ] **Step 3: CSS**

`public/assets/css/daems-search.css`:

```css
.daems-search { position: relative; }
.daems-search__input { width: 240px; padding: 6px 10px; border: 1px solid #d0d5dd; border-radius: 6px; font-size: 14px; }
.daems-search__dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #d0d5dd; border-top: none; border-radius: 0 0 6px 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); max-height: 440px; overflow-y: auto; z-index: 1000; }
.daems-search__group-header { padding: 8px 12px 4px; font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 600; letter-spacing: 0.05em; }
.daems-search__result { display: block; padding: 8px 12px; text-decoration: none; color: inherit; border-top: 1px solid #f3f4f6; }
.daems-search__result:hover { background: #f9fafb; }
.daems-search__result-title { font-size: 14px; font-weight: 500; color: #111827; }
.daems-search__result-snippet { font-size: 12px; color: #6b7280; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.daems-search__view-all { display: block; padding: 10px 12px; font-size: 13px; color: #4f46e5; text-align: center; border-top: 1px solid #e5e7eb; text-decoration: none; }
.daems-search__view-all:hover { background: #f9fafb; }
```

- [ ] **Step 4: Commit**

```bash
git add public/partials/search-typeahead.php public/assets/js/daems-search.js public/assets/css/daems-search.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): shared typeahead partial + JS (300ms debounce, grouped by domain, View all link) + CSS"
```

### Task 19: Wire typeahead into top-bars (public + backstage)

**Files:**
- Modify `public/partials/top-nav.php`
- Locate and modify the backstage top-bar template

- [ ] **Step 1: Find backstage top-bar**

```bash
cd /c/laragon/www/sites/daem-society && grep -rn "backstage.*top\|class=\"topbar\|\.backstage-header" public/pages/backstage/ 2>&1 | head -5
```

Likely file: `public/pages/backstage/layout.php` or a partial under `public/pages/backstage/partials/`.

- [ ] **Step 2: Wire into backstage top-bar**

Inside the top-bar markup (adjacent to the user dropdown, per SIP design), include:

```php
<?php
$searchEndpoint = '/api/backstage/search';
$searchBaseUrl  = '/backstage/search';
include __DIR__ . '/../../../partials/search-typeahead.php';
?>
```

Adjust the include path based on actual location. Also ensure the backstage layout loads `daems-search.js` + `daems-search.css`.

- [ ] **Step 3: Wire into public top-nav**

In `public/partials/top-nav.php`, add a search icon button that toggles an overlay containing the typeahead. Simplest approach: render the typeahead directly in the nav bar, visible on non-mobile; on mobile use a toggle button. For MVP:

```php
<div class="top-nav__search d-none d-lg-block">
    <?php
    $searchEndpoint = '/api/search';
    $searchBaseUrl  = '/search';
    include __DIR__ . '/search-typeahead.php';
    ?>
</div>
```

Add `<link rel="stylesheet" href="/assets/css/daems-search.css">` + `<script src="/assets/js/daems-search.js"></script>` to any layout that renders `top-nav.php`. Since multiple page templates include scripts independently, add to the common base layouts used by home/about/events/projects/forums/insights.

- [ ] **Step 4: PHP lint the modified files**

```bash
php -l public/partials/top-nav.php && php -l <backstage-top-bar-file>
```

- [ ] **Step 5: Commit**

```bash
git add public/partials/top-nav.php public/partials/search-typeahead.php <backstage-top-bar-file>
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): mount typeahead in public top-nav + backstage top-bar"
```

### Task 20: Dedicated search pages (public + backstage)

**Files:**
- Create `public/pages/search/index.php`
- Create `public/pages/backstage/search/index.php`

- [ ] **Step 1: Public `/search?q=` page**

`public/pages/search/index.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/ApiClient.php';
require_once __DIR__ . '/../../../src/I18n.php';

$q    = trim((string) ($_GET['q']    ?? ''));
$type = (string) ($_GET['type'] ?? 'all');

$results = ['data' => [], 'meta' => ['count' => 0]];
if (mb_strlen($q) >= 2) {
    $qs = http_build_query(['q' => $q, 'type' => $type !== 'all' ? $type : null, 'limit' => 20], '', '&');
    $ch = curl_init('http://daems-platform.local/api/v1/search?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Language: ' . I18n::locale(),
            'Host: daems-platform.local',
        ],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) $results = $decoded;
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Search — Daem Society</title>
        <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
        <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
        <link rel="stylesheet" href="/assets/css/daems.css">
        <link rel="stylesheet" href="/assets/css/daems-search.css">
    </head>
    <body>
        <?php include __DIR__ . '/../../partials/top-nav.php'; ?>
        <main class="container py-4">
            <h1>Search results for "<?= $esc($q) ?>"</h1>
            <div class="daems-search-chips mb-3">
                <?php foreach (['all', 'events', 'projects', 'forum', 'insights'] as $c): ?>
                    <a class="daems-search-chip<?= $type === $c ? ' is-active' : '' ?>"
                       href="/search?q=<?= urlencode($q) ?>&type=<?= $c ?>"><?= ucfirst($c) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (empty($results['data'])): ?>
                <p>No results.</p>
            <?php else: ?>
                <ul class="daems-search-results-list list-unstyled">
                    <?php foreach ($results['data'] as $h): ?>
                        <li class="daems-search-result-item mb-3">
                            <a href="<?= $esc((string) $h['url']) ?>" class="h5 d-block"><?= $esc((string) $h['title']) ?></a>
                            <small class="text-muted"><?= $esc(ucfirst((string) $h['entity_type'])) ?></small>
                            <div><?= $esc((string) $h['snippet']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </main>
        <?php include __DIR__ . '/../../partials/footer.php'; ?>
        <script src="/assets/js/bootstrap.bundle.min.js"></script>
    </body>
</html>
```

- [ ] **Step 2: Backstage `/backstage/search?q=` page**

`public/pages/backstage/search/index.php` — same structure as the public page with these concrete diffs:

1. Gate access: at the top, after `require_once` lines, bounce non-admins:

```php
$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || in_array(($u['role'] ?? ''), ['admin', 'moderator'], true));
if (!$isAdmin) { header('Location: /'); exit; }
```

2. API call uses backstage endpoint + Bearer token:

```php
$token = (string) ($_SESSION['token'] ?? '');
$ch = curl_init('http://daems-platform.local/api/v1/backstage/search?' . $qs);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => array_filter([
        'Accept: application/json',
        'Accept-Language: ' . I18n::locale(),
        'Authorization: Bearer ' . $token,
        'Host: daems-platform.local',
    ]),
]);
```

3. Chip list includes `'members'`:

```php
foreach (['all', 'events', 'projects', 'forum', 'insights', 'members'] as $c):
```

4. Wrap the `<main>` content with the backstage layout. Copy the pattern from `public/pages/backstage/settings/index.php`:

```php
ob_start();
// ... all the content that would be inside <main> in the public page ...
$pageContent = ob_get_clean();
$pageTitle = 'Search';
$activePage = 'search';
$breadcrumbs = [];
require __DIR__ . '/../layout.php';
```

5. Member result rendering — member hits have `entity_type === 'member'` and need the prefix-formatted number. Re-use `src/MemberNumberFormatter.php`:

```php
require_once __DIR__ . '/../../../src/MemberNumberFormatter.php';
$tenantPrefix = $_SESSION['tenant']['member_number_prefix'] ?? null;
// inside the result-item loop:
if (($h['entity_type'] ?? '') === 'member') {
    // Extract member_number from snippet format "Name · 000123 · role"
    // (SqlSearchRepository::searchMembers writes the raw number; we format for display)
    $displayNumber = MemberNumberFormatter::format(/* raw member_number from hit */, is_string($tenantPrefix) ? $tenantPrefix : null);
    // If the current snippet carries the raw number, swap it for $displayNumber when rendering.
}
```

If `SearchHit` doesn't expose `member_number` directly (the VO only has snippet + title), plan an earlier adjustment: add an optional `meta` field to `SearchHit` carrying per-domain extras, and populate `meta.member_number` in `SqlSearchRepository::searchMembers`. Alternatively, keep rendering simple and show the already-formatted number in the snippet from the backend (formatter is PHP-only and already runs in society — easier: backend stores raw, society-side backstage page calls the formatter on display). Pick whichever is smaller based on the actual state of `SearchHit` when you reach this task.

- [ ] **Step 3: CSS tweaks in `daems-search.css`** (append):

```css
.daems-search-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.daems-search-chip { padding: 6px 14px; border-radius: 999px; background: #f3f4f6; color: #374151; text-decoration: none; font-size: 13px; }
.daems-search-chip.is-active { background: #111827; color: #fff; }
.daems-search-chip:hover { background: #e5e7eb; }
.daems-search-chip.is-active:hover { background: #111827; }
```

- [ ] **Step 4: Lint + commit**

```bash
php -l public/pages/search/index.php && php -l public/pages/backstage/search/index.php
git add public/pages/search/ public/pages/backstage/search/ public/assets/css/daems-search.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(search): dedicated /search + /backstage/search pages with domain-chip filters"
```

### Task 21: E2E spec

**Files:** Create `tests/e2e/global-search.spec.ts`

- [ ] **Step 1: Write spec**

```typescript
import { test, expect } from '@playwright/test';

/**
 * Global search — covers top-nav typeahead on public site and the dedicated
 * /search results page. Backstage flows require auth cookies; not in this
 * smoke set.
 */

test.describe('Public global search', () => {
    test('top-nav exposes search input on desktop', async ({ page }) => {
        await page.goto('/');
        // Skip on viewports where .top-nav__search is display:none
        const input = page.locator('.top-nav__search .daems-search__input');
        await expect(input).toBeVisible();
    });

    test('short query (<2 chars) does not open dropdown', async ({ page }) => {
        await page.goto('/');
        const input = page.locator('.top-nav__search .daems-search__input');
        await input.fill('a');
        await page.waitForTimeout(400);
        await expect(page.locator('.top-nav__search .daems-search__dropdown')).toBeHidden();
    });

    test('dedicated page renders chips even when empty', async ({ page }) => {
        await page.goto('/search?q=zzzzzzzzz-unlikely-term');
        await expect(page.locator('.daems-search-chips')).toBeVisible();
        await expect(page.getByText('No results.')).toBeVisible();
    });
});
```

- [ ] **Step 2: Run against dev server**

```bash
cd /c/laragon/www/sites/daem-society && npx playwright test --project=chromium tests/e2e/global-search.spec.ts --reporter=line 2>&1 | tail -10
```

Expected: 3 passing OR skip if viewport is too small for desktop test.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/global-search.spec.ts
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(e2e): global search typeahead + dedicated page smoke (3 tests)"
```

### Task 22: Push society branch + CI + PR + merge

- [ ] **Step 1: Push**

```bash
cd /c/laragon/www/sites/daem-society && git push -u origin global-search 2>&1 | tail -3
```

- [ ] **Step 2: Open draft PR**

```bash
gh pr create --draft --base dev --head global-search \
  --title "WIP: Global search frontend (typeahead + dedicated pages)" \
  --body "Draft to trigger CI. Depends on daems-platform global-search PR."
```

- [ ] **Step 3: Watch CI (PHP 8.1 + 8.3 + Playwright smoke)**

```bash
RUN_ID=$(gh run list --branch global-search --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

- [ ] **Step 4: Move PR to ready + report URL**

```bash
PR_NUM=$(gh pr list --head global-search --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Global search frontend (typeahead + dedicated pages)" \
    --body "$(cat <<'EOF'
## Summary
- Shared typeahead component: partial + JS (300 ms debounce, grouped by domain, "View all →") + CSS
- Mounted in public top-nav (desktop) + backstage top-bar
- Dedicated /search?q= + /backstage/search?q= pages with domain-chip filters
- Proxies + 4 new routes
- 3 E2E smoke tests

## Depends on
- daems-platform global-search PR (backend routes must be live)

## Spec / Plan
- Spec: daems-platform/docs/superpowers/specs/2026-04-24-global-search-design.md
- Plan: daems-platform/docs/superpowers/plans/2026-04-24-global-search.md
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Society PR #$PR_NUM ready"
```

- [ ] **Step 5: Wait for explicit "mergaa society" before merging**

```bash
gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

- [ ] **Step 6: Final report**

Report both merge SHAs, manual verification TODO list:
- Type a term on `/` → dropdown shows grouped results
- Click a result → lands on domain page
- Visit `/search?q=…` → chips filter correctly
- Sign in as admin → `/backstage/search?q=…` works and members appear when admin
- Sign in as member (non-admin) → `/backstage/search?q=…&type=members` returns 403

---

## Self-review coverage map

| Spec section | Task(s) |
|---|---|
| Architecture platform classes | 4, 5, 7, 8, 13 |
| Routes + DI | 14 |
| Migrations (059, 060, 061) | 1, 2, 3 |
| Write-path sync | 15 |
| Tenant scope | 8 (integration test) |
| Draft filtering | 8, 10 (integration tests) |
| i18n fallback + locale badge | 8 |
| Forum body search | 11, 15 |
| Members admin-only | 12, 13 |
| Min query length | 7 |
| Dedicated page chips | 20 |
| Society proxies + routes | 17 |
| Typeahead component | 18, 19 |
| E2E smoke | 21 |
| PR + merge handoff | 16, 22 |
