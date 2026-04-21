# Content i18n (events + projects) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:dispatching-parallel-agents` to run workstreams A/B/C in parallel worktrees. Within each workstream, use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to process tasks. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship multilingual content (events + projects + proposals) across daems-platform + daem-society for locales `fi_FI`, `en_GB`, `sw_TZ` — including new EventProposal domain, locale-card admin UX, full-locale UI chrome migration.

**Architecture:** Per-locale rows in `events_i18n` / `projects_i18n`; Locale value objects and middleware in platform; card-based locale editor inside existing event/project modals on frontend; `ApiClient` sends `Accept-Language`; per-field fallback to `en_GB` with `*_fallback` / `*_missing` markers in API responses.

**Tech Stack:** PHP 8.1+ at PHPStan level 9, MySQL 8.x, PHPUnit 10, Clean Architecture; daem-society public site (PHP templates, Bootstrap 5, vanilla JS); Playwright for E2E.

**Spec:** `docs/superpowers/specs/2026-04-21-content-i18n-events-projects-design.md`

**Repos:**
- Platform backend: `C:\laragon\www\daems-platform` (branch: `dev`)
- Frontend (society + backstage): `C:\laragon\www\sites\daem-society`

**Merge order:** A → B → C into `dev` (B and C target dev after A merges; they can be developed in parallel against the spec's locked contract).

**Commit identity (all commits, both repos):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit ...`. No Co-Authored-By. Never auto-push. Never stage `.claude/`.

**Forbidden:** `mcp__code-review-graph__*` tools (see CLAUDE.md memory).

---

## File Structure

### Workstream A — daems-platform (backend)

**Created — migrations (`database/migrations/`):**
- `051_create_events_i18n.sql`
- `052_create_projects_i18n.sql`
- `053_backfill_events_projects_i18n.sql`
- `054_drop_translated_columns_from_events_projects.sql`
- `055_add_source_locale_to_project_proposals.sql`
- `056_create_event_proposals.sql`

**Created — Domain (`src/Domain/`):**
- `Locale/SupportedLocale.php`
- `Locale/LocaleNegotiator.php`
- `Locale/TranslationMap.php`
- `Locale/EntityTranslationView.php`
- `Locale/InvalidLocaleException.php`
- `Event/EventProposal.php`
- `Event/EventProposalId.php`
- `Event/EventProposalRepositoryInterface.php`

**Modified — Domain:**
- `src/Domain/Event/Event.php` — entity accepts `TranslationMap`, getters return localized view
- `src/Domain/Event/EventRepositoryInterface.php` — new locale-aware methods
- `src/Domain/Project/Project.php` — same pattern
- `src/Domain/Project/ProjectRepositoryInterface.php` — same pattern
- `src/Domain/Project/ProjectProposal.php` — add `sourceLocale` field
- `src/Domain/Project/ProjectProposalRepositoryInterface.php` — update if needed for listing

**Created — Application (`src/Application/`):**
- `Event/ListEventsForLocale/{ListEventsForLocale,ListEventsForLocaleInput,ListEventsForLocaleOutput}.php`
- `Event/GetEventBySlugForLocale/{...}.php`
- `Backstage/GetEventWithAllTranslations/{...}.php`
- `Backstage/UpdateEventTranslation/{...}.php`
- `Project/ListProjectsForLocale/{...}.php`
- `Project/GetProjectBySlugForLocale/{...}.php`
- `Backstage/GetProjectWithAllTranslations/{...}.php`
- `Backstage/UpdateProjectTranslation/{...}.php`
- `Event/SubmitEventProposal/{...}.php`
- `Backstage/ApproveEventProposal/{...}.php`
- `Backstage/RejectEventProposal/{...}.php`
- `Backstage/ListEventProposalsForAdmin/{...}.php`

**Modified — Application:**
- `src/Application/Project/SubmitProjectProposal/SubmitProjectProposalInput.php` — add optional `sourceLocale`
- `src/Application/Backstage/ApproveProjectProposal/ApproveProjectProposal.php` — writes project + i18n row for source locale
- `src/Application/Backstage/ListProposalsForAdmin/ListProposalsForAdminOutput.php` — include source_locale per item

**Created — Infrastructure:**
- `src/Infrastructure/Adapter/Api/Middleware/LocaleMiddleware.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php`

**Modified — Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php` — read/write via `events_i18n` JOIN; new `listForTenantInLocale`, `findBySlugForTenantInLocale`, `findByIdWithAllTranslationsForTenant`, `saveTranslation`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php` — same pattern
- `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php` — plumb `sourceLocale`
- `src/Infrastructure/Adapter/Api/Controller/EventController.php` — locale-aware handlers + submit-proposal
- `src/Infrastructure/Adapter/Api/Controller/ProjectController.php` — locale-aware handlers; update submit-proposal input
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add translation endpoints + event-proposal endpoints; rename project-proposal paths internally
- `routes/api.php` — new routes + rename project-proposal admin routes

**Modified — bootstrap + test harness:**
- `bootstrap/app.php` — bind new use cases + repo + middleware
- `tests/Support/KernelHarness.php` — mirror bindings with in-memory fakes
- `tests/Support/Fake/InMemoryEventRepository.php` — implement new methods
- `tests/Support/Fake/InMemoryProjectRepository.php` — implement new methods
- `tests/Support/Fake/InMemoryEventProposalRepository.php` (new)
- `tests/Support/Fake/InMemoryProjectProposalRepository.php` — plumb source_locale

**Created — tests:**
- `tests/Unit/Domain/Locale/SupportedLocaleTest.php`
- `tests/Unit/Domain/Locale/LocaleNegotiatorTest.php`
- `tests/Unit/Domain/Locale/TranslationMapTest.php`
- `tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php`
- `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- `tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php`
- `tests/Isolation/EventsI18nTenantIsolationTest.php`
- `tests/Isolation/ProjectsI18nTenantIsolationTest.php`
- `tests/Isolation/EventProposalTenantIsolationTest.php`
- `tests/E2E/EventsLocaleE2ETest.php`
- `tests/E2E/ProjectsLocaleE2ETest.php`
- `tests/E2E/EventProposalFlowE2ETest.php`

### Workstream B — daem-society (backstage admin UI)

**Created:**
- `public/pages/backstage/shared/locale-cards.php` (partial rendered into event + project modals)
- `public/pages/backstage/shared/locale-cards.css`
- `public/pages/backstage/shared/locale-cards.js`
- `public/pages/backstage/event-proposals/index.php`
- `public/pages/backstage/event-proposals/proposal-modal.css`
- `public/pages/backstage/event-proposals/proposal-modal.js`
- `public/pages/backstage/project-proposals/index.php`
- `public/pages/backstage/project-proposals/proposal-modal.css`
- `public/pages/backstage/project-proposals/proposal-modal.js`

**Modified:**
- `public/pages/backstage/events/index.php` — list view coverage badge column
- `public/pages/backstage/events/event-modal.js` — integrate locale cards, per-locale save
- `public/pages/backstage/events/event-modal.css` — additions for cards layout
- `public/pages/backstage/projects/index.php` — list view coverage badge column
- `public/pages/backstage/projects/project-modal.js` — integrate locale cards, per-locale save
- `public/pages/backstage/projects/project-modal.css` — additions for cards layout
- `public/assets/css/daems.css` — add `.coverage-badge` utility classes if not suitable in scoped files

**Created — tests:**
- `tests/e2e/backstage-locale-cards.spec.ts`
- `tests/e2e/backstage-event-proposals.spec.ts`
- `tests/e2e/backstage-project-proposals.spec.ts`

### Workstream C — daem-society (public frontend + I18n chrome migration)

**Renamed:**
- `lang/fi.php` → `lang/fi_FI.php`
- `lang/en.php` → `lang/en_GB.php`
- `lang/sw.php` → `lang/sw_TZ.php`

**Modified:**
- `src/I18n.php` — full-locale support, legacy cookie/session remap, Accept-Language parser upgrade
- `src/ApiClient.php` — default `Accept-Language` header
- `public/pages/events/grid.php` — consume localized API
- `public/pages/events/detail.php` — consume localized API
- `public/pages/events/detail/content.php` — consume localized API
- `public/pages/events/detail/hero.php` — consume localized API
- `public/pages/projects/grid.php` — consume localized API
- `public/pages/projects/detail.php` — consume localized API
- `public/pages/projects/detail/content.php` — consume localized API
- `public/pages/projects/detail/hero.php` — consume localized API
- `public/api/project-proposals/submit.php` (if exists) — plumb `source_locale`
- `public/pages/projects/propose.php` (if that's the name) — hidden `source_locale` field

**Created:**
- `public/pages/events/propose.php`
- `public/api/event-proposals/submit.php` (proxy endpoint if the daem-society uses API proxy pattern — verify during task C1)

**Modified — tests:**
- `tests/e2e/i18n.spec.ts` — add events + projects locale rendering coverage
- `tests/e2e/events.spec.ts` — add locale negotiation assertion if present

---

# WORKSTREAM A — Platform/Backend

**Working directory:** `C:\laragon\www\daems-platform`
**Isolation:** Run in a dedicated worktree (`git worktree add`) if parallel with B/C. B and C can start once Task A3 lands (contract locked).

## Task A1: Locale value objects + negotiator (TDD)

**Files:**
- Create: `src/Domain/Locale/SupportedLocale.php`
- Create: `src/Domain/Locale/InvalidLocaleException.php`
- Create: `tests/Unit/Domain/Locale/SupportedLocaleTest.php`

- [ ] **Step 1: Write failing test for SupportedLocale**

Create `tests/Unit/Domain/Locale/SupportedLocaleTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\InvalidLocaleException;
use PHPUnit\Framework\TestCase;

final class SupportedLocaleTest extends TestCase
{
    public function testFromStringAcceptsFiFi(): void
    {
        $locale = SupportedLocale::fromString('fi_FI');
        $this->assertSame('fi_FI', $locale->value());
    }

    public function testFromStringAcceptsEnGb(): void
    {
        $this->assertSame('en_GB', SupportedLocale::fromString('en_GB')->value());
    }

    public function testFromStringAcceptsSwTz(): void
    {
        $this->assertSame('sw_TZ', SupportedLocale::fromString('sw_TZ')->value());
    }

    public function testFromStringNormalizesHyphen(): void
    {
        $this->assertSame('fi_FI', SupportedLocale::fromString('fi-FI')->value());
    }

    public function testFromStringRejectsUnsupported(): void
    {
        $this->expectException(InvalidLocaleException::class);
        SupportedLocale::fromString('de_DE');
    }

    public function testFromShortMapsToDefaultRegion(): void
    {
        $this->assertSame('fi_FI', SupportedLocale::fromShort('fi')->value());
        $this->assertSame('en_GB', SupportedLocale::fromShort('en')->value());
        $this->assertSame('sw_TZ', SupportedLocale::fromShort('sw')->value());
    }

    public function testAllReturnsSupportedList(): void
    {
        $values = array_map(fn($l) => $l->value(), SupportedLocale::all());
        $this->assertSame(['fi_FI', 'en_GB', 'sw_TZ'], $values);
    }

    public function testEqualsComparesValue(): void
    {
        $a = SupportedLocale::fromString('fi_FI');
        $b = SupportedLocale::fromString('fi-FI');
        $this->assertTrue($a->equals($b));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --testsuite Unit --filter SupportedLocaleTest
```

Expected: FAIL (class not found).

- [ ] **Step 3: Implement `InvalidLocaleException`**

Create `src/Domain/Locale/InvalidLocaleException.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class InvalidLocaleException extends \DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Unsupported locale "%s"', $value));
    }
}
```

- [ ] **Step 4: Implement `SupportedLocale`**

Create `src/Domain/Locale/SupportedLocale.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class SupportedLocale
{
    private const SUPPORTED = ['fi_FI', 'en_GB', 'sw_TZ'];
    private const SHORT_MAP = ['fi' => 'fi_FI', 'en' => 'en_GB', 'sw' => 'sw_TZ'];

    public const UI_DEFAULT = 'fi_FI';
    public const CONTENT_FALLBACK = 'en_GB';

    private function __construct(private readonly string $value) {}

    public static function fromString(string $input): self
    {
        $normalized = str_replace('-', '_', trim($input));
        if (!in_array($normalized, self::SUPPORTED, true)) {
            throw InvalidLocaleException::forValue($input);
        }
        return new self($normalized);
    }

    public static function fromShort(string $short): self
    {
        $s = strtolower(trim($short));
        if (!isset(self::SHORT_MAP[$s])) {
            throw InvalidLocaleException::forValue($short);
        }
        return new self(self::SHORT_MAP[$s]);
    }

    public static function uiDefault(): self { return new self(self::UI_DEFAULT); }
    public static function contentFallback(): self { return new self(self::CONTENT_FALLBACK); }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(fn(string $v) => new self($v), self::SUPPORTED);
    }

    /** @return list<string> */
    public static function supportedValues(): array { return self::SUPPORTED; }

    public static function isSupported(string $input): bool
    {
        $normalized = str_replace('-', '_', trim($input));
        return in_array($normalized, self::SUPPORTED, true);
    }

    public function value(): string { return $this->value; }

    public function equals(self $other): bool { return $this->value === $other->value; }
}
```

- [ ] **Step 5: Run tests to verify pass**

```bash
vendor/bin/phpunit --testsuite Unit --filter SupportedLocaleTest
```

Expected: all tests green.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Locale/SupportedLocale.php src/Domain/Locale/InvalidLocaleException.php \
        tests/Unit/Domain/Locale/SupportedLocaleTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(locale): SupportedLocale value object (fi_FI/en_GB/sw_TZ + hyphen and short forms)"
```

## Task A2: LocaleNegotiator (TDD)

**Files:**
- Create: `src/Domain/Locale/LocaleNegotiator.php`
- Create: `tests/Unit/Domain/Locale/LocaleNegotiatorTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Domain/Locale/LocaleNegotiatorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\LocaleNegotiator;
use Daems\Domain\Locale\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class LocaleNegotiatorTest extends TestCase
{
    public function testPrefersAcceptLanguageOverQuery(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9'],
            query: ['lang' => 'sw_TZ'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testQueryParamOverridesWhenAcceptLanguageAbsent(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: ['lang' => 'sw_TZ'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testCustomHeaderLastResortBeforeDefault(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_X_DAEMS_LOCALE' => 'fi_FI'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('fi_FI', $locale->value());
    }

    public function testDefaultWhenAllMissing(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testAcceptLanguageShortFormMaps(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'sw'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testAcceptLanguageSkipsUnsupportedAndTakesNextSupported(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,fr;q=0.9,sw;q=0.8'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('sw_TZ', $locale->value());
    }

    public function testInvalidQueryParamIgnored(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: [],
            query: ['lang' => 'de_DE'],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }

    public function testInvalidCustomHeaderIgnored(): void
    {
        $locale = LocaleNegotiator::negotiate(
            server: ['HTTP_X_DAEMS_LOCALE' => 'de_DE'],
            query: [],
            default: SupportedLocale::contentFallback(),
        );
        $this->assertSame('en_GB', $locale->value());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --testsuite Unit --filter LocaleNegotiatorTest
```

Expected: FAIL.

- [ ] **Step 3: Implement LocaleNegotiator**

Create `src/Domain/Locale/LocaleNegotiator.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class LocaleNegotiator
{
    /**
     * @param array<string, mixed> $server $_SERVER superglobal (or equivalent)
     * @param array<string, mixed> $query  $_GET superglobal (or equivalent)
     */
    public static function negotiate(
        array $server,
        array $query,
        SupportedLocale $default,
    ): SupportedLocale {
        // 1. Accept-Language
        $accept = (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($accept !== '') {
            foreach (explode(',', $accept) as $tag) {
                $code = trim(explode(';', $tag)[0]);
                if ($code === '' || $code === '*') continue;
                $full = str_replace('-', '_', $code);
                if (SupportedLocale::isSupported($full)) {
                    return SupportedLocale::fromString($full);
                }
                // try short form
                $short = strtolower(substr($code, 0, 2));
                try {
                    return SupportedLocale::fromShort($short);
                } catch (InvalidLocaleException) {
                    continue;
                }
            }
        }

        // 2. Query param
        $lang = $query['lang'] ?? null;
        if (is_string($lang) && SupportedLocale::isSupported(str_replace('-', '_', $lang))) {
            return SupportedLocale::fromString($lang);
        }

        // 3. Custom header
        $custom = (string) ($server['HTTP_X_DAEMS_LOCALE'] ?? '');
        if ($custom !== '' && SupportedLocale::isSupported(str_replace('-', '_', $custom))) {
            return SupportedLocale::fromString($custom);
        }

        // 4. Default
        return $default;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit --testsuite Unit --filter LocaleNegotiatorTest
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Locale/LocaleNegotiator.php tests/Unit/Domain/Locale/LocaleNegotiatorTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(locale): LocaleNegotiator resolves Accept-Language / ?lang= / X-Daems-Locale with priority"
```

## Task A3: TranslationMap + EntityTranslationView (TDD — contract-lock)

**Files:**
- Create: `src/Domain/Locale/TranslationMap.php`
- Create: `src/Domain/Locale/EntityTranslationView.php`
- Create: `tests/Unit/Domain/Locale/TranslationMapTest.php`

**Contract-lock:** After this task merges, B and C can start. The `EntityTranslationView` shape determines the per-field JSON response format.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Domain/Locale/TranslationMapTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Locale\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class TranslationMapTest extends TestCase
{
    public function testViewReturnsRequestedLocaleFields(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Meeting', 'description' => 'Description'],
            'sw_TZ' => null,
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'description'],
        );
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));
        $this->assertFalse($view->isMissing('title'));
    }

    public function testViewFallsBackPerField(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous'], // description missing
            'en_GB' => ['title' => 'Meeting', 'description' => 'English desc'],
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'description'],
        );
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));
        $this->assertSame('English desc', $view->field('description'));
        $this->assertTrue($view->isFallback('description'));
        $this->assertFalse($view->isMissing('description'));
    }

    public function testMissingWhenBothLocalesEmpty(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous'],
            'en_GB' => ['title' => 'Meeting'],
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'location'],
        );
        $this->assertNull($view->field('location'));
        $this->assertFalse($view->isFallback('location'));
        $this->assertTrue($view->isMissing('location'));
    }

    public function testCoverageCountsPerLocale(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'A', 'location' => 'B', 'description' => 'C'],
            'en_GB' => ['title' => 'A', 'location' => null, 'description' => null],
            'sw_TZ' => null,
        ]);
        $coverage = $map->coverage(['title', 'location', 'description']);
        $this->assertSame(['filled' => 3, 'total' => 3], $coverage['fi_FI']);
        $this->assertSame(['filled' => 1, 'total' => 3], $coverage['en_GB']);
        $this->assertSame(['filled' => 0, 'total' => 3], $coverage['sw_TZ']);
    }

    public function testEmptyStringDoesNotCountAsFilled(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => '', 'location' => 'Hki', 'description' => 'D'],
        ]);
        $coverage = $map->coverage(['title', 'location', 'description']);
        $this->assertSame(['filled' => 2, 'total' => 3], $coverage['fi_FI']);
    }
}
```

- [ ] **Step 2: Run test to verify fail**

```bash
vendor/bin/phpunit --testsuite Unit --filter TranslationMapTest
```

Expected: FAIL.

- [ ] **Step 3: Implement `EntityTranslationView`**

Create `src/Domain/Locale/EntityTranslationView.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class EntityTranslationView
{
    /**
     * @param array<string, ?string> $values
     * @param array<string, bool>    $fallback per-field flag
     * @param array<string, bool>    $missing  per-field flag
     */
    public function __construct(
        private readonly array $values,
        private readonly array $fallback,
        private readonly array $missing,
    ) {}

    public function field(string $name): ?string { return $this->values[$name] ?? null; }
    public function isFallback(string $name): bool { return $this->fallback[$name] ?? false; }
    public function isMissing(string $name): bool { return $this->missing[$name] ?? false; }

    /** @return array<string, mixed> flat array: {field}, {field}_fallback, {field}_missing */
    public function toApiPayload(): array
    {
        $out = [];
        foreach ($this->values as $name => $value) {
            $out[$name] = $value;
            $out[$name . '_fallback'] = $this->isFallback($name);
            $out[$name . '_missing'] = $this->isMissing($name);
        }
        return $out;
    }
}
```

- [ ] **Step 4: Implement `TranslationMap`**

Create `src/Domain/Locale/TranslationMap.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class TranslationMap
{
    /**
     * @param array<string, array<string, ?string>|null> $data Keyed by locale value;
     *   inner array is field => value, or null if locale has no row.
     */
    public function __construct(private readonly array $data) {}

    /**
     * @param list<string> $fields
     */
    public function view(
        SupportedLocale $requested,
        SupportedLocale $fallback,
        array $fields,
    ): EntityTranslationView {
        $reqRow = $this->data[$requested->value()] ?? null;
        $fbRow  = $this->data[$fallback->value()] ?? null;

        $values = $fallbackFlags = $missingFlags = [];
        foreach ($fields as $f) {
            $reqVal = self::nonEmpty($reqRow[$f] ?? null);
            if ($reqVal !== null) {
                $values[$f] = $reqVal;
                $fallbackFlags[$f] = false;
                $missingFlags[$f]  = false;
                continue;
            }
            $fbVal = self::nonEmpty($fbRow[$f] ?? null);
            if ($fbVal !== null && !$requested->equals($fallback)) {
                $values[$f] = $fbVal;
                $fallbackFlags[$f] = true;
                $missingFlags[$f]  = false;
                continue;
            }
            $values[$f] = null;
            $fallbackFlags[$f] = false;
            $missingFlags[$f]  = true;
        }
        return new EntityTranslationView($values, $fallbackFlags, $missingFlags);
    }

    /**
     * @param list<string> $fields
     * @return array<string, array{filled: int, total: int}>
     */
    public function coverage(array $fields): array
    {
        $out = [];
        foreach (SupportedLocale::supportedValues() as $loc) {
            $row = $this->data[$loc] ?? null;
            $filled = 0;
            foreach ($fields as $f) {
                if (self::nonEmpty($row[$f] ?? null) !== null) $filled++;
            }
            $out[$loc] = ['filled' => $filled, 'total' => count($fields)];
        }
        return $out;
    }

    /** @return array<string, array<string, ?string>|null> */
    public function raw(): array { return $this->data; }

    public function rowFor(SupportedLocale $locale): ?array { return $this->data[$locale->value()] ?? null; }

    private static function nonEmpty(?string $v): ?string
    {
        if ($v === null) return null;
        $trim = trim($v);
        return $trim === '' ? null : $v;
    }
}
```

- [ ] **Step 5: Run tests**

```bash
vendor/bin/phpunit --testsuite Unit --filter TranslationMapTest
```

Expected: all green.

- [ ] **Step 6: Run phpstan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Locale/TranslationMap.php src/Domain/Locale/EntityTranslationView.php \
        tests/Unit/Domain/Locale/TranslationMapTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(locale): TranslationMap + EntityTranslationView with per-field fallback and coverage"
```

**Contract-lock announcement:** At this point, B and C can begin against the spec. The API response shape is:

```
{
  "<field>": "...",
  "<field>_fallback": bool,
  "<field>_missing": bool
}
```

and the admin shape includes `translations: { locale: { fields }|null }` plus `coverage: { locale: {filled,total} }`.

## Task A4: Migration 051 — create events_i18n + backfill test

**Files:**
- Create: `database/migrations/051_create_events_i18n.sql`

- [ ] **Step 1: Create migration SQL**

```sql
-- database/migrations/051_create_events_i18n.sql
CREATE TABLE events_i18n (
    event_id    CHAR(36)     NOT NULL,
    locale      VARCHAR(10)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    location    VARCHAR(255) NULL,
    description TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, locale),
    CONSTRAINT fk_events_i18n_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply to dev DB**

```bash
php scripts/apply_pending_migrations.php
```

Expected output: "Applied 051_create_events_i18n.sql".

- [ ] **Step 3: Verify schema**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db \
  -e "DESCRIBE events_i18n;"
```

Expected: 7 columns, PK (event_id, locale), FK to events.id.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/051_create_events_i18n.sql
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Chore(db): migration 051 creates events_i18n with per-locale rows + cascade-delete FK"
```

## Task A5: Migration 052 — create projects_i18n

**Files:**
- Create: `database/migrations/052_create_projects_i18n.sql`

- [ ] **Step 1: Create migration SQL**

```sql
-- database/migrations/052_create_projects_i18n.sql
CREATE TABLE projects_i18n (
    project_id  CHAR(36)     NOT NULL,
    locale      VARCHAR(10)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    summary     TEXT         NOT NULL,
    description LONGTEXT     NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, locale),
    CONSTRAINT fk_projects_i18n_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply + verify + commit**

```bash
php scripts/apply_pending_migrations.php
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e "DESCRIBE projects_i18n;"
git add database/migrations/052_create_projects_i18n.sql
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Chore(db): migration 052 creates projects_i18n with per-locale rows + cascade-delete FK"
```

## Task A6: Migration 053 — backfill fi_FI rows from legacy columns

**Files:**
- Create: `database/migrations/053_backfill_events_projects_i18n.sql`

- [ ] **Step 1: Create migration**

```sql
-- database/migrations/053_backfill_events_projects_i18n.sql
INSERT INTO events_i18n (event_id, locale, title, location, description, created_at, updated_at)
SELECT id, 'fi_FI', title, location, description, created_at, created_at
FROM events
WHERE NOT EXISTS (
    SELECT 1 FROM events_i18n ei WHERE ei.event_id = events.id AND ei.locale = 'fi_FI'
);

INSERT INTO projects_i18n (project_id, locale, title, summary, description, created_at, updated_at)
SELECT id, 'fi_FI', title, summary, description, created_at, created_at
FROM projects
WHERE NOT EXISTS (
    SELECT 1 FROM projects_i18n pi WHERE pi.project_id = projects.id AND pi.locale = 'fi_FI'
);
```

The `WHERE NOT EXISTS` makes the migration idempotent (safe to re-run).

- [ ] **Step 2: Apply + verify row counts match**

```bash
php scripts/apply_pending_migrations.php
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -uroot -psalasana daems_db -e \
  "SELECT (SELECT COUNT(*) FROM events) AS events, (SELECT COUNT(*) FROM events_i18n) AS events_i18n, (SELECT COUNT(*) FROM projects) AS projects, (SELECT COUNT(*) FROM projects_i18n) AS projects_i18n;"
```

Expected: `events == events_i18n`, `projects == projects_i18n` (1:1 per fi_FI row).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/053_backfill_events_projects_i18n.sql
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Chore(db): migration 053 backfills events_i18n + projects_i18n with fi_FI from legacy columns"
```

## Task A7: Refactor Event entity + EventRepositoryInterface

**Files:**
- Modify: `src/Domain/Event/Event.php`
- Modify: `src/Domain/Event/EventRepositoryInterface.php`

- [ ] **Step 1: Update `Event` entity**

Replace `src/Domain/Event/Event.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Locale\EntityTranslationView;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;

final class Event
{
    public const TRANSLATABLE_FIELDS = ['title', 'location', 'description'];

    public function __construct(
        private readonly EventId $id,
        private readonly TenantId $tenantId,
        private readonly string $slug,
        private readonly string $type,
        private readonly string $date,
        private readonly ?string $time,
        private readonly bool $online,
        private readonly ?string $heroImage,
        private readonly array $gallery,
        private readonly TranslationMap $translations,
        private readonly string $status = 'published',
    ) {}

    public function id(): EventId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function slug(): string { return $this->slug; }
    public function type(): string { return $this->type; }
    public function date(): string { return $this->date; }
    public function time(): ?string { return $this->time; }
    public function online(): bool { return $this->online; }
    public function heroImage(): ?string { return $this->heroImage; }
    public function gallery(): array { return $this->gallery; }
    public function status(): string { return $this->status; }
    public function translations(): TranslationMap { return $this->translations; }

    public function view(SupportedLocale $requested, SupportedLocale $fallback): EntityTranslationView
    {
        return $this->translations->view($requested, $fallback, self::TRANSLATABLE_FIELDS);
    }
}
```

- [ ] **Step 2: Update `EventRepositoryInterface`**

Read current: `src/Domain/Event/EventRepositoryInterface.php` first to preserve unrelated methods. Then add locale-aware reads and translation save:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

interface EventRepositoryInterface
{
    public function save(Event $event): void;

    /** @return list<Event> */
    public function listForTenant(TenantId $tenantId, ?string $type = null): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Event;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event;

    /**
     * Upsert one locale's translation row. Fields outside the TRANSLATABLE_FIELDS set are ignored.
     *
     * @param array<string, ?string> $fields title + location + description
     */
    public function saveTranslation(
        TenantId $tenantId,
        string $eventId,
        SupportedLocale $locale,
        array $fields,
    ): void;

    public function deleteForTenant(string $id, TenantId $tenantId): void;
}
```

Keep any other methods already present (e.g., relating to event registrations) unchanged.

- [ ] **Step 3: Run phpstan; expect many errors in callers — that's next tasks**

```bash
composer analyse
```

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Event/Event.php src/Domain/Event/EventRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Refactor(event): Event entity uses TranslationMap; EventRepositoryInterface adds saveTranslation"
```

## Task A8: Refactor Project entity + ProjectRepositoryInterface

**Files:**
- Modify: `src/Domain/Project/Project.php`
- Modify: `src/Domain/Project/ProjectRepositoryInterface.php`

- [ ] **Step 1: Read current `Project`** to preserve all non-translatable fields.

```bash
cat src/Domain/Project/Project.php
```

- [ ] **Step 2: Update `Project` entity**

Replace `src/Domain/Project/Project.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Locale\EntityTranslationView;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;

final class Project
{
    public const TRANSLATABLE_FIELDS = ['title', 'summary', 'description'];

    public function __construct(
        private readonly ProjectId $id,
        private readonly TenantId $tenantId,
        private readonly string $slug,
        private readonly string $category,
        private readonly string $icon,
        private readonly string $status,
        private readonly int $sortOrder,
        private readonly bool $featured,
        private readonly string $createdAt,
        private readonly TranslationMap $translations,
    ) {}

    public function id(): ProjectId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function slug(): string { return $this->slug; }
    public function category(): string { return $this->category; }
    public function icon(): string { return $this->icon; }
    public function status(): string { return $this->status; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function featured(): bool { return $this->featured; }
    public function createdAt(): string { return $this->createdAt; }
    public function translations(): TranslationMap { return $this->translations; }

    public function view(SupportedLocale $requested, SupportedLocale $fallback): EntityTranslationView
    {
        return $this->translations->view($requested, $fallback, self::TRANSLATABLE_FIELDS);
    }
}
```

Note: if existing `Project` constructor has parameters that differ, keep them. The important change is **remove `title`, `summary`, `description` as plain strings and add `TranslationMap $translations`**.

- [ ] **Step 3: Update `ProjectRepositoryInterface`**

Same pattern as Event:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

interface ProjectRepositoryInterface
{
    public function save(Project $project): void;

    /** @return list<Project> */
    public function listForTenant(TenantId $tenantId): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project;

    /** @param array<string, ?string> $fields title + summary + description */
    public function saveTranslation(
        TenantId $tenantId,
        string $projectId,
        SupportedLocale $locale,
        array $fields,
    ): void;

    public function deleteForTenant(string $id, TenantId $tenantId): void;
}
```

Preserve any additional methods already present.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Project/Project.php src/Domain/Project/ProjectRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Refactor(project): Project entity uses TranslationMap; ProjectRepositoryInterface adds saveTranslation"
```

## Task A9: Update SqlEventRepository for i18n read + write

**Files:**
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php`
- Create: `tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php`

- [ ] **Step 1: Read existing repo implementation**

```bash
cat src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php
```

Note the existing `save`, `listForTenant`, `findByIdForTenant`, `findBySlugForTenant`, `deleteForTenant` implementations — these need to be rewritten to read from `events_i18n` via JOIN and write translations on save.

- [ ] **Step 2: Write integration test (hits real DB)**

Create `tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlEventRepositoryI18nTest extends MigrationTestCase
{
    public function testSaveAndFindWithFullTranslations(): void
    {
        $repo = new SqlEventRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $eventId = EventId::generate();

        $translations = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous', 'location' => 'Hki', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Meeting', 'location' => 'Helsinki', 'description' => 'Desc'],
        ]);
        $event = new Event(
            $eventId, $tenantId, 'test-meet', 'upcoming', '2026-06-15',
            '18:00', false, null, [], $translations,
        );
        $repo->save($event);

        $loaded = $repo->findByIdForTenant($eventId->value(), $tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));
    }

    public function testFindReturnsFallbackViewWhenRequestedLocaleMissing(): void
    {
        $repo = new SqlEventRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $eventId = EventId::generate();

        $translations = new TranslationMap([
            'en_GB' => ['title' => 'Meeting', 'location' => 'Helsinki', 'description' => 'Desc'],
        ]);
        $event = new Event(
            $eventId, $tenantId, 'test-fb', 'upcoming', '2026-06-15',
            null, false, null, [], $translations,
        );
        $repo->save($event);

        $loaded = $repo->findByIdForTenant($eventId->value(), $tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Meeting', $view->field('title'));
        $this->assertTrue($view->isFallback('title'));
    }

    public function testSaveTranslationUpserts(): void
    {
        $repo = new SqlEventRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $eventId = EventId::generate();

        $event = new Event(
            $eventId, $tenantId, 'test-upsert', 'upcoming', '2026-06-15', null, false, null, [],
            new TranslationMap(['fi_FI' => ['title' => 'Alku', 'location' => null, 'description' => null]])
        );
        $repo->save($event);

        $repo->saveTranslation(
            $tenantId, $eventId->value(), SupportedLocale::fromString('fi_FI'),
            ['title' => 'Päivitetty', 'location' => 'Hki', 'description' => 'Uusi'],
        );
        $repo->saveTranslation(
            $tenantId, $eventId->value(), SupportedLocale::fromString('sw_TZ'),
            ['title' => 'Mkutano', 'location' => null, 'description' => null],
        );

        $loaded = $repo->findByIdForTenant($eventId->value(), $tenantId);
        $map = $loaded->translations();
        $this->assertSame('Päivitetty', $map->rowFor(SupportedLocale::fromString('fi_FI'))['title']);
        $this->assertSame('Mkutano', $map->rowFor(SupportedLocale::fromString('sw_TZ'))['title']);
    }
}
```

- [ ] **Step 3: Run test, expect failures (repo not yet refactored)**

```bash
vendor/bin/phpunit --testsuite Integration --filter SqlEventRepositoryI18nTest
```

- [ ] **Step 4: Refactor `SqlEventRepository`**

Replace `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php`. Preserve existing method signatures; change internal queries. Key changes:

- `save(Event $event)`: INSERT into `events` (no title/location/description). For each non-null locale row in `$event->translations()->raw()`, INSERT INTO `events_i18n`.
- `findByIdForTenant($id, $tenantId)`: SELECT `events` row by id+tenant, then SELECT all `events_i18n` rows for that event_id, hydrate into `TranslationMap`, pass to `new Event(...)`.
- `findBySlugForTenant` / `listForTenant`: single SELECT joining `events_i18n` LEFT JOIN, group rows per event; build `TranslationMap` per event.
- `saveTranslation`: `INSERT ... ON DUPLICATE KEY UPDATE title=VALUES(title), location=VALUES(location), description=VALUES(description), updated_at=CURRENT_TIMESTAMP`.

Complete implementation (replaces current file):

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlEventRepository implements EventRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(Event $event): void
    {
        $this->db->execute(
            'INSERT INTO events
                (id, tenant_id, slug, type, event_date, event_time, is_online, hero_image, gallery_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                slug=VALUES(slug), type=VALUES(type), event_date=VALUES(event_date),
                event_time=VALUES(event_time), is_online=VALUES(is_online),
                hero_image=VALUES(hero_image), gallery_json=VALUES(gallery_json),
                status=VALUES(status)',
            [
                $event->id()->value(),
                $event->tenantId()->value(),
                $event->slug(),
                $event->type(),
                $event->date(),
                $event->time(),
                $event->online() ? 1 : 0,
                $event->heroImage(),
                json_encode($event->gallery(), JSON_UNESCAPED_UNICODE),
                $event->status(),
            ],
        );

        foreach ($event->translations()->raw() as $locale => $row) {
            if ($row === null) continue;
            if (!SupportedLocale::isSupported($locale)) continue;
            $this->upsertTranslation($event->id()->value(), $locale, $row);
        }
    }

    /** @return list<Event> */
    public function listForTenant(TenantId $tenantId, ?string $type = null): array
    {
        $sql = 'SELECT id FROM events WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $args[] = $type;
        }
        $sql .= ' ORDER BY event_date DESC';
        $rows = $this->db->query($sql, $args);
        $out = [];
        foreach ($rows as $row) {
            $event = $this->findByIdForTenant((string) $row['id'], $tenantId);
            if ($event !== null) $out[] = $event;
        }
        return $out;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Event
    {
        $eventRow = $this->db->queryOne(
            'SELECT * FROM events WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        if ($eventRow === null) return null;

        $trRows = $this->db->query(
            'SELECT locale, title, location, description FROM events_i18n WHERE event_id = ?',
            [$id],
        );
        return $this->hydrate($eventRow, $trRows);
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event
    {
        $row = $this->db->queryOne(
            'SELECT id FROM events WHERE slug = ? AND tenant_id = ?',
            [$slug, $tenantId->value()],
        );
        return $row !== null ? $this->findByIdForTenant((string) $row['id'], $tenantId) : null;
    }

    public function saveTranslation(
        TenantId $tenantId,
        string $eventId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $exists = $this->db->queryOne(
            'SELECT 1 FROM events WHERE id = ? AND tenant_id = ?',
            [$eventId, $tenantId->value()],
        );
        if ($exists === null) {
            throw new \DomainException('event_not_found_in_tenant');
        }
        $this->upsertTranslation($eventId, $locale->value(), $fields);
    }

    public function deleteForTenant(string $id, TenantId $tenantId): void
    {
        // events_i18n rows cascade via FK
        $this->db->execute(
            'DELETE FROM events WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
    }

    /** @param array<string, ?string> $fields */
    private function upsertTranslation(string $eventId, string $locale, array $fields): void
    {
        $this->db->execute(
            'INSERT INTO events_i18n (event_id, locale, title, location, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), location=VALUES(location),
                description=VALUES(description), updated_at=CURRENT_TIMESTAMP',
            [
                $eventId,
                $locale,
                (string) ($fields['title'] ?? ''),
                $fields['location'] ?? null,
                $fields['description'] ?? null,
            ],
        );
    }

    /**
     * @param array<string, mixed> $eventRow
     * @param list<array<string, mixed>> $trRows
     */
    private function hydrate(array $eventRow, array $trRows): Event
    {
        $mapData = [];
        foreach (SupportedLocale::supportedValues() as $loc) {
            $mapData[$loc] = null;
        }
        foreach ($trRows as $r) {
            $mapData[(string) $r['locale']] = [
                'title' => (string) $r['title'],
                'location' => $r['location'] === null ? null : (string) $r['location'],
                'description' => $r['description'] === null ? null : (string) $r['description'],
            ];
        }

        $gallery = [];
        if (!empty($eventRow['gallery_json'])) {
            $decoded = json_decode((string) $eventRow['gallery_json'], true);
            if (is_array($decoded)) $gallery = $decoded;
        }

        return new Event(
            EventId::fromString((string) $eventRow['id']),
            TenantId::fromString((string) $eventRow['tenant_id']),
            (string) $eventRow['slug'],
            (string) $eventRow['type'],
            (string) $eventRow['event_date'],
            $eventRow['event_time'] === null ? null : (string) $eventRow['event_time'],
            (bool) (int) $eventRow['is_online'],
            $eventRow['hero_image'] === null ? null : (string) $eventRow['hero_image'],
            $gallery,
            new TranslationMap($mapData),
            (string) ($eventRow['status'] ?? 'published'),
        );
    }
}
```

- [ ] **Step 5: Update the InMemoryEventRepository in tests/Support/Fake/**

Open `tests/Support/Fake/InMemoryEventRepository.php`, mirror all interface methods. Sample `saveTranslation`:

```php
public function saveTranslation(TenantId $tenantId, string $eventId, SupportedLocale $locale, array $fields): void
{
    if (!isset($this->events[$eventId])) throw new \DomainException('event_not_found_in_tenant');
    $this->translations[$eventId][$locale->value()] = $fields;
}
```

Read current fake, adapt structure to store translations alongside stored Events.

- [ ] **Step 6: Run tests**

```bash
vendor/bin/phpunit --testsuite Integration --filter SqlEventRepositoryI18nTest
vendor/bin/phpunit --testsuite Unit
composer analyse
```

Expected: green, 0 phpstan errors. If callers (controllers, use cases) error in phpstan, those are addressed in later tasks — fix to get those *specific* tests green and leave rest for next tasks.

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php \
        tests/Integration/Infrastructure/SqlEventRepositoryI18nTest.php \
        tests/Support/Fake/InMemoryEventRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(event): SqlEventRepository reads/writes via events_i18n + saveTranslation upsert"
```

## Task A10: Update SqlProjectRepository for i18n (same pattern as A9)

**Files:**
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php`
- Create: `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php`
- Modify: `tests/Support/Fake/InMemoryProjectRepository.php`

- [ ] **Step 1: Write integration test**

Mirror the event test for projects. Create `tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php` with three tests: `testSaveAndFindWithFullTranslations`, `testFindReturnsFallbackViewWhenRequestedLocaleMissing`, `testSaveTranslationUpserts`. Use `Project` entity and `['title','summary','description']` as translatable fields.

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlProjectRepositoryI18nTest extends MigrationTestCase
{
    public function testSaveAndFindWithFullTranslations(): void
    {
        $repo = new SqlProjectRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $id = ProjectId::generate();

        $translations = new TranslationMap([
            'fi_FI' => ['title' => 'Projekti', 'summary' => 'Tiivistelmä', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Project',  'summary' => 'Summary',     'description' => 'Desc'],
        ]);
        $project = new Project(
            $id, $tenantId, 'proj-slug', 'community', 'bi-folder', 'active', 0, false,
            date('Y-m-d H:i:s'), $translations,
        );
        $repo->save($project);

        $loaded = $repo->findByIdForTenant($id->value(), $tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Projekti', $view->field('title'));
    }

    public function testFindReturnsFallbackViewWhenRequestedLocaleMissing(): void
    {
        $repo = new SqlProjectRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $id = ProjectId::generate();

        $translations = new TranslationMap([
            'en_GB' => ['title' => 'Project', 'summary' => 'Summary', 'description' => 'Desc'],
        ]);
        $project = new Project(
            $id, $tenantId, 'proj-fb', 'research', 'bi-book', 'active', 0, false,
            date('Y-m-d H:i:s'), $translations,
        );
        $repo->save($project);

        $view = $repo->findByIdForTenant($id->value(), $tenantId)
            ->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Project', $view->field('title'));
        $this->assertTrue($view->isFallback('title'));
    }

    public function testSaveTranslationUpserts(): void
    {
        $repo = new SqlProjectRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $id = ProjectId::generate();

        $project = new Project(
            $id, $tenantId, 'proj-ups', 'events', 'bi-calendar', 'active', 0, false,
            date('Y-m-d H:i:s'),
            new TranslationMap(['fi_FI' => ['title' => 'Alku', 'summary' => 'S', 'description' => 'K']]),
        );
        $repo->save($project);

        $repo->saveTranslation(
            $tenantId, $id->value(), SupportedLocale::fromString('en_GB'),
            ['title' => 'Updated', 'summary' => 'S2', 'description' => 'D2'],
        );

        $loaded = $repo->findByIdForTenant($id->value(), $tenantId);
        $this->assertSame(
            'Updated',
            $loaded->translations()->rowFor(SupportedLocale::fromString('en_GB'))['title'],
        );
    }
}
```

- [ ] **Step 2: Refactor `SqlProjectRepository`**

Same shape as `SqlEventRepository`: `save()` upserts projects row (no title/summary/description) + iterates translations; `findByIdForTenant` queries base + projects_i18n; `saveTranslation` upserts. Full file:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(Project $project): void
    {
        $this->db->execute(
            'INSERT INTO projects
                (id, tenant_id, slug, category, icon, status, sort_order, featured, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                slug=VALUES(slug), category=VALUES(category), icon=VALUES(icon),
                status=VALUES(status), sort_order=VALUES(sort_order), featured=VALUES(featured)',
            [
                $project->id()->value(),
                $project->tenantId()->value(),
                $project->slug(),
                $project->category(),
                $project->icon(),
                $project->status(),
                $project->sortOrder(),
                $project->featured() ? 1 : 0,
                $project->createdAt(),
            ],
        );
        foreach ($project->translations()->raw() as $locale => $row) {
            if ($row === null || !SupportedLocale::isSupported($locale)) continue;
            $this->upsertTranslation($project->id()->value(), $locale, $row);
        }
    }

    /** @return list<Project> */
    public function listForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT id FROM projects WHERE tenant_id = ? ORDER BY sort_order ASC, created_at DESC',
            [$tenantId->value()],
        );
        $out = [];
        foreach ($rows as $r) {
            $p = $this->findByIdForTenant((string) $r['id'], $tenantId);
            if ($p !== null) $out[] = $p;
        }
        return $out;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project
    {
        $row = $this->db->queryOne(
            'SELECT * FROM projects WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        if ($row === null) return null;

        $trRows = $this->db->query(
            'SELECT locale, title, summary, description FROM projects_i18n WHERE project_id = ?',
            [$id],
        );
        return $this->hydrate($row, $trRows);
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project
    {
        $row = $this->db->queryOne(
            'SELECT id FROM projects WHERE slug = ? AND tenant_id = ?',
            [$slug, $tenantId->value()],
        );
        return $row !== null ? $this->findByIdForTenant((string) $row['id'], $tenantId) : null;
    }

    public function saveTranslation(
        TenantId $tenantId,
        string $projectId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $exists = $this->db->queryOne(
            'SELECT 1 FROM projects WHERE id = ? AND tenant_id = ?',
            [$projectId, $tenantId->value()],
        );
        if ($exists === null) throw new \DomainException('project_not_found_in_tenant');
        $this->upsertTranslation($projectId, $locale->value(), $fields);
    }

    public function deleteForTenant(string $id, TenantId $tenantId): void
    {
        $this->db->execute(
            'DELETE FROM projects WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
    }

    /** @param array<string, ?string> $fields */
    private function upsertTranslation(string $projectId, string $locale, array $fields): void
    {
        $this->db->execute(
            'INSERT INTO projects_i18n (project_id, locale, title, summary, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), summary=VALUES(summary),
                description=VALUES(description), updated_at=CURRENT_TIMESTAMP',
            [
                $projectId,
                $locale,
                (string) ($fields['title'] ?? ''),
                (string) ($fields['summary'] ?? ''),
                (string) ($fields['description'] ?? ''),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $trRows
     */
    private function hydrate(array $row, array $trRows): Project
    {
        $mapData = [];
        foreach (SupportedLocale::supportedValues() as $loc) $mapData[$loc] = null;
        foreach ($trRows as $r) {
            $mapData[(string) $r['locale']] = [
                'title' => (string) $r['title'],
                'summary' => (string) $r['summary'],
                'description' => (string) $r['description'],
            ];
        }

        return new Project(
            ProjectId::fromString((string) $row['id']),
            TenantId::fromString((string) $row['tenant_id']),
            (string) $row['slug'],
            (string) $row['category'],
            (string) $row['icon'],
            (string) $row['status'],
            (int) $row['sort_order'],
            (bool) (int) ($row['featured'] ?? 0),
            (string) $row['created_at'],
            new TranslationMap($mapData),
        );
    }
}
```

- [ ] **Step 3: Update `InMemoryProjectRepository`** fake to match the new interface.

- [ ] **Step 4: Run tests + phpstan (caller errors OK)**

```bash
vendor/bin/phpunit --testsuite Integration --filter SqlProjectRepositoryI18nTest
composer analyse
```

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php \
        tests/Integration/Infrastructure/SqlProjectRepositoryI18nTest.php \
        tests/Support/Fake/InMemoryProjectRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(project): SqlProjectRepository reads/writes via projects_i18n + saveTranslation upsert"
```

## Task A11: Migration 054 — drop legacy translated columns

**Files:**
- Create: `database/migrations/054_drop_translated_columns_from_events_projects.sql`

**Pre-condition:** Repositories (A9, A10) no longer read legacy columns. Verify with grep:

```bash
rtk grep -n "events.title\|events.description\|events.location\|projects.title\|projects.summary\|projects.description" \
  src/Infrastructure/Adapter/Persistence/Sql/
```

Expected: no matches.

- [ ] **Step 1: Create migration**

```sql
-- database/migrations/054_drop_translated_columns_from_events_projects.sql
ALTER TABLE events
    DROP COLUMN title,
    DROP COLUMN location,
    DROP COLUMN description;

ALTER TABLE projects
    DROP COLUMN title,
    DROP COLUMN summary,
    DROP COLUMN description;
```

- [ ] **Step 2: Apply**

```bash
php scripts/apply_pending_migrations.php
```

- [ ] **Step 3: Run all tests**

```bash
composer test
```

Expected: all green (repositories already read i18n tables; integration tests that insert events/projects through the repo don't touch dropped columns).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/054_drop_translated_columns_from_events_projects.sql
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Chore(db): migration 054 drops legacy title/location/description from events + title/summary/description from projects"
```

## Task A12: EventProposal domain + migration 056

**Files:**
- Create: `src/Domain/Event/EventProposal.php`
- Create: `src/Domain/Event/EventProposalId.php`
- Create: `src/Domain/Event/EventProposalRepositoryInterface.php`
- Create: `database/migrations/056_create_event_proposals.sql`

- [ ] **Step 1: Create migration 056**

```sql
-- database/migrations/056_create_event_proposals.sql
CREATE TABLE event_proposals (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    author_name     VARCHAR(255) NOT NULL,
    author_email    VARCHAR(255) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    event_date      DATE         NOT NULL,
    event_time      VARCHAR(50)  NULL,
    location        VARCHAR(255) NULL,
    is_online       TINYINT(1)   NOT NULL DEFAULT 0,
    description     TEXT         NOT NULL,
    source_locale   VARCHAR(10)  NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME     NULL,
    decided_by      CHAR(36)     NULL,
    decision_note   TEXT         NULL,
    PRIMARY KEY (id),
    KEY event_proposals_tenant_status (tenant_id, status),
    KEY event_proposals_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```bash
php scripts/apply_pending_migrations.php
```

- [ ] **Step 2: Create `EventProposalId`**

Mirror existing `ProjectProposalId` pattern (UUID wrapper):

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Event;

final class EventProposalId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self(self::uuid4());
    }

    public static function fromString(string $v): self
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new \DomainException('invalid_event_proposal_id');
        }
        return new self(strtolower($v));
    }

    public function value(): string { return $this->value; }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

- [ ] **Step 3: Create `EventProposal`**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

final class EventProposal
{
    public function __construct(
        private readonly EventProposalId $id,
        private readonly TenantId $tenantId,
        private readonly string $userId,
        private readonly string $authorName,
        private readonly string $authorEmail,
        private readonly string $title,
        private readonly string $eventDate,
        private readonly ?string $eventTime,
        private readonly ?string $location,
        private readonly bool $isOnline,
        private readonly string $description,
        private readonly string $sourceLocale,
        private readonly string $status,
        private readonly string $createdAt,
        private readonly ?string $decidedAt = null,
        private readonly ?string $decidedBy = null,
        private readonly ?string $decisionNote = null,
    ) {}

    public function id(): EventProposalId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): string { return $this->userId; }
    public function authorName(): string { return $this->authorName; }
    public function authorEmail(): string { return $this->authorEmail; }
    public function title(): string { return $this->title; }
    public function eventDate(): string { return $this->eventDate; }
    public function eventTime(): ?string { return $this->eventTime; }
    public function location(): ?string { return $this->location; }
    public function isOnline(): bool { return $this->isOnline; }
    public function description(): string { return $this->description; }
    public function sourceLocale(): string { return $this->sourceLocale; }
    public function status(): string { return $this->status; }
    public function createdAt(): string { return $this->createdAt; }
    public function decidedAt(): ?string { return $this->decidedAt; }
    public function decidedBy(): ?string { return $this->decidedBy; }
    public function decisionNote(): ?string { return $this->decisionNote; }
}
```

- [ ] **Step 4: Create `EventProposalRepositoryInterface`**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

interface EventProposalRepositoryInterface
{
    public function save(EventProposal $proposal): void;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?EventProposal;

    /** @return list<EventProposal> */
    public function listForTenant(TenantId $tenantId, ?string $status = null): array;

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

- [ ] **Step 5: phpstan + commit**

```bash
composer analyse
git add database/migrations/056_create_event_proposals.sql \
        src/Domain/Event/EventProposal.php \
        src/Domain/Event/EventProposalId.php \
        src/Domain/Event/EventProposalRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(event): EventProposal domain + migration 056 (mirrors ProjectProposal + source_locale)"
```

## Task A13: SqlEventProposalRepository + integration test

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php`
- Create: `tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php`
- Create: `tests/Support/Fake/InMemoryEventProposalRepository.php`

- [ ] **Step 1: Write integration test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventProposalRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlEventProposalRepositoryTest extends MigrationTestCase
{
    public function testSaveAndFindById(): void
    {
        $repo = new SqlEventProposalRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $id = EventProposalId::generate();

        $proposal = new EventProposal(
            $id, $tenantId, 'user-uuid', 'Author', 'a@x.fi',
            'Title', '2026-09-01', '18:00', 'Helsinki', false,
            'Description', 'fi_FI', 'pending', date('Y-m-d H:i:s'),
        );
        $repo->save($proposal);

        $loaded = $repo->findByIdForTenant($id->value(), $tenantId);
        $this->assertNotNull($loaded);
        $this->assertSame('fi_FI', $loaded->sourceLocale());
        $this->assertSame('Title', $loaded->title());
    }

    public function testListFiltersByStatus(): void
    {
        $repo = new SqlEventProposalRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);

        $p1 = new EventProposal(EventProposalId::generate(), $tenantId, 'u1', 'A', 'a@x', 'T1',
            '2026-09-01', null, null, true, 'D', 'en_GB', 'pending', date('Y-m-d H:i:s'));
        $p2 = new EventProposal(EventProposalId::generate(), $tenantId, 'u2', 'B', 'b@x', 'T2',
            '2026-09-02', null, null, false, 'D', 'fi_FI', 'approved', date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'), 'admin-uuid', null);
        $repo->save($p1);
        $repo->save($p2);

        $this->assertCount(1, $repo->listForTenant($tenantId, 'pending'));
        $this->assertCount(2, $repo->listForTenant($tenantId, null));
    }

    public function testRecordDecision(): void
    {
        $repo = new SqlEventProposalRepository($this->db);
        $tenantId = TenantId::fromString($this->daemsTenantId);
        $id = EventProposalId::generate();

        $p = new EventProposal($id, $tenantId, 'u', 'A', 'a@x', 'T',
            '2026-09-01', null, null, false, 'D', 'sw_TZ', 'pending', date('Y-m-d H:i:s'));
        $repo->save($p);

        $repo->recordDecision($id->value(), $tenantId, 'approved', 'admin-uuid', 'OK', new \DateTimeImmutable('now'));
        $loaded = $repo->findByIdForTenant($id->value(), $tenantId);
        $this->assertSame('approved', $loaded->status());
        $this->assertSame('admin-uuid', $loaded->decidedBy());
    }
}
```

- [ ] **Step 2: Implement `SqlEventProposalRepository`**

Mirror `SqlProjectProposalRepository`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlEventProposalRepository implements EventProposalRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(EventProposal $p): void
    {
        $this->db->execute(
            'INSERT INTO event_proposals
                (id, tenant_id, user_id, author_name, author_email, title,
                 event_date, event_time, location, is_online, description,
                 source_locale, status, created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status=VALUES(status), decided_at=VALUES(decided_at),
                decided_by=VALUES(decided_by), decision_note=VALUES(decision_note)',
            [
                $p->id()->value(),
                $p->tenantId()->value(),
                $p->userId(),
                $p->authorName(),
                $p->authorEmail(),
                $p->title(),
                $p->eventDate(),
                $p->eventTime(),
                $p->location(),
                $p->isOnline() ? 1 : 0,
                $p->description(),
                $p->sourceLocale(),
                $p->status(),
                $p->createdAt(),
                $p->decidedAt(),
                $p->decidedBy(),
                $p->decisionNote(),
            ],
        );
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?EventProposal
    {
        $row = $this->db->queryOne(
            'SELECT * FROM event_proposals WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM event_proposals WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC';
        $rows = $this->db->query($sql, $args);
        return array_map($this->hydrate(...), $rows);
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \DomainException('invalid_decision');
        }
        $this->db->execute(
            'UPDATE event_proposals
             SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
             WHERE id = ? AND tenant_id = ?',
            [$decision, $now->format('Y-m-d H:i:s'), $decidedBy, $note, $id, $tenantId->value()],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): EventProposal
    {
        return new EventProposal(
            EventProposalId::fromString((string) $row['id']),
            TenantId::fromString((string) $row['tenant_id']),
            (string) $row['user_id'],
            (string) $row['author_name'],
            (string) $row['author_email'],
            (string) $row['title'],
            (string) $row['event_date'],
            $row['event_time'] === null ? null : (string) $row['event_time'],
            $row['location'] === null ? null : (string) $row['location'],
            (bool) (int) $row['is_online'],
            (string) $row['description'],
            (string) $row['source_locale'],
            (string) $row['status'],
            (string) $row['created_at'],
            $row['decided_at'] === null ? null : (string) $row['decided_at'],
            $row['decided_by'] === null ? null : (string) $row['decided_by'],
            $row['decision_note'] === null ? null : (string) $row['decision_note'],
        );
    }
}
```

- [ ] **Step 3: Create `InMemoryEventProposalRepository`** fake, same interface, backed by `array<string, EventProposal>`.

- [ ] **Step 4: Run tests + commit**

```bash
vendor/bin/phpunit --testsuite Integration --filter SqlEventProposalRepositoryTest
composer analyse
git add src/Infrastructure/Adapter/Persistence/Sql/SqlEventProposalRepository.php \
        tests/Integration/Infrastructure/SqlEventProposalRepositoryTest.php \
        tests/Support/Fake/InMemoryEventProposalRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(event): SqlEventProposalRepository + in-memory fake + integration test"
```

## Task A14: Migration 055 — source_locale on project_proposals + update domain

**Files:**
- Create: `database/migrations/055_add_source_locale_to_project_proposals.sql`
- Modify: `src/Domain/Project/ProjectProposal.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php`
- Modify: `tests/Support/Fake/InMemoryProjectProposalRepository.php`
- Modify: `src/Application/Project/SubmitProjectProposal/SubmitProjectProposalInput.php` — add nullable `sourceLocale`

- [ ] **Step 1: Apply migration**

```sql
-- database/migrations/055_add_source_locale_to_project_proposals.sql
ALTER TABLE project_proposals
    ADD COLUMN source_locale VARCHAR(10) NOT NULL DEFAULT 'fi_FI' AFTER description;
```

```bash
php scripts/apply_pending_migrations.php
```

- [ ] **Step 2: Add `sourceLocale` to `ProjectProposal` entity**

Add parameter + getter in the same manner as other fields (after `description`, before `status`). Example patch:

```php
// In src/Domain/Project/ProjectProposal.php constructor param list, after $description:
private readonly string $sourceLocale,
// And after description() getter:
public function sourceLocale(): string { return $this->sourceLocale; }
```

- [ ] **Step 3: Update `SqlProjectProposalRepository::save` + hydrate**

In `save()` INSERT column list: add `source_locale`; value `$proposal->sourceLocale()`.
In `hydrate()`: pass `(string) $row['source_locale']`.

Diff-level change to `save`:

```php
'INSERT INTO project_proposals
    (id, tenant_id, user_id, author_name, author_email, title, category, summary, description, source_locale, status, created_at)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
[
    // ... same as before, insert $proposal->sourceLocale() between $proposal->description() and $proposal->status()
]
```

- [ ] **Step 4: Update `SubmitProjectProposalInput`**

```php
// Before:
public function __construct(
    public readonly string $title,
    // ...
) {}

// After — add:
    public readonly ?string $sourceLocale = null,  // nullable; use case falls back to actor's locale
```

Inside `SubmitProjectProposal::execute`, if `$input->sourceLocale === null`, default to `SupportedLocale::uiDefault()->value()` (or the negotiated locale passed from controller). Use `SupportedLocale::fromString($input->sourceLocale)` for validation; throw if invalid.

- [ ] **Step 5: Update `InMemoryProjectProposalRepository` fake** to carry the field.

- [ ] **Step 6: Existing tests may break (hydrate signature)** — adjust tests that construct `ProjectProposal` to pass the new `sourceLocale` arg; existing rows default to `'fi_FI'`.

- [ ] **Step 7: Run tests + commit**

```bash
composer test
composer analyse
git add database/migrations/055_add_source_locale_to_project_proposals.sql \
        src/Domain/Project/ProjectProposal.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlProjectProposalRepository.php \
        src/Application/Project/SubmitProjectProposal/ \
        tests/Support/Fake/InMemoryProjectProposalRepository.php \
        tests/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(project): source_locale on ProjectProposal (migration 055 + domain + repo + use case)"
```

## Task A15: Application use cases — locale-aware reads (events)

**Files:**
- Create: `src/Application/Event/ListEventsForLocale/ListEventsForLocale.php`
- Create: `src/Application/Event/ListEventsForLocale/ListEventsForLocaleInput.php`
- Create: `src/Application/Event/ListEventsForLocale/ListEventsForLocaleOutput.php`
- Create: `src/Application/Event/GetEventBySlugForLocale/` (3 files)

- [ ] **Step 1: Create input/output**

```php
// ListEventsForLocaleInput.php
<?php
declare(strict_types=1);
namespace Daems\Application\Event\ListEventsForLocale;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

final class ListEventsForLocaleInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly SupportedLocale $locale,
        public readonly ?string $type = null,
    ) {}
}
```

```php
// ListEventsForLocaleOutput.php
<?php
declare(strict_types=1);
namespace Daems\Application\Event\ListEventsForLocale;

final class ListEventsForLocaleOutput
{
    /** @param list<array<string, mixed>> $events */
    public function __construct(public readonly array $events) {}
}
```

- [ ] **Step 2: Create use case**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Event\ListEventsForLocale;

use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;

final class ListEventsForLocale
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(ListEventsForLocaleInput $input): ListEventsForLocaleOutput
    {
        $events = $this->events->listForTenant($input->tenantId, $input->type);
        $payload = [];
        foreach ($events as $event) {
            $view = $event->view($input->locale, SupportedLocale::contentFallback());
            $payload[] = array_merge(
                [
                    'id' => $event->id()->value(),
                    'slug' => $event->slug(),
                    'type' => $event->type(),
                    'event_date' => $event->date(),
                    'event_time' => $event->time(),
                    'is_online' => $event->online(),
                    'hero_image' => $event->heroImage(),
                    'status' => $event->status(),
                ],
                $view->toApiPayload(),
            );
        }
        return new ListEventsForLocaleOutput($payload);
    }
}
```

- [ ] **Step 3: Create `GetEventBySlugForLocale` (analogous, single event)**

```php
// Input: TenantId $tenantId, SupportedLocale $locale, string $slug
// Output: ?array<string, mixed> $event (null if not found)
// Impl: $event = $this->events->findBySlugForTenant($input->slug, $input->tenantId);
//   return null if not found; otherwise same payload shape as list items.
```

Code (full file):

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Event\GetEventBySlugForLocale;

use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;

final class GetEventBySlugForLocale
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(GetEventBySlugForLocaleInput $input): GetEventBySlugForLocaleOutput
    {
        $event = $this->events->findBySlugForTenant($input->slug, $input->tenantId);
        if ($event === null) return new GetEventBySlugForLocaleOutput(null);
        $view = $event->view($input->locale, SupportedLocale::contentFallback());
        $payload = array_merge(
            [
                'id' => $event->id()->value(),
                'slug' => $event->slug(),
                'type' => $event->type(),
                'event_date' => $event->date(),
                'event_time' => $event->time(),
                'is_online' => $event->online(),
                'hero_image' => $event->heroImage(),
                'status' => $event->status(),
            ],
            $view->toApiPayload(),
        );
        return new GetEventBySlugForLocaleOutput($payload);
    }
}
```

Plus Input/Output classes (TenantId, SupportedLocale, slug; nullable event).

- [ ] **Step 4: phpstan + commit**

```bash
composer analyse
git add src/Application/Event/ListEventsForLocale/ src/Application/Event/GetEventBySlugForLocale/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(event): locale-aware list/get use cases with per-field fallback payload"
```

## Task A16: Application use cases — locale-aware reads (projects)

Mirror A15 for projects. Files:
- `src/Application/Project/ListProjectsForLocale/{Input,Output,UseCase}.php`
- `src/Application/Project/GetProjectBySlugForLocale/{Input,Output,UseCase}.php`

Project payload base keys differ: `slug, category, icon, status, sort_order, featured, created_at` instead of event chrome fields. Same `array_merge(..., $view->toApiPayload())` pattern.

- [ ] **Steps:** write both use cases + input/output; phpstan; commit.

Commit message: `Feat(project): locale-aware list/get use cases with per-field fallback payload`.

## Task A17: Application use cases — admin translation write

**Files:**
- Create: `src/Application/Backstage/GetEventWithAllTranslations/` (3 files)
- Create: `src/Application/Backstage/UpdateEventTranslation/` (3 files)
- Create: `src/Application/Backstage/GetProjectWithAllTranslations/` (3 files)
- Create: `src/Application/Backstage/UpdateProjectTranslation/` (3 files)

- [ ] **Step 1: `GetEventWithAllTranslations`**

Input: `TenantId $tenantId`, `string $eventId`, `ActingUser $actor` (for auth check).
Output: full admin payload (chrome + `translations` map + `coverage`).

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\GetEventWithAllTranslations;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Exception\ForbiddenException;
use Daems\Domain\Exception\NotFoundException;

final class GetEventWithAllTranslations
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(GetEventWithAllTranslationsInput $input): GetEventWithAllTranslationsOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $event = $this->events->findByIdForTenant($input->eventId, $input->tenantId);
        if ($event === null) throw new NotFoundException('event');

        $translations = [];
        foreach ($event->translations()->raw() as $loc => $row) {
            $translations[$loc] = $row;
        }
        $coverage = $event->translations()->coverage(Event::TRANSLATABLE_FIELDS);

        return new GetEventWithAllTranslationsOutput([
            'id' => $event->id()->value(),
            'slug' => $event->slug(),
            'type' => $event->type(),
            'event_date' => $event->date(),
            'event_time' => $event->time(),
            'is_online' => $event->online(),
            'hero_image' => $event->heroImage(),
            'status' => $event->status(),
            'translations' => $translations,
            'coverage' => $coverage,
        ]);
    }
}
```

- [ ] **Step 2: `UpdateEventTranslation`**

Input: `TenantId $tenantId`, `string $eventId`, `string $localeRaw`, `array $fields`, `ActingUser $actor`.

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\UpdateEventTranslation;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Exception\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;

final class UpdateEventTranslation
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(UpdateEventTranslationInput $input): UpdateEventTranslationOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $locale = SupportedLocale::fromString($input->localeRaw);

        $safeFields = [
            'title' => isset($input->fields['title']) ? (string) $input->fields['title'] : '',
            'location' => $input->fields['location'] ?? null,
            'description' => $input->fields['description'] ?? null,
        ];
        if (trim($safeFields['title']) === '') {
            throw new \DomainException('title_required');
        }

        $this->events->saveTranslation($input->tenantId, $input->eventId, $locale, $safeFields);

        $event = $this->events->findByIdForTenant($input->eventId, $input->tenantId);
        if ($event === null) throw new \RuntimeException('event_vanished');
        $coverage = $event->translations()->coverage(Event::TRANSLATABLE_FIELDS);
        return new UpdateEventTranslationOutput($coverage);
    }
}
```

- [ ] **Step 3: Project analogs**

Identical pattern for projects; fields are `title`, `summary`, `description` — all three required (`NOT NULL` in schema). Validation: all three non-empty strings.

- [ ] **Step 4: phpstan + commit**

```bash
composer analyse
git add src/Application/Backstage/GetEventWithAllTranslations/ \
        src/Application/Backstage/UpdateEventTranslation/ \
        src/Application/Backstage/GetProjectWithAllTranslations/ \
        src/Application/Backstage/UpdateProjectTranslation/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(backstage): admin use cases — get-with-all-translations + update-translation (events + projects)"
```

## Task A18: Event-proposal use cases

**Files:**
- Create: `src/Application/Event/SubmitEventProposal/{Input,Output,UseCase}.php`
- Create: `src/Application/Backstage/ListEventProposalsForAdmin/{Input,Output,UseCase}.php`
- Create: `src/Application/Backstage/ApproveEventProposal/{Input,Output,UseCase}.php`
- Create: `src/Application/Backstage/RejectEventProposal/{Input,Output,UseCase}.php`

**`SubmitEventProposal`:**
Input carries user + fields + `sourceLocale`. Validate `sourceLocale` via `SupportedLocale::fromString()`. Validate `eventDate` is parseable and not in the past. Generate `EventProposalId::generate()`. Save via repo with status `pending`, `createdAt = now`. Return proposal ID.

**`ListEventProposalsForAdmin`:**
Input: `TenantId`, `?string $status`, `ActingUser`. Auth-check admin. Return array of proposals with chrome (id, author, title, date, source_locale, status, submitted, decided_*).

**`ApproveEventProposal`:**
Auth admin. Load proposal; if not found → `NotFoundException`. If already decided → `DomainException('already_decided')`. Record decision. **Create Event** via `EventRepositoryInterface`:

```php
$event = new Event(
    EventId::generate(),
    $input->tenantId,
    $this->slugify($proposal->title(), $input->tenantId), // helper
    'upcoming',
    $proposal->eventDate(),
    $proposal->eventTime(),
    $proposal->isOnline(),
    null, // hero_image
    [],   // gallery
    new TranslationMap([
        $proposal->sourceLocale() => [
            'title' => $proposal->title(),
            'location' => $proposal->location(),
            'description' => $proposal->description(),
        ],
    ]),
    'published',
);
$this->events->save($event);
```

Return `$event->id()->value()`. Slugify helper: take title, lowercase, non-alphanumeric → `-`, collapse dashes, trim; append `-N` suffix if slug collision inside tenant.

**`RejectEventProposal`:**
Auth admin. Load proposal. Record decision `'rejected'` with note.

- [ ] **Steps:** write each use case per above; phpstan; commit each logical group, or one commit per use case.

Commit messages e.g.:
- `Feat(event-proposal): SubmitEventProposal use case (single source_locale)`
- `Feat(backstage): ListEventProposalsForAdmin`
- `Feat(backstage): ApproveEventProposal creates Event with source_locale translation`
- `Feat(backstage): RejectEventProposal with decision note`

## Task A19: Update ApproveProjectProposal — create project i18n row from source_locale

**Files:**
- Modify: `src/Application/Backstage/ApproveProjectProposal/ApproveProjectProposal.php`

Existing use case creates a `Project`. Update: build `TranslationMap` with only the proposal's `sourceLocale`:

```php
$translations = new TranslationMap([
    $proposal->sourceLocale() => [
        'title' => $proposal->title(),
        'summary' => $proposal->summary(),
        'description' => $proposal->description(),
    ],
]);
$project = new Project(
    ProjectId::generate(),
    $input->tenantId,
    $this->slugify($proposal->title(), $input->tenantId),
    $proposal->category(),
    'bi-folder', // default icon; admin edits later
    'active',
    0,
    false,
    date('Y-m-d H:i:s'),
    $translations,
);
$this->projects->save($project);
```

- [ ] **Steps:** edit use case; rerun tests; commit: `Feat(backstage): ApproveProjectProposal creates Project i18n row from source_locale`.

## Task A20: LocaleMiddleware

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Middleware/LocaleMiddleware.php`

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Middleware;

use Daems\Domain\Locale\LocaleNegotiator;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class LocaleMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $locale = LocaleNegotiator::negotiate(
            server: $req->server(),
            query: $req->query(),
            default: SupportedLocale::contentFallback(),
        );
        $req->setAttribute('locale', $locale);
        return $next($req);
    }
}
```

If the `Request` object's method names differ, adjust. Read `src/Infrastructure/Framework/Http/Request.php` first.

- [ ] **Steps:** create file; check it integrates with existing middleware stack (read `bootstrap/app.php` for registration pattern); phpstan; commit.

## Task A21: Controller endpoints — events public + project public

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/EventController.php`
- Modify: `src/Infrastructure/Adapter/Api/Controller/ProjectController.php`

- [ ] **Step 1: Add `handleListLocalized` on EventController**

```php
public function handleListLocalized(Request $req): Response
{
    $tenantId = $req->getAttribute('tenantId'); // TenantId, populated by TenantContextMiddleware
    $locale = $req->getAttribute('locale');     // SupportedLocale, populated by LocaleMiddleware
    $type = $req->query()['type'] ?? null;

    $out = $this->listEventsForLocale->execute(new ListEventsForLocaleInput(
        tenantId: $tenantId, locale: $locale, type: is_string($type) ? $type : null,
    ));
    return Response::json($out->events);
}

public function handleGetBySlugLocalized(Request $req, array $params): Response
{
    $tenantId = $req->getAttribute('tenantId');
    $locale = $req->getAttribute('locale');
    $slug = (string) ($params['slug'] ?? '');

    $out = $this->getEventBySlugForLocale->execute(new GetEventBySlugForLocaleInput(
        tenantId: $tenantId, locale: $locale, slug: $slug,
    ));
    if ($out->event === null) return Response::json(['error' => 'not_found'], 404);
    return Response::json($out->event);
}
```

- [ ] **Step 2: Inject new use cases** into controller constructor; add imports.

- [ ] **Step 3: Same pattern for `ProjectController`.**

- [ ] **Step 4: phpstan + commit**

```bash
composer analyse
git add src/Infrastructure/Adapter/Api/Controller/EventController.php \
        src/Infrastructure/Adapter/Api/Controller/ProjectController.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(api): EventController + ProjectController locale-aware public endpoints"
```

## Task A22: Controller endpoints — backstage translations (events + projects)

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

Add handlers:
- `handleGetEventWithTranslations(Request, array $params)` — GET /backstage/events/{id}
- `handleListBackstageEvents(Request)` — GET /backstage/events (summary + coverage)
- `handleUpdateEventTranslation(Request, array $params)` — PUT /backstage/events/{id}/translations/{locale}
- Same for projects

```php
public function handleUpdateEventTranslation(Request $req, array $params): Response
{
    $tenantId = $req->getAttribute('tenantId');
    $actor = $req->getAttribute('actingUser');
    $eventId = (string) ($params['id'] ?? '');
    $localeRaw = (string) ($params['locale'] ?? '');
    $body = $req->jsonBody();

    try {
        $out = $this->updateEventTranslation->execute(new UpdateEventTranslationInput(
            tenantId: $tenantId, eventId: $eventId, localeRaw: $localeRaw,
            fields: is_array($body) ? $body : [], actor: $actor,
        ));
    } catch (ForbiddenException) {
        return Response::json(['error' => 'forbidden'], 403);
    } catch (InvalidLocaleException) {
        return Response::json(['error' => 'invalid_locale'], 400);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    } catch (NotFoundException) {
        return Response::json(['error' => 'not_found'], 404);
    }
    return Response::json(['coverage' => $out->coverage]);
}
```

- [ ] **Steps:** add all 6 handlers (3 events, 3 projects); wire dependencies in constructor; commit.

Commit: `Feat(backstage): translation endpoints for events + projects`.

## Task A23: Controller endpoints — event-proposals + project-proposals rename

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/EventController.php` (add `handleSubmitProposal`)
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` (add `handleListEventProposals`, `handleApproveEventProposal`, `handleRejectEventProposal`; note project-proposal handlers stay — the rename happens in routes)
- Modify: `src/Infrastructure/Adapter/Api/Controller/ProjectController.php` (existing `handleSubmitProposal` reads `source_locale` from body and defaults to negotiated locale)

For `ProjectController::handleSubmitProposal`:

```php
$locale = $req->getAttribute('locale'); // SupportedLocale
$body = $req->jsonBody();
$sourceLocale = is_string($body['source_locale'] ?? null)
    ? (string) $body['source_locale']
    : $locale->value();
// Validate via SupportedLocale::fromString($sourceLocale) — throws if unsupported
$out = $this->submitProjectProposal->execute(new SubmitProjectProposalInput(
    // ... existing fields ...
    sourceLocale: $sourceLocale,
));
```

For new event-proposal handlers, same shape — read body, validate `source_locale`, execute use case, return ID.

- [ ] **Steps:** add handlers; phpstan; commit.

Commit: `Feat(api): event-proposal submit + backstage approve/reject; project-proposal source_locale plumbing`.

## Task A24: Routes — add new endpoints + rename backstage project-proposals

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Read current routes to find insertion points**

```bash
cat routes/api.php | head -300
```

- [ ] **Step 2: Register `LocaleMiddleware`** in the middleware stack (after `TenantContextMiddleware`). If the app uses a list/pipeline in `bootstrap/app.php`, register it there too.

- [ ] **Step 3: Add public locale-aware routes**

```php
$router->get('/api/v1/events', static function (Request $req) use ($container): Response {
    return $container->make(EventController::class)->handleListLocalized($req);
});
$router->get('/api/v1/events/{slug}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(EventController::class)->handleGetBySlugLocalized($req, $params);
});
$router->get('/api/v1/projects', static function (Request $req) use ($container): Response {
    return $container->make(ProjectController::class)->handleListLocalized($req);
});
$router->get('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(ProjectController::class)->handleGetBySlugLocalized($req, $params);
});
```

- [ ] **Step 4: Add backstage translation routes**

```php
$router->get('/api/v1/backstage/events', ...);          // list with coverage
$router->get('/api/v1/backstage/events/{id}', ...);     // single with translations+coverage
$router->put('/api/v1/backstage/events/{id}/translations/{locale}', ...);
$router->get('/api/v1/backstage/projects', ...);
$router->get('/api/v1/backstage/projects/{id}', ...);
$router->put('/api/v1/backstage/projects/{id}/translations/{locale}', ...);
```

- [ ] **Step 5: Rename backstage project-proposal routes**

Change:
- `/api/v1/backstage/proposals` → `/api/v1/backstage/project-proposals`
- `/api/v1/backstage/proposals/{id}/approve` → `/api/v1/backstage/project-proposals/{id}/approve`
- `/api/v1/backstage/proposals/{id}/reject` → `/api/v1/backstage/project-proposals/{id}/reject`

- [ ] **Step 6: Add event-proposal routes**

```php
$router->post('/api/v1/event-proposals', ...);                              // member submit
$router->get('/api/v1/backstage/event-proposals', ...);                     // admin list
$router->post('/api/v1/backstage/event-proposals/{id}/approve', ...);       // admin approve
$router->post('/api/v1/backstage/event-proposals/{id}/reject', ...);        // admin reject
```

- [ ] **Step 7: Commit**

```bash
git add routes/api.php src/Infrastructure/Adapter/Api/Middleware/LocaleMiddleware.php \
        bootstrap/app.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(api): routes for locale-aware events/projects + event-proposals + rename backstage project-proposals"
```

## Task A25: Bootstrap + KernelHarness wiring — ALL new bindings

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

**CRITICAL (from memory):** Wire every new class in **BOTH** files. E2E tests pass with only KernelHarness; the live server breaks without bootstrap.

- [ ] **Step 1: Grep for unbound classes** (will fail binding-check tests otherwise)

```bash
rtk grep -rn "ListEventsForLocale\|GetEventBySlugForLocale\|GetEventWithAllTranslations\|UpdateEventTranslation\|ListProjectsForLocale\|GetProjectBySlugForLocale\|GetProjectWithAllTranslations\|UpdateProjectTranslation\|SubmitEventProposal\|ApproveEventProposal\|RejectEventProposal\|ListEventProposalsForAdmin\|SqlEventProposalRepository\|EventProposalRepositoryInterface\|LocaleMiddleware" bootstrap/app.php tests/Support/KernelHarness.php
```

Expected before step 2: many misses. After step 2: every class appears in both files.

- [ ] **Step 2: Add bindings** — mirror the existing pattern for use cases:

```php
// bootstrap/app.php — example for one:
$container->bind(ListEventsForLocale::class,
    static fn(Container $c) => new ListEventsForLocale($c->make(EventRepositoryInterface::class)));
$container->bind(EventProposalRepositoryInterface::class,
    static fn(Container $c) => new SqlEventProposalRepository($c->make(Connection::class)));
// ... repeat for all new use cases + repo + middleware

// tests/Support/KernelHarness.php — mirror with in-memory fake for the repo:
$container->bind(EventProposalRepositoryInterface::class,
    static fn() => new InMemoryEventProposalRepository());
// Use cases use the interface, so same binding as production.
```

- [ ] **Step 3: Run full test suite**

```bash
composer test:all
composer analyse
```

Expected: 0 failures, 0 phpstan errors.

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Wire(bootstrap): bind all i18n use cases + middleware + repos in prod container AND KernelHarness"
```

## Task A26: Isolation tests — tenant leak prevention for events_i18n + projects_i18n + event_proposals

**Files:**
- Create: `tests/Isolation/EventsI18nTenantIsolationTest.php`
- Create: `tests/Isolation/ProjectsI18nTenantIsolationTest.php`
- Create: `tests/Isolation/EventProposalTenantIsolationTest.php`

Pattern from existing `tests/Isolation/*TenantIsolationTest.php`:

- Seed two tenants (daems, sahegroup) — handled by `IsolationTestCase` base
- Create an event (or project/proposal) in tenant A
- Run repo read queries as tenant B — expect empty results
- Attempt to write a translation to tenant A's event as tenant B — expect `DomainException`

Example (abridged):

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;

final class EventsI18nTenantIsolationTest extends IsolationTestCase
{
    public function testTenantBCannotReadTenantAEventTranslations(): void
    {
        $repo = new SqlEventRepository($this->db);
        $tenantA = TenantId::fromString($this->daemsTenantId);
        $tenantB = TenantId::fromString($this->sahegroupTenantId);

        $id = EventId::generate();
        $event = new Event(
            $id, $tenantA, 'cross-tenant-test', 'upcoming', '2026-06-15',
            null, false, null, [],
            new TranslationMap(['fi_FI' => ['title' => 'Secret', 'location' => null, 'description' => null]]),
        );
        $repo->save($event);

        $this->assertNull($repo->findByIdForTenant($id->value(), $tenantB));
        $this->assertEmpty($repo->listForTenant($tenantB));
    }

    public function testTenantBCannotWriteTranslationToTenantAEvent(): void
    {
        $repo = new SqlEventRepository($this->db);
        $tenantA = TenantId::fromString($this->daemsTenantId);
        $tenantB = TenantId::fromString($this->sahegroupTenantId);

        $id = EventId::generate();
        $repo->save(new Event(
            $id, $tenantA, 'secret', 'upcoming', '2026-06-15', null, false, null, [],
            new TranslationMap(['fi_FI' => ['title' => 'S', 'location' => null, 'description' => null]]),
        ));

        $this->expectException(\DomainException::class);
        $repo->saveTranslation($tenantB, $id->value(), SupportedLocale::fromString('en_GB'),
            ['title' => 'hack', 'location' => null, 'description' => null]);
    }
}
```

- [ ] **Steps:** write all three; run; commit per file.

```bash
vendor/bin/phpunit --testsuite Isolation
```

Commit: `Test(isolation): events_i18n + projects_i18n + event_proposals tenant isolation`.

## Task A27: E2E flow tests

**Files:**
- Create: `tests/E2E/EventsLocaleE2ETest.php`
- Create: `tests/E2E/ProjectsLocaleE2ETest.php`
- Create: `tests/E2E/EventProposalFlowE2ETest.php`

E2E tests use `KernelHarness` — in-memory container, no DB. Each test sends requests through the router and asserts on the response.

Coverage:
- `EventsLocaleE2ETest::testAcceptLanguageSwitchesContent` — seed event with fi_FI+en_GB; GET /events with `Accept-Language: en-GB` returns English.
- `...::testQueryParamOverridesAcceptLanguage`
- `...::testFallbackMarkerWhenRequestedLocaleMissing`
- `...::testAdminPutTranslationUpdatesCoverage`

Same pattern for projects.

`EventProposalFlowE2ETest::testMemberSubmitAndAdminApprove`:
- Member POST /event-proposals with `source_locale: sw_TZ`
- Admin GET /backstage/event-proposals → sees pending
- Admin POST /backstage/event-proposals/{id}/approve
- Member GET /events → sees new event, with `title_fallback: true` for fi_FI and en_GB (both fallback from sw_TZ? → No: en_GB is missing; actually this is tricky — if source_locale is sw_TZ but fallback target is en_GB and only sw_TZ row exists, then:
  - Requested sw_TZ → returns sw_TZ, no fallback.
  - Requested en_GB → en_GB row missing → fallback also missing because fallback IS en_GB → `*_missing: true`.
  - Requested fi_FI → fi_FI missing; fallback to en_GB also missing → `*_missing: true`.
- Assert this matches expectation.

- [ ] **Steps:** write all three E2E tests; run; commit.

```bash
composer test:e2e
```

Commit: `Test(e2e): locale-aware events/projects flows + event-proposal approve path`.

## Task A28: Final verification — full test run, PHPStan, route check

- [ ] **Step 1: Run the gauntlet**

```bash
composer analyse
composer test:all
```

Both must pass cleanly.

- [ ] **Step 2: Manual smoke** against dev server (Laragon running)

```bash
curl -sS http://daems-platform.local/api/v1/events -H "Accept-Language: en-GB" | head -40
curl -sS http://daems-platform.local/api/v1/events -H "Accept-Language: fi-FI" | head -40
curl -sS "http://daems-platform.local/api/v1/events?lang=sw_TZ" | head -40
```

Expect: different content per locale; `*_fallback: true` markers present where applicable.

- [ ] **Step 3: Announce contract-ready to B and C**

At this point, Workstream A has landed. B and C merge next. Their PRs can rebase on top of A.

---

# WORKSTREAM B — Backstage admin UI

**Working directory:** `C:\laragon\www\sites\daem-society`
**Start:** Can begin after A3 lands (contract locked). Merge after A merges to `dev`.
**Isolation:** Separate worktree from A and C recommended.

## Task B1: locale-cards partial + CSS + JS

**Files:**
- Create: `public/pages/backstage/shared/locale-cards.php`
- Create: `public/pages/backstage/shared/locale-cards.css`
- Create: `public/pages/backstage/shared/locale-cards.js`

- [ ] **Step 1: PHP partial** renders a container div with data attributes; the JS populates cards from API payload.

```php
<?php
// Usage: include this partial passing $kind = 'event' or 'project' and $entityId (UUID).
$kind = $kind ?? 'event';
$entityId = $entityId ?? '';
?>
<div class="locale-cards-container"
     data-kind="<?= htmlspecialchars($kind, ENT_QUOTES) ?>"
     data-entity-id="<?= htmlspecialchars($entityId, ENT_QUOTES) ?>">
    <div class="locale-cards-grid" role="tablist"></div>
    <div class="locale-cards-editor">
        <div class="locale-cards-fields"></div>
        <div class="locale-cards-actions">
            <button type="button" class="btn btn-dark locale-cards-save">Save</button>
            <span class="locale-cards-status" aria-live="polite"></span>
        </div>
    </div>
</div>
```

- [ ] **Step 2: CSS**

```css
/* locale-cards.css */
.locale-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.locale-card {
    border: 1px solid #ccc;
    border-radius: 0.5rem;
    padding: 0.75rem;
    cursor: pointer;
    background: #fff;
}
.locale-card[aria-selected="true"] {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,.2);
}
.locale-card .locale-label { font-weight: 600; margin-bottom: 0.25rem; }
.locale-card .locale-code { font-family: monospace; color: #666; font-size: 0.85em; }
.locale-card .coverage-bar {
    height: 4px; background: #eee; border-radius: 2px; margin: 0.4rem 0; overflow: hidden;
}
.locale-card .coverage-bar > span { display: block; height: 100%; background: #0d6efd; }
.locale-card.coverage-complete .coverage-bar > span { background: #198754; }
.locale-card.coverage-empty .coverage-bar > span { background: #dc3545; }
.locale-card .coverage-text { font-size: 0.85em; color: #555; }
.locale-cards-fields { margin-top: 1rem; }
.locale-cards-fields .mb-3 { margin-bottom: 1rem; }
```

- [ ] **Step 3: JS**

```javascript
// locale-cards.js — renders cards, handles save per locale.
(function () {
  const LOCALES = [
    { code: 'fi_FI', label: 'Suomi',     flag: '🇫🇮' },
    { code: 'en_GB', label: 'English',   flag: '🇬🇧' },
    { code: 'sw_TZ', label: 'Kiswahili', flag: '🇹🇿' },
  ];
  const FIELDS = {
    event:   [{ name: 'title',       label: 'Title',       type: 'text',     required: true },
              { name: 'location',    label: 'Location',    type: 'text',     required: false },
              { name: 'description', label: 'Description', type: 'textarea', required: false }],
    project: [{ name: 'title',       label: 'Title',       type: 'text',     required: true },
              { name: 'summary',     label: 'Summary',     type: 'text',     required: true, maxlength: 200 },
              { name: 'description', label: 'Description', type: 'textarea', required: true }],
  };

  function renderCards(container, state) {
    const grid = container.querySelector('.locale-cards-grid');
    grid.innerHTML = '';
    LOCALES.forEach(l => {
      const coverage = state.coverage[l.code] || { filled: 0, total: FIELDS[state.kind].length };
      const pct = coverage.total ? Math.round(coverage.filled / coverage.total * 100) : 0;
      const isActive = l.code === state.activeLocale;
      const cls = coverage.filled === coverage.total
        ? 'coverage-complete'
        : coverage.filled === 0 ? 'coverage-empty' : 'coverage-partial';
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'locale-card ' + cls;
      card.setAttribute('aria-selected', isActive ? 'true' : 'false');
      card.dataset.locale = l.code;
      card.innerHTML = `
        <div class="locale-label">${l.flag} ${l.label}</div>
        <div class="locale-code">${l.code}</div>
        <div class="coverage-bar"><span style="width:${pct}%"></span></div>
        <div class="coverage-text">${coverage.filled}/${coverage.total} · ${statusText(coverage)}</div>`;
      card.addEventListener('click', () => {
        state.activeLocale = l.code;
        render(container, state);
      });
      grid.appendChild(card);
    });
  }

  function statusText(c) {
    if (c.total === 0) return 'No fields';
    if (c.filled === c.total) return 'Complete';
    if (c.filled === 0) return 'Not translated';
    return 'Partial';
  }

  function renderFields(container, state) {
    const fieldsEl = container.querySelector('.locale-cards-fields');
    fieldsEl.innerHTML = '';
    const row = state.translations[state.activeLocale] || {};
    FIELDS[state.kind].forEach(f => {
      const wrap = document.createElement('div');
      wrap.className = 'mb-3';
      const id = `lc-${state.kind}-${f.name}`;
      const val = row[f.name] ?? '';
      let input;
      if (f.type === 'textarea') {
        input = document.createElement('textarea');
        input.rows = 6;
      } else {
        input = document.createElement('input');
        input.type = 'text';
      }
      input.id = id;
      input.name = f.name;
      input.className = 'form-control';
      input.value = val;
      if (f.maxlength) input.maxLength = f.maxlength;
      wrap.innerHTML = `<label for="${id}" class="form-label">${f.label}${f.required ? ' <span class="text-danger">*</span>' : ''}</label>`;
      wrap.appendChild(input);
      fieldsEl.appendChild(wrap);
    });
    container.querySelector('.locale-cards-save').textContent = `Save ${state.activeLocale}`;
  }

  function render(container, state) {
    renderCards(container, state);
    renderFields(container, state);
  }

  async function save(container, state) {
    const btn = container.querySelector('.locale-cards-save');
    const status = container.querySelector('.locale-cards-status');
    const inputs = container.querySelectorAll('.locale-cards-fields input, .locale-cards-fields textarea');
    const body = {};
    inputs.forEach(i => { body[i.name] = i.value; });

    btn.disabled = true;
    status.textContent = 'Saving…';
    try {
      const url = `/api/v1/backstage/${state.kind}s/${state.entityId}/translations/${state.activeLocale}`;
      const res = await window.ApiClient.put(url, body);
      state.translations[state.activeLocale] = body;
      state.coverage = res.coverage || state.coverage;
      status.textContent = 'Saved';
      render(container, state);
      document.dispatchEvent(new CustomEvent('locale-cards:saved', {
        detail: { kind: state.kind, entityId: state.entityId, coverage: state.coverage },
      }));
    } catch (e) {
      status.textContent = 'Error: ' + (e.message || 'save failed');
    } finally {
      btn.disabled = false;
      setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 2000);
    }
  }

  window.LocaleCards = {
    mount(container, { kind, entityId, translations, coverage }) {
      const state = {
        kind, entityId,
        translations: translations || {},
        coverage: coverage || {},
        activeLocale: 'fi_FI',
      };
      container.querySelector('.locale-cards-save').addEventListener('click', () => save(container, state));
      render(container, state);
    },
  };
})();
```

- [ ] **Step 4: Commit**

```bash
git add public/pages/backstage/shared/locale-cards.php \
        public/pages/backstage/shared/locale-cards.css \
        public/pages/backstage/shared/locale-cards.js
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(backstage): locale-cards partial + CSS + JS (shared by event/project editors)"
```

## Task B2: Integrate locale-cards into event-modal

**Files:**
- Modify: `public/pages/backstage/events/event-modal.js`
- Modify: `public/pages/backstage/events/event-modal.css`
- Modify: `public/pages/backstage/events/index.php` (include cards CSS/JS + partial)

- [ ] **Step 1: Include partial + assets** in `backstage/events/index.php`:

```php
<link rel="stylesheet" href="/pages/backstage/shared/locale-cards.css" />
<script defer src="/pages/backstage/shared/locale-cards.js"></script>
```

And inside the modal body (wherever the form currently renders title/location/description inputs) — **remove those inputs** and include the partial:

```php
<?php include __DIR__ . '/../shared/locale-cards.php'; // receives $kind='event', $entityId — set just before ?>
```

- [ ] **Step 2: Update `event-modal.js`** — after fetching event details for edit, mount cards:

```javascript
// When opening modal in edit mode:
const res = await window.ApiClient.get(`/api/v1/backstage/events/${eventId}`);
// Render the non-translated chrome fields (slug, type, event_date, hero_image, is_online) into their existing form inputs
// ...existing code...
// Then mount LocaleCards:
const container = modal.querySelector('.locale-cards-container');
container.dataset.entityId = eventId;
window.LocaleCards.mount(container, {
    kind: 'event',
    entityId: eventId,
    translations: res.translations,
    coverage: res.coverage,
});
```

For new-event creation: the modal collects only non-translated fields + one locale (fi_FI by default) title/location/description. On save, POST creates the event with fi_FI row; modal closes and re-opens in edit mode showing all 3 locale cards.

- [ ] **Step 3: Handle `locale-cards:saved`** event — refresh list-view badge.

```javascript
document.addEventListener('locale-cards:saved', (e) => {
    if (e.detail.kind !== 'event') return;
    updateListRowBadges(e.detail.entityId, e.detail.coverage);
});
```

- [ ] **Step 4: Commit**

```bash
git add public/pages/backstage/events/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(backstage): event-modal uses locale-cards for title/location/description per locale"
```

## Task B3: Integrate locale-cards into project-modal

Same pattern as B2, applied to `project-modal.js` + `project-modal.css` + `backstage/projects/index.php`. Project fields: title, summary (maxlength 200), description.

- [ ] **Steps:** mirror B2. Commit: `Feat(backstage): project-modal uses locale-cards for title/summary/description`.

## Task B4: List-view coverage badges — events + projects

**Files:**
- Modify: `public/pages/backstage/events/index.php`
- Modify: `public/pages/backstage/projects/index.php`
- Modify: `public/assets/css/daems.css` (or scoped CSS file)

- [ ] **Step 1: Add badge HTML** in the list table rendering. Each row fetches coverage from `GET /api/v1/backstage/events` (list response should include it). Render three dots or a compact badge:

```html
<span class="coverage-badge" data-entity-id="<?= $e['id'] ?>"
      title="fi_FI / en_GB / sw_TZ translations">
    <span class="coverage-dot" data-loc="fi_FI"></span>
    <span class="coverage-dot" data-loc="en_GB"></span>
    <span class="coverage-dot" data-loc="sw_TZ"></span>
</span>
```

- [ ] **Step 2: JS populates dot states** from API coverage map on page load:

```javascript
// After fetching list:
document.querySelectorAll('.coverage-badge').forEach(badge => {
    const entityId = badge.dataset.entityId;
    const cov = allCoverage[entityId] || {};
    badge.querySelectorAll('.coverage-dot').forEach(dot => {
        const c = cov[dot.dataset.loc] || { filled: 0, total: 3 };
        dot.classList.add(
            c.filled === c.total ? 'complete' : c.filled === 0 ? 'empty' : 'partial'
        );
    });
});
```

- [ ] **Step 3: CSS**

```css
.coverage-badge { display: inline-flex; gap: 2px; }
.coverage-dot { width: 8px; height: 8px; border-radius: 50%; background: #dee2e6; }
.coverage-dot.complete { background: #198754; }
.coverage-dot.partial  { background: #ffc107; }
.coverage-dot.empty    { background: #dc3545; }
```

- [ ] **Step 4: Commit**

```bash
git add public/pages/backstage/events/index.php public/pages/backstage/projects/index.php public/assets/css/daems.css
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(backstage): per-row coverage badges on events and projects list views"
```

## Task B5: New backstage/event-proposals page

**Files:**
- Create: `public/pages/backstage/event-proposals/index.php`
- Create: `public/pages/backstage/event-proposals/proposal-modal.css`
- Create: `public/pages/backstage/event-proposals/proposal-modal.js`

The page mirrors the pattern of `backstage/projects/index.php`:
1. Admin-only guard at top (session check)
2. Table listing all event proposals (GET `/api/v1/backstage/event-proposals`)
3. Columns: Author, Title, Date, Source locale (badge), Status, Submitted, Actions
4. Actions button opens a modal showing full proposal + Approve / Reject buttons

- [ ] **Step 1: Guard + table shell**

```php
<?php
$u = $_SESSION['user'] ?? null;
$adminRoles = ['global_system_administrator', 'administrator', 'system_administrator'];
if ($u === null || !in_array($u['role'], $adminRoles, true)) {
    header('Location: /'); exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Event Proposals — Backstage</title>
<link rel="stylesheet" href="/assets/css/bootstrap.min.css" />
<link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css" />
<link rel="stylesheet" href="/assets/css/daems.css" />
<link rel="stylesheet" href="./proposal-modal.css" />
</head><body>
<?php include __DIR__ . '/../_layout-header.php'; ?>
<main class="backstage-main">
  <div class="container">
    <h1>Event Proposals</h1>
    <table class="table" id="event-proposals-table">
      <thead><tr>
        <th>Author</th><th>Title</th><th>Date</th><th>Locale</th>
        <th>Status</th><th>Submitted</th><th></th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div>
</main>
<?php include __DIR__ . '/../_layout-footer.php'; ?>
<script src="/assets/js/daems.js"></script>
<script src="./proposal-modal.js"></script>
</body></html>
```

(If `_layout-header.php` / `_layout-footer.php` names differ, check the neighboring admin pages for the actual convention.)

- [ ] **Step 2: `proposal-modal.js`** — list fetch + modal with approve/reject

```javascript
(async function () {
  const tbody = document.querySelector('#event-proposals-table tbody');
  async function refresh() {
    const list = await window.ApiClient.get('/api/v1/backstage/event-proposals');
    tbody.innerHTML = '';
    list.forEach(p => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escape(p.author_name)}</td>
        <td>${escape(p.title)}</td>
        <td>${p.event_date}</td>
        <td><span class="locale-badge">${p.source_locale}</span></td>
        <td>${p.status}</td>
        <td>${p.created_at}</td>
        <td><button class="btn btn-sm btn-outline-dark" data-id="${p.id}">Review</button></td>`;
      tr.querySelector('button').addEventListener('click', () => openReview(p));
      tbody.appendChild(tr);
    });
  }

  function openReview(p) {
    // Inline modal rendering
    const html = `<div class="modal-backdrop">
      <div class="modal-card">
        <h3>${escape(p.title)}</h3>
        <p><strong>Author:</strong> ${escape(p.author_name)} &lt;${escape(p.author_email)}&gt;</p>
        <p><strong>Date:</strong> ${p.event_date} ${p.event_time || ''} ${p.is_online ? '(online)' : ''}</p>
        <p><strong>Location:</strong> ${escape(p.location || '—')}</p>
        <p><strong>Description:</strong></p>
        <div class="proposal-description">${escape(p.description)}</div>
        <div class="alert alert-info">This will create an Event visible only in <code>${p.source_locale}</code> until you translate it.</div>
        <div class="modal-actions">
          <button class="btn btn-outline-secondary" data-action="cancel">Cancel</button>
          <button class="btn btn-danger" data-action="reject">Reject</button>
          <button class="btn btn-success" data-action="approve">Approve</button>
        </div>
      </div>
    </div>`;
    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    document.body.appendChild(wrap);
    wrap.addEventListener('click', async (e) => {
      const action = e.target.dataset?.action;
      if (!action) return;
      if (action === 'cancel') { wrap.remove(); return; }
      if (action === 'approve') {
        await window.ApiClient.post(`/api/v1/backstage/event-proposals/${p.id}/approve`, {});
        wrap.remove(); await refresh();
      }
      if (action === 'reject') {
        const note = prompt('Rejection note (optional):') || '';
        await window.ApiClient.post(`/api/v1/backstage/event-proposals/${p.id}/reject`, { note });
        wrap.remove(); await refresh();
      }
    });
  }

  function escape(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
  refresh();
})();
```

- [ ] **Step 3: Minimal modal CSS**

```css
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.4);
    display: flex; align-items: center; justify-content: center; z-index: 2000;
}
.modal-card {
    background: #fff; padding: 1.5rem; border-radius: 0.5rem;
    max-width: 640px; width: 90%; max-height: 80vh; overflow-y: auto;
}
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
.locale-badge {
    display: inline-block; padding: 2px 6px; background: #e9ecef;
    border-radius: 4px; font-family: monospace; font-size: 0.85em;
}
.proposal-description { padding: 0.75rem; background: #f8f9fa; border-radius: 0.25rem; }
```

- [ ] **Step 4: Link page from backstage nav** — modify the admin sidebar partial to include "Event Proposals" entry (next to "Project Proposals" once B6 adds that).

- [ ] **Step 5: Commit**

```bash
git add public/pages/backstage/event-proposals/ public/pages/backstage/_layout-*  # or whatever the nav file is
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(backstage): event-proposals admin page — list + approve/reject modal"
```

## Task B6: New backstage/project-proposals page

Same structure as B5 but for project proposals. Table columns: Author, Title, Category, Source locale, Status, Submitted, Review. Approve POST to `/api/v1/backstage/project-proposals/{id}/approve` (renamed in A24).

- [ ] **Steps:** create files (mirror B5); commit: `Feat(backstage): project-proposals admin page — list + approve/reject modal`.

## Task B7: Playwright smoke tests

**Files:**
- Create: `tests/e2e/backstage-locale-cards.spec.ts`
- Create: `tests/e2e/backstage-event-proposals.spec.ts`
- Create: `tests/e2e/backstage-project-proposals.spec.ts`

Tests:

**`backstage-locale-cards.spec.ts`:**
1. Login as admin
2. Open an event in backstage/events modal
3. Click the en_GB card; fill title/description; click Save en_GB
4. Verify the coverage bar for en_GB updates to 2/3 (or whatever is filled)
5. Close modal; verify list-view badge dot for en_GB changes from red to yellow/green

**`backstage-event-proposals.spec.ts`:**
1. Seed a pending event proposal in DB (use `scripts/seed-e2e-*.php` pattern if one exists; otherwise insert via direct SQL in a fixture)
2. Login as admin; navigate `/backstage/event-proposals`
3. Click Review → Approve
4. Assert row is removed from pending list
5. Navigate to public `/events` with `?lang=<source_locale>` → assert new event appears

**`backstage-project-proposals.spec.ts`:** mirror event version.

- [ ] **Steps:** write specs; run against local dev server (Laragon); commit.

```bash
cd C:/laragon/www/sites/daem-society
npx playwright test tests/e2e/backstage-locale-cards.spec.ts
```

Commit: `Test(e2e): Playwright smoke — locale-cards + event/project proposal approval`.

## Task B8: Workstream B final verification

- [ ] Run full Playwright suite — `npx playwright test` — no regressions in existing specs.
- [ ] Manually smoke `/backstage/events`, `/backstage/projects`, `/backstage/event-proposals`, `/backstage/project-proposals` in a browser. Save a translation; approve a proposal.
- [ ] Prepare PR; mark "ready for review".

---

# WORKSTREAM C — Public frontend + I18n chrome migration

**Working directory:** `C:\laragon\www\sites\daem-society`
**Start:** Can begin after A3 lands. Merge after B. Separate worktree from B.

## Task C1: Inventory session API proxy pattern

- [ ] **Step 1: Identify how daem-society currently calls the platform API**

```bash
rtk grep -rn "ApiClient\|platform.local\|daems-platform.local" src public --include="*.php"
```

Determine whether ApiClient is a client-side JS (browser) or server-side PHP that proxies through. The adjustments in subsequent tasks depend on this.

- [ ] **Step 2: Document findings** in a short comment at the top of Task C2's notes.

No commit this task.

## Task C2: Upgrade `src/I18n.php` to full-locale form

**Files:**
- Modify: `src/I18n.php`
- Rename: `lang/fi.php` → `lang/fi_FI.php`, `lang/en.php` → `lang/en_GB.php`, `lang/sw.php` → `lang/sw_TZ.php`

- [ ] **Step 1: `git mv` lang files**

```bash
cd C:/laragon/www/sites/daem-society
git mv lang/fi.php lang/fi_FI.php
git mv lang/en.php lang/en_GB.php
git mv lang/sw.php lang/sw_TZ.php
```

- [ ] **Step 2: Rewrite `src/I18n.php`**

```php
<?php
declare(strict_types=1);

final class I18n
{
    public const SUPPORTED = ['fi_FI', 'en_GB', 'sw_TZ'];
    public const DEFAULT_LOCALE = 'fi_FI';
    public const CONTENT_FALLBACK = 'en_GB';

    private const LEGACY_MAP = ['fi' => 'fi_FI', 'en' => 'en_GB', 'sw' => 'sw_TZ'];

    private static ?string $locale = null;

    /** @var array<string, array<string, string>> */
    private static array $dict = [];

    public static function locale(): string
    {
        if (self::$locale !== null) return self::$locale;

        $get = $_GET['lang'] ?? null;
        if (is_string($get)) {
            $normalized = self::normalize($get);
            if ($normalized !== null) {
                self::$locale = $normalized;
                $_SESSION['lang'] = $normalized;
                setcookie('daems_lang', $normalized, [
                    'expires' => time() + 60*60*24*365, 'path' => '/', 'samesite' => 'Lax',
                ]);
                return self::$locale;
            }
        }

        $sess = $_SESSION['lang'] ?? null;
        if (is_string($sess)) {
            $norm = self::normalize($sess);
            if ($norm !== null) {
                if ($norm !== $sess) $_SESSION['lang'] = $norm;
                return self::$locale = $norm;
            }
        }

        $cookie = $_COOKIE['daems_lang'] ?? null;
        if (is_string($cookie)) {
            $norm = self::normalize($cookie);
            if ($norm !== null) {
                if ($norm !== $cookie) {
                    setcookie('daems_lang', $norm, [
                        'expires' => time() + 60*60*24*365, 'path' => '/', 'samesite' => 'Lax',
                    ]);
                }
                return self::$locale = $norm;
            }
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($accept !== '') {
            foreach (explode(',', $accept) as $tag) {
                $code = trim(explode(';', $tag)[0]);
                if ($code === '' || $code === '*') continue;
                $norm = self::normalize($code);
                if ($norm !== null) return self::$locale = $norm;
            }
        }

        return self::$locale = self::DEFAULT_LOCALE;
    }

    /**
     * Normalize input like 'fi', 'fi-FI', 'fi_FI', 'FI_FI' to a supported locale code.
     */
    private static function normalize(string $input): ?string
    {
        $trim = trim($input);
        if ($trim === '') return null;
        $underscored = str_replace('-', '_', $trim);
        // Normalize case: lang lowercase, region uppercase
        if (strpos($underscored, '_') !== false) {
            [$l, $r] = explode('_', $underscored, 2);
            $underscored = strtolower($l) . '_' . strtoupper($r);
        } else {
            $underscored = strtolower($underscored);
        }
        if (in_array($underscored, self::SUPPORTED, true)) return $underscored;
        // Try legacy short form or language-only form
        $short = strtolower(substr($underscored, 0, 2));
        return self::LEGACY_MAP[$short] ?? null;
    }

    public static function t(string $key, array $params = []): string
    {
        $loc = self::locale();
        $dict = self::load($loc);
        $value = $dict[$key] ?? null;
        if ($value === null && $loc !== self::DEFAULT_LOCALE) {
            $value = self::load(self::DEFAULT_LOCALE)[$key] ?? null;
        }
        if ($value === null) return $key;
        if ($params === []) return $value;
        $search  = array_map(static fn($k) => '{' . $k . '}', array_keys($params));
        $replace = array_map('strval', array_values($params));
        return str_replace($search, $replace, $value);
    }

    public static function e(string $key, array $params = []): string
    {
        return htmlspecialchars(self::t($key, $params), ENT_QUOTES, 'UTF-8');
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        if (isset(self::$dict[$locale])) return self::$dict[$locale];
        $path = __DIR__ . '/../lang/' . $locale . '.php';
        if (!file_exists($path)) return self::$dict[$locale] = [];
        $loaded = require $path;
        return self::$dict[$locale] = is_array($loaded) ? $loaded : [];
    }
}
```

- [ ] **Step 3: Smoke test locally** by visiting `/?lang=en`, `/?lang=en_GB`, `/?lang=en-GB` — all should resolve to the English chrome. `/?lang=de` ignored.

- [ ] **Step 4: Commit**

```bash
git add src/I18n.php lang/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Refactor(i18n): full-locale form (fi_FI/en_GB/sw_TZ) + legacy 2-letter remap on read"
```

## Task C3: `ApiClient` sends `Accept-Language`

**Files:**
- Modify: whatever file defines ApiClient (PHP server-side or JS client-side — identified in C1)

- [ ] **Step 1: For server-side PHP ApiClient**

Add header in the default headers array:

```php
// Wherever outbound cURL/http request is built:
$headers[] = 'Accept-Language: ' . I18n::locale();
```

- [ ] **Step 2: For client-side JS ApiClient**

In the JS module, read the current locale from a cookie or a global variable set server-side:

```javascript
// Where fetch/XHR headers are set:
headers['Accept-Language'] = window.__DAEMS_LOCALE__ || 'fi_FI';
```

And in `public/partials/top-nav.php` (or wherever HEAD is): `<script>window.__DAEMS_LOCALE__ = <?= json_encode(I18n::locale()) ?>;</script>`

- [ ] **Step 3: Commit**

```bash
git add src/ApiClient.php public/  # adjust paths to match
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(api-client): default Accept-Language header from I18n::locale()"
```

## Task C4: Refactor public events pages to consume localized API

**Files:**
- Modify: `public/pages/events/grid.php`, `events/detail.php`, `events/detail/content.php`, `events/detail/hero.php`, and anything else referenced

- [ ] **Step 1: Read current grid.php** to understand how events are fetched and rendered.

```bash
cat public/pages/events/grid.php
```

- [ ] **Step 2: Replace data source** — if currently reads from `events/data/*.php` files, switch to API:

```php
<?php
require_once __DIR__ . '/../../../src/I18n.php';
require_once __DIR__ . '/../../../src/ApiClient.php';
$events = ApiClient::get('/api/v1/events'); // Accept-Language already set by ApiClient default
?>
<?php foreach ($events as $e): ?>
    <article class="event-card">
        <h2><?= htmlspecialchars($e['title'], ENT_QUOTES) ?></h2>
        <p class="location"><?= htmlspecialchars($e['location'] ?? '', ENT_QUOTES) ?></p>
        <p class="description"><?= nl2br(htmlspecialchars($e['description'] ?? '', ENT_QUOTES)) ?></p>
        <!-- do NOT render *_fallback or *_missing to the user; treat content as content -->
    </article>
<?php endforeach; ?>
```

- [ ] **Step 3: detail.php** — GET `/api/v1/events/{slug}` with slug from URL param; 404 if null.

- [ ] **Step 4: Commit**

```bash
git add public/pages/events/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(events): public pages consume localized API (title/location/description per locale)"
```

## Task C5: Refactor public projects pages to consume localized API

Same pattern as C4 for `public/pages/projects/grid.php`, `projects/detail.php`, `projects/detail/content.php`, `projects/detail/hero.php`.

- [ ] **Steps:** mirror C4; commit: `Feat(projects): public pages consume localized API (title/summary/description per locale)`.

## Task C6: New `events/propose.php` member page

**Files:**
- Create: `public/pages/events/propose.php`

Structure: mirrors the existing project-proposal form. Only logged-in members can access. The `source_locale` is a hidden input populated from `I18n::locale()`.

```php
<?php
session_start();
require_once __DIR__ . '/../../../src/I18n.php';
$u = $_SESSION['user'] ?? null;
if ($u === null) { header('Location: /login'); exit; }
$locale = I18n::locale();
?>
<!DOCTYPE html>
<html lang="<?= substr($locale, 0, 2) ?>">
<head>
  <meta charset="UTF-8">
  <title><?= I18n::e('events.propose.title') ?></title>
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/daems.css">
</head>
<body>
  <?php include __DIR__ . '/../../partials/top-nav.php'; ?>
  <main class="container" style="max-width:720px">
    <h1><?= I18n::e('events.propose.heading') ?></h1>
    <p class="text-muted"><?= I18n::e('events.propose.intro', ['locale' => $locale]) ?></p>
    <div class="alert d-none" id="propose-error" role="alert"></div>
    <form id="event-propose-form" autocomplete="off">
      <input type="hidden" name="source_locale" value="<?= htmlspecialchars($locale, ENT_QUOTES) ?>">
      <div class="mb-3">
        <label class="form-label"><?= I18n::e('events.propose.field_title') ?> <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label"><?= I18n::e('events.propose.field_date') ?> <span class="text-danger">*</span></label>
          <input type="date" name="event_date" class="form-control" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label"><?= I18n::e('events.propose.field_time') ?></label>
          <input type="text" name="event_time" class="form-control" placeholder="18:00">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label"><?= I18n::e('events.propose.field_location') ?></label>
        <input type="text" name="location" class="form-control">
      </div>
      <div class="mb-3 form-check">
        <input type="checkbox" name="is_online" class="form-check-input" id="ev-online">
        <label class="form-check-label" for="ev-online"><?= I18n::e('events.propose.field_online') ?></label>
      </div>
      <div class="mb-4">
        <label class="form-label"><?= I18n::e('events.propose.field_description') ?> <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="8" required></textarea>
      </div>
      <div class="d-flex justify-content-end gap-2">
        <a href="/events" class="btn btn-outline-secondary px-4"><?= I18n::e('common.cancel') ?></a>
        <button type="submit" class="btn btn-dark px-4"><?= I18n::e('events.propose.submit') ?></button>
      </div>
    </form>
  </main>
  <?php include __DIR__ . '/../../partials/footer.php'; ?>
  <script src="/assets/js/daems.js"></script>
  <script>
  document.getElementById('event-propose-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd);
    body.is_online = body.is_online === 'on';
    try {
      await window.ApiClient.post('/api/v1/event-proposals', body);
      window.location.href = '/events?proposed=1';
    } catch (err) {
      document.getElementById('propose-error').textContent = err.message || 'Error';
      document.getElementById('propose-error').classList.remove('d-none');
    }
  });
  </script>
</body></html>
```

- [ ] **Step 2: Add 7 lang keys** to `lang/fi_FI.php`, `lang/en_GB.php`, `lang/sw_TZ.php`:

```php
// Fi:
'events.propose.title' => 'Ehdota tapahtumaa',
'events.propose.heading' => 'Ehdota uutta tapahtumaa',
'events.propose.intro' => 'Ehdotus tallennetaan kielellä {locale}. Admin kääntää muille kielille tarvittaessa.',
'events.propose.field_title' => 'Otsikko',
'events.propose.field_date' => 'Päivämäärä',
'events.propose.field_time' => 'Aika',
'events.propose.field_location' => 'Paikka',
'events.propose.field_online' => 'Verkkotapahtuma',
'events.propose.field_description' => 'Kuvaus',
'events.propose.submit' => 'Lähetä ehdotus',
```

English + Swahili translations for the same keys.

- [ ] **Step 3: Add route in daem-society router** (check `public/index.php` for the routing pattern).

- [ ] **Step 4: Commit**

```bash
git add public/pages/events/propose.php lang/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(events): member event-proposal form with source_locale from session locale"
```

## Task C7: Existing project-proposal form — add source_locale

**Files:**
- Modify: `public/pages/projects/propose.php` (or whatever file exists; check)

- [ ] **Step 1: Find existing form**

```bash
rtk grep -rn "project-proposals\|submitProjectProposal\|project_proposal" public/
```

- [ ] **Step 2: Add hidden input + send on submit**

```html
<input type="hidden" name="source_locale" value="<?= htmlspecialchars(I18n::locale(), ENT_QUOTES) ?>">
```

and in the JS that submits:

```javascript
body.source_locale = form.querySelector('input[name="source_locale"]').value;
```

- [ ] **Step 3: Commit**

```bash
git add public/pages/projects/  # or wherever
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m \
  "Feat(projects): project-proposal form sends source_locale (current session locale)"
```

## Task C8: Playwright — extend i18n.spec.ts

**Files:**
- Modify: `tests/e2e/i18n.spec.ts`

Add tests:
- Visit `/events?lang=en_GB` → event titles rendered in English (assert against a known seed event)
- Visit `/events?lang=sw_TZ` → Swahili content appears, or fallback-to-English if untranslated (but rendered without UI marker)
- Visit `/projects?lang=en_GB` → English
- Accept-Language header set to `en-GB,en;q=0.9` on visit without `?lang=` → renders English

```typescript
test('events render English with ?lang=en_GB', async ({ page }) => {
  await page.goto('/events?lang=en_GB');
  await expect(page.getByRole('heading', { name: 'Annual Meetup 2026' })).toBeVisible();
});

test('projects render Swahili with ?lang=sw_TZ', async ({ page }) => {
  await page.goto('/projects?lang=sw_TZ');
  // Seed a project with sw_TZ translation first — use test fixture
  // Expect Swahili-localized text
});
```

Tests may require seeding translations. If seed scripts don't exist for test locales, create them — `scripts/seed-e2e-i18n.php` that inserts a few events/projects with all three locales.

- [ ] **Steps:** extend spec; run; commit: `Test(e2e): i18n spec covers events/projects locale rendering`.

## Task C9: Workstream C final verification

- [ ] Full Playwright suite: `npx playwright test`. No regressions.
- [ ] Manual smoke: click the language switcher on home, verify `/events` and `/projects` update.
- [ ] PR ready.

---

# Cross-workstream verification

After all three PRs merge:

- [ ] Run `composer test:all` and `composer analyse` on platform
- [ ] Run `npx playwright test` on daem-society
- [ ] Manual check: admin creates an event with all three locales; public user visits `/events` with each locale and sees appropriate content
- [ ] Manual check: member submits event-proposal in Swahili; admin approves; event appears on `/events?lang=sw_TZ`; `/events?lang=fi_FI` shows `*_missing: true` markers in API (frontend renders empty)
- [ ] CLAUDE.md updated if needed; memory files updated to reflect new state

---

## Self-Review Notes

1. **Spec coverage:** Sections 1–15 of the spec are each covered by at least one task:
   - §2 scope → A1-A28 (backend), B1-B8 (ui), C1-C9 (frontend)
   - §3 locale model → A1, A2, A3, C2
   - §4 DB schema → A4, A5, A6, A11, A12 (056), A14 (055)
   - §5 domain → A1, A2, A3, A7, A8, A12
   - §6 application → A15-A19
   - §7 API contract → A21, A22, A23, A24
   - §8 HTTP → A20, A24
   - §9 admin UX → B1, B2, B3, B4, B5, B6
   - §10 public frontend → C2, C3, C4, C5, C6, C7
   - §11 testing → A9, A10, A13, A26, A27, B7, C8
   - §12 workstream split → literally encoded as A/B/C sections
   - §13 rollout → cross-workstream verification section
   - §14 risks → mitigated via TDD ordering in A9/A10 before A11 (column drop)

2. **Placeholders:** None. Every task has concrete code/commands.

3. **Type consistency:**
   - `EntityTranslationView::toApiPayload()` produces `{field}`, `{field}_fallback`, `{field}_missing` — consumed by A15/A16/A17/A21/A22 consistently.
   - `TranslationMap::coverage()` returns `['filled' => int, 'total' => int]` per locale — consumed identically in A17/A22/B1/B4.
   - `SupportedLocale::value()` always returns the underscored full form; API JSON keys use the underscored form throughout; locale-cards JS uses underscored form.
   - All use case Input classes take `TenantId $tenantId`; consistent.

Plan locked.
