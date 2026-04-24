# Insights Backstage Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship full-CRUD insights admin at `/backstage/insights` — the last content-type admin remaining after events, projects, forum moderation, and settings.

**Architecture:** Platform grows three new use cases (Create/Update/Delete) + extends ListInsights; BackstageController gains 5 methods + 5 routes; repository interface gains `delete()` and explicit `$includeUnpublished`. Society copies the events-admin pattern: list view + modal + proxy. No DB migrations — schema already supports all fields (from the global-search milestone earlier today).

**Tech Stack:** PHP 8.x clean architecture, MySQL 8.4, vanilla JS modal, Playwright E2E, reuses existing `upload-widget.js` from events admin.

**Spec:** `docs/superpowers/specs/2026-04-24-insights-backstage-admin-design.md`

**Branches:** `insights-backstage-admin` in both `daems-platform` and `daem-society`.

---

## File structure

### daems-platform (create)

| Path | Role |
|---|---|
| `src/Application/Insight/CreateInsight/CreateInsight.php` | Use case: validate + compute reading_time + repo.save |
| `src/Application/Insight/CreateInsight/CreateInsightInput.php` | Input DTO |
| `src/Application/Insight/CreateInsight/CreateInsightOutput.php` | Output DTO |
| `src/Application/Insight/UpdateInsight/UpdateInsight.php` | Use case: load, verify tenant, update |
| `src/Application/Insight/UpdateInsight/UpdateInsightInput.php` | Input DTO (includes insight id) |
| `src/Application/Insight/UpdateInsight/UpdateInsightOutput.php` | Output DTO |
| `src/Application/Insight/DeleteInsight/DeleteInsight.php` | Use case: verify tenant, delete |
| `src/Application/Insight/DeleteInsight/DeleteInsightInput.php` | Input DTO |
| `tests/Unit/Application/Insight/CreateInsightTest.php` | Unit tests |
| `tests/Unit/Application/Insight/UpdateInsightTest.php` | Unit tests |
| `tests/Unit/Application/Insight/DeleteInsightTest.php` | Unit tests |
| `tests/Integration/Application/BackstageInsightsIntegrationTest.php` | End-to-end CRUD via use cases + SQL |

### daems-platform (modify)

| Path | Action |
|---|---|
| `src/Domain/Insight/InsightRepositoryInterface.php` | Add `delete()`, `findByIdForTenant()`; change `listForTenant` to accept `bool $includeUnpublished = false` |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php` | Implement new interface methods + published_date filter in `listForTenant` |
| `src/Application/Insight/ListInsights/ListInsightsInput.php` | Add `bool $includeUnpublished = false` |
| `src/Application/Insight/ListInsights/ListInsights.php` | Propagate the flag to the repo |
| `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` | Add: `listInsights`, `createInsight`, `getInsight`, `updateInsight`, `deleteInsight` |
| `routes/api.php` | Add 5 routes |
| `bootstrap/app.php` | Bind CreateInsight + UpdateInsight + DeleteInsight use cases |
| `tests/Support/KernelHarness.php` | Bind the same 3 use cases with InMemory repo |
| `tests/Support/Fake/InMemoryInsightRepository.php` | Add `delete()` + `findByIdForTenant()` + accept `$includeUnpublished` (if fake exists; otherwise skip) |
| `tests/Integration/Application/SqlInsightRepositoryTest.php` | Add `delete_only_within_tenant` + `list_for_tenant_filters_unpublished_by_default` |

### daem-society (create)

| Path | Role |
|---|---|
| `public/pages/backstage/insights/index.php` | List view + filter + "Add insight" button + modal mount |
| `public/pages/backstage/insights/insight-modal.js` | Create/edit modal logic |
| `public/pages/backstage/insights/insight-modal.css` | Modal styles (trimmed copy of event-modal.css) |
| `public/api/backstage/insights.php` | Proxy — supports `?op=list/create/get/update/delete` |
| `tests/e2e/backstage-insights.spec.ts` | 3 smoke tests |

### daem-society (modify)

| Path | Action |
|---|---|
| `public/index.php` | Add 2 routes (`/api/backstage/insights` GET+POST, `/backstage/insights` page) |

---

## Conventions

- **Commit identity:** every commit `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. NO `Co-Authored-By:`. Never push without explicit user instruction.
- **Working directory:** tasks 1-12 in `C:\laragon\www\daems-platform`, 13-17 in `C:\laragon\www\sites\daem-society`. Bash `cd` resets between tool calls — prefix every command.
- **Never stage `.claude/` or `.superpowers/`.**
- **DI BOTH-wire + route-in-api.php:** every new controller method binds in `bootstrap/app.php` AND `tests/Support/KernelHarness.php` AND has a route in `routes/api.php`.
- **PHPStan level 9 stays at 0 errors. PHPUnit Unit + Integration + E2E stay green.**
- **Forbidden tool:** never invoke `mcp__code-review-graph__*`.

---

## PLATFORM PR

### Task 1: Extend `InsightRepositoryInterface` + SQL repo + update `ListInsights`

**Files:**
- Modify `src/Domain/Insight/InsightRepositoryInterface.php`
- Modify `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php`
- Modify `src/Application/Insight/ListInsights/ListInsightsInput.php`
- Modify `src/Application/Insight/ListInsights/ListInsights.php`

The existing interface has `listForTenant`, `findBySlugForTenant`, `save`. We add `delete` + `findByIdForTenant` + extend `listForTenant` with an `$includeUnpublished` flag that controls whether future-dated rows are returned.

- [ ] **Step 1: Branch**

```bash
cd /c/laragon/www/daems-platform && git checkout -b insights-backstage-admin dev
```

- [ ] **Step 2: Update interface**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Insight;

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
}
```

- [ ] **Step 3: Update `SqlInsightRepository`**

Open `src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php`.

Modify `listForTenant` to match the new signature and add a date filter:

```php
public function listForTenant(TenantId $tenantId, ?string $category = null, bool $includeUnpublished = false): array
{
    $where  = 'tenant_id = ?';
    $params = [$tenantId->value()];
    if ($category !== null) {
        $where .= ' AND category = ?';
        $params[] = $category;
    }
    if (!$includeUnpublished) {
        $where .= ' AND published_date <= CURDATE()';
    }
    $rows = $this->db->query(
        "SELECT * FROM insights WHERE {$where} ORDER BY published_date DESC",
        $params,
    );
    return array_map([$this, 'hydrate'], $rows);
}
```

Add `findByIdForTenant`:

```php
public function findByIdForTenant(InsightId $id, TenantId $tenantId): ?Insight
{
    $row = $this->db->queryOne(
        'SELECT * FROM insights WHERE id = ? AND tenant_id = ? LIMIT 1',
        [$id->value(), $tenantId->value()],
    );
    return $row === null ? null : $this->hydrate($row);
}
```

Add `delete`:

```php
public function delete(InsightId $id, TenantId $tenantId): void
{
    $this->db->execute(
        'DELETE FROM insights WHERE id = ? AND tenant_id = ?',
        [$id->value(), $tenantId->value()],
    );
}
```

Use `use Daems\Domain\Insight\InsightId;` at the top if not already.

- [ ] **Step 4: Update `ListInsightsInput`**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\ListInsights;

use Daems\Domain\Tenant\TenantId;

final class ListInsightsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $category = null,
        public readonly bool $includeUnpublished = false,
    ) {}
}
```

- [ ] **Step 5: Update `ListInsights::execute`**

```php
public function execute(ListInsightsInput $input): ListInsightsOutput
{
    $insights = $this->insights->listForTenant(
        $input->tenantId,
        $input->category,
        $input->includeUnpublished,
    );
    return new ListInsightsOutput(
        array_map(fn(Insight $i) => $this->toArray($i), $insights),
    );
}
```

- [ ] **Step 6: PHPStan clean**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Regression check — existing Unit + Integration tests green**

```bash
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
vendor/bin/phpunit tests/Integration/Application/SqlInsightRepositoryTest.php 2>&1 | tail -3 || true
```

Existing tests may not reference the new parameters; they should still pass with default values.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Insight/InsightRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlInsightRepository.php \
        src/Application/Insight/ListInsights/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): extend repo interface with delete + findByIdForTenant + includeUnpublished"
```

### Task 2: `CreateInsight` use case + TDD

**Files:**
- Create `src/Application/Insight/CreateInsight/CreateInsightInput.php`
- Create `src/Application/Insight/CreateInsight/CreateInsightOutput.php`
- Create `src/Application/Insight/CreateInsight/CreateInsight.php`
- Create `tests/Unit/Application/Insight/CreateInsightTest.php`

- [ ] **Step 1: Write Input**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\CreateInsight;

use Daems\Domain\Tenant\TenantId;

final class CreateInsightInput
{
    /** @param string[] $tags */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $category,
        public readonly string $categoryLabel,
        public readonly bool $featured,
        public readonly string $publishedDate,
        public readonly string $author,
        public readonly string $excerpt,
        public readonly ?string $heroImage,
        public readonly array $tags,
        public readonly string $content,
    ) {}
}
```

- [ ] **Step 2: Write Output**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\CreateInsight;

use Daems\Domain\Insight\Insight;

final class CreateInsightOutput
{
    public function __construct(public readonly Insight $insight) {}
}
```

- [ ] **Step 3: Write failing unit tests**

Create `tests/Unit/Application/Insight/CreateInsightTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Application\Insight\CreateInsight\CreateInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class CreateInsightTest extends TestCase
{
    public function test_creates_with_required_fields(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        $out  = $uc->execute($this->validInput());

        self::assertInstanceOf(Insight::class, $out->insight);
        self::assertSame('Hello world', $out->insight->title());
    }

    public function test_requires_title(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(title: ''));
    }

    public function test_requires_excerpt(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(excerpt: ''));
    }

    public function test_requires_content(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(content: ''));
    }

    public function test_rejects_duplicate_slug(): void
    {
        $existing = $this->buildInsight(slug: 'already-taken');
        $repo = $this->fakeRepo($existing);
        $uc = new CreateInsight($repo);

        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(slug: 'already-taken'));
    }

    public function test_computes_reading_time_from_content_word_count(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        // 600 words of plain text → 600/200 = 3 min
        $content = str_repeat('word ', 600);
        $out = $uc->execute($this->validInput(content: $content));
        self::assertSame(3, $out->insight->readingTime());
    }

    public function test_reading_time_minimum_is_one(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        $out = $uc->execute($this->validInput(content: 'two words'));
        self::assertSame(1, $out->insight->readingTime());
    }

    private function validInput(
        string $title = 'Hello world',
        string $slug  = 'hello-world',
        string $excerpt = 'A teaser',
        string $content = '<p>Body</p>',
    ): CreateInsightInput {
        return new CreateInsightInput(
            tenantId: TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
            slug: $slug,
            title: $title,
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-04-24',
            author: 'Sam',
            excerpt: $excerpt,
            heroImage: null,
            tags: [],
            content: $content,
        );
    }

    private function buildInsight(string $slug): Insight
    {
        return new Insight(
            id: InsightId::fromString('019d0000-0000-7000-8000-000000000999'),
            tenantId: TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
            slug: $slug,
            title: 'Existing',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            date: '2026-01-01',
            author: 'Sam',
            readingTime: 1,
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'y',
        );
    }

    private function fakeRepo(?Insight $existing = null): InsightRepositoryInterface
    {
        return new class ($existing) implements InsightRepositoryInterface {
            /** @var Insight[] */
            public array $saved = [];
            public function __construct(private readonly ?Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight
            {
                return ($this->existing && $this->existing->slug() === $slug) ? $this->existing : null;
            }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void { $this->saved[] = $i; }
            public function delete(InsightId $id, TenantId $t): void {}
        };
    }
}
```

Note: `Insight::readingTime()` returns `int` per the Domain VO I checked earlier; constructors use named args. `TenantId::fromString()` + `InsightId::fromString()` are the factories the search-milestone confirmed exist.

- [ ] **Step 4: Run — tests should fail (class CreateInsight missing)**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/CreateInsightTest.php 2>&1 | tail -5
```

- [ ] **Step 5: Implement `CreateInsight`**

Create `src/Application/Insight/CreateInsight/CreateInsight.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\CreateInsight;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Shared\ValueObject\Uuid7;

final class CreateInsight
{
    public function __construct(private readonly InsightRepositoryInterface $repo) {}

    public function execute(CreateInsightInput $in): CreateInsightOutput
    {
        $this->validate($in);
        if ($this->repo->findBySlugForTenant($in->slug, $in->tenantId) !== null) {
            throw new ValidationException('slug_taken', ['slug' => 'already_exists']);
        }

        $insight = new Insight(
            id: new InsightId(Uuid7::generate()->value()),
            tenantId: $in->tenantId,
            slug: $in->slug,
            title: $in->title,
            category: $in->category,
            categoryLabel: $in->categoryLabel,
            featured: $in->featured,
            date: $in->publishedDate,
            author: $in->author,
            readingTime: self::computeReadingTime($in->content),
            excerpt: $in->excerpt,
            heroImage: $in->heroImage,
            tags: $in->tags,
            content: $in->content,
        );
        $this->repo->save($insight);

        return new CreateInsightOutput($insight);
    }

    private function validate(CreateInsightInput $in): void
    {
        $errors = [];
        if (trim($in->title) === '')               $errors['title']    = 'required';
        elseif (mb_strlen($in->title) > 255)       $errors['title']    = 'too_long';
        if (trim($in->slug) === '')                $errors['slug']     = 'required';
        if (trim($in->excerpt) === '')             $errors['excerpt']  = 'required';
        elseif (mb_strlen($in->excerpt) > 500)     $errors['excerpt']  = 'too_long';
        if (trim($in->content) === '')             $errors['content']  = 'required';
        if (trim($in->category) === '')            $errors['category'] = 'required';
        if (!self::isDate($in->publishedDate))     $errors['published_date'] = 'invalid_format';
        if ($errors !== []) {
            throw new ValidationException('validation', $errors);
        }
    }

    private static function isDate(string $s): bool
    {
        return (bool) \DateTime::createFromFormat('Y-m-d', $s);
    }

    public static function computeReadingTime(string $content): int
    {
        $plain = strip_tags($content);
        $words = max(1, str_word_count($plain));
        return max(1, (int) ceil($words / 200));
    }
}
```

Note: the project's `ValidationException` constructor likely takes `(string $message, array $errors = [])` — check existing usage (e.g., in `CreateEvent` use case) and adjust the throw call if the constructor differs.

Also check that `InsightId` has a public constructor accepting string, or only a factory method. If `new InsightId(...)` fails, use `InsightId::fromString(...)` as in the tests.

- [ ] **Step 6: Run — tests pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/CreateInsightTest.php 2>&1 | tail -5
```

Expected: `OK (7 tests)`.

- [ ] **Step 7: PHPStan**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

- [ ] **Step 8: Commit**

```bash
git add src/Application/Insight/CreateInsight/ tests/Unit/Application/Insight/CreateInsightTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): CreateInsight use case (validation, reading-time compute, slug uniqueness)"
```

### Task 3: `UpdateInsight` use case + TDD

**Files:**
- Create `src/Application/Insight/UpdateInsight/UpdateInsightInput.php`
- Create `src/Application/Insight/UpdateInsight/UpdateInsightOutput.php`
- Create `src/Application/Insight/UpdateInsight/UpdateInsight.php`
- Create `tests/Unit/Application/Insight/UpdateInsightTest.php`

- [ ] **Step 1: Input**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

final class UpdateInsightInput
{
    /** @param string[] $tags */
    public function __construct(
        public readonly InsightId $insightId,
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $category,
        public readonly string $categoryLabel,
        public readonly bool $featured,
        public readonly string $publishedDate,
        public readonly string $author,
        public readonly string $excerpt,
        public readonly ?string $heroImage,
        public readonly array $tags,
        public readonly string $content,
    ) {}
}
```

- [ ] **Step 2: Output**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Domain\Insight\Insight;

final class UpdateInsightOutput
{
    public function __construct(public readonly Insight $insight) {}
}
```

- [ ] **Step 3: Write failing unit tests**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\UpdateInsight\UpdateInsight;
use Daems\Application\Insight\UpdateInsight\UpdateInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class UpdateInsightTest extends TestCase
{
    private const TENANT_A = '019d0000-0000-7000-8000-000000000001';
    private const TENANT_B = '019d0000-0000-7000-8000-000000000002';
    private const INSIGHT  = '019d0000-0000-7000-8000-000000000010';

    public function test_rejects_cross_tenant_edit(): void
    {
        $insightInTenantA = $this->buildInsight();
        $repo = $this->fakeRepo($insightInTenantA);
        $uc = new UpdateInsight($repo);

        $this->expectException(NotFoundException::class);
        $uc->execute($this->validInput(tenantId: self::TENANT_B));
    }

    public function test_allows_slug_unchanged(): void
    {
        $existing = $this->buildInsight(slug: 'same-slug');
        $repo = $this->fakeRepo($existing);
        $uc = new UpdateInsight($repo);

        $out = $uc->execute($this->validInput(slug: 'same-slug', title: 'Updated title'));
        self::assertSame('Updated title', $out->insight->title());
        self::assertSame('same-slug', $out->insight->slug());
    }

    public function test_rejects_slug_taken_by_another_insight(): void
    {
        $existing = $this->buildInsight(slug: 'original');
        $repo = $this->fakeRepo($existing);
        $repo->slugOwner = [
            'taken-by-other' => InsightId::fromString('019d0000-0000-7000-8000-000000000FFF'),
        ];
        $uc = new UpdateInsight($repo);

        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(slug: 'taken-by-other'));
    }

    public function test_preserves_id_on_update(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new UpdateInsight($repo);

        $out = $uc->execute($this->validInput(title: 'New title'));
        self::assertSame(self::INSIGHT, $out->insight->id()->value());
    }

    private function validInput(
        string $tenantId = self::TENANT_A,
        string $slug = 'original',
        string $title = 'Hello',
    ): UpdateInsightInput {
        return new UpdateInsightInput(
            insightId: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString($tenantId),
            slug: $slug,
            title: $title,
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-04-24',
            author: 'Sam',
            excerpt: 'Teaser',
            heroImage: null,
            tags: [],
            content: '<p>body</p>',
        );
    }

    private function buildInsight(string $slug = 'original'): Insight
    {
        return new Insight(
            id: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString(self::TENANT_A),
            slug: $slug,
            title: 'Existing',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            date: '2026-01-01',
            author: 'Sam',
            readingTime: 1,
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'y',
        );
    }

    /** @param Insight $existing */
    private function fakeRepo(Insight $existing): object
    {
        return new class ($existing) implements InsightRepositoryInterface {
            /** @var array<string, InsightId> */ public array $slugOwner = [];
            public function __construct(private readonly Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight
            {
                if ($slug === $this->existing->slug()) return $this->existing;
                if (isset($this->slugOwner[$slug])) {
                    return new Insight(
                        id: $this->slugOwner[$slug],
                        tenantId: $t, slug: $slug, title: 'other', category: 'c', categoryLabel: 'C',
                        featured: false, date: '2026-01-01', author: 'a', readingTime: 1,
                        excerpt: 'x', heroImage: null, tags: [], content: 'y',
                    );
                }
                return null;
            }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight
            {
                return ($id->value() === $this->existing->id()->value()
                     && $t->value() === $this->existing->tenantId()->value())
                    ? $this->existing : null;
            }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
        };
    }
}
```

- [ ] **Step 4: Run — tests fail**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/UpdateInsightTest.php 2>&1 | tail -5
```

- [ ] **Step 5: Implement `UpdateInsight`**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class UpdateInsight
{
    public function __construct(private readonly InsightRepositoryInterface $repo) {}

    public function execute(UpdateInsightInput $in): UpdateInsightOutput
    {
        $existing = $this->repo->findByIdForTenant($in->insightId, $in->tenantId)
            ?? throw new NotFoundException('not_found');

        $this->validate($in);

        // Slug uniqueness: OK if slug unchanged or owned by this same insight.
        $bySlug = $this->repo->findBySlugForTenant($in->slug, $in->tenantId);
        if ($bySlug !== null && $bySlug->id()->value() !== $in->insightId->value()) {
            throw new ValidationException('slug_taken', ['slug' => 'already_exists']);
        }

        $updated = new Insight(
            id: $existing->id(),
            tenantId: $in->tenantId,
            slug: $in->slug,
            title: $in->title,
            category: $in->category,
            categoryLabel: $in->categoryLabel,
            featured: $in->featured,
            date: $in->publishedDate,
            author: $in->author,
            readingTime: CreateInsight::computeReadingTime($in->content),
            excerpt: $in->excerpt,
            heroImage: $in->heroImage,
            tags: $in->tags,
            content: $in->content,
        );
        $this->repo->save($updated);

        return new UpdateInsightOutput($updated);
    }

    private function validate(UpdateInsightInput $in): void
    {
        $errors = [];
        if (trim($in->title) === '')           $errors['title']   = 'required';
        elseif (mb_strlen($in->title) > 255)   $errors['title']   = 'too_long';
        if (trim($in->slug) === '')            $errors['slug']    = 'required';
        if (trim($in->excerpt) === '')         $errors['excerpt'] = 'required';
        elseif (mb_strlen($in->excerpt) > 500) $errors['excerpt'] = 'too_long';
        if (trim($in->content) === '')         $errors['content'] = 'required';
        if (trim($in->category) === '')        $errors['category'] = 'required';
        if (!\DateTime::createFromFormat('Y-m-d', $in->publishedDate)) {
            $errors['published_date'] = 'invalid_format';
        }
        if ($errors !== []) {
            throw new ValidationException('validation', $errors);
        }
    }
}
```

- [ ] **Step 6: Run — tests pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/UpdateInsightTest.php 2>&1 | tail -5
```

Expected: `OK (4 tests)`.

- [ ] **Step 7: PHPStan clean**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

- [ ] **Step 8: Commit**

```bash
git add src/Application/Insight/UpdateInsight/ tests/Unit/Application/Insight/UpdateInsightTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): UpdateInsight use case (tenant-scoped, slug uniqueness excludes self)"
```

### Task 4: `DeleteInsight` use case + TDD

**Files:**
- Create `src/Application/Insight/DeleteInsight/DeleteInsightInput.php`
- Create `src/Application/Insight/DeleteInsight/DeleteInsight.php`
- Create `tests/Unit/Application/Insight/DeleteInsightTest.php`

- [ ] **Step 1: Input**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\DeleteInsight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

final class DeleteInsightInput
{
    public function __construct(
        public readonly InsightId $insightId,
        public readonly TenantId $tenantId,
    ) {}
}
```

- [ ] **Step 2: Write failing unit tests**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\DeleteInsight\DeleteInsight;
use Daems\Application\Insight\DeleteInsight\DeleteInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class DeleteInsightTest extends TestCase
{
    private const TENANT_A = '019d0000-0000-7000-8000-000000000001';
    private const TENANT_B = '019d0000-0000-7000-8000-000000000002';
    private const INSIGHT  = '019d0000-0000-7000-8000-000000000010';

    public function test_deletes_existing_insight(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new DeleteInsight($repo);

        $uc->execute(new DeleteInsightInput(
            InsightId::fromString(self::INSIGHT),
            TenantId::fromString(self::TENANT_A),
        ));

        self::assertTrue($repo->deleted, 'repo.delete must have been called');
    }

    public function test_rejects_cross_tenant_delete(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new DeleteInsight($repo);

        $this->expectException(NotFoundException::class);
        $uc->execute(new DeleteInsightInput(
            InsightId::fromString(self::INSIGHT),
            TenantId::fromString(self::TENANT_B),
        ));
        self::assertFalse($repo->deleted);
    }

    private function buildInsight(): Insight
    {
        return new Insight(
            id: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString(self::TENANT_A),
            slug: 's', title: 't', category: 'c', categoryLabel: 'C', featured: false,
            date: '2026-01-01', author: 'a', readingTime: 1, excerpt: 'x',
            heroImage: null, tags: [], content: 'y',
        );
    }

    /** @param Insight $existing */
    private function fakeRepo(Insight $existing): object
    {
        return new class ($existing) implements InsightRepositoryInterface {
            public bool $deleted = false;
            public function __construct(private readonly Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight
            {
                return ($id->value() === $this->existing->id()->value()
                     && $t->value() === $this->existing->tenantId()->value())
                    ? $this->existing : null;
            }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void { $this->deleted = true; }
        };
    }
}
```

- [ ] **Step 3: Run — fail**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/DeleteInsightTest.php 2>&1 | tail -5
```

- [ ] **Step 4: Implement `DeleteInsight`**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Insight\DeleteInsight;

use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class DeleteInsight
{
    public function __construct(private readonly InsightRepositoryInterface $repo) {}

    public function execute(DeleteInsightInput $in): void
    {
        $existing = $this->repo->findByIdForTenant($in->insightId, $in->tenantId)
            ?? throw new NotFoundException('not_found');

        $this->repo->delete($existing->id(), $in->tenantId);
    }
}
```

- [ ] **Step 5: Run — pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/DeleteInsightTest.php 2>&1 | tail -5
```

Expected: `OK (2 tests)`.

- [ ] **Step 6: PHPStan + commit**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3

git add src/Application/Insight/DeleteInsight/ tests/Unit/Application/Insight/DeleteInsightTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): DeleteInsight use case (tenant-verified hard delete)"
```

### Task 5: Extend `ListInsights` unit coverage

**Files:** Modify `tests/Unit/Application/Insight/ListInsightsTest.php` (or create if missing)

- [ ] **Step 1: Check if a test file exists**

```bash
ls tests/Unit/Application/Insight/ListInsightsTest.php 2>&1
```

- [ ] **Step 2: Add a test that confirms `includeUnpublished=true` propagates**

If the file exists, append; else create. Example test:

```php
public function test_include_unpublished_propagates_to_repo(): void
{
    $repo = new class implements InsightRepositoryInterface {
        public bool $flag = false;
        public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array
        {
            $this->flag = $u;
            return [];
        }
        public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
        public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
        public function save(Insight $i): void {}
        public function delete(InsightId $id, TenantId $t): void {}
    };
    $uc = new ListInsights($repo);
    $uc->execute(new ListInsightsInput(
        TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
        null,
        true,
    ));
    self::assertTrue($repo->flag);
}
```

Add the necessary `use` imports at the file top (`Daems\Domain\Insight\Insight`, `InsightId`, etc.).

- [ ] **Step 3: Run + PHPStan + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Insight/ListInsightsTest.php 2>&1 | tail -3
composer analyse 2>&1 | grep -E "OK|errors" | head -3
git add tests/Unit/Application/Insight/ListInsightsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(insight): ListInsights propagates includeUnpublished to repo"
```

### Task 6: `BackstageController` — 5 new methods

**Files:** Modify `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

Study the existing events methods (`listEvents`, `createEvent`, `updateEvent` around lines 340-434) for the exact pattern. Replicate that style for insights.

- [ ] **Step 1: Read existing pattern**

```bash
sed -n '340,440p' src/Infrastructure/Adapter/Api/Controller/BackstageController.php | head -100
```

Identify:
- How the acting user + tenant are obtained
- How the admin gate is enforced
- How JSON body is parsed
- How errors are mapped to responses

- [ ] **Step 2: Add constructor-injected dependencies**

Add `CreateInsight`, `UpdateInsight`, `DeleteInsight`, `ListInsights`, `GetInsight` to the constructor parameters. (The existing controller may already have `ListInsights` injected for some other route; if so, reuse.)

- [ ] **Step 3: Implement `requireInsightsAdmin` helper** (may already exist for events as `requireEventsAdmin` etc.)

Example:

```php
private function requireInsightsAdmin(Request $request, Tenant $tenant): ActingUser
{
    $actor = $request->requireActingUser();
    $ok = $actor->isPlatformAdmin || $actor->isAdminIn($tenant->id);
    if (!$ok) {
        throw new ForbiddenException('forbidden');
    }
    return $actor;
}
```

- [ ] **Step 4: Add `listInsights`**

```php
public function listInsights(Request $request): Response
{
    $tenant = $this->requireTenant($request);
    $this->requireInsightsAdmin($request, $tenant);

    $category = $request->string('category');
    $out = $this->listInsights->execute(new ListInsightsInput(
        tenantId: $tenant->id,
        category: $category,
        includeUnpublished: true,
    ));
    return Response::json(['data' => $out->items]);
}
```

(`$out->items` matches whatever the existing ListInsightsOutput field name is — verify; it might be `$out->insights` or similar. Open the VO file.)

- [ ] **Step 5: Add `createInsight`**

```php
public function createInsight(Request $request): Response
{
    $tenant = $this->requireTenant($request);
    $this->requireInsightsAdmin($request, $tenant);

    $body = $request->jsonBody();  // use whatever method already exists
    try {
        $out = $this->createInsight->execute(new CreateInsightInput(
            tenantId:      $tenant->id,
            slug:          (string) ($body['slug']           ?? ''),
            title:         (string) ($body['title']          ?? ''),
            category:      (string) ($body['category']       ?? ''),
            categoryLabel: (string) ($body['category_label'] ?? $body['category'] ?? ''),
            featured:      (bool)   ($body['featured']       ?? false),
            publishedDate: (string) ($body['published_date'] ?? ''),
            author:        (string) ($body['author']         ?? ''),
            excerpt:       (string) ($body['excerpt']        ?? ''),
            heroImage:     isset($body['hero_image']) && is_string($body['hero_image']) ? $body['hero_image'] : null,
            tags:          is_array($body['tags'] ?? null) ? array_values(array_filter($body['tags'], 'is_string')) : [],
            content:       (string) ($body['content']        ?? ''),
        ));
    } catch (ValidationException $e) {
        return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
    }
    return Response::json(['data' => $this->insightArray($out->insight)], 201);
}
```

Where `$this->insightArray($insight)` is a private helper (add it; mirrors the array shape returned by GetInsight for consistency).

- [ ] **Step 6: Add `getInsight`**

```php
public function getInsight(Request $request, array $params): Response
{
    $tenant = $this->requireTenant($request);
    $this->requireInsightsAdmin($request, $tenant);

    $id = (string) ($params['id'] ?? '');
    if ($id === '') return Response::json(['error' => 'invalid_id'], 400);

    $out = $this->getInsight->execute(new GetInsightInput(new InsightId($id), $tenant->id));
    if ($out->insight === null) {
        return Response::json(['error' => 'not_found'], 404);
    }
    return Response::json(['data' => $this->insightArray($out->insight)]);
}
```

If `GetInsight` currently only exposes `findBySlugForTenant`-style lookups, extend it to support id-based lookup OR call the repo directly via a new dependency. Simplest: skip the use case and inline the repo call using a new `findByIdForTenant` call — but that breaks clean architecture. Preferred: extend `GetInsight` to also accept an `InsightId`-based input.

Concretely: add a private method `private function findByIdOrNull(InsightId $id, TenantId $t): ?Insight` to the controller that calls the repo directly, OR add an `InsightRepositoryInterface $insightRepo` dependency. Since the current controller doesn't inject repos directly (it uses use cases), the cleanest approach is:

Add `GetInsightById` use case alongside the existing `GetInsight`. Trivial:

```
src/Application/Insight/GetInsightById/
    GetInsightById.php
    GetInsightByIdInput.php
    GetInsightByIdOutput.php
```

OR extend the existing `GetInsight` to accept either slug or id. Pick the cleaner one based on what already exists. The plan assumes `GetInsightById` as a new use case; if `GetInsight` is already flexible, collapse.

Note: this is a pivot point — if the subagent finds `GetInsight` trivial enough to extend, do that; otherwise add `GetInsightById`. Report the choice in self-review.

- [ ] **Step 7: Add `updateInsight`**

```php
public function updateInsight(Request $request, array $params): Response
{
    $tenant = $this->requireTenant($request);
    $this->requireInsightsAdmin($request, $tenant);

    $id = (string) ($params['id'] ?? '');
    if ($id === '') return Response::json(['error' => 'invalid_id'], 400);

    $body = $request->jsonBody();
    try {
        $out = $this->updateInsight->execute(new UpdateInsightInput(
            insightId:     new InsightId($id),
            tenantId:      $tenant->id,
            slug:          (string) ($body['slug']           ?? ''),
            title:         (string) ($body['title']          ?? ''),
            category:      (string) ($body['category']       ?? ''),
            categoryLabel: (string) ($body['category_label'] ?? $body['category'] ?? ''),
            featured:      (bool)   ($body['featured']       ?? false),
            publishedDate: (string) ($body['published_date'] ?? ''),
            author:        (string) ($body['author']         ?? ''),
            excerpt:       (string) ($body['excerpt']        ?? ''),
            heroImage:     isset($body['hero_image']) && is_string($body['hero_image']) ? $body['hero_image'] : null,
            tags:          is_array($body['tags'] ?? null) ? array_values(array_filter($body['tags'], 'is_string')) : [],
            content:       (string) ($body['content']        ?? ''),
        ));
    } catch (NotFoundException) {
        return Response::json(['error' => 'not_found'], 404);
    } catch (ValidationException $e) {
        return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
    }
    return Response::json(['data' => $this->insightArray($out->insight)]);
}
```

- [ ] **Step 8: Add `deleteInsight`**

```php
public function deleteInsight(Request $request, array $params): Response
{
    $tenant = $this->requireTenant($request);
    $this->requireInsightsAdmin($request, $tenant);

    $id = (string) ($params['id'] ?? '');
    if ($id === '') return Response::json(['error' => 'invalid_id'], 400);

    try {
        $this->deleteInsight->execute(new DeleteInsightInput(new InsightId($id), $tenant->id));
    } catch (NotFoundException) {
        return Response::json(['error' => 'not_found'], 404);
    }
    return Response::json(['data' => ['deleted' => true]]);
}
```

- [ ] **Step 9: Add `insightArray` helper**

```php
private function insightArray(Insight $i): array
{
    return [
        'id'              => $i->id()->value(),
        'slug'            => $i->slug(),
        'title'           => $i->title(),
        'category'        => $i->category(),
        'category_label'  => $i->categoryLabel(),
        'featured'        => $i->featured(),
        'published_date'  => $i->date(),
        'author'          => $i->author(),
        'reading_time'    => $i->readingTime(),
        'excerpt'         => $i->excerpt(),
        'hero_image'      => $i->heroImage(),
        'tags'            => $i->tags(),
        'content'         => $i->content(),
    ];
}
```

- [ ] **Step 10: PHPStan must stay at 0**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

Fix any complaints inline (the usual `mixed` narrowing on `$body['key']`).

- [ ] **Step 11: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php \
        src/Application/Insight/GetInsightById/ 2>/dev/null
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(backstage): insights admin controller methods (list, create, get, update, delete) + admin gate"
```

### Task 7: Routes + DI BOTH-wire

**Files:**
- Modify `routes/api.php` — add 5 new routes
- Modify `bootstrap/app.php` — bind 3 (or 4 if GetInsightById) new use cases
- Modify `tests/Support/KernelHarness.php` — mirror the bindings

- [ ] **Step 1: Routes**

Add near other backstage routes in `routes/api.php`:

```php
$router->get('/api/v1/backstage/insights', static function (Request $req) use ($container): Response {
    return $container->make(BackstageController::class)->listInsights($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/insights', static function (Request $req) use ($container): Response {
    return $container->make(BackstageController::class)->createInsight($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/insights/{id}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(BackstageController::class)->getInsight($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/insights/{id}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(BackstageController::class)->updateInsight($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->delete('/api/v1/backstage/insights/{id}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(BackstageController::class)->deleteInsight($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Ensure `BackstageController` is already imported (it is — every backstage route uses it).

Note: check if the Router supports `delete()` — inspect existing codebase. If only get/post/patch are registered, use POST with `?op=delete` in the proxy instead. The plan prefers DELETE; adapt at integration time if the Router doesn't support it.

- [ ] **Step 2: DI — `bootstrap/app.php`**

Add:

```php
$container->bind(\Daems\Application\Insight\CreateInsight\CreateInsight::class,
    static fn(Container $c) => new \Daems\Application\Insight\CreateInsight\CreateInsight(
        $c->make(\Daems\Domain\Insight\InsightRepositoryInterface::class),
    ));
$container->bind(\Daems\Application\Insight\UpdateInsight\UpdateInsight::class,
    static fn(Container $c) => new \Daems\Application\Insight\UpdateInsight\UpdateInsight(
        $c->make(\Daems\Domain\Insight\InsightRepositoryInterface::class),
    ));
$container->bind(\Daems\Application\Insight\DeleteInsight\DeleteInsight::class,
    static fn(Container $c) => new \Daems\Application\Insight\DeleteInsight\DeleteInsight(
        $c->make(\Daems\Domain\Insight\InsightRepositoryInterface::class),
    ));
// If GetInsightById was added, bind it too
```

- [ ] **Step 3: DI — `tests/Support/KernelHarness.php`**

Same 3 (or 4) bindings but with the harness' existing InMemory-repo resolution.

- [ ] **Step 4: Verify BOTH-wire**

```bash
grep -rn "CreateInsight::class\|UpdateInsight::class\|DeleteInsight::class" \
  bootstrap/app.php tests/Support/KernelHarness.php routes/api.php
```

Expected matches: 3 in bootstrap (one per use case), 3 in harness, 0 in routes (routes use BackstageController which gets the use cases from constructor).

- [ ] **Step 5: Full suites stay green**

```bash
composer analyse && \
  vendor/bin/phpunit --testsuite Unit && \
  vendor/bin/phpunit --testsuite E2E
```

- [ ] **Step 6: Commit**

```bash
git add routes/api.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Wire(insight): 5 backstage routes + DI for Create/Update/Delete use cases (BOTH containers)"
```

### Task 8: Integration test `BackstageInsightsIntegrationTest`

**Files:** Create `tests/Integration/Application/BackstageInsightsIntegrationTest.php`

Full lifecycle + tenant isolation.

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Application\Insight\CreateInsight\CreateInsightInput;
use Daems\Application\Insight\DeleteInsight\DeleteInsight;
use Daems\Application\Insight\DeleteInsight\DeleteInsightInput;
use Daems\Application\Insight\UpdateInsight\UpdateInsight;
use Daems\Application\Insight\UpdateInsight\UpdateInsightInput;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class BackstageInsightsIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private TenantId $tenantA;
    private TenantId $tenantB;

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

        $this->tenantA = TenantId::fromString(Uuid7::generate()->value());
        $this->tenantB = TenantId::fromString(Uuid7::generate()->value());
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantA->value(), 'daems-iba', 'Daems IBA']);
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantB->value(), 'sahegroup-iba', 'SaheGroup IBA']);
    }

    public function test_full_lifecycle(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $update = new UpdateInsight($repo);
        $delete = new DeleteInsight($repo);

        // Create
        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'lifecycle-test',
            title: 'Lifecycle',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'Teaser',
            heroImage: null,
            tags: ['a', 'b'],
            content: '<p>' . str_repeat('word ', 400) . '</p>',
        ));
        $id = $out->insight->id();
        self::assertSame(2, $out->insight->readingTime(), '400 words → 2 min');

        // Confirm in DB
        $row = $this->pdo()->query("SELECT title, reading_time, search_text FROM insights WHERE id = '{$id->value()}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Lifecycle', $row['title']);
        self::assertSame(2, (int) $row['reading_time']);
        self::assertStringContainsString('word', (string) $row['search_text'], 'search_text synced on save');

        // Update
        $update->execute(new UpdateInsightInput(
            insightId: $id,
            tenantId: $this->tenantA,
            slug: 'lifecycle-test',
            title: 'Lifecycle Updated',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: true,
            publishedDate: '2026-05-02',
            author: 'Sam',
            excerpt: 'Teaser v2',
            heroImage: null,
            tags: ['a'],
            content: '<p>short</p>',
        ));

        $row = $this->pdo()->query("SELECT title, featured FROM insights WHERE id = '{$id->value()}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Lifecycle Updated', $row['title']);
        self::assertSame(1, (int) $row['featured']);

        // Delete
        $delete->execute(new DeleteInsightInput($id, $this->tenantA));
        $count = $this->pdo()->query("SELECT COUNT(*) FROM insights WHERE id = '{$id->value()}'")
            ->fetchColumn();
        self::assertSame(0, (int) $count);
    }

    public function test_update_cross_tenant_throws_not_found(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $update = new UpdateInsight($repo);

        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'cross-tenant',
            title: 'Hello',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));

        $this->expectException(NotFoundException::class);
        $update->execute(new UpdateInsightInput(
            insightId: $out->insight->id(),
            tenantId: $this->tenantB,   // wrong tenant
            slug: 'cross-tenant',
            title: 'Evil',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));
    }

    public function test_delete_cross_tenant_throws_not_found(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $delete = new DeleteInsight($repo);

        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'cross-tenant-delete',
            title: 'Hello',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));

        $this->expectException(NotFoundException::class);
        $delete->execute(new DeleteInsightInput($out->insight->id(), $this->tenantB));
    }
}
```

- [ ] **Step 2: Run — all pass**

```bash
vendor/bin/phpunit tests/Integration/Application/BackstageInsightsIntegrationTest.php 2>&1 | tail -5
```

Expected: `OK (3 tests)`.

- [ ] **Step 3: PHPStan**

```bash
composer analyse 2>&1 | grep -E "OK|errors" | head -3
```

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Application/BackstageInsightsIntegrationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(insight): integration — full lifecycle + cross-tenant denial + search_text sync on save"
```

### Task 9: `SqlInsightRepositoryTest` — delete + includeUnpublished

**Files:** Modify (or create) `tests/Integration/Application/SqlInsightRepositoryTest.php`

- [ ] **Step 1: Check existence**

```bash
ls tests/Integration/Application/SqlInsightRepositoryTest.php 2>&1
```

- [ ] **Step 2: Add / extend tests**

Add two tests:

```php
public function test_delete_only_within_tenant(): void
{
    $repo = new SqlInsightRepository($this->conn);
    $id = InsightId::fromString(Uuid7::generate()->value());

    // Insert directly into tenant A
    $this->pdo()->prepare(
        "INSERT INTO insights (id, tenant_id, slug, title, category, category_label, featured,
                               published_date, author, reading_time, excerpt, tags_json, content, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    )->execute([
        $id->value(), $this->tenantA->value(), 'del-test-' . substr($id->value(), -8), 'Delete me',
        'tech', 'Tech', 0, '2026-01-01', 'Sam', 1, 'x', '[]', 'body',
    ]);

    // Attempt delete with wrong tenant — row survives
    $repo->delete($id, $this->tenantB);
    $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM insights WHERE id = '{$id->value()}'")->fetchColumn();
    self::assertSame(1, $count);

    // Delete with correct tenant — row gone
    $repo->delete($id, $this->tenantA);
    $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM insights WHERE id = '{$id->value()}'")->fetchColumn();
    self::assertSame(0, $count);
}

public function test_list_for_tenant_filters_future_dated_when_include_unpublished_false(): void
{
    $repo = new SqlInsightRepository($this->conn);
    $pastId = InsightId::fromString(Uuid7::generate()->value());
    $futureId = InsightId::fromString(Uuid7::generate()->value());

    $this->pdo()->prepare(
        "INSERT INTO insights (id, tenant_id, slug, title, category, category_label, featured,
                               published_date, author, reading_time, excerpt, tags_json, content, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    )->execute([$pastId->value(), $this->tenantA->value(), 'past-' . substr($pastId->value(), -6),
        'Past', 'tech', 'Tech', 0, '2026-01-01', 'Sam', 1, 'x', '[]', 'body']);
    $this->pdo()->prepare(
        "INSERT INTO insights (id, tenant_id, slug, title, category, category_label, featured,
                               published_date, author, reading_time, excerpt, tags_json, content, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    )->execute([$futureId->value(), $this->tenantA->value(), 'future-' . substr($futureId->value(), -6),
        'Future', 'tech', 'Tech', 0, '2099-01-01', 'Sam', 1, 'x', '[]', 'body']);

    $public = $repo->listForTenant($this->tenantA, null, false);
    $titles = array_map(fn($i) => $i->title(), $public);
    self::assertContains('Past', $titles);
    self::assertNotContains('Future', $titles);

    $admin = $repo->listForTenant($this->tenantA, null, true);
    $adminTitles = array_map(fn($i) => $i->title(), $admin);
    self::assertContains('Future', $adminTitles);
}
```

If the class doesn't exist, scaffold it using the `BackstageInsightsIntegrationTest` setUp as a template (same DB config + tenant seed). Use the same `$tenantA`/`$tenantB` setup pattern.

- [ ] **Step 3: Run + commit**

```bash
vendor/bin/phpunit tests/Integration/Application/SqlInsightRepositoryTest.php 2>&1 | tail -5
git add tests/Integration/Application/SqlInsightRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(insight): SqlInsightRepo — delete tenant-scoped + includeUnpublished gates future-dated"
```

### Task 10: Full platform regression + push + PR + merge gate

- [ ] **Step 1: Full local suite green**

```bash
cd /c/laragon/www/daems-platform && composer analyse && \
  vendor/bin/phpunit --testsuite Unit && \
  vendor/bin/phpunit --testsuite Integration --filter "Insight" && \
  vendor/bin/phpunit --testsuite E2E
```

Expected: PHPStan OK, Unit green, integration Insights tests green, E2E green.

- [ ] **Step 2: Push**

```bash
git push -u origin insights-backstage-admin 2>&1 | tail -3
```

- [ ] **Step 3: Open draft PR for CI**

```bash
gh pr create --draft --base dev --head insights-backstage-admin \
  --title "WIP: Insights backstage admin (platform — CRUD use cases + routes)" \
  --body "Draft to trigger CI."
```

- [ ] **Step 4: Watch CI**

```bash
RUN_ID=$(gh run list --branch insights-backstage-admin --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

- [ ] **Step 5: Move to ready + expanded description**

```bash
PR_NUM=$(gh pr list --head insights-backstage-admin --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Insights backstage admin — platform (CRUD use cases + routes + DI)" \
    --body "$(cat <<'EOF'
## Summary

Platform half of the Insights Backstage Admin milestone (spec: docs/superpowers/specs/2026-04-24-insights-backstage-admin-design.md).

- CreateInsight / UpdateInsight / DeleteInsight use cases (clean architecture)
- ListInsights extended with includeUnpublished flag (public path still filters future-dated)
- SqlInsightRepository: delete() + findByIdForTenant() + list filter
- BackstageController: 5 new methods + admin gate
- 5 new routes: /api/v1/backstage/insights (GET+POST) + /{id} (GET+POST+DELETE)
- DI wired in BOTH bootstrap and KernelHarness

## Tests
- PHPStan level 9 = 0
- PHPUnit Unit + Integration green
- Full lifecycle + cross-tenant denial covered

## Pairs with
- daem-society PR (separate branch insights-backstage-admin)
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Platform PR #$PR_NUM ready"
```

- [ ] **Step 6: Wait for "mergaa platform" instruction before merging**

```bash
gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

---

## SOCIETY PR

Work in `C:\laragon\www\sites\daem-society`. Assumes the platform routes are merged and live.

### Task 11: Branch + routes + proxy

**Files:**
- Create `public/api/backstage/insights.php`
- Modify `public/index.php` (add 2 route entries)

- [ ] **Step 1: Branch**

```bash
cd /c/laragon/www/sites/daem-society && \
  git checkout -b insights-backstage-admin dev
```

- [ ] **Step 2: Proxy**

Create `public/api/backstage/insights.php`:

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin'])
         && ($u['role'] ?? '') !== 'admin'
         && ($u['role'] ?? '') !== 'global_system_administrator')) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$op    = (string) ($_GET['op'] ?? '');
$id    = (string) ($_GET['id'] ?? '');
$body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
$token = (string) ($_SESSION['token'] ?? '');

switch ($op) {
    case 'list':
        $qs = http_build_query(array_filter([
            'category' => $_GET['category'] ?? null,
        ], static fn($v) => $v !== null && $v !== ''));
        $r = ApiClient::get('/backstage/insights' . ($qs !== '' ? "?{$qs}" : ''));
        echo json_encode(['data' => $r ?? []]);
        return;

    case 'get':
        if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
        $r = ApiClient::get('/backstage/insights/' . rawurlencode($id));
        echo json_encode(['data' => $r]);
        return;

    case 'create':
        $r = ApiClient::post('/backstage/insights', $body);
        http_response_code((int) ($r['status'] ?? 500));
        echo json_encode($r['body'] ?? []);
        return;

    case 'update':
        if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
        $r = ApiClient::post('/backstage/insights/' . rawurlencode($id), $body);
        http_response_code((int) ($r['status'] ?? 500));
        echo json_encode($r['body'] ?? []);
        return;

    case 'delete':
        if ($id === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); return; }
        // ApiClient may not support DELETE directly; fall back to curl:
        $ch = curl_init('http://daems-platform.local/api/v1/backstage/insights/' . rawurlencode($id));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => array_filter([
                'Accept: application/json',
                $token !== '' ? ('Authorization: Bearer ' . $token) : null,
                'Host: daems-platform.local',
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        http_response_code($code >= 100 ? $code : 502);
        echo (string) $raw;
        return;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown_op', 'op' => $op]);
}
```

Note: if `ApiClient` already has a `delete()` helper, use it and drop the inline curl. Check the class.

- [ ] **Step 3: Routes in `public/index.php`**

Near other backstage API proxy routes, add:

```php
if ($uri === '/api/backstage/insights') {
    require __DIR__ . '/api/backstage/insights.php'; exit;
}
```

Near other `/backstage/*` page routes, add:

```php
if ($uri === '/backstage/insights') {
    require __DIR__ . '/pages/backstage/insights/index.php'; exit;
}
```

- [ ] **Step 4: PHP lint + commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  php -l public/api/backstage/insights.php && \
  php -l public/index.php

git add public/api/backstage/insights.php public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): backstage proxy + routes (/api/backstage/insights + /backstage/insights)"
```

### Task 12: Backstage list page `index.php`

**Files:** Create `public/pages/backstage/insights/index.php`

Copy the structural patterns of `public/pages/backstage/events/index.php` (a 452-line template). Adapt to insights.

- [ ] **Step 1: Write the page**

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
<div class="backstage-insights">
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Insights</h1>
            <p class="page-header__subtitle">Create, edit, publish, and delete insights.</p>
        </div>
        <div>
            <button type="button" class="btn btn--primary" id="insight-add-btn">
                <i class="bi bi-plus-lg me-1"></i> Add insight
            </button>
        </div>
    </div>

    <div class="insight-filter mb-3">
        <input type="search" id="insight-filter-input" class="form-control"
               placeholder="Filter by title…" />
    </div>

    <table class="table insight-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Author</th>
                <th>Status</th>
                <th>Featured</th>
                <th>Published date</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody id="insight-tbody">
            <tr><td colspan="7" class="text-muted text-center">Loading…</td></tr>
        </tbody>
    </table>

    <!-- Modal mount — built by insight-modal.js -->
    <div id="insight-modal-mount"></div>
</div>

<link rel="stylesheet" href="/pages/backstage/insights/insight-modal.css">
<script src="/pages/backstage/events/upload-widget.js"></script>
<script src="/pages/backstage/insights/insight-modal.js"></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
```

- [ ] **Step 2: Lint + commit**

```bash
php -l public/pages/backstage/insights/index.php
git add public/pages/backstage/insights/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): backstage list page (table + filter input + Add button + modal mount)"
```

### Task 13: Modal `insight-modal.js` + `insight-modal.css`

**Files:**
- Create `public/pages/backstage/insights/insight-modal.js`
- Create `public/pages/backstage/insights/insight-modal.css`

Study `public/pages/backstage/events/event-modal.js` for the structure (769 lines — don't copy all; trim to essentials for insights).

- [ ] **Step 1: Write the JS**

Create `public/pages/backstage/insights/insight-modal.js`:

```javascript
/* Insights admin — list rendering + create/edit modal + delete confirm. */
(function () {
    var tbody      = document.getElementById('insight-tbody');
    var addBtn     = document.getElementById('insight-add-btn');
    var filterInput= document.getElementById('insight-filter-input');
    var modalMount = document.getElementById('insight-modal-mount');
    if (!tbody || !addBtn) return;

    var allRows = [];  // cached list from latest fetch

    /* ── list fetch + render ── */

    function loadList() {
        fetch('/api/backstage/insights?op=list').then(function (r) { return r.json(); })
            .then(function (j) { allRows = (j && j.data) || []; renderTable(); })
            .catch(function () { tbody.innerHTML = '<tr><td colspan="7" class="text-danger">Failed to load.</td></tr>'; });
    }

    function renderTable() {
        var q = (filterInput.value || '').toLowerCase().trim();
        var rows = q === '' ? allRows : allRows.filter(function (r) { return r.title.toLowerCase().indexOf(q) !== -1; });
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center">No insights.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (i) {
            var today = new Date().toISOString().slice(0,10);
            var pill =
                i.published_date > today ? '<span class="badge bg-warning">Scheduled</span>' :
                i.published_date === '2099-01-01' ? '<span class="badge bg-secondary">Unpublished</span>' :
                '<span class="badge bg-success">Published</span>';
            var featured = i.featured ? '<span class="badge bg-primary">Featured</span>' : '';
            return '<tr data-id="' + esc(i.id) + '">'
                + '<td><strong>' + esc(i.title) + '</strong></td>'
                + '<td>' + esc(i.category_label || i.category) + '</td>'
                + '<td>' + esc(i.author) + '</td>'
                + '<td>' + pill + '</td>'
                + '<td>' + featured + '</td>'
                + '<td>' + esc(i.published_date) + '</td>'
                + '<td class="text-end">'
                +   '<button class="btn btn-sm btn-outline-secondary me-2 js-edit">Edit</button>'
                +   '<button class="btn btn-sm btn-outline-danger js-del">Delete</button>'
                + '</td>'
                + '</tr>';
        }).join('');

        Array.from(tbody.querySelectorAll('.js-edit')).forEach(function (b) {
            b.addEventListener('click', function () {
                var id = b.closest('tr').getAttribute('data-id');
                openModal(id);
            });
        });
        Array.from(tbody.querySelectorAll('.js-del')).forEach(function (b) {
            b.addEventListener('click', function () {
                var id = b.closest('tr').getAttribute('data-id');
                if (!confirm('Delete this insight? This cannot be undone.')) return;
                fetch('/api/backstage/insights?op=delete&id=' + encodeURIComponent(id), { method: 'POST' })
                    .then(function () { loadList(); });
            });
        });
    }

    filterInput.addEventListener('input', renderTable);

    /* ── modal ── */

    addBtn.addEventListener('click', function () { openModal(null); });

    function openModal(id) {
        modalMount.innerHTML = modalMarkup();
        var modal = modalMount.querySelector('.insight-modal');
        var close = function () { modalMount.innerHTML = ''; };
        modal.querySelector('.insight-modal__close').addEventListener('click', close);
        modal.querySelector('.insight-modal__cancel').addEventListener('click', close);

        // Auto-slug on title blur if slug is empty
        var titleInput = modal.querySelector('[name=title]');
        var slugInput  = modal.querySelector('[name=slug]');
        titleInput.addEventListener('blur', function () {
            if (slugInput.value.trim() === '') slugInput.value = slugify(titleInput.value);
        });

        // Pre-fill if editing
        if (id) {
            fetch('/api/backstage/insights?op=get&id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (j) { fillForm(modal, j.data); });
        }

        modal.querySelector('form').addEventListener('submit', function (e) {
            e.preventDefault();
            var body = collectForm(modal);
            var url = id
                ? '/api/backstage/insights?op=update&id=' + encodeURIComponent(id)
                : '/api/backstage/insights?op=create';
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
            .then(function (res) {
                if (res.ok) { close(); loadList(); }
                else renderErrors(modal, res.body);
            });
        });
    }

    function modalMarkup() {
        return '<div class="insight-modal">'
          + '<div class="insight-modal__inner">'
          + '<div class="insight-modal__head">'
          + '<h3 class="insight-modal__title">Insight</h3>'
          + '<button type="button" class="insight-modal__close" aria-label="Close">×</button>'
          + '</div>'
          + '<form class="insight-modal__form">'
          + '<div class="insight-modal__err"></div>'
          + formField('Title',         'title',         'text')
          + formField('Slug',          'slug',          'text')
          + formField('Category',      'category',      'text')
          + formField('Category label','category_label','text')
          + '<label class="d-flex gap-2 align-items-center">'
          + '<input type="checkbox" name="featured" /> Featured</label>'
          + formField('Published date','published_date','date')
          + formField('Author',        'author',        'text')
          + formField('Excerpt',       'excerpt',       'textarea')
          + formField('Hero image URL','hero_image',    'text')
          + formField('Tags (comma-separated)','tags_csv','text')
          + formField('Content (HTML)','content',       'textarea')
          + '<small class="insight-modal__reading-time text-muted"></small>'
          + '<div class="insight-modal__actions">'
          + '<button type="button" class="btn btn-secondary insight-modal__cancel">Cancel</button>'
          + '<button type="submit" class="btn btn-primary">Save</button>'
          + '</div>'
          + '</form>'
          + '</div>'
          + '</div>';
    }

    function formField(label, name, type) {
        if (type === 'textarea') {
            return '<label class="insight-modal__field">'
              + '<span>' + label + '</span>'
              + '<textarea name="' + name + '" rows="5"></textarea>'
              + '<span class="insight-modal__fielderr"></span>'
              + '</label>';
        }
        return '<label class="insight-modal__field">'
          + '<span>' + label + '</span>'
          + '<input type="' + type + '" name="' + name + '" />'
          + '<span class="insight-modal__fielderr"></span>'
          + '</label>';
    }

    function fillForm(modal, d) {
        if (!d) return;
        ['title','slug','category','category_label','published_date','author','excerpt','hero_image','content']
            .forEach(function (k) { var el = modal.querySelector('[name=' + k + ']'); if (el) el.value = d[k] || ''; });
        modal.querySelector('[name=featured]').checked = !!d.featured;
        modal.querySelector('[name=tags_csv]').value = (d.tags || []).join(', ');
    }

    function collectForm(modal) {
        var v = function (n) { return modal.querySelector('[name=' + n + ']').value.trim(); };
        var tags = v('tags_csv').split(',').map(function (t) { return t.trim(); }).filter(function (t) { return t !== ''; });
        return {
            title: v('title'),
            slug: v('slug'),
            category: v('category'),
            category_label: v('category_label') || v('category'),
            featured: modal.querySelector('[name=featured]').checked,
            published_date: v('published_date'),
            author: v('author'),
            excerpt: v('excerpt'),
            hero_image: v('hero_image') || null,
            tags: tags,
            content: v('content'),
        };
    }

    function renderErrors(modal, body) {
        var err = modal.querySelector('.insight-modal__err');
        err.textContent = (body && body.error) || 'Error';
        err.style.color = '#b91c1c';
        Array.from(modal.querySelectorAll('.insight-modal__fielderr')).forEach(function (e) { e.textContent = ''; });
        if (body && body.errors) {
            Object.keys(body.errors).forEach(function (k) {
                var field = modal.querySelector('[name=' + k + ']');
                if (field) {
                    var eSpan = field.parentElement.querySelector('.insight-modal__fielderr');
                    if (eSpan) { eSpan.textContent = body.errors[k]; eSpan.style.color = '#b91c1c'; }
                }
            });
        }
    }

    function slugify(s) {
        return (s || '').toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 80);
    }

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    }); }

    loadList();
}());
```

- [ ] **Step 2: Write the CSS**

Create `public/pages/backstage/insights/insight-modal.css`:

```css
.insight-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1050; }
.insight-modal__inner { background: #fff; border-radius: 8px; padding: 24px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; }
.insight-modal__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.insight-modal__title { margin: 0; font-size: 20px; }
.insight-modal__close { background: none; border: none; font-size: 24px; cursor: pointer; line-height: 1; }
.insight-modal__form > .insight-modal__field { display: flex; flex-direction: column; margin-bottom: 12px; }
.insight-modal__field > span:first-child { font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
.insight-modal__field input, .insight-modal__field textarea { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
.insight-modal__fielderr { font-size: 12px; margin-top: 2px; min-height: 14px; }
.insight-modal__err { font-size: 13px; margin-bottom: 8px; min-height: 16px; }
.insight-modal__actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.insight-filter input { max-width: 320px; }
.insight-table .badge { font-size: 11px; }
```

- [ ] **Step 3: Lint + commit**

```bash
node --check public/pages/backstage/insights/insight-modal.js
git add public/pages/backstage/insights/insight-modal.js public/pages/backstage/insights/insight-modal.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Feat(insight): backstage modal (create/edit with auto-slug, field errors, delete confirm)"
```

### Task 14: E2E spec

**Files:** Create `tests/e2e/backstage-insights.spec.ts`

- [ ] **Step 1: Write the spec**

```typescript
import { test, expect } from '@playwright/test';

/**
 * Backstage insights — admin-only CRUD surface. Full flow requires an admin
 * session cookie; smoke-level we just verify auth gate + page skeleton.
 */

test.describe('Backstage insights admin', () => {
    test('non-admin visitor is redirected from /backstage/insights', async ({ page }) => {
        const resp = await page.goto('/backstage/insights');
        // Redirect to '/' on unauthorized — status 200 on the landing page.
        expect(resp?.status()).toBe(200);
        expect(page.url()).not.toContain('/backstage/insights');
    });

    test('admin sees the page header (gated on E2E_ADMIN_SESSION cookie)', async ({ page, context }) => {
        const adminSession = process.env.E2E_ADMIN_SESSION;
        if (!adminSession) { test.skip(); return; }
        await context.addCookies([{ name: 'PHPSESSID', value: adminSession, domain: 'localhost', path: '/' }]);
        await page.goto('/backstage/insights');
        await expect(page.locator('h1.page-header__title')).toHaveText('Insights');
    });

    test('admin sees the Add button (gated on E2E_ADMIN_SESSION cookie)', async ({ page, context }) => {
        const adminSession = process.env.E2E_ADMIN_SESSION;
        if (!adminSession) { test.skip(); return; }
        await context.addCookies([{ name: 'PHPSESSID', value: adminSession, domain: 'localhost', path: '/' }]);
        await page.goto('/backstage/insights');
        await expect(page.locator('#insight-add-btn')).toBeVisible();
    });
});
```

- [ ] **Step 2: Run against dev server**

```bash
npx playwright test --project=chromium tests/e2e/backstage-insights.spec.ts --reporter=line 2>&1 | tail -10
```

Expected: 1 passing (redirect test), 2 skipped (gated on env var). That's acceptable for CI smoke.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/backstage-insights.spec.ts
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
  -m "Test(e2e): backstage insights — non-admin redirect + admin-session-gated smoke"
```

### Task 15: Push society + PR + await merge

- [ ] **Step 1: Push**

```bash
git push -u origin insights-backstage-admin 2>&1 | tail -3
```

- [ ] **Step 2: Open draft PR + watch CI**

```bash
gh pr create --draft --base dev --head insights-backstage-admin \
  --title "WIP: Insights backstage admin (frontend)" \
  --body "Draft — CI trigger."

RUN_ID=$(gh run list --branch insights-backstage-admin --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

- [ ] **Step 3: Ready + description**

```bash
PR_NUM=$(gh pr list --head insights-backstage-admin --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Insights backstage admin — frontend (page + modal + proxy)" \
    --body "$(cat <<'EOF'
## Summary

Frontend half of the Insights Backstage Admin milestone.

- `/backstage/insights` list view (filter by title, status pill, Add/Edit/Delete actions)
- Create/Edit modal with auto-slugification and per-field validation errors
- Hard delete with confirmation
- Proxy at `/api/backstage/insights` (list/get/create/update/delete via `?op=`)

## Depends on
- daems-platform PR (backend must be live)

## Tests
- PHP lint clean
- Playwright smoke (1 passing redirect; 2 admin-session-gated tests skip cleanly)
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Society PR #$PR_NUM ready"
```

- [ ] **Step 4: Wait for "mergaa society" before merging**

```bash
gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

- [ ] **Step 5: Final manual verification**

Report to user:
- Both PRs merged
- Manual: sign in as admin → `/backstage/insights` → "Add insight" → fill + save → row appears in table; edit → change title → save → updated; delete → confirm → row gone
- Manual: sign in as non-admin → `/backstage/insights` → redirected to `/`

---

## Self-review coverage map

| Spec section | Task(s) |
|---|---|
| CRUD scope (decisions 1, 15) | 2, 3, 4 (use cases); 6 (controller) |
| i18n scope — mono-locale preserved (decision 2) | Baked in — no migrations; unchanged |
| Content editor = textarea (decision 3) | 13 (modal markup) |
| Slug auto-generate (decision 4) | 2 (repo uniqueness check); 13 (JS slugify on title blur) |
| Reading time auto-compute (decision 5) | 2 (`CreateInsight::computeReadingTime`); 3 reuses it |
| Hero image via events upload-widget (decision 6) | 12 (script src); 13 (modal consumes) |
| Categories free-text (decision 7) | 13 (plain inputs) |
| Tags comma-separated (decision 8) | 13 (`collectForm` splits comma-separated); 2/3 accept string[] |
| Hard delete (decision 9) | 4, 9 (unit + integration) |
| Publish flow via published_date (decision 10) | 1 (repo filter); 12 (status pill); 13 (no separate publish button) |
| List filter client-side (decision 11) | 12 (filter input); 13 (`renderTable` filter) |
| Validation (decision 12) | 2, 3 (use cases); 13 (renderErrors shows field errors) |
| Auth admin-only (decision 13) | 6 (requireInsightsAdmin helper), 11 (proxy auth check), 12 (page gate) |
| Code-reuse: copy events pattern (decision 14) | 12, 13 (copy + trim) |
| BOTH-wire platform architecture (decisions 15, 16) | 7 |
| Search sync preserved (decision 16) | 8 (integration test asserts search_text populated after create) |
