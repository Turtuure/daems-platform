# Dashboard Widgets & Per-User Customisation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static dashboard with a role-aware widget system where users drag-drop reorder/hide widgets and pick from a catalogue filtered by role and enabled modules.

**Architecture:** Clean Architecture with new `Daems\Domain\Dashboard`, `Daems\Application\Dashboard`, `Daems\Infrastructure\Dashboard` namespaces. Widgets are PHP classes implementing the `Widget` abstract; modules contribute widgets via `WidgetRegistry::register()`. Layout state persists per (user × tenant) in a new `user_dashboards` table.

**Tech Stack:** PHP 8.3, MySQL 8.4, vanilla JS + SortableJS (vendored), CSS Grid, ApexCharts (already in use).

**Spec:** [docs/superpowers/specs/2026-05-10-dashboard-widgets-customization-design.md](../specs/2026-05-10-dashboard-widgets-customization-design.md)

**Branch:** `dashboard-widgets` (created from `dev` at execution time via `superpowers:using-git-worktrees`).

**Cross-repo work:** Tasks 18–22 add widgets to module repos (`c:/laragon/www/modules/{events,projects,forum}/`). Each module gets its own commits in its own repo; the platform repo tracks the framework only.

**Commit identity (every commit):**
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."
```

**Never auto-push.** Report SHAs and wait for explicit "pushaa".

---

## Task 1: Migration 073 — `user_dashboards` table

**Files:**
- Create: `database/migrations/073_create_user_dashboards_table.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 073_create_user_dashboards_table.sql
-- Per-user dashboard layout customisation. Empty table = users see role default.

CREATE TABLE user_dashboards (
  id            BINARY(16)    NOT NULL,
  user_id       BINARY(16)    NOT NULL,
  tenant_id     BINARY(16)    NOT NULL,
  layout        JSON          NOT NULL,
  updated_at    DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_tenant (user_id, tenant_id),
  KEY idx_tenant (tenant_id),
  CONSTRAINT fk_user_dashboards_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_dashboards_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Run migration locally to verify it applies**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/073_create_user_dashboards_table.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana -e "DESCRIBE daems_db.user_dashboards;"
```

Expected: 5 columns (id, user_id, tenant_id, layout, updated_at) + the PK and FKs.

- [ ] **Step 3: Update `IsolationTestCase` HWM** — bump from 72 to 73

`tests/Isolation/IsolationTestCase.php` — find the constant or property holding the highest migration number applied (search for `72`) and change to `73`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/073_create_user_dashboards_table.sql tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 073 — user_dashboards table"
```

---

## Task 2: Domain — `Widget` abstract + supporting value objects

**Files:**
- Create: `src/Domain/Dashboard/WidgetCategory.php`
- Create: `src/Domain/Dashboard/WidgetSpan.php`
- Create: `src/Domain/Dashboard/MinRole.php`
- Create: `src/Domain/Dashboard/Widget.php`
- Test: `tests/Unit/Domain/Dashboard/WidgetSpanTest.php`

- [ ] **Step 1: Write `WidgetCategory.php` (enum)**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

enum WidgetCategory: string
{
    case Numbers  = 'numbers';
    case Lists    = 'lists';
    case Charts   = 'charts';
    case Actions  = 'actions';
    case Activity = 'activity';
    case Platform = 'platform';
}
```

- [ ] **Step 2: Write `MinRole.php` (enum)**

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

enum MinRole: string
{
    case Member    = 'member';
    case Moderator = 'moderator';
    case Admin     = 'admin';
    case Gsa       = 'gsa';

    /** Compares roles by hierarchy: gsa > admin > moderator > member. */
    public function isReachableBy(self $userRole): bool
    {
        return self::rank($userRole) >= self::rank($this);
    }

    private static function rank(self $r): int
    {
        return match ($r) {
            self::Member    => 0,
            self::Moderator => 1,
            self::Admin     => 2,
            self::Gsa       => 3,
        };
    }
}
```

- [ ] **Step 3: Write the failing test for `WidgetSpan`**

`tests/Unit/Domain/Dashboard/WidgetSpanTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\InvalidWidgetSpan;
use Daems\Domain\Dashboard\WidgetSpan;
use PHPUnit\Framework\TestCase;

final class WidgetSpanTest extends TestCase
{
    public function test_accepts_1_to_4(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            self::assertSame($n, WidgetSpan::of($n)->value());
        }
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(InvalidWidgetSpan::class);
        WidgetSpan::of(0);
    }

    public function test_rejects_five(): void
    {
        $this->expectException(InvalidWidgetSpan::class);
        WidgetSpan::of(5);
    }
}
```

- [ ] **Step 4: Run test — expect failure**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/WidgetSpanTest.php
```

Expected: `Class "Daems\Domain\Dashboard\WidgetSpan" not found` (or similar).

- [ ] **Step 5: Implement `WidgetSpan` and `InvalidWidgetSpan`**

`src/Domain/Dashboard/Exception/InvalidWidgetSpan.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard\Exception;

final class InvalidWidgetSpan extends \DomainException {}
```

`src/Domain/Dashboard/WidgetSpan.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\InvalidWidgetSpan;

final class WidgetSpan
{
    private function __construct(private readonly int $value) {}

    public static function of(int $n): self
    {
        if ($n < 1 || $n > 4) {
            throw new InvalidWidgetSpan("Widget span must be 1-4, got {$n}");
        }
        return new self($n);
    }

    public function value(): int { return $this->value; }
}
```

- [ ] **Step 6: Implement `Widget` abstract**

`src/Domain/Dashboard/Widget.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

abstract class Widget
{
    abstract public function id(): string;
    abstract public function category(): WidgetCategory;
    abstract public function defaultSpan(): WidgetSpan;
    abstract public function minRole(): MinRole;

    /** null = core widget; otherwise module slug ('events', 'forum', etc.). 'platform' for GSA-only widgets. */
    public function module(): ?string { return null; }

    abstract public function labelKey(): string;
    abstract public function descriptionKey(): string;

    /** Server-rendered HTML for the widget body (no chrome — caller wraps in grid cell). */
    abstract public function render(TenantId $tenantId, User $user): string;

    /** JSON-serialisable data shape for the widget — used by GET /widget/{id}/data and may be inlined into render(). */
    abstract public function data(TenantId $tenantId): array;
}
```

- [ ] **Step 7: Run tests — expect pass**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/WidgetSpanTest.php
```

Expected: `OK (3 tests, 6 assertions)`.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Dashboard tests/Unit/Domain/Dashboard
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/dashboard): Widget abstract + WidgetCategory/MinRole/WidgetSpan VOs"
```

---

## Task 3: Domain — `WidgetRegistry`

**Files:**
- Create: `src/Domain/Dashboard/Exception/WidgetAlreadyRegistered.php`
- Create: `src/Domain/Dashboard/Exception/UnknownWidget.php`
- Create: `src/Domain/Dashboard/WidgetRegistry.php`
- Test: `tests/Unit/Domain/Dashboard/WidgetRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\UnknownWidget;
use Daems\Domain\Dashboard\Exception\WidgetAlreadyRegistered;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use PHPUnit\Framework\TestCase;

final class WidgetRegistryTest extends TestCase
{
    public function test_register_and_find(): void
    {
        $r = new WidgetRegistry();
        $w = $this->fakeWidget('core.x', null, MinRole::Admin);
        $r->register($w);

        self::assertSame($w, $r->find('core.x'));
    }

    public function test_duplicate_id_throws(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.x', null, MinRole::Admin));

        $this->expectException(WidgetAlreadyRegistered::class);
        $r->register($this->fakeWidget('core.x', null, MinRole::Admin));
    }

    public function test_find_unknown_throws(): void
    {
        $this->expectException(UnknownWidget::class);
        (new WidgetRegistry())->find('core.nope');
    }

    public function test_filter_drops_widgets_above_role(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.a', null,       MinRole::Admin));
        $r->register($this->fakeWidget('plat.b', 'platform', MinRole::Gsa));

        $filtered = $r->filterFor(MinRole::Admin, ['events']);

        self::assertCount(1, $filtered);
        self::assertSame('core.a', $filtered[0]->id());
    }

    public function test_filter_drops_widgets_for_disabled_modules(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.a',   null,     MinRole::Admin));
        $r->register($this->fakeWidget('events.k', 'events', MinRole::Admin));
        $r->register($this->fakeWidget('forum.k',  'forum',  MinRole::Admin));

        $filtered = $r->filterFor(MinRole::Admin, ['events']); // only events enabled

        $ids = array_map(fn(Widget $w) => $w->id(), $filtered);
        sort($ids);
        self::assertSame(['core.a', 'events.k'], $ids);
    }

    public function test_filter_keeps_platform_widgets_for_gsa(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('plat.a', 'platform', MinRole::Gsa));

        $filtered = $r->filterFor(MinRole::Gsa, []);

        self::assertCount(1, $filtered);
    }

    private function fakeWidget(string $id, ?string $module, MinRole $minRole): Widget
    {
        return new class($id, $module, $minRole) extends Widget {
            public function __construct(
                private readonly string $id,
                private readonly ?string $module,
                private readonly MinRole $minRole,
            ) {}
            public function id(): string                  { return $this->id; }
            public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
            public function defaultSpan(): WidgetSpan     { return WidgetSpan::of(1); }
            public function minRole(): MinRole            { return $this->minRole; }
            public function module(): ?string             { return $this->module; }
            public function labelKey(): string            { return 'x'; }
            public function descriptionKey(): string      { return 'y'; }
            public function render(TenantId $t, User $u): string { return ''; }
            public function data(TenantId $t): array      { return []; }
        };
    }
}
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/WidgetRegistryTest.php
```

- [ ] **Step 3: Implement exceptions + registry**

`src/Domain/Dashboard/Exception/WidgetAlreadyRegistered.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard\Exception;

final class WidgetAlreadyRegistered extends \DomainException {}
```

`src/Domain/Dashboard/Exception/UnknownWidget.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard\Exception;

final class UnknownWidget extends \DomainException {}
```

`src/Domain/Dashboard/WidgetRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\UnknownWidget;
use Daems\Domain\Dashboard\Exception\WidgetAlreadyRegistered;

final class WidgetRegistry
{
    /** @var array<string, Widget> */
    private array $widgets = [];

    public function register(Widget $w): void
    {
        $id = $w->id();
        if (isset($this->widgets[$id])) {
            throw new WidgetAlreadyRegistered("Widget '{$id}' already registered");
        }
        $this->widgets[$id] = $w;
    }

    public function find(string $id): Widget
    {
        return $this->widgets[$id] ?? throw new UnknownWidget("Unknown widget '{$id}'");
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    /**
     * @param list<string> $enabledModules slugs of modules enabled for the active tenant.
     *                                     'platform' module is implicit — always available.
     * @return list<Widget>
     */
    public function filterFor(MinRole $userRole, array $enabledModules): array
    {
        $allowedModules = array_merge($enabledModules, ['platform']);
        $out = [];
        foreach ($this->widgets as $w) {
            if (!$w->minRole()->isReachableBy($userRole)) {
                continue;
            }
            if ($w->module() !== null && !in_array($w->module(), $allowedModules, true)) {
                continue;
            }
            $out[] = $w;
        }
        return $out;
    }

    /** @return list<Widget> */
    public function all(): array
    {
        return array_values($this->widgets);
    }
}
```

- [ ] **Step 4: Run test — expect pass**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/WidgetRegistryTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Dashboard/{Exception,WidgetRegistry.php} tests/Unit/Domain/Dashboard/WidgetRegistryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/dashboard): WidgetRegistry — register/find/filter by role+modules"
```

---

## Task 4: Domain — `UserDashboard` entity + `LayoutEntry` VO + repo interface

**Files:**
- Create: `src/Domain/Dashboard/LayoutEntry.php`
- Create: `src/Domain/Dashboard/UserDashboard.php`
- Create: `src/Domain/Dashboard/UserDashboardRepositoryInterface.php`
- Test: `tests/Unit/Domain/Dashboard/UserDashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class UserDashboardTest extends TestCase
{
    public function test_construction_and_layout_access(): void
    {
        $userId   = UserId::generate();
        $tenantId = TenantId::generate();
        $layout = [
            new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
            new LayoutEntry('core.member_growth_chart', WidgetSpan::of(3)),
        ];
        $dash = new UserDashboard($userId, $tenantId, $layout, new \DateTimeImmutable());

        self::assertSame($userId, $dash->userId());
        self::assertSame($tenantId, $dash->tenantId());
        self::assertCount(2, $dash->layout());
        self::assertSame('core.members_kpi', $dash->layout()[0]->widgetId());
    }

    public function test_layout_entry_to_array(): void
    {
        $e = new LayoutEntry('core.x', WidgetSpan::of(2));
        self::assertSame(['widget_id' => 'core.x', 'span' => 2], $e->toArray());
    }
}
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/UserDashboardTest.php
```

- [ ] **Step 3: Implement domain types**

`src/Domain/Dashboard/LayoutEntry.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

final class LayoutEntry
{
    public function __construct(
        private readonly string $widgetId,
        private readonly WidgetSpan $span,
    ) {}

    public function widgetId(): string { return $this->widgetId; }
    public function span(): WidgetSpan { return $this->span; }

    /** @return array{widget_id: string, span: int} */
    public function toArray(): array
    {
        return ['widget_id' => $this->widgetId, 'span' => $this->span->value()];
    }
}
```

`src/Domain/Dashboard/UserDashboard.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class UserDashboard
{
    /** @param list<LayoutEntry> $layout */
    public function __construct(
        private readonly UserId $userId,
        private readonly TenantId $tenantId,
        private readonly array $layout,
        private readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function userId(): UserId          { return $this->userId; }
    public function tenantId(): TenantId      { return $this->tenantId; }
    /** @return list<LayoutEntry> */
    public function layout(): array            { return $this->layout; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
```

`src/Domain/Dashboard/UserDashboardRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface UserDashboardRepositoryInterface
{
    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard;

    public function save(UserDashboard $dashboard): void;

    public function delete(UserId $userId, TenantId $tenantId): void;
}
```

- [ ] **Step 4: Run test — expect pass**

```bash
vendor/bin/phpunit tests/Unit/Domain/Dashboard/UserDashboardTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Dashboard tests/Unit/Domain/Dashboard/UserDashboardTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/dashboard): UserDashboard entity + LayoutEntry + repo interface"
```

---

## Task 5: Frontend — `DefaultLayouts`

**Files:**
- Create: `src/Frontend/Dashboard/DefaultLayouts.php`
- Test: `tests/Unit/Frontend/Dashboard/DefaultLayoutsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend\Dashboard;

use Daems\Domain\Dashboard\MinRole;
use Daems\Frontend\Dashboard\DefaultLayouts;
use PHPUnit\Framework\TestCase;

final class DefaultLayoutsTest extends TestCase
{
    public function test_admin_default_has_8_widgets(): void
    {
        $layout = DefaultLayouts::for(MinRole::Admin);
        self::assertCount(8, $layout);
        self::assertSame('core.members_kpi', $layout[0]->widgetId());
    }

    public function test_moderator_default_has_forum_focus(): void
    {
        $layout = DefaultLayouts::for(MinRole::Moderator);
        $ids = array_map(fn($e) => $e->widgetId(), $layout);
        self::assertContains('forum.reports_kpi', $ids);
        self::assertContains('forum.reports_queue', $ids);
    }

    public function test_gsa_default_has_platform_widgets(): void
    {
        $layout = DefaultLayouts::for(MinRole::Gsa);
        $ids = array_map(fn($e) => $e->widgetId(), $layout);
        self::assertContains('platform.tenant_status_grid', $ids);
        self::assertContains('platform.tenants_kpi', $ids);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement `DefaultLayouts`**

`src/Frontend/Dashboard/DefaultLayouts.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Frontend\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetSpan;

final class DefaultLayouts
{
    /** @return list<LayoutEntry> */
    public static function for(MinRole $role): array
    {
        return match ($role) {
            MinRole::Admin     => self::admin(),
            MinRole::Moderator => self::moderator(),
            MinRole::Gsa       => self::gsa(),
            MinRole::Member    => [], // members don't see the dashboard, but be defensive
        };
    }

    /** @return list<LayoutEntry> */
    private static function admin(): array
    {
        return [
            new LayoutEntry('core.members_kpi',         WidgetSpan::of(1)),
            new LayoutEntry('core.applications_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('events.events_kpi',        WidgetSpan::of(1)),
            new LayoutEntry('projects.projects_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('core.member_growth_chart', WidgetSpan::of(3)),
            new LayoutEntry('core.quick_actions',       WidgetSpan::of(1)),
            new LayoutEntry('core.pending_apps_list',   WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',       WidgetSpan::of(2)),
        ];
    }

    /** @return list<LayoutEntry> */
    private static function moderator(): array
    {
        return [
            new LayoutEntry('forum.reports_kpi',        WidgetSpan::of(1)),
            new LayoutEntry('forum.posts_today_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('forum.flagged_users_kpi',  WidgetSpan::of(1)),
            new LayoutEntry('forum.pinned_topics_kpi',  WidgetSpan::of(1)),
            new LayoutEntry('forum.reports_queue',      WidgetSpan::of(4)),
            new LayoutEntry('forum.recent_posts_list',  WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',       WidgetSpan::of(2)),
        ];
    }

    /** @return list<LayoutEntry> */
    private static function gsa(): array
    {
        return [
            new LayoutEntry('platform.tenants_kpi',           WidgetSpan::of(1)),
            new LayoutEntry('platform.users_kpi',             WidgetSpan::of(1)),
            new LayoutEntry('platform.db_size_kpi',           WidgetSpan::of(1)),
            new LayoutEntry('platform.uptime_kpi',            WidgetSpan::of(1)),
            new LayoutEntry('platform.tenant_status_grid',    WidgetSpan::of(4)),
            new LayoutEntry('platform.tenant_activity_chart', WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',             WidgetSpan::of(2)),
        ];
    }
}
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Dashboard tests/Unit/Frontend/Dashboard
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(frontend/dashboard): DefaultLayouts — admin/moderator/gsa role defaults"
```

---

## Task 6: Application — `GetUserLayout` use case

**Files:**
- Create: `src/Application/Dashboard/GetUserLayout/GetUserLayout.php`
- Create: `src/Application/Dashboard/GetUserLayout/GetUserLayoutOutput.php`
- Test: `tests/Unit/Application/Dashboard/GetUserLayoutTest.php`

The use case implements the spec's resolution order: saved row → role default → filter by enabled modules + min role.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class GetUserLayoutTest extends TestCase
{
    public function test_returns_role_default_when_no_saved_row(): void
    {
        [$registry, $repo] = $this->fixtures();
        $useCase = new GetUserLayout($registry, $repo);

        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, ['events', 'projects']);

        self::assertTrue($output->isDefault());
        self::assertGreaterThan(0, count($output->layout()));
    }

    public function test_returns_saved_layout_when_row_exists(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId,
            $tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, ['events', 'projects']);

        self::assertFalse($output->isDefault());
        self::assertCount(1, $output->layout());
    }

    public function test_filters_out_widgets_for_disabled_modules(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId,
            $tenantId,
            [
                new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
                new LayoutEntry('events.events_kpi', WidgetSpan::of(1)),
            ],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, []); // no modules

        self::assertCount(1, $output->layout());
        self::assertSame('core.members_kpi', $output->layout()[0]->widgetId());
    }

    public function test_filters_out_widgets_above_user_role(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId,
            $tenantId,
            [
                new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
                new LayoutEntry('platform.tenants_kpi', WidgetSpan::of(1)),
            ],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, []); // not GSA

        self::assertCount(1, $output->layout());
        self::assertSame('core.members_kpi', $output->layout()[0]->widgetId());
    }

    /** @return array{0: WidgetRegistry, 1: InMemoryUserDashboardRepository} */
    private function fixtures(): array
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.members_kpi',         null,       MinRole::Admin));
        $r->register(new FakeWidget('core.applications_kpi',    null,       MinRole::Admin));
        $r->register(new FakeWidget('core.member_growth_chart', null,       MinRole::Admin, 3));
        $r->register(new FakeWidget('core.quick_actions',       null,       MinRole::Admin));
        $r->register(new FakeWidget('core.pending_apps_list',   null,       MinRole::Admin, 2));
        $r->register(new FakeWidget('core.activity_feed',       null,       MinRole::Admin, 2));
        $r->register(new FakeWidget('events.events_kpi',        'events',   MinRole::Admin));
        $r->register(new FakeWidget('projects.projects_kpi',    'projects', MinRole::Admin));
        $r->register(new FakeWidget('platform.tenants_kpi',     'platform', MinRole::Gsa));

        return [$r, new InMemoryUserDashboardRepository()];
    }
}
```

`tests/Unit/Application/Dashboard/Support/FakeWidget.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard\Support;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

final class FakeWidget extends Widget
{
    public function __construct(
        private readonly string $id,
        private readonly ?string $module,
        private readonly MinRole $minRole,
        private readonly int $span = 1,
    ) {}

    public function id(): string                  { return $this->id; }
    public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan     { return WidgetSpan::of($this->span); }
    public function minRole(): MinRole            { return $this->minRole; }
    public function module(): ?string             { return $this->module; }
    public function labelKey(): string            { return "label.{$this->id}"; }
    public function descriptionKey(): string      { return "desc.{$this->id}"; }
    public function render(TenantId $t, User $u): string { return "<!--{$this->id}-->"; }
    public function data(TenantId $t): array      { return ['id' => $this->id]; }
}
```

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement `InMemoryUserDashboardRepository`**

`src/Infrastructure/Dashboard/InMemoryUserDashboardRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InMemoryUserDashboardRepository implements UserDashboardRepositoryInterface
{
    /** @var array<string, UserDashboard> keyed by "userId|tenantId" */
    private array $rows = [];

    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard
    {
        return $this->rows[$this->key($userId, $tenantId)] ?? null;
    }

    public function save(UserDashboard $dashboard): void
    {
        $this->rows[$this->key($dashboard->userId(), $dashboard->tenantId())] = $dashboard;
    }

    public function delete(UserId $userId, TenantId $tenantId): void
    {
        unset($this->rows[$this->key($userId, $tenantId)]);
    }

    private function key(UserId $u, TenantId $t): string
    {
        return $u->toString() . '|' . $t->toString();
    }
}
```

- [ ] **Step 4: Implement `GetUserLayout`**

`src/Application/Dashboard/GetUserLayout/GetUserLayoutOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\GetUserLayout;

use Daems\Domain\Dashboard\LayoutEntry;

final class GetUserLayoutOutput
{
    /** @param list<LayoutEntry> $layout */
    public function __construct(
        private readonly array $layout,
        private readonly bool $isDefault,
    ) {}

    /** @return list<LayoutEntry> */
    public function layout(): array { return $this->layout; }
    public function isDefault(): bool { return $this->isDefault; }
}
```

`src/Application/Dashboard/GetUserLayout/GetUserLayout.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\GetUserLayout;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Frontend\Dashboard\DefaultLayouts;

final class GetUserLayout
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly UserDashboardRepositoryInterface $repo,
    ) {}

    /** @param list<string> $enabledModules */
    public function execute(
        UserId $userId,
        TenantId $tenantId,
        MinRole $userRole,
        array $enabledModules,
    ): GetUserLayoutOutput {
        $saved = $this->repo->findFor($userId, $tenantId);
        $isDefault = $saved === null;
        $layout = $saved !== null
            ? $saved->layout()
            : DefaultLayouts::for($userRole);

        $allowedModules = array_merge($enabledModules, ['platform']);
        $filtered = [];
        foreach ($layout as $entry) {
            if (!$this->registry->has($entry->widgetId())) {
                continue; // unknown widget id (e.g. a removed widget) — skip silently
            }
            $widget = $this->registry->find($entry->widgetId());
            if (!$widget->minRole()->isReachableBy($userRole)) {
                continue;
            }
            $module = $widget->module();
            if ($module !== null && !in_array($module, $allowedModules, true)) {
                continue;
            }
            $filtered[] = $entry;
        }

        return new GetUserLayoutOutput($filtered, $isDefault);
    }
}
```

- [ ] **Step 5: Run test — expect pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Dashboard/GetUserLayoutTest.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Application/Dashboard/GetUserLayout src/Infrastructure/Dashboard/InMemoryUserDashboardRepository.php tests/Unit/Application/Dashboard
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/dashboard): GetUserLayout use case + InMemory repo"
```

---

## Task 7: Application — `SaveUserLayout` use case

**Files:**
- Create: `src/Application/Dashboard/SaveUserLayout/SaveUserLayout.php`
- Create: `src/Application/Dashboard/SaveUserLayout/SaveUserLayoutInput.php`
- Create: `src/Domain/Dashboard/Exception/InvalidLayout.php`
- Test: `tests/Unit/Application/Dashboard/SaveUserLayoutTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayoutInput;
use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class SaveUserLayoutTest extends TestCase
{
    public function test_saves_valid_layout(): void
    {
        [$useCase, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $useCase->execute(new SaveUserLayoutInput(
            $userId, $tenantId, MinRole::Admin, ['events'],
            [['widget_id' => 'core.members_kpi', 'span' => 1]],
        ));

        self::assertNotNull($repo->findFor($userId, $tenantId));
    }

    public function test_rejects_unknown_widget(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'core.nope', 'span' => 1]],
        ));
    }

    public function test_rejects_widget_above_role(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'platform.tenants_kpi', 'span' => 1]],
        ));
    }

    public function test_rejects_widget_for_disabled_module(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [], // events disabled
            [['widget_id' => 'events.events_kpi', 'span' => 1]],
        ));
    }

    public function test_rejects_span_out_of_range(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'core.members_kpi', 'span' => 5]],
        ));
    }

    public function test_rejects_duplicate_widget_id(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [
                ['widget_id' => 'core.members_kpi', 'span' => 1],
                ['widget_id' => 'core.members_kpi', 'span' => 1],
            ],
        ));
    }

    public function test_rejects_malformed_entry(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['span' => 1]], // missing widget_id
        ));
    }

    /** @return array{0: SaveUserLayout, 1: InMemoryUserDashboardRepository} */
    private function fixtures(): array
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.members_kpi',     null,       MinRole::Admin));
        $r->register(new FakeWidget('events.events_kpi',    'events',   MinRole::Admin));
        $r->register(new FakeWidget('platform.tenants_kpi', 'platform', MinRole::Gsa));
        $repo = new InMemoryUserDashboardRepository();
        return [new SaveUserLayout($r, $repo, new \DateTimeImmutable('2026-05-10 12:00:00')), $repo];
    }
}
```

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement `InvalidLayout` exception**

`src/Domain/Dashboard/Exception/InvalidLayout.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard\Exception;

final class InvalidLayout extends \DomainException
{
    public static function unknownWidget(string $id): self    { return new self("Unknown widget: {$id}"); }
    public static function aboveRole(string $id): self        { return new self("Widget '{$id}' requires higher role"); }
    public static function disabledModule(string $id, string $module): self { return new self("Widget '{$id}' requires module '{$module}' which is not enabled"); }
    public static function invalidSpan(string $id, int $span): self { return new self("Widget '{$id}' has invalid span {$span}"); }
    public static function duplicateWidget(string $id): self  { return new self("Widget '{$id}' appears twice in layout"); }
    public static function malformedEntry(): self             { return new self('Layout entry missing widget_id or span'); }
}
```

- [ ] **Step 4: Implement use case**

`src/Application/Dashboard/SaveUserLayout/SaveUserLayoutInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\SaveUserLayout;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class SaveUserLayoutInput
{
    /**
     * @param list<string> $enabledModules
     * @param list<array{widget_id?: string, span?: int}> $rawLayout raw incoming JSON
     */
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly MinRole $userRole,
        public readonly array $enabledModules,
        public readonly array $rawLayout,
    ) {}
}
```

`src/Application/Dashboard/SaveUserLayout/SaveUserLayout.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\SaveUserLayout;

use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;

final class SaveUserLayout
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly UserDashboardRepositoryInterface $repo,
        private readonly \DateTimeImmutable $now,
    ) {}

    public function execute(SaveUserLayoutInput $in): void
    {
        $allowedModules = array_merge($in->enabledModules, ['platform']);
        $seenIds = [];
        $entries = [];

        foreach ($in->rawLayout as $raw) {
            if (!isset($raw['widget_id'], $raw['span'])) {
                throw InvalidLayout::malformedEntry();
            }
            $id = (string) $raw['widget_id'];
            $span = (int) $raw['span'];

            if (isset($seenIds[$id])) {
                throw InvalidLayout::duplicateWidget($id);
            }
            $seenIds[$id] = true;

            if (!$this->registry->has($id)) {
                throw InvalidLayout::unknownWidget($id);
            }
            $widget = $this->registry->find($id);

            if (!$widget->minRole()->isReachableBy($in->userRole)) {
                throw InvalidLayout::aboveRole($id);
            }
            $module = $widget->module();
            if ($module !== null && !in_array($module, $allowedModules, true)) {
                throw InvalidLayout::disabledModule($id, $module);
            }
            if ($span < 1 || $span > 4) {
                throw InvalidLayout::invalidSpan($id, $span);
            }

            $entries[] = new LayoutEntry($id, WidgetSpan::of($span));
        }

        $this->repo->save(new UserDashboard(
            $in->userId,
            $in->tenantId,
            $entries,
            $this->now,
        ));
    }
}
```

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Commit**

```bash
git add src/Application/Dashboard/SaveUserLayout src/Domain/Dashboard/Exception/InvalidLayout.php tests/Unit/Application/Dashboard/SaveUserLayoutTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/dashboard): SaveUserLayout use case + validation"
```

---

## Task 8: Application — `ResetUserLayout` use case

**Files:**
- Create: `src/Application/Dashboard/ResetUserLayout/ResetUserLayout.php`
- Test: `tests/Unit/Application/Dashboard/ResetUserLayoutTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;

final class ResetUserLayoutTest extends TestCase
{
    public function test_deletes_existing_row(): void
    {
        $repo = new InMemoryUserDashboardRepository();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();
        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.x', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        (new ResetUserLayout($repo))->execute($userId, $tenantId);

        self::assertNull($repo->findFor($userId, $tenantId));
    }

    public function test_idempotent_when_no_row(): void
    {
        $repo = new InMemoryUserDashboardRepository();
        // No exception expected.
        (new ResetUserLayout($repo))->execute(UserId::generate(), TenantId::generate());
        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement use case**

`src/Application/Dashboard/ResetUserLayout/ResetUserLayout.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ResetUserLayout;

use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ResetUserLayout
{
    public function __construct(
        private readonly UserDashboardRepositoryInterface $repo,
    ) {}

    public function execute(UserId $userId, TenantId $tenantId): void
    {
        $this->repo->delete($userId, $tenantId);
    }
}
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Dashboard/ResetUserLayout tests/Unit/Application/Dashboard/ResetUserLayoutTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/dashboard): ResetUserLayout use case"
```

---

## Task 9: Application — `ListCatalog` use case

**Files:**
- Create: `src/Application/Dashboard/ListCatalog/ListCatalog.php`
- Create: `src/Application/Dashboard/ListCatalog/CatalogItem.php`
- Test: `tests/Unit/Application/Dashboard/ListCatalogTest.php`

The catalog is the filtered list shown in the "+ Add widget" modal. Each item carries `in_layout` (already on user's layout) and `locked_reason` (why a GSA-only widget is unavailable).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\ListCatalog\ListCatalog;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class ListCatalogTest extends TestCase
{
    public function test_lists_widgets_for_user_role_and_modules(): void
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.a',   null,       MinRole::Admin));
        $r->register(new FakeWidget('events.x', 'events',   MinRole::Admin));
        $r->register(new FakeWidget('forum.y',  'forum',    MinRole::Admin));

        $items = (new ListCatalog($r))->execute(MinRole::Admin, ['events'], []);

        $ids = array_map(fn($i) => $i->widgetId, $items);
        sort($ids);
        self::assertSame(['core.a', 'events.x'], $ids);
    }

    public function test_marks_widgets_already_in_layout(): void
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.a', null, MinRole::Admin));
        $r->register(new FakeWidget('core.b', null, MinRole::Admin));

        $items = (new ListCatalog($r))->execute(
            MinRole::Admin,
            [],
            [new LayoutEntry('core.a', WidgetSpan::of(1))],
        );

        $byId = [];
        foreach ($items as $i) { $byId[$i->widgetId] = $i; }
        self::assertTrue($byId['core.a']->inLayout);
        self::assertFalse($byId['core.b']->inLayout);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement use case + DTO**

`src/Application/Dashboard/ListCatalog/CatalogItem.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ListCatalog;

final class CatalogItem
{
    public function __construct(
        public readonly string $widgetId,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly string $category,
        public readonly int $defaultSpan,
        public readonly ?string $module,
        public readonly bool $inLayout,
        public readonly ?string $lockedReason,
    ) {}

    /** @return array{widget_id: string, label_key: string, description_key: string, category: string, default_span: int, module: ?string, in_layout: bool, locked_reason: ?string} */
    public function toArray(): array
    {
        return [
            'widget_id'       => $this->widgetId,
            'label_key'       => $this->labelKey,
            'description_key' => $this->descriptionKey,
            'category'        => $this->category,
            'default_span'    => $this->defaultSpan,
            'module'          => $this->module,
            'in_layout'       => $this->inLayout,
            'locked_reason'   => $this->lockedReason,
        ];
    }
}
```

`src/Application/Dashboard/ListCatalog/ListCatalog.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ListCatalog;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;

final class ListCatalog
{
    public function __construct(
        private readonly WidgetRegistry $registry,
    ) {}

    /**
     * @param list<string> $enabledModules
     * @param list<LayoutEntry> $currentLayout — used to mark in_layout
     * @return list<CatalogItem>
     */
    public function execute(MinRole $userRole, array $enabledModules, array $currentLayout): array
    {
        $inLayoutIds = array_flip(array_map(fn($e) => $e->widgetId(), $currentLayout));
        $items = [];
        foreach ($this->registry->filterFor($userRole, $enabledModules) as $w) {
            $items[] = new CatalogItem(
                widgetId:       $w->id(),
                labelKey:       $w->labelKey(),
                descriptionKey: $w->descriptionKey(),
                category:       $w->category()->value,
                defaultSpan:    $w->defaultSpan()->value(),
                module:         $w->module(),
                inLayout:       isset($inLayoutIds[$w->id()]),
                lockedReason:   null,
            );
        }
        return $items;
    }
}
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Dashboard/ListCatalog tests/Unit/Application/Dashboard/ListCatalogTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/dashboard): ListCatalog use case"
```

---

## Task 10: Infrastructure — `SqlUserDashboardRepository`

**Files:**
- Create: `src/Infrastructure/Dashboard/SqlUserDashboardRepository.php`
- Test: `tests/Integration/SqlUserDashboardRepositoryTest.php`

Existing pattern: see `src/Infrastructure/Adapter/Persistence/Sql/SqlUserInviteRepository.php` for an example using `Connection`. Repositories take a `Connection` and use `pdo()` for prepared statements.

- [ ] **Step 1: Write the integration test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\SqlUserDashboardRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlUserDashboardRepositoryTest extends MigrationTestCase
{
    public function test_save_and_find(): void
    {
        $repo = new SqlUserDashboardRepository($this->container->make(Connection::class));

        $userId   = $this->seedUser('admin@example.com');
        $tenantId = $this->seedTenant('test-tenant');

        $dash = new UserDashboard(
            $userId,
            $tenantId,
            [
                new LayoutEntry('core.members_kpi',  WidgetSpan::of(1)),
                new LayoutEntry('core.activity_feed', WidgetSpan::of(2)),
            ],
            new \DateTimeImmutable('2026-05-10 12:00:00'),
        );

        $repo->save($dash);
        $loaded = $repo->findFor($userId, $tenantId);

        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->layout());
        self::assertSame('core.members_kpi', $loaded->layout()[0]->widgetId());
        self::assertSame(2, $loaded->layout()[1]->span()->value());
    }

    public function test_save_upserts_existing(): void
    {
        $repo = new SqlUserDashboardRepository($this->container->make(Connection::class));
        $userId   = $this->seedUser('admin@example.com');
        $tenantId = $this->seedTenant('test-tenant');

        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));
        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.activity_feed', WidgetSpan::of(2))],
            new \DateTimeImmutable(),
        ));

        $loaded = $repo->findFor($userId, $tenantId);
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->layout());
        self::assertSame('core.activity_feed', $loaded->layout()[0]->widgetId());
    }

    public function test_delete_removes_row(): void
    {
        $repo = new SqlUserDashboardRepository($this->container->make(Connection::class));
        $userId   = $this->seedUser('admin@example.com');
        $tenantId = $this->seedTenant('test-tenant');

        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        $repo->delete($userId, $tenantId);
        self::assertNull($repo->findFor($userId, $tenantId));
    }
}
```

(`seedUser()` and `seedTenant()` exist in `MigrationTestCase` — match their existing signatures by reading the base class.)

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/phpunit tests/Integration/SqlUserDashboardRepositoryTest.php
```

- [ ] **Step 3: Implement repository**

`src/Infrastructure/Dashboard/SqlUserDashboardRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlUserDashboardRepository implements UserDashboardRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT layout, updated_at FROM user_dashboards WHERE user_id = :uid AND tenant_id = :tid'
        );
        $stmt->execute([
            ':uid' => $userId->toBytes(),
            ':tid' => $tenantId->toBytes(),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $raw = json_decode((string) $row['layout'], true, flags: JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($raw as $r) {
            $entries[] = new LayoutEntry((string) $r['widget_id'], WidgetSpan::of((int) $r['span']));
        }
        return new UserDashboard(
            $userId,
            $tenantId,
            $entries,
            new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    public function save(UserDashboard $dashboard): void
    {
        $layoutJson = json_encode(
            array_map(fn(LayoutEntry $e) => $e->toArray(), $dashboard->layout()),
            JSON_THROW_ON_ERROR,
        );

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO user_dashboards (id, user_id, tenant_id, layout, updated_at)
             VALUES (UUID_TO_BIN(:id), :uid, :tid, :layout, :updated_at)
             ON DUPLICATE KEY UPDATE layout = VALUES(layout), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            ':id'         => $this->newUuid(),
            ':uid'        => $dashboard->userId()->toBytes(),
            ':tid'        => $dashboard->tenantId()->toBytes(),
            ':layout'     => $layoutJson,
            ':updated_at' => $dashboard->updatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(UserId $userId, TenantId $tenantId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM user_dashboards WHERE user_id = :uid AND tenant_id = :tid'
        );
        $stmt->execute([
            ':uid' => $userId->toBytes(),
            ':tid' => $tenantId->toBytes(),
        ]);
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

(If a UUID helper already exists in the project — search `src/` for `UUID_TO_BIN` callers — reuse it instead of duplicating.)

- [ ] **Step 4: Run integration test — expect pass**

```bash
vendor/bin/phpunit tests/Integration/SqlUserDashboardRepositoryTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Dashboard/SqlUserDashboardRepository.php tests/Integration/SqlUserDashboardRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/dashboard): SqlUserDashboardRepository — JSON layout column upsert"
```

---

## Task 11: Core widgets — KPI and chart shells (no real data yet)

**Files:**
- Create: `src/Infrastructure/Dashboard/CoreWidgets/MembersKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/ApplicationsKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/MemberGrowthChartWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/PlatformActivityChartWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/QuickActionsWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/PendingAppsListWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/ActivityFeedWidget.php`

Each widget reuses the existing data sources via the existing repositories (e.g., `MembersRepositoryInterface`). The constructors take dependencies via DI; bootstrap wires them.

- [ ] **Step 1: Implement `MembersKpiWidget`**

The current data source is `\Daems\Application\Admin\GetAdminStats\GetAdminStats` — it returns `members`, `members_change`, `members_sparkline`. Reuse it for now: inject `GetAdminStats` and pull only the members slice.

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Application\Admin\GetAdminStats\GetAdminStats;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

final class MembersKpiWidget extends Widget
{
    public function __construct(private readonly GetAdminStats $stats) {}

    public function id(): string                  { return 'core.members_kpi'; }
    public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan     { return WidgetSpan::of(1); }
    public function minRole(): MinRole            { return MinRole::Admin; }
    public function labelKey(): string            { return 'backstage.dashboard.widget.members_kpi.label'; }
    public function descriptionKey(): string      { return 'backstage.dashboard.widget.members_kpi.description'; }

    public function render(TenantId $t, User $u): string
    {
        $d = $this->data($t);
        return $this->kpiCard('blue', 'members_kpi', $d['value'], $d['change'], $d['sparkline']);
    }

    public function data(TenantId $t): array
    {
        $stats = $this->stats->execute($t);
        return [
            'value'     => $stats->members,
            'change'    => $stats->membersChange ?? 0,
            'sparkline' => $stats->membersSparkline ?? [],
        ];
    }

    /** @param list<int> $sparkline */
    private function kpiCard(string $color, string $key, int $value, float $change, array $sparkline): string
    {
        // Reuse the existing metric-card partial — see public/backstage/pages/partials/metric-card.php
        ob_start();
        $card = [
            'id'     => $key,
            'label'  => \Daems\Frontend\I18n::t("backstage.dashboard.widget.{$key}.label"),
            'color'  => $color,
            'value'  => $value,
            'change' => $change,
            'enter'  => 1,
            'icon'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
        ];
        extract($card);
        require __DIR__ . '/../../../../public/backstage/pages/partials/metric-card.php';
        return (string) ob_get_clean();
    }
}
```

(Note: the relative path to `metric-card.php` from `src/Infrastructure/Dashboard/CoreWidgets/` is fragile but matches the existing `pages/index.php` pattern. If a renderer service feels cleaner later, refactor — not in v1.)

- [ ] **Step 2: Implement remaining 6 core widgets following the same pattern**

Each widget:
- Takes its data source via constructor (reuse existing `GetAdminStats`, `ListPendingApplications`, `MemberGrowthRepository`, etc.)
- Implements `id()`, `category()`, `defaultSpan()`, `minRole()`, `labelKey()`, `descriptionKey()`, `render()`, `data()`
- Returns HTML matching the current dashboard look

Mapping to data:
- `ApplicationsKpiWidget` (span 1) — `GetAdminStats::execute()->pendingApplications`
- `MemberGrowthChartWidget` (span 3) — existing `/backstage/member-growth` data; render an empty `<div id="chart-..."></div>` and embed JSON in a `<script>` tag. Actual ApexCharts rendering happens in `dashboard.js` (Task 28).
- `PlatformActivityChartWidget` (span 3) — same shape, different data source (`AdminController::platformActivity` if it exists; otherwise stub `data()` returning empty `series` and update once a use case is identified).
- `QuickActionsWidget` (span 1) — static button list (New event / Review apps / New insight). No data fetch.
- `PendingAppsListWidget` (span 2) — reuse `ListPendingApplications` use case.
- `ActivityFeedWidget` (span 2) — new use case `Application/Dashboard/GetActivityFeed/GetActivityFeed.php` that aggregates: new members (membership audit), new applications, application decisions. Implement minimally for v1: pull last 10 from a new view or unionised query. **If the union is non-trivial, scope it down to "last 10 applications" only and leave a TODO comment for full activity** — the widget itself is unblocked.

For each widget, write a unit test that asserts:
- `id()`, `category()`, `defaultSpan()`, `minRole()`, `module()` return the spec values
- `data()` returns the expected shape (mock the dependency)

Example test name: `tests/Unit/Infrastructure/Dashboard/CoreWidgets/MembersKpiWidgetTest.php`.

- [ ] **Step 3: Run all unit tests for core widgets**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Dashboard/CoreWidgets/
```

Expected: green.

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/Dashboard/CoreWidgets tests/Unit/Infrastructure/Dashboard/CoreWidgets
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/dashboard): 7 core widgets (KPIs/charts/lists/actions/feed)"
```

---

## Task 12: Platform widgets — GSA-only

**Files:**
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/TenantsKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/PlatformUsersKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/DbSizeKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/UptimeKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/TenantStatusGridWidget.php`
- Create: `src/Infrastructure/Dashboard/PlatformWidgets/TenantActivityChartWidget.php`

These widgets all set `module() === 'platform'` and `minRole() === MinRole::Gsa`.

Data sources to reuse / create:

- `TenantsKpiWidget` — count of `tenants` rows where `suspended_at IS NULL`. Use the existing `TenantRepositoryInterface::all()` if it exists; otherwise add a `count()` method to the interface + SQL impl in this task.
- `PlatformUsersKpiWidget` — `SELECT COUNT(*) FROM users WHERE deleted_at IS NULL`. Add a `\Daems\Domain\User\UserRepositoryInterface::countAll()` method + SQL impl.
- `DbSizeKpiWidget` — `SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()`. Wrap in a tiny `Application/Platform/GetDbSize/GetDbSize` use case so widget code stays clean.
- `UptimeKpiWidget` — `SHOW GLOBAL STATUS LIKE 'Uptime'` (MySQL session uptime in seconds). Same pattern: small platform use case.
- `TenantStatusGridWidget` — list all tenants with `name`, `member_count` (join to user_tenants), `module_count` (join to tenant_modules), `suspended` flag.
- `TenantActivityChartWidget` — bucket events per tenant per day for last 7 days. New use case `GetTenantActivity` aggregating from existing audit tables (forum_posts, projects, events, applications). v1 may stub with empty series and a TODO if aggregation is heavy.

- [ ] **Step 1: Implement each widget with the same shape as core widgets**

Each follows the pattern in Task 11 — constructor injects use case, `data()` calls it, `render()` produces HTML.

- [ ] **Step 2: Write unit test per widget**

Same shape as core widget tests: assert metadata, mock the use case, assert `data()` shape.

- [ ] **Step 3: Run tests — expect pass**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Dashboard/PlatformWidgets/
```

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/Dashboard/PlatformWidgets src/Application/Platform tests/Unit/Infrastructure/Dashboard/PlatformWidgets
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/dashboard): 6 platform widgets (GSA-only) + supporting use cases"
```

---

## Task 13: Module widgets — events module

**Files (in module repo `c:/laragon/www/modules/events/`):**
- Create: `backend/src/Frontend/Backstage/Widgets/EventsKpiWidget.php`
- Create: `backend/src/Frontend/Backstage/Widgets/UpcomingEventsListWidget.php`
- Modify: `backend/bindings.php` (register the 2 widgets)

Both widgets set `module() === 'events'`.

- [ ] **Step 1: Implement `EventsKpiWidget`**

`backend/src/Frontend/Backstage/Widgets/EventsKpiWidget.php` (in events module repo):

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Events\Frontend\Backstage\Widgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use DaemsModule\Events\Application\CountUpcomingEvents\CountUpcomingEvents;

final class EventsKpiWidget extends Widget
{
    public function __construct(private readonly CountUpcomingEvents $count) {}

    public function id(): string                  { return 'events.events_kpi'; }
    public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan     { return WidgetSpan::of(1); }
    public function minRole(): MinRole            { return MinRole::Admin; }
    public function module(): ?string             { return 'events'; }
    public function labelKey(): string            { return 'backstage.dashboard.widget.events_kpi.label'; }
    public function descriptionKey(): string      { return 'backstage.dashboard.widget.events_kpi.description'; }

    public function render(TenantId $t, User $u): string
    {
        $d = $this->data($t);
        // Reuse the platform's metric-card partial via include — same approach as core widgets.
        ob_start();
        $card = ['id' => 'events_kpi', 'label' => \Daems\Frontend\I18n::t($this->labelKey()),
                 'color' => 'green', 'value' => $d['value'], 'change' => $d['change'],
                 'enter' => 1, 'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'];
        extract($card);
        require dirname(__DIR__, 5) . '/daems-platform/public/backstage/pages/partials/metric-card.php';
        return (string) ob_get_clean();
    }

    public function data(TenantId $t): array
    {
        return [
            'value'  => $this->count->execute($t)->value,
            'change' => $this->count->execute($t)->changePercent ?? 0,
        ];
    }
}
```

The `dirname(__DIR__, 5)` hops out of `modules/events/backend/src/Frontend/Backstage/Widgets/` to reach the platform repo's metric-card partial. If this feels brittle, an alternative is to copy `metric-card.php` into a shared package — for v1 the include path is acceptable.

If `CountUpcomingEvents` doesn't exist in the events module, search for the existing count source (`grep -rn "upcoming\|countUpcoming" backend/src/`) and use whatever class returns the count. Add a thin wrapper if nothing fits.

- [ ] **Step 2: Implement `UpcomingEventsListWidget`**

`backend/src/Frontend/Backstage/Widgets/UpcomingEventsListWidget.php`:

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Events\Frontend\Backstage\Widgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;
use DaemsModule\Events\Application\ListUpcomingEvents\ListUpcomingEvents;

final class UpcomingEventsListWidget extends Widget
{
    public function __construct(private readonly ListUpcomingEvents $list) {}

    public function id(): string                  { return 'events.upcoming_list'; }
    public function category(): WidgetCategory    { return WidgetCategory::Lists; }
    public function defaultSpan(): WidgetSpan     { return WidgetSpan::of(2); }
    public function minRole(): MinRole            { return MinRole::Admin; }
    public function module(): ?string             { return 'events'; }
    public function labelKey(): string            { return 'backstage.dashboard.widget.upcoming_events_list.label'; }
    public function descriptionKey(): string      { return 'backstage.dashboard.widget.upcoming_events_list.description'; }

    public function render(TenantId $t, User $u): string
    {
        $events = $this->list->execute($t, limit: 5);
        $items = array_map(static function ($e) {
            $title = htmlspecialchars($e->title, ENT_QUOTES, 'UTF-8');
            $date  = htmlspecialchars($e->startsAt->format('d.m.Y H:i'), ENT_QUOTES, 'UTF-8');
            return "<li><span class='date'>{$date}</span> · {$title}</li>";
        }, $events);
        $title = I18n::e($this->labelKey());
        $list = $items === [] ? '<li class="empty">' . I18n::e('backstage.dashboard.widget.upcoming_events_list.empty') . '</li>' : implode('', $items);
        return "<div class='card'><div class='card__body'><p class='card__title'>{$title}</p><ul class='dashboard-list'>{$list}</ul></div></div>";
    }

    public function data(TenantId $t): array
    {
        return ['items' => $this->list->execute($t, limit: 5)];
    }
}
```

- [ ] **Step 3: Update module's `bindings.php`** to register both widgets:

```php
// Append to the existing bindings closure in c:/laragon/www/modules/events/backend/bindings.php
$container->afterBoot(function (Container $c): void {
    $registry = $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class);
    $registry->register(new \DaemsModule\Events\Frontend\Backstage\Widgets\EventsKpiWidget(
        $c->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class), // or events-specific stats
    ));
    $registry->register(new \DaemsModule\Events\Frontend\Backstage\Widgets\UpcomingEventsListWidget(
        $c->make(\DaemsModule\Events\Application\ListUpcomingEvents\ListUpcomingEvents::class),
    ));
});
```

(If the container has no `afterBoot()` hook, the registration must run after the platform binds `WidgetRegistry`. Adjust the load order in `bootstrap/app.php` so module bindings run after `WidgetRegistry` is bound — see Task 16.)

- [ ] **Step 4: Write unit tests in module repo for both widgets**

- [ ] **Step 5: Commit in events module repo**

```bash
cd c:/laragon/www/modules/events
git add backend/src/Frontend/Backstage/Widgets backend/bindings.php backend/tests
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(events): dashboard widgets — events_kpi, upcoming_list"
```

---

## Task 14: Module widgets — projects module

**Files (in module repo `c:/laragon/www/modules/projects/`):**
- Create: `backend/src/Frontend/Backstage/Widgets/ProjectsKpiWidget.php`
- Modify: `backend/bindings.php`

- [ ] **Step 1: Implement `ProjectsKpiWidget`** — span 1, counts active projects via existing projects module use case.

- [ ] **Step 2: Update module's `bindings.php`** to register the widget (same pattern as Task 13).

- [ ] **Step 3: Write unit test**

- [ ] **Step 4: Commit in projects module repo**

```bash
cd c:/laragon/www/modules/projects
git add backend/src/Frontend/Backstage/Widgets backend/bindings.php backend/tests
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(projects): dashboard widget — projects_kpi"
```

---

## Task 15: Module widgets — forum module (6 widgets)

**Files (in module repo `c:/laragon/www/modules/forum/`):**
- Create: `backend/src/Frontend/Backstage/Widgets/ReportsKpiWidget.php` (span 1)
- Create: `backend/src/Frontend/Backstage/Widgets/PostsTodayKpiWidget.php` (span 1)
- Create: `backend/src/Frontend/Backstage/Widgets/FlaggedUsersKpiWidget.php` (span 1)
- Create: `backend/src/Frontend/Backstage/Widgets/PinnedTopicsKpiWidget.php` (span 1)
- Create: `backend/src/Frontend/Backstage/Widgets/ReportsQueueWidget.php` (span 4)
- Create: `backend/src/Frontend/Backstage/Widgets/RecentForumPostsListWidget.php` (span 2)
- Modify: `backend/bindings.php`

Data sources to use (find via grep in forum module's existing use cases):

- `ReportsKpiWidget` — `count(forum_reports WHERE resolved_at IS NULL)` — likely existing count use case or add one.
- `PostsTodayKpiWidget` — `count(forum_posts WHERE DATE(created_at) = CURDATE())`.
- `FlaggedUsersKpiWidget` — `count(distinct user_id from forum_user_warnings WHERE active = true)`.
- `PinnedTopicsKpiWidget` — `count(forum_topics WHERE pinned_at IS NOT NULL)`.
- `ReportsQueueWidget` — full reports list with reporter info, reported content excerpt, age.
- `RecentForumPostsListWidget` — last 5 posts with topic title, author, age.

- [ ] **Step 1: Implement all 6 widgets** following Task 11 pattern.

- [ ] **Step 2: Update `bindings.php` to register all 6**

- [ ] **Step 3: Write unit tests for each widget**

- [ ] **Step 4: Commit in forum module repo**

```bash
cd c:/laragon/www/modules/forum
git add backend/src/Frontend/Backstage/Widgets backend/bindings.php backend/tests
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(forum): 6 moderator dashboard widgets"
```

---

## Task 16: DI wiring — `bootstrap/app.php` (production)

**Files:**
- Modify: `bootstrap/app.php`

The container must:
1. Bind `WidgetRegistry` as a singleton.
2. Bind all core widgets and platform widgets.
3. Run module bindings AFTER `WidgetRegistry` is bound (so module widgets can register).
4. Bind use cases (`GetUserLayout`, `SaveUserLayout`, `ResetUserLayout`, `ListCatalog`).
5. Bind `UserDashboardRepositoryInterface` → `SqlUserDashboardRepository`.

- [ ] **Step 1: Read current `bootstrap/app.php` and locate the module-binding registration block**

```bash
grep -n "moduleRegistry\|registerBindings" bootstrap/app.php
```

- [ ] **Step 2: Add `WidgetRegistry` and use case bindings BEFORE module binding registration**

Insert (in the same style as existing bindings):

```php
// Dashboard widget registry — must be bound BEFORE module bindings run so
// modules can register their widgets in their bindings.php.
$container->singleton(
    \Daems\Domain\Dashboard\WidgetRegistry::class,
    static fn() => new \Daems\Domain\Dashboard\WidgetRegistry(),
);

$container->bind(
    \Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Dashboard\SqlUserDashboardRepository(
        $c->make(Connection::class),
    ),
);

$container->bind(
    \Daems\Application\Dashboard\GetUserLayout\GetUserLayout::class,
    static fn(Container $c) => new \Daems\Application\Dashboard\GetUserLayout\GetUserLayout(
        $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
        $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
    ),
);

$container->bind(
    \Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout::class,
    static fn(Container $c) => new \Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout(
        $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
        $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
        new \DateTimeImmutable(),
    ),
);

$container->bind(
    \Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout::class,
    static fn(Container $c) => new \Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout(
        $c->make(\Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class),
    ),
);

$container->bind(
    \Daems\Application\Dashboard\ListCatalog\ListCatalog::class,
    static fn(Container $c) => new \Daems\Application\Dashboard\ListCatalog\ListCatalog(
        $c->make(\Daems\Domain\Dashboard\WidgetRegistry::class),
    ),
);

// Register all core + platform widgets immediately. Modules register theirs in
// their own bindings.php (loaded just below).
$registry = $container->make(\Daems\Domain\Dashboard\WidgetRegistry::class);

// 7 core widgets
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersKpiWidget(
    $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\ApplicationsKpiWidget(
    $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MemberGrowthChartWidget(
    $container->make(\Daems\Domain\Member\MemberGrowthRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PlatformActivityChartWidget(
    $container->make(\Daems\Application\Admin\GetAdminStats\GetAdminStats::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\QuickActionsWidget());
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PendingAppsListWidget(
    $container->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\ActivityFeedWidget(
    $container->make(\Daems\Application\Dashboard\GetActivityFeed\GetActivityFeed::class),
));

// 6 platform widgets (GSA only)
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantsKpiWidget(
    $container->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\PlatformUsersKpiWidget(
    $container->make(\Daems\Domain\User\UserRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\DbSizeKpiWidget(
    $container->make(\Daems\Application\Platform\GetDbSize\GetDbSize::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\UptimeKpiWidget(
    $container->make(\Daems\Application\Platform\GetUptime\GetUptime::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantStatusGridWidget(
    $container->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\PlatformWidgets\TenantActivityChartWidget(
    $container->make(\Daems\Application\Platform\GetTenantActivity\GetTenantActivity::class),
));
```

Adjust constructor signatures to match the actual repositories/use cases used by each widget — the names above match the spec's data-source mappings in Tasks 11–12.

- [ ] **Step 3: Verify module bindings run after this block**

The existing line that calls `$moduleRegistry->registerBindings($container)` must come AFTER the widget bindings. If it currently comes before, move it.

- [ ] **Step 4: Smoke test — run the live server**

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://daems-platform.local/
```

Expected: 200. (We are not yet hitting the dashboard — just verifying the container still boots.)

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(bootstrap): wire dashboard widget registry, repos, use cases"
```

---

## Task 17: DI wiring — `tests/Support/KernelHarness.php` (test container)

**Files:**
- Modify: `tests/Support/KernelHarness.php`

Mirror the production wiring with `InMemoryUserDashboardRepository` instead of SQL.

- [ ] **Step 1: Add identical bindings to KernelHarness, swapping the repo**

```php
// In whichever method builds the container (find it via grep -n "WidgetRegistry\|GetUserLayout" tests/Support/KernelHarness.php)
$container->singleton(
    \Daems\Domain\Dashboard\WidgetRegistry::class,
    static fn() => new \Daems\Domain\Dashboard\WidgetRegistry(),
);

$container->bind(
    \Daems\Domain\Dashboard\UserDashboardRepositoryInterface::class,
    static fn() => new \Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository(),
);

// ... same use case bindings as bootstrap/app.php ...

// Register the same widgets — module widgets won't be available in harness
// because module bindings.php files don't run here, so unit-test the registry
// against fakes if a test needs a module widget.
```

- [ ] **Step 2: Run unit tests + E2E smoke**

```bash
composer test:e2e
```

Expected: all 141 tests still pass (no regression).

- [ ] **Step 3: Commit**

```bash
git add tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(harness): mirror dashboard wiring with InMemory repo"
```

---

## Task 18: API controller — `DashboardController`

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Controller/DashboardController.php`
- Modify: `routes/api.php` — add 4 routes
- Test: `tests/E2E/DashboardLayoutE2ETest.php`

- [ ] **Step 1: Write E2E tests for all 4 endpoints (failing)**

```php
<?php
declare(strict_types=1);

namespace Tests\E2E;

use Tests\Support\KernelHarness;

final class DashboardLayoutE2ETest extends \PHPUnit\Framework\TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness();
    }

    public function test_get_layout_returns_role_default_for_new_user(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $resp = $this->h->json('GET', '/api/v1/backstage/dashboard/layout', [], $token);

        self::assertSame(200, $resp->status());
        $body = $resp->json();
        self::assertTrue($body['is_default']);
        self::assertSame('admin', $body['role']);
        self::assertGreaterThan(0, count($body['layout']));
    }

    public function test_put_layout_persists_user_choice(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $resp = $this->h->json('PUT', '/api/v1/backstage/dashboard/layout', [
            'layout' => [['widget_id' => 'core.members_kpi', 'span' => 1]],
        ], $token);

        self::assertSame(204, $resp->status());

        $get = $this->h->json('GET', '/api/v1/backstage/dashboard/layout', [], $token);
        self::assertFalse($get->json()['is_default']);
        self::assertCount(1, $get->json()['layout']);
    }

    public function test_put_rejects_unknown_widget(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $resp = $this->h->json('PUT', '/api/v1/backstage/dashboard/layout', [
            'layout' => [['widget_id' => 'core.nope', 'span' => 1]],
        ], $token);

        self::assertSame(400, $resp->status());
    }

    public function test_put_rejects_gsa_widget_for_admin(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $resp = $this->h->json('PUT', '/api/v1/backstage/dashboard/layout', [
            'layout' => [['widget_id' => 'platform.tenants_kpi', 'span' => 1]],
        ], $token);

        self::assertSame(400, $resp->status());
    }

    public function test_delete_resets_to_default(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $this->h->json('PUT', '/api/v1/backstage/dashboard/layout', [
            'layout' => [['widget_id' => 'core.members_kpi', 'span' => 1]],
        ], $token);
        $resp = $this->h->json('DELETE', '/api/v1/backstage/dashboard/layout', [], $token);
        self::assertSame(204, $resp->status());

        $get = $this->h->json('GET', '/api/v1/backstage/dashboard/layout', [], $token);
        self::assertTrue($get->json()['is_default']);
    }

    public function test_catalog_filters_by_role(): void
    {
        $admin = $this->h->seedAdminInTenant('test-tenant');
        $token = $this->h->loginAs($admin);

        $resp = $this->h->json('GET', '/api/v1/backstage/dashboard/catalog', [], $token);

        self::assertSame(200, $resp->status());
        $widgetIds = array_column($resp->json(), 'widget_id');
        self::assertNotContains('platform.tenants_kpi', $widgetIds, 'admin should not see GSA widgets');
        self::assertContains('core.members_kpi', $widgetIds);
    }
}
```

(`KernelHarness::seedAdminInTenant`, `loginAs`, and `json()` already exist — match their signatures by reading the file. If a method like `json()` doesn't exist, this is the chance to add it; otherwise use the existing helpers.)

- [ ] **Step 2: Run tests — expect failure**

- [ ] **Step 3: Implement controller**

`src/Infrastructure/Adapter/Api/Controller/DashboardController.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Application\Dashboard\ListCatalog\ListCatalog;
use Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayoutInput;
use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class DashboardController
{
    public function __construct(
        private readonly GetUserLayout $get,
        private readonly SaveUserLayout $save,
        private readonly ResetUserLayout $reset,
        private readonly ListCatalog $listCatalog,
        private readonly TenantModuleResolver $modules,
    ) {}

    public function getLayout(Request $req): Response
    {
        $userId   = $this->userIdFrom($req);
        $tenantId = $this->tenantIdFrom($req);
        $role     = $this->roleFrom($req);
        $modules  = $this->modulesFor($tenantId);

        $output = $this->get->execute($userId, $tenantId, $role, $modules);

        return Response::json([
            'data' => [
                'layout'     => array_map(fn($e) => $e->toArray(), $output->layout()),
                'is_default' => $output->isDefault(),
                'role'       => $role->value,
            ],
        ]);
    }

    public function putLayout(Request $req): Response
    {
        $body = $req->jsonBody();
        $rawLayout = $body['layout'] ?? null;
        if (!is_array($rawLayout)) {
            return Response::json(['error' => ['code' => 'invalid_layout', 'message' => '`layout` must be an array']], 400);
        }

        try {
            $this->save->execute(new SaveUserLayoutInput(
                $this->userIdFrom($req),
                $this->tenantIdFrom($req),
                $this->roleFrom($req),
                $this->modulesFor($this->tenantIdFrom($req)),
                $rawLayout,
            ));
        } catch (InvalidLayout $e) {
            return Response::json(['error' => ['code' => 'invalid_layout', 'message' => $e->getMessage()]], 400);
        }
        return Response::noContent();
    }

    public function deleteLayout(Request $req): Response
    {
        $this->reset->execute($this->userIdFrom($req), $this->tenantIdFrom($req));
        return Response::noContent();
    }

    public function getCatalog(Request $req): Response
    {
        $tenantId = $this->tenantIdFrom($req);
        $role     = $this->roleFrom($req);
        $modules  = $this->modulesFor($tenantId);

        $current = $this->get->execute($this->userIdFrom($req), $tenantId, $role, $modules)->layout();
        $items = $this->listCatalog->execute($role, $modules, $current);

        return Response::json([
            'data' => array_map(fn($i) => $i->toArray(), $items),
        ]);
    }

    private function userIdFrom(Request $req): UserId
    {
        // AuthMiddleware stores the resolved user in the request attributes.
        // Pattern matches AdminController::stats — verify by reading
        // src/Infrastructure/Adapter/Api/Controller/AdminController.php and
        // copy the exact attribute key used (likely 'user_id' or 'auth_user').
        $raw = (string) $req->attribute('auth_user_id');
        return UserId::fromString($raw);
    }

    private function tenantIdFrom(Request $req): TenantId
    {
        // TenantContextMiddleware stashes the resolved Tenant entity.
        /** @var \Daems\Domain\Tenant\Tenant $tenant */
        $tenant = $req->attribute('tenant');
        return $tenant->id();
    }

    private function roleFrom(Request $req): MinRole
    {
        // user_tenants.role for the active tenant + is_platform_admin override.
        // The middleware should have already resolved this — match the key used
        // in AdminController.
        if ((bool) $req->attribute('is_platform_admin') === true) {
            return MinRole::Gsa;
        }
        $tenantRole = (string) $req->attribute('tenant_role'); // 'admin'|'moderator'|'member'
        return match ($tenantRole) {
            'admin'     => MinRole::Admin,
            'moderator' => MinRole::Moderator,
            default     => MinRole::Member,
        };
    }

    /** @return list<string> */
    private function modulesFor(TenantId $t): array
    {
        return $this->modules->enabledModulesFor($t);
    }
}
```

If `Request::attribute()` doesn't exist or middleware uses a different stash mechanism, read `AdminController::stats()` and use the exact same lookup. The keys (`auth_user_id`, `tenant`, `is_platform_admin`, `tenant_role`) are placeholders — verify them against the middleware code before this task is done.

- [ ] **Step 4: Add routes in `routes/api.php`**

After the existing `backstage/stats` block:

```php
$router->get('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
    return $container->make(DashboardController::class)->getLayout($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->put('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
    return $container->make(DashboardController::class)->putLayout($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->delete('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
    return $container->make(DashboardController::class)->deleteLayout($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/dashboard/catalog', static function (Request $req) use ($container): Response {
    return $container->make(DashboardController::class)->getCatalog($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Add `use Daems\Infrastructure\Adapter\Api\Controller\DashboardController;` at the top.

- [ ] **Step 5: Bind DashboardController in BOTH `bootstrap/app.php` and `KernelHarness`**

```php
$container->bind(
    \Daems\Infrastructure\Adapter\Api\Controller\DashboardController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\DashboardController(
        $c->make(\Daems\Application\Dashboard\GetUserLayout\GetUserLayout::class),
        $c->make(\Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout::class),
        $c->make(\Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout::class),
        $c->make(\Daems\Application\Dashboard\ListCatalog\ListCatalog::class),
        $c->make(\Daems\Domain\Tenant\TenantModuleResolver::class),
    ),
);
```

- [ ] **Step 6: Run E2E tests — expect pass**

```bash
composer test:e2e
```

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/DashboardController.php routes/api.php bootstrap/app.php tests/Support/KernelHarness.php tests/E2E/DashboardLayoutE2ETest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api): DashboardController + 4 layout/catalog routes"
```

---

## Task 19: Isolation test — cross-tenant layout isolation

**Files:**
- Create: `tests/Isolation/UserDashboardIsolationTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Tests\Isolation;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Infrastructure\Dashboard\SqlUserDashboardRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class UserDashboardIsolationTest extends IsolationTestCase
{
    public function test_user_layout_in_tenant_a_invisible_to_tenant_b(): void
    {
        $repo = new SqlUserDashboardRepository($this->container->make(Connection::class));

        $userId   = $this->seedUser('admin@example.com');
        $tenantA  = $this->seedTenant('tenant-a');
        $tenantB  = $this->seedTenant('tenant-b');

        $repo->save(new UserDashboard(
            $userId, $tenantA,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        self::assertNotNull($repo->findFor($userId, $tenantA));
        self::assertNull($repo->findFor($userId, $tenantB), 'tenant-A layout must not leak to tenant-B');
    }

    public function test_delete_only_affects_target_tenant(): void
    {
        $repo = new SqlUserDashboardRepository($this->container->make(Connection::class));
        $userId   = $this->seedUser('admin@example.com');
        $tenantA  = $this->seedTenant('tenant-a');
        $tenantB  = $this->seedTenant('tenant-b');

        $repo->save(new UserDashboard($userId, $tenantA, [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))], new \DateTimeImmutable()));
        $repo->save(new UserDashboard($userId, $tenantB, [new LayoutEntry('core.activity_feed', WidgetSpan::of(2))], new \DateTimeImmutable()));

        $repo->delete($userId, $tenantA);

        self::assertNull($repo->findFor($userId, $tenantA));
        self::assertNotNull($repo->findFor($userId, $tenantB));
    }
}
```

- [ ] **Step 2: Run test**

```bash
vendor/bin/phpunit tests/Isolation/UserDashboardIsolationTest.php
```

Expected: green.

- [ ] **Step 3: Commit**

```bash
git add tests/Isolation/UserDashboardIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/isolation): cross-tenant user dashboard isolation"
```

---

## Task 20: i18n keys — widget labels + edit-mode chrome

**Files:**
- Modify: `lang/en_GB.php`
- Modify: `lang/fi_FI.php`
- Modify: `lang/sw_TZ.php`

Keys to add (each in 3 locales):

```
backstage.dashboard.title                            Dashboard / Etusivu / Dashibodi (already exists — verify)
backstage.dashboard.edit_mode                        Edit dashboard / Muokkaa / Hariri
backstage.dashboard.edit_done                        Done / Valmis / Maliza
backstage.dashboard.edit_cancel                      Cancel / Peruuta / Ghairi
backstage.dashboard.edit_reset                       Reset to default / Palauta oletus / Rudisha
backstage.dashboard.edit_reset_confirm               Reset your dashboard? Custom layout will be lost. / ... / ...
backstage.dashboard.add_widget                       + Add widget / + Lisää widget / + Ongeza widget
backstage.dashboard.catalog.title                    Add widget / Lisää widget / Ongeza widget
backstage.dashboard.catalog.search                   Search widgets… / Hae widgettejä… / Tafuta widget…
backstage.dashboard.catalog.category.all             All / Kaikki / Yote
backstage.dashboard.catalog.category.numbers         Numbers / Numerot / Nambari
backstage.dashboard.catalog.category.lists           Lists / Listat / Orodha
backstage.dashboard.catalog.category.charts          Charts / Kaaviot / Chati
backstage.dashboard.catalog.category.actions         Actions / Toiminnot / Vitendo
backstage.dashboard.catalog.category.activity        Activity / Aktiviteetti / Shughuli
backstage.dashboard.catalog.in_layout                Already added / Jo lisätty / Tayari imeongezwa

# Widget labels and descriptions — one pair per widget
backstage.dashboard.widget.members_kpi.label        Members / Jäsenet / Wanachama
backstage.dashboard.widget.members_kpi.description  Total members count with 30-day trend / ... / ...
# (continue for all 13 platform-owned widgets and the 9 module widgets that ship in v1)
```

- [ ] **Step 1: Add all keys to all 3 lang files**

- [ ] **Step 2: Run module manifest validator** (the boot-time validator from memory `project_module_registry`):

```bash
php -r "require 'bootstrap/app.php';"
```

Expected: no errors. (If the manifest validator complains about missing keys, add them.)

- [ ] **Step 3: Commit**

```bash
git add lang/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(i18n): dashboard widget labels + edit-mode chrome (3 locales)"
```

---

## Task 21: Frontend — rewrite `pages/index.php`

**Files:**
- Modify: `public/backstage/pages/index.php`
- Create: `public/backstage/pages/partials/dashboard-grid.php`

The new page:
1. Fetches resolved layout via `ApiClient::get('/backstage/dashboard/layout')`.
2. For each `LayoutEntry`, looks up the widget via the registry (server-side container access through `$GLOBALS['daems_backstage_container']` like the sidebar does in `layout.php`).
3. Renders each widget into a grid cell with `grid-column: span N`.
4. Shows a pencil button that toggles `?edit=1` mode.

- [ ] **Step 1: Replace `index.php` body**

```php
<?php
/** Admin Dashboard — widget grid (post-refactor). */

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.dashboard';
$activePage  = 'dashboard';
$breadcrumbs = [];

$container = $GLOBALS['daems_backstage_container'] ?? null;
$tenant    = $GLOBALS['daems_backstage_tenant']    ?? null;
$user      = $_SESSION['user'] ?? null;

$editMode = isset($_GET['edit']);
$layoutEntries = [];
$widgets = [];

if ($container !== null && $tenant !== null && $user !== null) {
    /** @var \Daems\Domain\Tenant\TenantModuleResolver $modules */
    $modules = $container->make(\Daems\Domain\Tenant\TenantModuleResolver::class);
    $registry = $container->make(WidgetRegistry::class);
    $useCase  = $container->make(GetUserLayout::class);

    $userId   = \Daems\Domain\User\UserId::fromString((string) $user['id']);
    $tenantId = $tenant->id();
    $role     = !empty($user['is_platform_admin']) ? MinRole::Gsa
              : (($user['role'] ?? 'admin') === 'admin' ? MinRole::Admin : MinRole::Moderator);
    $enabledModules = $modules->enabledModulesFor($tenantId);

    $output = $useCase->execute($userId, $tenantId, $role, $enabledModules);
    $layoutEntries = $output->layout();

    foreach ($layoutEntries as $entry) {
        $widgets[$entry->widgetId()] = $registry->find($entry->widgetId());
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.dashboard.title') ?></h1>
        <p class="page-header__subtitle"><?= I18n::e('backstage.dashboard.subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <?php if ($editMode): ?>
            <a href="?" class="btn btn--ghost"><?= I18n::e('backstage.dashboard.edit_cancel') ?></a>
            <button type="button" class="btn btn--primary" id="dashboard-save-done"><?= I18n::e('backstage.dashboard.edit_done') ?></button>
        <?php else: ?>
            <a href="?edit=1" class="btn btn--ghost" id="dashboard-edit-toggle">
                <i class="bi bi-pencil"></i>
                <?= I18n::e('backstage.dashboard.edit_mode') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-grid <?= $editMode ? 'is-editing' : '' ?>" id="dashboard-grid">
    <?php foreach ($layoutEntries as $entry):
        $w = $widgets[$entry->widgetId()];
    ?>
    <div class="dashboard-cell" style="grid-column: span <?= $entry->span()->value() ?>;" data-widget-id="<?= htmlspecialchars($entry->widgetId(), ENT_QUOTES, 'UTF-8') ?>" data-span="<?= $entry->span()->value() ?>">
        <?php if ($editMode): ?>
        <button type="button" class="dashboard-cell__handle" title="<?= I18n::e('backstage.dashboard.drag_handle') ?>">⋮⋮</button>
        <button type="button" class="dashboard-cell__remove" data-widget-id="<?= htmlspecialchars($entry->widgetId(), ENT_QUOTES, 'UTF-8') ?>" title="<?= I18n::e('backstage.dashboard.remove') ?>">✕</button>
        <?php endif; ?>
        <?= $w->render($tenantId, \Daems\Domain\User\User::reconstitute(/* user id from session */)) ?>
    </div>
    <?php endforeach; ?>

    <?php if ($editMode): ?>
    <button type="button" class="dashboard-add-widget" id="dashboard-add-widget" style="grid-column: span 4;">
        <?= I18n::e('backstage.dashboard.add_widget') ?>
    </button>
    <?php endif; ?>
</div>

<?php if ($editMode): ?>
<button type="button" class="dashboard-reset" id="dashboard-reset"><?= I18n::e('backstage.dashboard.edit_reset') ?></button>
<?php endif; ?>

<script>
window.DaemsDashboard = {
    editMode: <?= $editMode ? 'true' : 'false' ?>,
    layoutEndpoint: '/api/backstage/dashboard/layout',
    catalogEndpoint: '/api/backstage/dashboard/catalog',
};
</script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/layout.php';
```

(`User::reconstitute()` — adjust to whatever factory exists for building a User from a session — read `src/Domain/User/User.php`.)

- [ ] **Step 2: Manual smoke — start dev server, log in, visit `/backstage`**

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://daems.local/backstage/
```

Expected: 200. (Without auth this will redirect; with auth via Laragon you should see the new grid.)

- [ ] **Step 3: Commit**

```bash
git add public/backstage/pages/index.php public/backstage/pages/partials/dashboard-grid.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Refactor(backstage): dashboard renders via widget registry + role-aware layout"
```

---

## Task 22: Vendor SortableJS

**Files:**
- Create: `public/backstage/assets/js/vendor/sortable.min.js`

- [ ] **Step 1: Download SortableJS 1.15 (latest stable, MIT)**

```bash
curl -sS -L -o public/backstage/assets/js/vendor/sortable.min.js https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js
```

- [ ] **Step 2: Verify**

```bash
ls -la public/backstage/assets/js/vendor/sortable.min.js
head -c 200 public/backstage/assets/js/vendor/sortable.min.js
```

Expected: ~25 KB, starts with `/**!\n * Sortable 1.15.x ...`.

- [ ] **Step 3: Add to layout's `<script>` block**

Modify `public/backstage/pages/layout.php` head section (search for `daems-backstage.js`):

```php
<script src="/backstage/assets/js/vendor/sortable.min.js" defer></script>
```

- [ ] **Step 4: Commit**

```bash
git add public/backstage/assets/js/vendor/sortable.min.js public/backstage/pages/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(assets): vendor SortableJS 1.15.6 for dashboard drag-drop"
```

---

## Task 23: Frontend — `dashboard-edit.js`

**Files:**
- Create: `public/backstage/assets/js/dashboard-edit.js`

This script handles edit-mode interactions: drag-drop reorder, hide widget, save layout, reset, open catalog modal.

- [ ] **Step 1: Implement the script**

```javascript
/**
 * Dashboard edit mode — drag-drop reorder, hide widget, add from catalog.
 * Activated only when window.DaemsDashboard.editMode === true.
 */
(function () {
    'use strict';

    if (!window.DaemsDashboard || !window.DaemsDashboard.editMode) return;

    var grid = document.getElementById('dashboard-grid');
    if (!grid) return;

    var cfg = window.DaemsDashboard;

    function readLayout() {
        return Array.prototype.map.call(grid.querySelectorAll('.dashboard-cell'), function (cell) {
            return {
                widget_id: cell.getAttribute('data-widget-id'),
                span: parseInt(cell.getAttribute('data-span') || '1', 10),
            };
        });
    }

    function saveLayout() {
        return fetch(cfg.layoutEndpoint, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ layout: readLayout() }),
            credentials: 'same-origin',
        });
    }

    // SortableJS: reorder cells, save on drop
    if (window.Sortable) {
        Sortable.create(grid, {
            handle: '.dashboard-cell__handle',
            animation: 150,
            onEnd: saveLayout,
        });
    }

    // Hide widget X button
    grid.addEventListener('click', function (e) {
        var btn = e.target.closest('.dashboard-cell__remove');
        if (!btn) return;
        var cell = btn.closest('.dashboard-cell');
        if (cell) {
            cell.remove();
            saveLayout();
        }
    });

    // Reset to default
    var resetBtn = document.getElementById('dashboard-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (!confirm(resetBtn.getAttribute('data-confirm') || 'Reset?')) return;
            fetch(cfg.layoutEndpoint, { method: 'DELETE', credentials: 'same-origin' })
                .then(function () { window.location.search = ''; });
        });
    }

    // Done button — exit edit mode
    var doneBtn = document.getElementById('dashboard-save-done');
    if (doneBtn) {
        doneBtn.addEventListener('click', function () {
            window.location.search = '';
        });
    }

    // Open catalog modal
    var addBtn = document.getElementById('dashboard-add-widget');
    if (addBtn) {
        addBtn.addEventListener('click', openCatalog);
    }

    function openCatalog() {
        fetch(cfg.catalogEndpoint, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                renderCatalogModal(resp.data || []);
            });
    }

    function renderCatalogModal(items) {
        // Build modal markup, attach to document.body, wire close + add handlers.
        // On add: append a new .dashboard-cell with the widget's render output
        // (server-rendered via a separate /widget/{id}/render endpoint, or
        // simpler: reload the page so server renders the new layout).
        // v1 ships the simpler reload approach.
        var modal = document.createElement('div');
        modal.className = 'dashboard-catalog-modal';
        modal.innerHTML = buildCatalogHtml(items);
        document.body.appendChild(modal);
        modal.querySelector('.dashboard-catalog-modal__close').addEventListener('click', function () {
            modal.remove();
        });
        modal.addEventListener('click', function (e) {
            var card = e.target.closest('.dashboard-catalog-modal__item');
            if (!card || card.classList.contains('is-locked') || card.classList.contains('is-in-layout')) return;
            var widgetId = card.getAttribute('data-widget-id');
            var span = parseInt(card.getAttribute('data-span') || '1', 10);
            // Append to layout, save, reload so server renders the new widget
            var current = readLayout();
            current.push({ widget_id: widgetId, span: span });
            fetch(cfg.layoutEndpoint, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ layout: current }),
                credentials: 'same-origin',
            }).then(function () { window.location.reload(); });
        });
    }

    function buildCatalogHtml(items) {
        var grouped = {};
        items.forEach(function (i) {
            (grouped[i.category] = grouped[i.category] || []).push(i);
        });
        var html = '<div class="dashboard-catalog-modal__inner">';
        html += '<div class="dashboard-catalog-modal__header">';
        html += '<h3>Add widget</h3>';
        html += '<button type="button" class="dashboard-catalog-modal__close">✕</button>';
        html += '</div>';
        html += '<div class="dashboard-catalog-modal__body">';
        Object.keys(grouped).sort().forEach(function (cat) {
            html += '<div class="dashboard-catalog-modal__category"><h4>' + cat + '</h4><div class="dashboard-catalog-modal__grid">';
            grouped[cat].forEach(function (i) {
                var classes = ['dashboard-catalog-modal__item'];
                if (i.in_layout) classes.push('is-in-layout');
                if (i.locked_reason) classes.push('is-locked');
                html += '<div class="' + classes.join(' ') + '" data-widget-id="' + i.widget_id + '" data-span="' + i.default_span + '">';
                html += '<div class="title">' + i.label_key + '</div>';
                html += '<div class="meta">span ' + i.default_span + (i.module ? ' · ' + i.module : '') + '</div>';
                if (i.in_layout) html += '<div class="status">✓ added</div>';
                if (i.locked_reason) html += '<div class="status">⊘ ' + i.locked_reason + '</div>';
                html += '</div>';
            });
            html += '</div></div>';
        });
        html += '</div></div>';
        return html;
    }
})();
```

- [ ] **Step 2: Add `<script src="/backstage/assets/js/dashboard-edit.js" defer></script>`**

In `public/backstage/pages/index.php` after the `<script>` JSON config block.

- [ ] **Step 3: Manual browser smoke**

Visit `http://daems.local/backstage/?edit=1`, drag a card, drop it elsewhere, verify it persists on reload. Click ✕ on a card, verify it disappears and stays gone after reload. Click "+ Add widget", verify modal opens with categories. Click a card to add — page should reload with the new widget visible.

- [ ] **Step 4: Commit**

```bash
git add public/backstage/assets/js/dashboard-edit.js public/backstage/pages/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(frontend): dashboard-edit.js — drag-drop, hide, catalog modal"
```

---

## Task 24: Edit-mode CSS

**Files:**
- Modify: `public/backstage/assets/css/daems-backstage.css`

Add CSS for: `.dashboard-grid`, `.dashboard-cell`, `.dashboard-cell__handle`, `.dashboard-cell__remove`, `.dashboard-add-widget`, `.dashboard-reset`, `.dashboard-catalog-modal*`.

Key rules per memory: **no translateY/scale hover animations** — use border/background/opacity changes only.

- [ ] **Step 1: Append the styles**

```css
/* Dashboard grid — 4-column layout with widget spans */
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-4);
}

.dashboard-cell {
  position: relative;
  background: var(--surface-dark);
  border: var(--border-default);
  border-radius: var(--radius-md);
  overflow: hidden;
}

/* Edit mode wrap — yellow tint to signal "you are editing" */
.dashboard-grid.is-editing {
  padding: var(--space-3);
  border: 2px dashed var(--status-warning);
  border-radius: var(--radius-md);
  background: var(--status-warning-subtle);
}

.dashboard-grid.is-editing .dashboard-cell {
  cursor: grab;
}

.dashboard-cell__handle,
.dashboard-cell__remove {
  position: absolute;
  top: var(--space-2);
  width: 22px;
  height: 22px;
  display: none;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  background: var(--surface-light);
  border: 1px solid var(--surface-border);
  font-size: 11px;
  z-index: 5;
}

.dashboard-cell__handle { left: var(--space-2); color: var(--text-muted); }
.dashboard-cell__remove { right: var(--space-2); color: var(--status-error); }

.dashboard-grid.is-editing .dashboard-cell__handle,
.dashboard-grid.is-editing .dashboard-cell__remove {
  display: inline-flex;
}

.dashboard-cell__remove:hover {
  background: var(--status-error-subtle);
  border-color: var(--status-error);
}

.dashboard-add-widget {
  padding: var(--space-4);
  border: 2px dashed var(--brand-primary);
  background: var(--brand-primary-subtle);
  color: var(--brand-primary);
  font-weight: var(--weight-semibold);
  border-radius: var(--radius-md);
}

.dashboard-add-widget:hover {
  background: var(--brand-primary-muted);
}

.dashboard-reset {
  margin-top: var(--space-4);
  background: transparent;
  color: var(--status-error);
  text-decoration: underline;
  font-size: var(--text-small);
}

/* Catalog modal */
.dashboard-catalog-modal {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: center;
}

.dashboard-catalog-modal__inner {
  width: min(720px, 90vw);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  background: var(--surface-dark);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xl);
  overflow: hidden;
}

.dashboard-catalog-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4);
  border-bottom: var(--border-default);
}

.dashboard-catalog-modal__body {
  padding: var(--space-4);
  overflow-y: auto;
}

.dashboard-catalog-modal__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-2);
}

.dashboard-catalog-modal__item {
  padding: var(--space-3);
  border: var(--border-default);
  border-radius: var(--radius-md);
  background: var(--surface-medium);
  font-size: var(--text-small);
  cursor: pointer;
}

.dashboard-catalog-modal__item:hover {
  border-color: var(--brand-primary);
  background: var(--brand-primary-subtle);
}

.dashboard-catalog-modal__item.is-in-layout {
  opacity: 0.5;
  cursor: not-allowed;
}

.dashboard-catalog-modal__item.is-locked {
  opacity: 0.5;
  cursor: not-allowed;
  border-style: dashed;
}
```

- [ ] **Step 2: Browser check** — visit `?edit=1`, confirm the visual edit-mode chrome.

- [ ] **Step 3: Commit**

```bash
git add public/backstage/assets/css/daems-backstage.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(css): dashboard edit-mode + catalog modal styles"
```

---

## Task 25: Backstage proxy handler

**Files:**
- Create: `public/backstage/api/dashboard.php`

This proxies `/api/backstage/dashboard/*` from the tenant frontend (society) to the platform's `/api/v1/backstage/dashboard/*` via `ApiClient`.

- [ ] **Step 1: Implement the proxy**

```php
<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (
    !$u
    || (
        empty($u['is_platform_admin'])
        && ($u['role'] ?? '') !== 'admin'
        && ($u['role'] ?? '') !== 'global_system_administrator'
        && ($u['role'] ?? '') !== 'moderator'
    )
) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$op     = (string) ($_GET['op'] ?? 'layout'); // layout | catalog

try {
    if ($op === 'layout') {
        if ($method === 'GET') {
            echo json_encode(ApiClient::get('/backstage/dashboard/layout'));
        } elseif ($method === 'PUT') {
            $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
            ApiClient::put('/backstage/dashboard/layout', $body);
            http_response_code(204);
        } elseif ($method === 'DELETE') {
            ApiClient::delete('/backstage/dashboard/layout');
            http_response_code(204);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']);
        }
    } elseif ($op === 'catalog' && $method === 'GET') {
        echo json_encode(ApiClient::get('/backstage/dashboard/catalog'));
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'internal', 'message' => $e->getMessage()]);
}
```

(`ApiClient::put()` and `delete()` may not exist — check `src/Frontend/ApiClient.php`. If only `get()` and `post()` exist, add the missing methods in this task.)

- [ ] **Step 2: Wire URL routing**

The frontend calls `/api/backstage/dashboard/layout`. The router file at `c:/laragon/www/sites/daem-society/public/index.php` already has a block for `str_starts_with($uri, '/api/backstage/')` (line 387 area) that includes `c:/laragon/www/daems-platform/public/backstage/api-router.php`. That router needs an entry for `dashboard`:

```php
// In public/backstage/api-router.php
if (str_starts_with($uri, '/api/backstage/dashboard/')) {
    require __DIR__ . '/api/dashboard.php';
    exit;
}
```

- [ ] **Step 3: Manual smoke**

```bash
curl -sS -X GET -b /tmp/c.txt "http://daems.local/api/backstage/dashboard/layout"
```

Expected: 200 with `{ data: { layout: [...], is_default: true, role: "admin" } }` after auth.

- [ ] **Step 4: Commit**

```bash
git add public/backstage/api/dashboard.php public/backstage/api-router.php src/Frontend/ApiClient.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): dashboard proxy handler + ApiClient PUT/DELETE methods"
```

---

## Task 26: Decommission `/backstage/stats` page-level usage

**Files:**
- Modify: `public/backstage/pages/index.php` (already done in Task 21 — verify the old `ApiClient::get('/backstage/stats')` line is gone)
- Possibly modify: `routes/api.php` (only if no other consumers)

- [ ] **Step 1: Grep for other consumers of `/backstage/stats`**

```bash
grep -rn "backstage/stats" --include="*.php" --include="*.js" .
```

Expected output should now show ONLY:
- `routes/api.php` (the route definition)
- Possibly some test stubs

If nothing real uses it outside `routes/api.php`, the route can be removed in this task. If other pages still hit it (e.g., a notification widget), leave it alone — separate cleanup.

- [ ] **Step 2: If safe to remove, delete the route from `routes/api.php`**

Search for the `/api/v1/backstage/stats` line and remove it. Same for `/api/v1/admin/stats` if no consumer.

- [ ] **Step 3: Run all tests**

```bash
composer test:all
```

Expected: green. (If a test stubs `/backstage/stats` directly, update it to use the dashboard endpoints instead.)

- [ ] **Step 4: Commit (if changes were safe to make)**

```bash
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(api): /backstage/stats — replaced by per-widget data() endpoints"
```

(If the route still has other consumers, skip this task and leave a TODO comment in the spec's "Out-of-scope" list.)

---

## Task 27: Final verification

- [ ] **Step 1: PHPStan level 9**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 2: Full test suite**

```bash
composer test:all
```

Expected: all green.

- [ ] **Step 3: Browser smoke — admin role**

1. Open `http://daems.local/backstage/` as an admin user.
2. Verify all 8 admin-default widgets render.
3. Click pencil → verify edit mode visual cues (yellow border, drag handles, X buttons).
4. Drag a widget to reorder — verify it persists on reload.
5. Click X on a widget — verify it disappears.
6. Click "+ Add widget" → verify modal opens with categories.
7. Click an available widget — verify reload adds it.
8. Click "Done" → verify edit mode exits.
9. Click pencil again, click "Reset to default" → confirm dialog → verify reset to admin default.

- [ ] **Step 4: Browser smoke — moderator role**

Log in as a user with `user_tenants.role = 'moderator'`. Verify the moderator default renders (4 forum KPIs + reports queue + recent posts + activity feed) and that the catalog hides admin-only widgets.

- [ ] **Step 5: Browser smoke — GSA role**

Log in as a GSA. Verify GSA default renders (platform KPIs + tenant grid + cross-tenant feed) and that the catalog includes platform widgets.

- [ ] **Step 6: Browser smoke — disabled module**

In GSA tenant management, disable the `events` module for the daems tenant. Reload `/backstage/` as the daems admin — verify `events_kpi` and `upcoming_events_list` are silently dropped from the layout. The catalog should also hide them.

- [ ] **Step 7: Report SHAs and wait for "pushaa"**

Per CLAUDE.md, never auto-push. Run:

```bash
git log --oneline dev..HEAD
```

Print the SHAs and wait for explicit "pushaa" before pushing the branch.

---

## Summary of changes

| Layer | Files added | Files modified |
|-------|-------------|----------------|
| Migration | 1 | 1 (IsolationTestCase) |
| Domain | 9 | 0 |
| Application | 11 | 0 |
| Infrastructure | 16 (incl. 13 widgets) | 0 |
| Frontend | 4 (DefaultLayouts + 2 partials + dashboard-edit.js) | 1 (layout.php) |
| API | 1 controller + 4 routes | routes/api.php, public/backstage/api-router.php, public/backstage/api/dashboard.php (new) |
| DI wiring | 0 | bootstrap/app.php, KernelHarness.php |
| i18n | 0 | 3 lang files |
| CSS | 0 | daems-backstage.css |
| Tests | 11+ test files | 0 |
| Module repos | 9 widgets across 3 repos | 3 bindings.php files |

Total: ~50 new files, ~10 modified.

## Self-review checklist

After completing the plan, verify:

- [ ] Every spec section maps to ≥1 task. Cross-check the spec's "Architecture", "API contract", "Frontend", "Module integration", "Validation rules", "Migration of current dashboard", "Testing", "DI wiring" sections.
- [ ] No "TBD" / "TODO" / "fill in" placeholders. Check by `grep -rn "TBD\|TODO\|FIXME" docs/superpowers/plans/2026-05-10-dashboard-widgets-customization.md`.
- [ ] Type/method names consistent: `Widget::id()` not `Widget::getId()` everywhere; `LayoutEntry::widgetId()` not `widgetID()`; `MinRole::Admin` not `MinRole::ADMIN`.
- [ ] `WidgetSpan::of()` not `::create()` everywhere.
- [ ] `UserDashboardRepositoryInterface` methods: `findFor`, `save`, `delete` — all callers use the same names.
- [ ] Both `bootstrap/app.php` AND `tests/Support/KernelHarness.php` get the same bindings (Tasks 16 and 17).
- [ ] All commits use Dev Team identity. Never auto-push.
- [ ] All module-repo work (Tasks 13–15) commits in those repos, not the platform repo.
