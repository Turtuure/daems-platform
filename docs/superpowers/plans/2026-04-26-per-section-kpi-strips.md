# Per-Section KPI Strips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a 4-up KPI strip with ApexCharts sparklines to five backstage pages (Members, Applications, Events, Projects, Notifications), reusing the Phase 1 design system and the Phase 2 backend pattern.

**Architecture:** Five independent sub-phases (3-7), each producing one new `GET /api/v1/backstage/{section}/stats` endpoint, one `ListXStats` use case, repository slice methods on existing repos, BOTH-wire DI bindings, four test layers, and a frontend KPI strip integration with a section-specific stats JS file. Each phase ships in its own commit chain. The page bodies of all five sections are untouched in this iteration — the strip sits above the existing page header and content.

**Tech Stack:** PHP 8.1+ (Clean Architecture), MySQL 8.4, PHPStan level 9, PHPUnit, vanilla JS, ApexCharts, existing `daems-backstage-system.css/js` (Phase 1 deliverables), existing `kpi-card.php` partial (Phase 1).

**Spec:** `docs/superpowers/specs/2026-04-26-per-section-kpi-strips-design.md`

**Phase order:**
- Phase 3 — Members
- Phase 4 — Applications
- Phase 5 — Events
- Phase 6 — Projects
- Phase 7 — Notifications

**Reference templates** (read these once before starting Phase 3):
- Phase 1 use case shape: `src/Application/Insight/ListInsightStats/ListInsightStats.php`
- Phase 2 multi-repo use case: `src/Application/Backstage/Forum/ListForumStats/ListForumStats.php`
- Phase 2 controller method: `BackstageController::statsForum` (`:1199`)
- Phase 1 controller method: `BackstageController::statsInsights` (`:1540`)
- Phase 2 frontend strip: `daem-society/public/pages/backstage/forum/forum-kpi-strip.js`
- Proxy with `op=stats`: `daem-society/public/api/backstage/forum.php` (and `insights.php`)
- DI binding examples: `bootstrap/app.php:981` (forum stats), `tests/Support/KernelHarness.php:414` (forum stats)

**Per-phase preflight (run once before starting any phase):**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana \
  -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**Per-phase final verification:**

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
composer analyse
```

**Commit identity (every commit):**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."
```

**Never push.** Report SHA, wait for explicit "pushaa".

---

## Phase 3 — Members KPI strip

KPI map:

| `kpi_id` | Value | Sparkline | Variant |
|----------|-------|-----------|---------|
| `total_members`  | `COUNT(*) FROM user_tenants WHERE tenant_id=? AND left_at IS NULL` | `joined_at` daily 30d | `blue` |
| `new_members`    | Same WHERE + `joined_at >= NOW() - 30 DAY` | Same series | `green` |
| `supporters`     | `... JOIN users ON membership_type='supporter'` | `joined_at` filtered to supporters, daily 30d | `purple` |
| `inactive`       | `... JOIN users ON membership_status='inactive'` | `member_status_audit.created_at` daily 30d where `new_status='inactive'` | `amber` |

**Repos involved:** `UserTenantRepositoryInterface`, `MemberStatusAuditRepositoryInterface`. Add stats slice methods to both.

### Task 3.1: UserTenantRepository stats slice + integration test

**Files:**
- Modify: `src/Domain/Tenant/UserTenantRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantRepository.php`
- Modify (or create if no in-memory exists yet): `src/Infrastructure/Adapter/Persistence/Memory/InMemoryUserTenantRepository.php` (verify name; if absent, the InMemory variant lives where the Forum InMemory analogues live — search `tests/Support` first)
- Test: `tests/Integration/UserTenantStatsTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class UserTenantStatsTest extends MigrationTestCase
{
    public function test_membership_stats_for_tenant_returns_expected_shape(): void
    {
        $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
        $this->seedTenant($tenantId, 'daems');

        // 3 members joined in last 30d, 1 supporter (membership_type), 1 inactive (membership_status)
        $u1 = $this->seedUser('alice@example.test', membershipType: 'individual', membershipStatus: 'active');
        $u2 = $this->seedUser('bob@example.test',   membershipType: 'supporter',  membershipStatus: 'active');
        $u3 = $this->seedUser('carol@example.test', membershipType: 'individual', membershipStatus: 'inactive');

        $repo = new SqlUserTenantRepository($this->db);
        $repo->attach($u1, $tenantId, UserTenantRole::Member);
        $repo->attach($u2, $tenantId, UserTenantRole::Supporter);
        $repo->attach($u3, $tenantId, UserTenantRole::Member);

        $stats = $repo->membershipStatsForTenant($tenantId);

        self::assertSame(3, $stats['total_members']['value']);
        self::assertSame(3, $stats['new_members']['value']);
        self::assertSame(1, $stats['supporters']['value']);
        self::assertSame(1, $stats['inactive']['value']);

        self::assertCount(30, $stats['total_members']['sparkline']);
        self::assertSame(['date', 'value'], array_keys($stats['total_members']['sparkline'][0]));
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
vendor/bin/phpunit --testsuite Integration --filter UserTenantStatsTest
```

Expected: FAIL with `Call to undefined method ... membershipStatsForTenant()`.

- [ ] **Step 3: Add the interface method**

In `src/Domain/Tenant/UserTenantRepositoryInterface.php`, add:

```php
    /**
     * @return array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function membershipStatsForTenant(TenantId $tenantId): array;
```

- [ ] **Step 4: Implement in SqlUserTenantRepository**

Add (mirror Phase 1 `SqlInsightRepository::statsForTenant` for the zero-fill pattern):

```php
    public function membershipStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $tid = $tenantId->value;

        $totalRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM user_tenants WHERE tenant_id = ? AND left_at IS NULL',
            [$tid],
        );
        $newRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM user_tenants WHERE tenant_id = ? AND left_at IS NULL AND joined_at >= (NOW() - INTERVAL 30 DAY)',
            [$tid],
        );
        $suppRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM user_tenants ut JOIN users u ON u.id = ut.user_id
             WHERE ut.tenant_id = ? AND ut.left_at IS NULL AND u.membership_type = ?',
            [$tid, 'supporter'],
        );
        $inactRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM user_tenants ut JOIN users u ON u.id = ut.user_id
             WHERE ut.tenant_id = ? AND ut.left_at IS NULL AND u.membership_status = ?',
            [$tid, 'inactive'],
        );

        $joinedDaily = $this->dailyCounts(
            'SELECT DATE(joined_at) AS d, COUNT(*) AS n FROM user_tenants
              WHERE tenant_id = ? AND joined_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(joined_at)',
            [$tid],
            backwardDays: 30,
        );
        $supportersDaily = $this->dailyCounts(
            'SELECT DATE(ut.joined_at) AS d, COUNT(*) AS n FROM user_tenants ut
              JOIN users u ON u.id = ut.user_id
              WHERE ut.tenant_id = ? AND u.membership_type = ?
                AND ut.joined_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(ut.joined_at)',
            [$tid, 'supporter'],
            backwardDays: 30,
        );

        return [
            'total_members' => ['value' => (int) ($totalRow['n'] ?? 0), 'sparkline' => $joinedDaily],
            'new_members'   => ['value' => (int) ($newRow['n']   ?? 0), 'sparkline' => $joinedDaily],
            'supporters'    => ['value' => (int) ($suppRow['n']  ?? 0), 'sparkline' => $supportersDaily],
            'inactive'      => ['value' => (int) ($inactRow['n'] ?? 0), 'sparkline' => []], // filled by use case from MemberStatusAudit
        ];
    }

    /**
     * @param array<int, scalar> $params
     * @return list<array{date: string, value: int}>
     */
    private function dailyCounts(string $sql, array $params, int $backwardDays): array
    {
        $rows = $this->db->fetchAll($sql, $params);
        $byDate = [];
        foreach ($rows as $r) { $byDate[(string) $r['d']] = (int) $r['n']; }
        $out = [];
        $today = new \DateTimeImmutable('today');
        for ($i = $backwardDays - 1; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $out[] = ['date' => $d, 'value' => $byDate[$d] ?? 0];
        }
        return $out;
    }
```

Note: the `inactive` sparkline is filled by the use case from `MemberStatusAuditRepository` (next task) — it's not a property of `user_tenants` alone. The repo returns `[]` for that key; the use case overrides.

- [ ] **Step 5: Add InMemory variant for tests that use it**

In the InMemory variant, add:

```php
    public function membershipStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // Test fakes can return a static-shape stub; production uses SQL.
        return [
            'total_members' => ['value' => 0, 'sparkline' => []],
            'new_members'   => ['value' => 0, 'sparkline' => []],
            'supporters'    => ['value' => 0, 'sparkline' => []],
            'inactive'      => ['value' => 0, 'sparkline' => []],
        ];
    }
```

- [ ] **Step 6: Re-run integration test to verify pass**

```bash
vendor/bin/phpunit --testsuite Integration --filter UserTenantStatsTest
```

Expected: PASS.

- [ ] **Step 7: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Tenant/UserTenantRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantRepository.php \
        src/Infrastructure/Adapter/Persistence/Memory/InMemoryUserTenantRepository.php \
        tests/Integration/UserTenantStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(members): UserTenant.membershipStatsForTenant — 4 KPI slice + zero-filled sparklines"
```

### Task 3.2: MemberStatusAudit stats slice + integration test

**Files:**
- Modify: `src/Domain/Membership/MemberStatusAuditRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberStatusAuditRepository.php`
- Modify InMemory equivalent if it exists (search first)
- Test: append a test method to `tests/Integration/UserTenantStatsTest.php` OR create `tests/Integration/MemberStatusAuditStatsTest.php` (prefer the latter for cleanliness)

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberStatusAuditRepository;

final class MemberStatusAuditStatsTest extends MigrationTestCase
{
    public function test_inactive_transitions_daily_returns_30_zero_filled_points(): void
    {
        $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
        $this->seedTenant($tenantId, 'daems');
        $admin = $this->seedUser('admin@example.test');
        $u1    = $this->seedUser('alice@example.test');

        $this->db->execute(
            'INSERT INTO member_status_audit (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                '99999999-9999-9999-9999-999999999999',
                $tenantId->value, $u1->value, 'active', 'inactive',
                'reason', $admin->value,
            ],
        );

        $repo = new SqlMemberStatusAuditRepository($this->db);
        $series = $repo->dailyTransitionsForTenant($tenantId, 'inactive');

        self::assertCount(30, $series);
        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $todayPoint = array_values(array_filter($series, fn ($p) => $p['date'] === $todayKey))[0] ?? null;
        self::assertNotNull($todayPoint);
        self::assertSame(1, $todayPoint['value']);
    }
}
```

- [ ] **Step 2: Run test, confirm fail (method missing).**

```bash
vendor/bin/phpunit --testsuite Integration --filter MemberStatusAuditStatsTest
```

- [ ] **Step 3: Add interface method**

```php
    /** @return list<array{date: string, value: int}> */
    public function dailyTransitionsForTenant(\Daems\Domain\Tenant\TenantId $tenantId, string $newStatus): array;
```

- [ ] **Step 4: Implement in SQL repo**

```php
    public function dailyTransitionsForTenant(\Daems\Domain\Tenant\TenantId $tenantId, string $newStatus): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM member_status_audit
              WHERE tenant_id = ? AND new_status = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tenantId->value, $newStatus],
        );
        $byDate = [];
        foreach ($rows as $r) { $byDate[(string) $r['d']] = (int) $r['n']; }

        $out = [];
        $today = new \DateTimeImmutable('today');
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $out[] = ['date' => $d, 'value' => $byDate[$d] ?? 0];
        }
        return $out;
    }
```

- [ ] **Step 5: Add InMemory variant returning empty 30-point series.**

```php
    public function dailyTransitionsForTenant(\Daems\Domain\Tenant\TenantId $tenantId, string $newStatus): array
    {
        $today = new \DateTimeImmutable('today');
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $out[] = ['date' => $today->modify('-' . $i . ' days')->format('Y-m-d'), 'value' => 0];
        }
        return $out;
    }
```

- [ ] **Step 6: Run test → PASS, run PHPStan → 0 errors.**

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Membership/MemberStatusAuditRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlMemberStatusAuditRepository.php \
        src/Infrastructure/Adapter/Persistence/Memory/InMemoryMemberStatusAuditRepository.php \
        tests/Integration/MemberStatusAuditStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(members): MemberStatusAudit.dailyTransitionsForTenant — 30d sparkline series"
```

### Task 3.3: ListMembersStats use case + unit test

**Files:**
- Create: `src/Application/Backstage/Members/ListMembersStats/ListMembersStats.php`
- Create: `src/Application/Backstage/Members/ListMembersStats/ListMembersStatsInput.php`
- Create: `src/Application/Backstage/Members/ListMembersStats/ListMembersStatsOutput.php`
- Test: `tests/Unit/Application/Backstage/ListMembersStatsTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStatsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListMembersStatsTest extends TestCase
{
    public function test_assembles_4_kpis_with_inactive_sparkline_from_audit_repo(): void
    {
        $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
        $admin    = ActingUser::admin(UserId::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'), $tenantId);

        $userTenantRepo = new class implements \Daems\Domain\Tenant\UserTenantRepositoryInterface {
            public function findRole($u, $t): ?\Daems\Domain\Tenant\UserTenantRole { return null; }
            public function attach($u, $t, $r): void {}
            public function detach($u, $t): void {}
            public function rolesForUser($u): array { return []; }
            public function markAllLeftForUser(string $u, \DateTimeImmutable $n): void {}
            public function membershipStatsForTenant(\Daems\Domain\Tenant\TenantId $t): array {
                return [
                    'total_members' => ['value' => 5, 'sparkline' => [['date' => '2026-04-26', 'value' => 1]]],
                    'new_members'   => ['value' => 2, 'sparkline' => [['date' => '2026-04-26', 'value' => 1]]],
                    'supporters'    => ['value' => 1, 'sparkline' => [['date' => '2026-04-26', 'value' => 0]]],
                    'inactive'      => ['value' => 1, 'sparkline' => []],
                ];
            }
        };

        $auditRepo = new class implements \Daems\Domain\Membership\MemberStatusAuditRepositoryInterface {
            public function record(...$args): void {}
            public function dailyTransitionsForTenant(\Daems\Domain\Tenant\TenantId $t, string $s): array {
                return [['date' => '2026-04-26', 'value' => 3]];
            }
        };

        $usecase = new ListMembersStats($userTenantRepo, $auditRepo);
        $out = $usecase->execute(new ListMembersStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(5, $out->stats['total_members']['value']);
        self::assertSame(1, $out->stats['inactive']['value']);
        self::assertSame([['date' => '2026-04-26', 'value' => 3]], $out->stats['inactive']['sparkline']);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
        $nonAdmin = ActingUser::regular(UserId::fromString('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'), $tenantId);

        $userTenantRepo = $this->makeStubRepo();
        $auditRepo      = $this->makeStubAuditRepo();

        $usecase = new ListMembersStats($userTenantRepo, $auditRepo);

        $this->expectException(ForbiddenException::class);
        $usecase->execute(new ListMembersStatsInput(acting: $nonAdmin, tenantId: $tenantId));
    }

    // Helpers — keep stub repos near the test that needs them
    private function makeStubRepo(): \Daems\Domain\Tenant\UserTenantRepositoryInterface { /* same anonymous shape as above */ }
    private function makeStubAuditRepo(): \Daems\Domain\Membership\MemberStatusAuditRepositoryInterface { /* same shape */ }
}
```

Verify the exact constructor of `ActingUser` and the test helpers; if `ActingUser::admin/regular` factory methods do not exist, replicate the construction shape used in `tests/Unit/Application/Backstage/ListForumStatsTest.php` (assumed — search for it as your starting template). If no such test exists for forum stats, look at `tests/Unit` for any existing use-case test that constructs `ActingUser`.

- [ ] **Step 2: Run, fail (class missing).**

```bash
vendor/bin/phpunit --testsuite Unit --filter ListMembersStatsTest
```

- [ ] **Step 3: Create Input DTO**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\Members\ListMembersStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListMembersStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
```

- [ ] **Step 4: Create Output DTO**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\Members\ListMembersStats;

final class ListMembersStatsOutput
{
    /**
     * @param array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
```

- [ ] **Step 5: Create the use case**

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\Members\ListMembersStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;

final class ListMembersStats
{
    public function __construct(
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly MemberStatusAuditRepositoryInterface $audit,
    ) {}

    public function execute(ListMembersStatsInput $input): ListMembersStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        $stats = $this->userTenants->membershipStatsForTenant($input->tenantId);
        $stats['inactive']['sparkline'] = $this->audit->dailyTransitionsForTenant($input->tenantId, 'inactive');

        return new ListMembersStatsOutput(stats: $stats);
    }
}
```

- [ ] **Step 6: Run unit test → PASS, PHPStan → 0 errors.**

- [ ] **Step 7: Commit**

```bash
git add src/Application/Backstage/Members/ tests/Unit/Application/Backstage/ListMembersStatsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(members): ListMembersStats — 4 KPIs, admin-gated; inactive sparkline from audit"
```

### Task 3.4: Controller method + route + DI BOTH-wire

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Add controller method**

In `BackstageController.php`, near the existing `statsForum` method, add:

```php
    public function statsMembers(\Daems\Infrastructure\Adapter\Api\Http\Request $request): \Daems\Infrastructure\Adapter\Api\Http\Response
    {
        $acting = $request->requireActingUser();
        $tenant = $this->requireTenant($request);

        try {
            $out = $this->listMembersStats->execute(new \Daems\Application\Backstage\Members\ListMembersStats\ListMembersStatsInput(
                acting:   $acting,
                tenantId: $tenant->id,
            ));
        } catch (\Daems\Domain\Auth\ForbiddenException) {
            return \Daems\Infrastructure\Adapter\Api\Http\Response::forbidden('Admin only');
        }

        return \Daems\Infrastructure\Adapter\Api\Http\Response::json(['data' => $out->stats]);
    }
```

Add the `private readonly ListMembersStats $listMembersStats` constructor parameter following the existing constructor pattern. Use the FQN imports at top of file (`use Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats;` etc.).

- [ ] **Step 2: Add route**

In `routes/api.php`, place near the existing forum stats route (~line 421):

```php
    $router->get('/api/v1/backstage/members/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsMembers($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 3: Bind use case in `bootstrap/app.php`**

Locate the existing `ListForumStats` binding (around line 981). Add directly below it:

```php
$container->bind(\Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Members\ListMembersStats\ListMembersStats(
        $c->make(\Daems\Domain\Tenant\UserTenantRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class),
    ),
);
```

Then locate the `BackstageController` constructor binding (line ~440-470) and add `$c->make(...)` for `ListMembersStats` as a new constructor argument.

- [ ] **Step 4: Bind use case in `tests/Support/KernelHarness.php`**

Mirror Step 3 in `tests/Support/KernelHarness.php` (around line 414, where `ListForumStats` is bound).

- [ ] **Step 5: Verify both wires**

```bash
grep -n "ListMembersStats" bootstrap/app.php tests/Support/KernelHarness.php
```

Expected: at least one match in EACH file.

- [ ] **Step 6: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php \
        routes/api.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(members): statsMembers controller + route + ListMembersStats in BOTH bootstrap + KernelHarness"
```

### Task 3.5: E2E + isolation tests

**Files:**
- Test: `tests/E2E/Backstage/MembersStatsEndpointTest.php`
- Test: `tests/Isolation/MembersStatsIsolationTest.php`

- [ ] **Step 1: Write E2E test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class MembersStatsEndpointTest extends TestCase
{
    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $harness = KernelHarness::booted();
        $admin   = $harness->seedAdminInTenant('daems');

        $response = $harness->getJson(
            '/api/v1/backstage/members/stats',
            host: 'daems-platform.local',
            asUser: $admin,
        );

        self::assertSame(200, $response->status);
        self::assertArrayHasKey('total_members', $response->body['data']);
        self::assertArrayHasKey('new_members',   $response->body['data']);
        self::assertArrayHasKey('supporters',    $response->body['data']);
        self::assertArrayHasKey('inactive',      $response->body['data']);
    }

    public function test_non_admin_gets_403(): void
    {
        $harness = KernelHarness::booted();
        $regular = $harness->seedRegularUserInTenant('daems');

        $response = $harness->getJson(
            '/api/v1/backstage/members/stats',
            host: 'daems-platform.local',
            asUser: $regular,
        );

        self::assertSame(403, $response->status);
    }
}
```

(Verify `KernelHarness` helper method names — adjust to match those used in `tests/E2E/Backstage/ForumStatsEndpointTest.php` if different.)

- [ ] **Step 2: Write isolation test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Tests\Integration\IsolationTestCase;

final class MembersStatsIsolationTest extends IsolationTestCase
{
    public function test_member_count_for_daems_excludes_sahegroup_members(): void
    {
        // IsolationTestCase seeds two tenants; seed 2 members in each
        $this->seedMember(tenantSlug: 'daems',      email: 'd1@x.test');
        $this->seedMember(tenantSlug: 'daems',      email: 'd2@x.test');
        $this->seedMember(tenantSlug: 'sahegroup',  email: 's1@x.test');
        $this->seedMember(tenantSlug: 'sahegroup',  email: 's2@x.test');

        $admin = $this->seedAdminInTenant('daems');
        $body  = $this->callStatsAsAdmin('/api/v1/backstage/members/stats', 'daems-platform.local', $admin);

        self::assertSame(2, $body['data']['total_members']['value']);
    }
}
```

If `IsolationTestCase` does not have `seedMember` / `callStatsAsAdmin` helpers, look at `tests/Isolation/InsightStatsIsolationTest.php` for the exact helper shape and copy.

- [ ] **Step 3: Run E2E + isolation suites**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana \
  -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
vendor/bin/phpunit --testsuite E2E --filter MembersStatsEndpointTest
vendor/bin/phpunit --testsuite Integration --filter MembersStatsIsolationTest
```

Expected: 2 + 1 tests pass.

- [ ] **Step 4: Run full test triple to confirm nothing regressed**

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
composer analyse
```

Expected: all green; PHPStan 0 errors.

- [ ] **Step 5: Commit**

```bash
git add tests/E2E/Backstage/MembersStatsEndpointTest.php tests/Isolation/MembersStatsIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(members): E2E stats endpoint + tenant isolation for member-count"
```

### Task 3.6: Frontend — KPI strip on Members page

**Files:**
- Modify: `daem-society/public/pages/backstage/members/index.php`
- Create: `daem-society/public/pages/backstage/members/members-stats.js`
- Modify: `daem-society/public/api/backstage/members.php` (add `op=stats`; create file if absent)

- [ ] **Step 1: Verify or create the proxy**

Open `daem-society/public/api/backstage/members.php`. If it does not exist, create it modeled on `daem-society/public/api/backstage/insights.php`. Whether new or existing, ensure the file starts with:

```php
<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!class_exists('ApiClient')) {
    require_once __DIR__ . '/../../../src/ApiClient.php';
}
header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin']) && ($u['role'] ?? '') !== 'admin' && ($u['role'] ?? '') !== 'global_system_administrator')) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$op = (string) ($_GET['op'] ?? '');
```

Then add the `stats` case:

```php
try {
    switch ($op) {
        case 'stats':
            $r = ApiClient::get('/backstage/members/stats');
            echo json_encode(['data' => $r ?? []]);
            return;

        // ... existing cases (preserve them) ...

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown_op', 'op' => $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Insert KPI strip into Members page**

In `daem-society/public/pages/backstage/members/index.php`, locate the markup right after `.page-header` block (before the existing filters/table). Insert:

```php
<?php
$icon_member  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
$icon_plus    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14"/></svg>';
$icon_heart   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
$icon_pause   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';

$kpis = [
    ['kpi_id' => 'total_members', 'label' => 'Total members', 'value' => '—', 'icon_html' => $icon_member, 'icon_variant' => 'blue',   'trend_label' => 'active in tenant', 'trend_direction' => 'muted'],
    ['kpi_id' => 'new_members',   'label' => 'New (30d)',     'value' => '—', 'icon_html' => $icon_plus,   'icon_variant' => 'green',  'trend_label' => 'last 30 days',     'trend_direction' => 'muted'],
    ['kpi_id' => 'supporters',    'label' => 'Supporters',    'value' => '—', 'icon_html' => $icon_heart,  'icon_variant' => 'purple', 'trend_label' => 'membership_type',  'trend_direction' => 'muted'],
    ['kpi_id' => 'inactive',      'label' => 'Inactive',      'value' => '—', 'icon_html' => $icon_pause,  'icon_variant' => 'amber',  'trend_label' => 'paused status',    'trend_direction' => 'warn'],
];
?>
<div class="kpis-grid">
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
</div>
<script src="/pages/backstage/members/members-stats.js" defer></script>
```

- [ ] **Step 3: Create the stats JS**

Create `daem-society/public/pages/backstage/members/members-stats.js`:

```js
/**
 * Members KPI strip — fetches /api/backstage/members.php?op=stats and populates
 * KPI values + sparklines on the members page.
 */
(function () {
  'use strict';

  if (!document.querySelector('.kpis-grid .kpi-card[data-kpi="total_members"]')) return;

  var KPI_COLORS = {
    total_members: '#3b82f6',
    new_members:   '#16a34a',
    supporters:    '#a855f7',
    inactive:      '#d97706',
  };

  fetch('/api/backstage/members.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('members stats failed', e); });

  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });

    Object.keys(KPI_COLORS).forEach(function (id) {
      setKpi(id, data[id]);
      initSpark(id, data[id] && data[id].sparkline);
    });
  }

  function setKpi(id, payload) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value);
  }

  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id]);
  }
})();
```

- [ ] **Step 4: Manual UAT**

```
1. Open http://daem-society.local/backstage/members in browser
2. Verify 4 KPI cards render above the existing members table
3. Verify all 4 cards have non-zero values that match a hand spotcheck:
   "C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana daems_db -e "
     SELECT COUNT(*) AS total FROM user_tenants WHERE left_at IS NULL;"
4. Verify sparklines render under Total/New/Supporters; Inactive sparkline is empty if no transitions in last 30d
5. Toggle dark theme — verify parity
6. Resize to ≤640px — grid collapses to single column
7. DevTools Network: confirm GET /api/backstage/members.php?op=stats returns 200 with all 4 KPI keys
8. grep -r "transform: translate\|transform: scale" daem-society/public/pages/backstage/members/ — must return nothing
```

- [ ] **Step 5: Commit (frontend repo)**

Switch to the frontend repo directory and commit:

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/members/index.php \
        public/pages/backstage/members/members-stats.js \
        public/api/backstage/members.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Frontend(members): KPI strip + members-stats.js + members.php proxy op=stats"
cd C:/laragon/www/daems-platform
```

**Phase 3 done.** Run final preflight (`composer analyse` + 3 testsuites all green) before starting Phase 4.

---

## Phase 4 — Applications KPI strip

KPI map:

| `kpi_id` | Value | Sparkline | Variant |
|----------|-------|-----------|---------|
| `pending`            | `member_apps + supporter_apps WHERE status='pending'` (combined) | combined `created_at` daily 30d | `amber` |
| `approved_30d`       | combined `WHERE status='approved' AND decided_at >= NOW()-30D` | daily `decided_at` for approved 30d | `green` |
| `rejected_30d`       | combined `WHERE status='rejected' AND decided_at >= NOW()-30D` | daily `decided_at` for rejected 30d | `red` |
| `avg_response_hours` | `AVG(TIMESTAMPDIFF(HOUR, created_at, decided_at))` over decided_at >= NOW()-30D, both tables | empty `[]` | `gray` |

**Repos involved:** `MemberApplicationRepositoryInterface`, `SupporterApplicationRepositoryInterface`. Each gets a stats-slice method; the use case combines.

### Task 4.1: MemberApplication stats slice + integration test

**Files:**
- Modify: `src/Domain/Membership/MemberApplicationRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php`
- Modify InMemory equivalent
- Test: `tests/Integration/MemberApplicationStatsTest.php`

- [ ] **Step 1: Write integration test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;

final class MemberApplicationStatsTest extends MigrationTestCase
{
    public function test_application_stats_for_tenant_returns_4_keys(): void
    {
        $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
        $this->seedTenant($tenantId, 'daems');

        $this->insertApp($tenantId, 'pending');
        $this->insertApp($tenantId, 'approved', decidedHoursAgo: 5);
        $this->insertApp($tenantId, 'rejected', decidedHoursAgo: 2);

        $repo = new SqlMemberApplicationRepository($this->db);
        $stats = $repo->statsForTenant($tenantId);

        self::assertSame(1, $stats['pending']['value']);
        self::assertSame(1, $stats['approved_30d']['value']);
        self::assertSame(1, $stats['rejected_30d']['value']);
        self::assertSame(2, $stats['decided_count']); // helper field for use case to compute weighted avg
        self::assertGreaterThan(0, $stats['decided_total_hours']);
        self::assertCount(30, $stats['pending']['sparkline']);
    }

    // helper writes a row to member_applications
    private function insertApp(TenantId $tenantId, string $status, int $decidedHoursAgo = 0): void { /* INSERT */ }
}
```

- [ ] **Step 2: Run, fail.**

- [ ] **Step 3: Add interface method**

```php
    /**
     * @return array{
     *   pending: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   approved_30d: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   rejected_30d: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   decided_count: int,
     *   decided_total_hours: int
     * }
     */
    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;
```

The use case averages `decided_total_hours / decided_count` across both repos.

- [ ] **Step 4: Implement in SQL repo**

```php
    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $tid = $tenantId->value;

        $pendRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM member_applications WHERE tenant_id = ? AND status = ?',
            [$tid, 'pending'],
        );
        $apprRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM member_applications WHERE tenant_id = ? AND status = ? AND decided_at >= (NOW() - INTERVAL 30 DAY)',
            [$tid, 'approved'],
        );
        $rejRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM member_applications WHERE tenant_id = ? AND status = ? AND decided_at >= (NOW() - INTERVAL 30 DAY)',
            [$tid, 'rejected'],
        );
        $avgRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(TIMESTAMPDIFF(HOUR, created_at, decided_at)), 0) AS total_h
               FROM member_applications WHERE tenant_id = ? AND decided_at >= (NOW() - INTERVAL 30 DAY)',
            [$tid],
        );

        $pendingDaily = $this->dailyCounts(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM member_applications
              WHERE tenant_id = ? AND status = ? AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid, 'pending'],
        );
        $approvedDaily = $this->dailyCountsByDecided($tid, 'approved');
        $rejectedDaily = $this->dailyCountsByDecided($tid, 'rejected');

        return [
            'pending'             => ['value' => (int) ($pendRow['n'] ?? 0), 'sparkline' => $pendingDaily],
            'approved_30d'        => ['value' => (int) ($apprRow['n'] ?? 0), 'sparkline' => $approvedDaily],
            'rejected_30d'        => ['value' => (int) ($rejRow['n']  ?? 0), 'sparkline' => $rejectedDaily],
            'decided_count'       => (int) ($avgRow['n'] ?? 0),
            'decided_total_hours' => (int) ($avgRow['total_h'] ?? 0),
        ];
    }

    /** @return list<array{date: string, value: int}> */
    private function dailyCountsByDecided(string $tid, string $status): array
    {
        return $this->dailyCounts(
            'SELECT DATE(decided_at) AS d, COUNT(*) AS n FROM member_applications
              WHERE tenant_id = ? AND status = ? AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)',
            [$tid, $status],
        );
    }

    /** @param array<int, scalar> $params @return list<array{date: string, value: int}> */
    private function dailyCounts(string $sql, array $params): array
    {
        $rows = $this->db->fetchAll($sql, $params);
        $byDate = [];
        foreach ($rows as $r) { $byDate[(string) $r['d']] = (int) $r['n']; }
        $today = new \DateTimeImmutable('today');
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $out[] = ['date' => $d, 'value' => $byDate[$d] ?? 0];
        }
        return $out;
    }
```

- [ ] **Step 5: InMemory variant returns zeros + 30-zero series.**

- [ ] **Step 6: Run test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(applications): MemberApplication.statsForTenant — pending/decided slices + sparklines"
```

### Task 4.2: SupporterApplication stats slice + integration test

Mirror Task 4.1 with table `supporter_applications` and class `SqlSupporterApplicationRepository`. Same shape, same return contract.

- [ ] **Step 1-7: Repeat Task 4.1's steps with `supporter_applications` table and `SqlSupporterApplicationRepository`**, including a parallel `tests/Integration/SupporterApplicationStatsTest.php`.

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(applications): SupporterApplication.statsForTenant — same shape as member apps"
```

### Task 4.3: ListApplicationsStats use case + unit test

**Files:**
- Create: `src/Application/Backstage/Applications/ListApplicationsStats/{ListApplicationsStats,Input,Output}.php`
- Test: `tests/Unit/Application/Backstage/ListApplicationsStatsTest.php`

- [ ] **Step 1: Write unit test asserting combination logic**

```php
public function test_combines_member_and_supporter_repos_and_computes_avg(): void
{
    $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
    $admin    = ActingUser::admin(/* ... */, $tenantId);

    $memberRepo = $this->stubAppRepo(stats: [
        'pending'             => ['value' => 3, 'sparkline' => $this->seriesOf([1, 1, 1])],
        'approved_30d'        => ['value' => 2, 'sparkline' => $this->seriesOf([1, 1])],
        'rejected_30d'        => ['value' => 1, 'sparkline' => $this->seriesOf([1])],
        'decided_count'       => 3,
        'decided_total_hours' => 30, // avg 10h
    ]);
    $supporterRepo = $this->stubAppRepo(stats: [
        'pending'             => ['value' => 1, 'sparkline' => $this->seriesOf([1])],
        'approved_30d'        => ['value' => 0, 'sparkline' => $this->seriesOf([])],
        'rejected_30d'        => ['value' => 0, 'sparkline' => $this->seriesOf([])],
        'decided_count'       => 0,
        'decided_total_hours' => 0,
    ]);

    $usecase = new ListApplicationsStats($memberRepo, $supporterRepo);
    $out = $usecase->execute(new ListApplicationsStatsInput(acting: $admin, tenantId: $tenantId));

    self::assertSame(4, $out->stats['pending']['value']);
    self::assertSame(2, $out->stats['approved_30d']['value']);
    self::assertSame(1, $out->stats['rejected_30d']['value']);
    self::assertSame(10, $out->stats['avg_response_hours']['value']); // (30+0)/(3+0) = 10
    self::assertSame([], $out->stats['avg_response_hours']['sparkline']);
}

public function test_avg_zero_when_no_decisions(): void
{
    // both repos return decided_count=0 → use case must render 0 (or '—') without divide-by-zero
    // Implementation choice locked: render 0; frontend may interpret 0 as '—' if it wants.
}
```

- [ ] **Step 2-3: Fail, then create Input/Output DTOs.**

```php
// Input
final class ListApplicationsStatsInput {
    public function __construct(public readonly ActingUser $acting, public readonly TenantId $tenantId) {}
}

// Output
final class ListApplicationsStatsOutput {
    /**
     * @param array{
     *   pending: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   approved_30d: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   rejected_30d: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   avg_response_hours: array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
```

- [ ] **Step 4: Implement use case**

```php
final class ListApplicationsStats
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListApplicationsStatsInput $input): ListApplicationsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }
        $m = $this->memberApps->statsForTenant($input->tenantId);
        $s = $this->supporterApps->statsForTenant($input->tenantId);

        $sumKpi = static function (array $a, array $b): array {
            $merged = [];
            foreach ($a['sparkline'] as $i => $point) {
                $merged[] = [
                    'date'  => $point['date'],
                    'value' => $point['value'] + ($b['sparkline'][$i]['value'] ?? 0),
                ];
            }
            return ['value' => $a['value'] + $b['value'], 'sparkline' => $merged];
        };

        $totalDecided   = $m['decided_count'] + $s['decided_count'];
        $totalHours     = $m['decided_total_hours'] + $s['decided_total_hours'];
        $avgHoursValue  = $totalDecided > 0 ? (int) round($totalHours / $totalDecided) : 0;

        return new ListApplicationsStatsOutput(stats: [
            'pending'            => $sumKpi($m['pending'], $s['pending']),
            'approved_30d'       => $sumKpi($m['approved_30d'], $s['approved_30d']),
            'rejected_30d'       => $sumKpi($m['rejected_30d'], $s['rejected_30d']),
            'avg_response_hours' => ['value' => $avgHoursValue, 'sparkline' => []],
        ]);
    }
}
```

- [ ] **Step 5-6: Run unit test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(applications): ListApplicationsStats — combines member+supporter, computes avg response hours"
```

### Task 4.4: Controller + route + DI BOTH-wire

Same shape as Task 3.4 with these substitutions:
- Method name: `statsApplications`
- Route: `/api/v1/backstage/applications/stats`
- Use case binding signature:
```php
$container->bind(\Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats::class,
    static fn(Container $c) => new \Daems\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats(
        $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
        $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
    ),
);
```

- [ ] **Step 1-7: Mirror Task 3.4 with substitutions above.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(applications): statsApplications controller + route + ListApplicationsStats in BOTH containers"
```

### Task 4.5: E2E + isolation tests

Mirror Task 3.5 with:
- Endpoint: `/api/v1/backstage/applications/stats`
- Payload assertions: `pending`, `approved_30d`, `rejected_30d`, `avg_response_hours` keys
- Isolation: seed pending apps in both tenants; assert only daems's count is in daems response

- [ ] **Step 1-5: Mirror Task 3.5 with endpoint above.**

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(applications): E2E stats endpoint + tenant isolation"
```

### Task 4.6: Frontend KPI strip on Applications page

Mirror Task 3.6 with:
- Page: `daem-society/public/pages/backstage/applications/index.php`
- Proxy: `daem-society/public/api/backstage/applications.php` (verify whether file exists; create or modify)
- JS: `daem-society/public/pages/backstage/applications/applications-stats.js`
- KPI ids: `pending` / `approved_30d` / `rejected_30d` / `avg_response_hours`
- Variants/icons: amber clock / green check / red x / gray hourglass
- Trend label for `avg_response_hours`: render as `Nh` plain (the JS sets `.kpi-card__value` to the integer; append `'h'` after for that one card via the JS)

JS sketch (full, repeat-friendly):

```js
(function () {
  'use strict';
  if (!document.querySelector('.kpis-grid .kpi-card[data-kpi="pending"]')) return;
  var KPI_COLORS = {
    pending:            '#d97706',
    approved_30d:       '#16a34a',
    rejected_30d:       '#dc2626',
    avg_response_hours: '#64748b',
  };
  fetch('/api/backstage/applications.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('applications stats failed', e); });
  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });
    setKpi('pending',      data.pending,      '');
    setKpi('approved_30d', data.approved_30d, '');
    setKpi('rejected_30d', data.rejected_30d, '');
    setKpi('avg_response_hours', data.avg_response_hours, 'h'); // append "h"
    initSpark('pending',            data.pending && data.pending.sparkline);
    initSpark('approved_30d',       data.approved_30d && data.approved_30d.sparkline);
    initSpark('rejected_30d',       data.rejected_30d && data.rejected_30d.sparkline);
    initSpark('avg_response_hours', data.avg_response_hours && data.avg_response_hours.sparkline);
  }
  function setKpi(id, payload, suffix) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value) + (suffix || '');
  }
  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id]);
  }
})();
```

- [ ] **Step 1-5: Mirror Task 3.6 (proxy, page-include, JS, manual UAT).**

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Frontend(applications): KPI strip + applications-stats.js + applications.php proxy op=stats"
cd C:/laragon/www/daems-platform
```

**Phase 4 done.**

---

## Phase 5 — Events KPI strip

KPI map:

| `kpi_id` | Value | Sparkline | Variant |
|----------|-------|-----------|---------|
| `upcoming`            | `events status='published' AND event_date >= CURDATE()` | **forward** 30d: count of published events whose `event_date` falls on each next 30 days | `blue` |
| `drafts`              | `events status='draft'` | `created_at` daily 30d, `status='draft'` filter | `gray` |
| `registrations_30d`   | `event_registrations registered_at >= NOW()-30D` | daily `registered_at` 30d | `green` |
| `pending_proposals`   | `event_proposals status='pending'` | `event_proposals.created_at` daily 30d | `amber` |

**Repos involved:** `EventRepositoryInterface`, `EventRegistrationRepository*` (verify name — likely embedded in `EventRepositoryInterface` or separate; check `Glob src/Domain/Event/*.php`), `EventProposalRepositoryInterface`. Add stats slices.

### Task 5.1: EventRepository stats slice (Upcoming + Drafts) + integration test

**Files:**
- Modify: `src/Domain/Event/EventRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php`
- InMemory variant
- Test: `tests/Integration/EventStatsTest.php`

- [ ] **Step 1: Integration test**

```php
public function test_event_stats_for_tenant_upcoming_forward_drafts_backward(): void
{
    $tenantId = TenantId::fromString('11111111-1111-1111-1111-111111111111');
    $this->seedTenant($tenantId, 'daems');

    // 2 upcoming published, 1 draft
    $this->insertEvent($tenantId, 'published', daysAhead: 5);
    $this->insertEvent($tenantId, 'published', daysAhead: 10);
    $this->insertEvent($tenantId, 'draft',     daysAhead: 0);

    $repo = new SqlEventRepository($this->db);
    $stats = $repo->statsForTenant($tenantId);

    self::assertSame(2, $stats['upcoming']['value']);
    self::assertSame(1, $stats['drafts']['value']);
    self::assertCount(30, $stats['upcoming']['sparkline']);  // forward 30d
    self::assertCount(30, $stats['drafts']['sparkline']);    // backward 30d
}
```

- [ ] **Step 2: Run, fail.**

- [ ] **Step 3: Add interface signature**

```php
    /**
     * @return array{
     *   upcoming: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   drafts:   array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;
```

- [ ] **Step 4: SQL implementation**

```php
    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        $tid = $tenantId->value;

        $up    = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM events WHERE tenant_id=? AND status='published' AND event_date >= CURDATE()",
            [$tid],
        )['n'] ?? 0);
        $draft = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM events WHERE tenant_id=? AND status='draft'",
            [$tid],
        )['n'] ?? 0);

        // Forward 30 days for upcoming
        $upRows = $this->db->fetchAll(
            "SELECT event_date AS d, COUNT(*) AS n FROM events
              WHERE tenant_id=? AND status='published'
                AND event_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 29 DAY)
              GROUP BY event_date",
            [$tid],
        );
        $upByDate = [];
        foreach ($upRows as $r) { $upByDate[(string) $r['d']] = (int) $r['n']; }
        $upSpark = [];
        $today = new \DateTimeImmutable('today');
        for ($i = 0; $i < 30; $i++) {
            $d = $today->modify('+' . $i . ' days')->format('Y-m-d');
            $upSpark[] = ['date' => $d, 'value' => $upByDate[$d] ?? 0];
        }

        // Backward 30 days for drafts (by created_at)
        $drRows = $this->db->fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM events
              WHERE tenant_id=? AND status='draft'
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)",
            [$tid],
        );
        $drByDate = [];
        foreach ($drRows as $r) { $drByDate[(string) $r['d']] = (int) $r['n']; }
        $drSpark = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $drSpark[] = ['date' => $d, 'value' => $drByDate[$d] ?? 0];
        }

        return [
            'upcoming' => ['value' => $up,    'sparkline' => $upSpark],
            'drafts'   => ['value' => $draft, 'sparkline' => $drSpark],
        ];
    }
```

- [ ] **Step 5: InMemory variant returns zeros + 30-zero series for both.**

- [ ] **Step 6: Run test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(events): EventRepo.statsForTenant — upcoming forward 30d, drafts backward 30d"
```

### Task 5.2: EventRegistration stats slice + integration test

If a separate `EventRegistrationRepositoryInterface` exists, add to it. Otherwise add `dailyRegistrationsForTenant` to `EventRepositoryInterface`. Verify which during the task.

- [ ] **Step 1-7: Mirror Task 5.1 with `event_registrations` and `registered_at`. Method name `dailyRegistrationsForTenant`. Returns `['value' => int (count last 30d), 'sparkline' => 30 backward-day points]`.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(events): EventRegistration.dailyRegistrationsForTenant — 30d series"
```

### Task 5.3: EventProposal stats slice + integration test

- [ ] **Step 1-7: Mirror with `event_proposals`, status='pending', sparkline by `created_at` 30d backward. Method name `pendingStatsForTenant`. Returns `['value' => int, 'sparkline' => 30 points]`.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(events): EventProposal.pendingStatsForTenant — pending count + 30d incoming sparkline"
```

### Task 5.4: ListEventsStats use case + unit test

**Files:**
- Create: `src/Application/Backstage/Events/ListEventsStats/{ListEventsStats,Input,Output}.php`
- Test: `tests/Unit/Application/Backstage/ListEventsStatsTest.php`

- [ ] **Step 1: Unit test asserting orchestration of 3 repos.**

- [ ] **Step 2-4: Build Input/Output/UseCase**

```php
final class ListEventsStats
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly EventRegistrationRepositoryInterface $registrations, // or alternative if registrations live on EventRepo
        private readonly EventProposalRepositoryInterface $proposals,
    ) {}

    public function execute(ListEventsStatsInput $input): ListEventsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }
        $eventStats = $this->events->statsForTenant($input->tenantId);
        $regs       = $this->registrations->dailyRegistrationsForTenant($input->tenantId);
        $props      = $this->proposals->pendingStatsForTenant($input->tenantId);

        return new ListEventsStatsOutput(stats: [
            'upcoming'           => $eventStats['upcoming'],
            'drafts'             => $eventStats['drafts'],
            'registrations_30d'  => $regs,
            'pending_proposals'  => $props,
        ]);
    }
}
```

- [ ] **Step 5-6: Run unit test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(events): ListEventsStats — assembles 4 KPIs from 3 repos; admin-gated"
```

### Task 5.5: Controller + route + DI BOTH-wire

Mirror Task 3.4 with method name `statsEvents`, route `/api/v1/backstage/events/stats`, and binding signature using `EventRepositoryInterface`, `EventRegistrationRepositoryInterface`, `EventProposalRepositoryInterface`.

- [ ] **Step 1-7: Mirror Task 3.4.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(events): statsEvents controller + route + ListEventsStats in BOTH containers"
```

### Task 5.6: E2E + isolation tests

Mirror Task 3.5 with endpoint `/api/v1/backstage/events/stats` and 4 KPI keys.

- [ ] **Step 1-5: Mirror.**

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(events): E2E stats endpoint + tenant isolation"
```

### Task 5.7: Frontend KPI strip on Events page

Mirror Task 3.6 with:
- Page: `daem-society/public/pages/backstage/events/index.php`
- Proxy: `daem-society/public/api/backstage/events.php`
- JS: `daem-society/public/pages/backstage/events/events-stats.js`
- KPI ids/colors: upcoming blue / drafts gray / registrations_30d green / pending_proposals amber

- [ ] **Step 1-5: Mirror Task 3.6 with substitutions.**

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Frontend(events): KPI strip + events-stats.js + events.php proxy op=stats"
cd C:/laragon/www/daems-platform
```

**Phase 5 done.**

---

## Phase 6 — Projects KPI strip

KPI map:

| `kpi_id` | Value | Sparkline | Variant |
|----------|-------|-----------|---------|
| `active`            | `projects WHERE status='active'` | `created_at` daily 30d, `status='active'` filter | `green` |
| `drafts`            | `projects WHERE status='draft'`  | `created_at` daily 30d, `status='draft'` filter | `gray` |
| `featured`          | `projects WHERE status='active' AND featured=1` | empty `[]` | `purple` |
| `pending_proposals` | `project_proposals status='pending'` | `project_proposals.created_at` daily 30d | `amber` |

**Repos involved:** `ProjectRepositoryInterface`, `ProjectProposalRepositoryInterface`.

### Task 6.1: ProjectRepository stats slice + integration test

Mirror Task 5.1 with `projects` table, three values (`active`, `drafts`, `featured`), and these queries:
- `active`: status='active' / sparkline created_at 30d backward filtered to status='active'
- `drafts`: status='draft' / sparkline created_at 30d backward filtered to status='draft'
- `featured`: status='active' AND featured=1 / sparkline `[]`

- [ ] **Step 1-7: Mirror Task 5.1 with these queries.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(projects): ProjectRepo.statsForTenant — active/drafts/featured KPI slice"
```

### Task 6.2: ProjectProposal stats slice + integration test

- [ ] **Step 1-7: Mirror Task 5.3 with `project_proposals`. Method name `pendingStatsForTenant`. Same shape (count + 30d backward `created_at` sparkline).**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(projects): ProjectProposal.pendingStatsForTenant — pending count + sparkline"
```

### Task 6.3: ListProjectsStats use case + unit test

```php
final class ListProjectsStats
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly ProjectProposalRepositoryInterface $proposals,
    ) {}

    public function execute(ListProjectsStatsInput $input): ListProjectsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }
        $proj  = $this->projects->statsForTenant($input->tenantId);
        $props = $this->proposals->pendingStatsForTenant($input->tenantId);

        return new ListProjectsStatsOutput(stats: [
            'active'             => $proj['active'],
            'drafts'             => $proj['drafts'],
            'featured'           => $proj['featured'],
            'pending_proposals'  => $props,
        ]);
    }
}
```

- [ ] **Step 1-7: Mirror Task 5.4 with the use case above.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(projects): ListProjectsStats — 4 KPIs from 2 repos; admin-gated"
```

### Task 6.4: Controller + route + DI BOTH-wire

Mirror Task 3.4 with method `statsProjects`, route `/api/v1/backstage/projects/stats`.

- [ ] **Step 1-7: Mirror Task 3.4.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(projects): statsProjects controller + route + ListProjectsStats in BOTH containers"
```

### Task 6.5: E2E + isolation tests

Mirror Task 3.5 with endpoint `/api/v1/backstage/projects/stats`.

- [ ] **Step 1-5: Mirror.**

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(projects): E2E stats endpoint + tenant isolation"
```

### Task 6.6: Frontend KPI strip on Projects page

Mirror Task 3.6 with:
- Page: `daem-society/public/pages/backstage/projects/index.php`
- Proxy: `daem-society/public/api/backstage/projects.php`
- JS: `daem-society/public/pages/backstage/projects/projects-stats.js`
- KPI colors: active green / drafts gray / featured purple / pending_proposals amber
- For featured KPI, sparkline is `[]` — `Sparkline.init(el, [], color)` returns early gracefully (Phase 1 contract); the card renders without sparkline area populated. Verified Phase 2.

- [ ] **Step 1-5: Mirror Task 3.6.**

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Frontend(projects): KPI strip + projects-stats.js + projects.php proxy op=stats"
cd C:/laragon/www/daems-platform
```

**Phase 6 done.**

---

## Phase 7 — Notifications KPI strip

The Notifications page is the unified pending-actions inbox. There is no `notifications` table; this phase aggregates four sources + per-actor dismissals.

KPI map (Activity-strip / Direction A from spec):

| `kpi_id` | Value | Sparkline | Variant |
|----------|-------|-----------|---------|
| `pending_you`     | total pending across `member_applications` + `supporter_applications` + `project_proposals` + `forum_reports`, MINUS rows where `(admin_id, app_id) ∈ admin_application_dismissals` for the requesting actor | combined daily `created_at` 30d across the 4 sources | `amber` |
| `pending_all`     | same total without dismissal filter | same combined sparkline | `gray` |
| `cleared_30d`     | `decided_at >= NOW()-30D` from member/supporter/project_proposals + forum_reports closures within 30d (column TBD per planning — see below) | daily `decided_at` (or equivalent) 30d | `green` |
| `oldest_pending_d`| `MAX(DATEDIFF(NOW(), created_at))` across all pending rows | empty `[]` | `red` |

**Pre-planning verification step:** before Task 7.1 begins, the engineer must search `forum_reports` schema for the closure timestamp:

```bash
grep -n "forum_reports\|created_at\|resolved_at\|decided_at" database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql
```

If `forum_reports` lacks a closure timestamp, fall back to joining `forum_moderation_audit` rows where `target_type='report'` and `created_at` falls in the 30-day window. Document the chosen column in commit message.

**Repos involved:** `MemberApplicationRepositoryInterface`, `SupporterApplicationRepositoryInterface`, `ProjectProposalRepositoryInterface`, `ForumReportRepositoryInterface`, `AdminApplicationDismissalRepositoryInterface`. Each gets a `notificationStatsForTenant` slice (or reuses existing pending counts where adequate).

### Task 7.1: Repo slices for the 4 sources

This task batches small slice methods on 4 existing repos. TDD per repo, but commit one bundle.

**Files:**
- Modify: 4 SQL repos + their domain interfaces + InMemory variants
- Tests: `tests/Integration/NotificationStatsSourceSlicesTest.php` (one test per source)

- [ ] **Step 1: Integration test covering all 4 source slices**

```php
public function test_member_apps_pending_slice_returns_count_and_30d_series(): void { /* ... */ }
public function test_supporter_apps_pending_slice_returns_count_and_30d_series(): void { /* ... */ }
public function test_project_proposals_pending_slice_returns_count_and_30d_series(): void { /* ... */ }
public function test_forum_reports_pending_slice_returns_count_and_30d_series(): void { /* ... */ }
public function test_oldest_pending_age_in_days_across_sources(): void { /* ... */ }
```

- [ ] **Step 2: Run, fail (methods missing).**

- [ ] **Step 3: Add `notificationStatsForTenant(TenantId): array` to each domain interface and SQL repo**

Method contract (each repo):
```php
/**
 * @return array{
 *   pending_count: int,
 *   created_at_daily_30d: list<array{date: string, value: int}>,
 *   oldest_pending_age_days: int   // 0 when no pending rows
 * }
 */
public function notificationStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array;
```

For `forum_reports` use `status='open'`; for the other 3 use `status='pending'`. Mirror sparkline shape from Task 3.1 (zero-filled 30 backward days).

- [ ] **Step 4: SQL implementations.**

For each repo, the query template (substitute table name, status column value, status value):

```php
public function notificationStatsForTenant(TenantId $tenantId): array
{
    $tid = $tenantId->value;
    $row = $this->db->fetchOne(
        "SELECT COUNT(*) AS n, COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest
           FROM <table> WHERE tenant_id=? AND status=?",
        [$tid, '<pending-status>'],
    );
    $rows = $this->db->fetchAll(
        "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM <table>
          WHERE tenant_id=? AND created_at >= (CURDATE() - INTERVAL 29 DAY)
          GROUP BY DATE(created_at)",
        [$tid],
    );
    $byDate = [];
    foreach ($rows as $r) { $byDate[(string) $r['d']] = (int) $r['n']; }
    $today = new \DateTimeImmutable('today');
    $spark = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
        $spark[] = ['date' => $d, 'value' => $byDate[$d] ?? 0];
    }
    return [
        'pending_count'         => (int) ($row['n'] ?? 0),
        'created_at_daily_30d'  => $spark,
        'oldest_pending_age_days' => (int) ($row['oldest'] ?? 0),
    ];
}
```

- [ ] **Step 5: InMemory variants return zero-filled stubs for tests.**

- [ ] **Step 6: Run integration test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(notifications): notificationStatsForTenant slices on 4 source repos"
```

### Task 7.2: AdminApplicationDismissal repo — actor-dismissed-ids slice

**Files:**
- Modify: `src/Domain/Dismissal/AdminApplicationDismissalRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminApplicationDismissalRepository.php`
- InMemory variant
- Test: `tests/Integration/AdminApplicationDismissalSliceTest.php`

- [ ] **Step 1: Integration test**

```php
public function test_dismissed_app_ids_for_admin_returns_set_of_ids(): void
{
    // Insert 2 dismissals for adminA, 1 for adminB
    // Assert: dismissedAppIdsFor(adminA) returns the 2 ids only
}
```

- [ ] **Step 2: Run, fail.**

- [ ] **Step 3: Add interface method**

```php
    /** @return list<string> */
    public function dismissedAppIdsFor(\Daems\Domain\User\UserId $adminId): array;
```

- [ ] **Step 4: SQL implementation**

```php
    public function dismissedAppIdsFor(\Daems\Domain\User\UserId $adminId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT app_id FROM admin_application_dismissals WHERE admin_id = ?',
            [$adminId->value],
        );
        return array_map(static fn ($r) => (string) $r['app_id'], $rows);
    }
```

- [ ] **Step 5: InMemory variant returns empty array.**

- [ ] **Step 6: Run test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(notifications): AdminApplicationDismissal.dismissedAppIdsFor — for actor filter"
```

### Task 7.3: Cleared-events slice on relevant repos

Each of `MemberApplicationRepository`, `SupporterApplicationRepository`, `ProjectProposalRepository`, and `ForumReportRepository` (or `ForumModerationAuditRepository` if forum closures live there per pre-planning verification) gets a `clearedDailyForTenant(TenantId): list<array{date,value}>` method returning a 30-day backward series of "decided_at OR equivalent closure timestamp" counts.

- [ ] **Step 1-7: Add method per repo with TDD. Single integration test file `ClearedDailySlicesTest.php` covering all four. Commit:**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Repo(notifications): clearedDailyForTenant slices on 4 closure-tracking repos"
```

### Task 7.4: ListNotificationsStats use case + unit test

**Files:**
- Create: `src/Application/Backstage/Notifications/ListNotificationsStats/{ListNotificationsStats,Input,Output}.php`
- Test: `tests/Unit/Application/Backstage/ListNotificationsStatsTest.php`

- [ ] **Step 1: Unit test asserting orchestration of 5 repos + actor dismissal subtraction**

```php
public function test_pending_you_subtracts_dismissed_count_pending_all_does_not(): void
{
    // Set up stubs:
    //   member apps: pending_count=3 (ids: m1, m2, m3)
    //   supporter apps: pending_count=1 (id: s1)
    //   project proposals: pending_count=2 (ids: p1, p2)
    //   forum reports: pending_count=1 (id: f1)
    //   dismissals for actor: [m1, p2] (2 ids)
    // Expect:
    //   pending_all.value = 7
    //   pending_you.value = 5
    //   oldest_pending_d.value = max across sources
    //   sparklines element-wise summed
}
```

Note: subtracting "dismissed count" assumes the dismissal id-set is per-tenant disjoint with other tenants' app ids — guaranteed because app ids are UUIDs. The use case takes the count of `dismissedAppIdsFor(actor)` and subtracts from `pending_all` for `pending_you`. (If the dismissal repo can't be filtered by tenant, this is acceptable because UUID collisions are negligible.)

- [ ] **Step 2: Run, fail.**

- [ ] **Step 3: Build Input/Output DTOs**

```php
final class ListNotificationsStatsInput {
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}

final class ListNotificationsStatsOutput {
    /**
     * @param array{
     *   pending_you: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   pending_all: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   cleared_30d: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   oldest_pending_d: array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
```

- [ ] **Step 4: Implement use case**

```php
final class ListNotificationsStats
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
        private readonly ProjectProposalRepositoryInterface $projectProposals,
        private readonly ForumReportRepositoryInterface $forumReports,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    ) {}

    public function execute(ListNotificationsStatsInput $input): ListNotificationsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }
        $tenantId = $input->tenantId;
        $m = $this->memberApps->notificationStatsForTenant($tenantId);
        $s = $this->supporterApps->notificationStatsForTenant($tenantId);
        $p = $this->projectProposals->notificationStatsForTenant($tenantId);
        $f = $this->forumReports->notificationStatsForTenant($tenantId);

        $totalPending = $m['pending_count'] + $s['pending_count'] + $p['pending_count'] + $f['pending_count'];
        $oldestDays   = max(
            $m['oldest_pending_age_days'],
            $s['oldest_pending_age_days'],
            $p['oldest_pending_age_days'],
            $f['oldest_pending_age_days'],
        );

        $sumSeries = static function (array $series): array {
            $out = [];
            for ($i = 0; $i < 30; $i++) {
                $date = $series[0][$i]['date'] ?? '';
                $value = 0;
                foreach ($series as $s) { $value += (int) ($s[$i]['value'] ?? 0); }
                $out[] = ['date' => $date, 'value' => $value];
            }
            return $out;
        };

        $incomingSparkline = $sumSeries([
            $m['created_at_daily_30d'], $s['created_at_daily_30d'],
            $p['created_at_daily_30d'], $f['created_at_daily_30d'],
        ]);

        // Cleared = sum of clearedDailyForTenant series across 4 repos
        $clearedSeries = $sumSeries([
            $this->memberApps->clearedDailyForTenant($tenantId),
            $this->supporterApps->clearedDailyForTenant($tenantId),
            $this->projectProposals->clearedDailyForTenant($tenantId),
            $this->forumReports->clearedDailyForTenant($tenantId),
        ]);
        $clearedTotal = array_sum(array_column($clearedSeries, 'value'));

        // pending_you = pending_all - count of actor's dismissals
        $dismissedIds = $this->dismissals->dismissedAppIdsFor($input->acting->userId);
        $pendingYou   = max(0, $totalPending - count($dismissedIds));

        return new ListNotificationsStatsOutput(stats: [
            'pending_you'      => ['value' => $pendingYou,    'sparkline' => $incomingSparkline],
            'pending_all'      => ['value' => $totalPending,  'sparkline' => $incomingSparkline],
            'cleared_30d'      => ['value' => $clearedTotal,  'sparkline' => $clearedSeries],
            'oldest_pending_d' => ['value' => $oldestDays,    'sparkline' => []],
        ]);
    }
}
```

- [ ] **Step 5-6: Run unit test → PASS, PHPStan 0.**

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "UseCase(notifications): ListNotificationsStats — 4 KPIs orchestrating 5 repos with actor-dismissal filter"
```

### Task 7.5: Controller + route + DI BOTH-wire

Mirror Task 3.4 with method `statsNotifications`, route `/api/v1/backstage/notifications/stats`. Binding takes 5 repo interfaces.

- [ ] **Step 1-7: Mirror Task 3.4.**

- [ ] **Step 8: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(notifications): statsNotifications controller + route + ListNotificationsStats in BOTH containers"
```

### Task 7.6: E2E + isolation tests

Mirror Task 3.5 with endpoint `/api/v1/backstage/notifications/stats`.

Additional isolation: seed pending app in tenant A and pending app in tenant B; admin in tenant A queries; assert payload only counts tenant A.

- [ ] **Step 1-5: Mirror Task 3.5.**

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(notifications): E2E stats endpoint + tenant isolation + actor-dismissal filter check"
```

### Task 7.7: Frontend KPI strip on Notifications page

Mirror Task 3.6 with:
- Page: `daem-society/public/pages/backstage/notifications/index.php`
- Proxy: `daem-society/public/api/backstage/notifications.php` (verify exists or create new mirroring `forum.php`)
- JS: `daem-society/public/pages/backstage/notifications/notifications-stats.js`
- KPI ids: `pending_you` / `pending_all` / `cleared_30d` / `oldest_pending_d`
- Variants: amber bell / gray inbox / green check / red clock
- For `oldest_pending_d`, JS appends `'d'` suffix (same pattern as Phase 4 `avg_response_hours` appending `'h'`)

- [ ] **Step 1-5: Mirror Task 3.6.**

- [ ] **Step 6: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Frontend(notifications): KPI strip + notifications-stats.js + notifications.php proxy op=stats"
cd C:/laragon/www/daems-platform
```

**Phase 7 done — full project complete.**

---

## Final verification (run after Phase 7)

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana \
  -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
composer analyse
```

All green; PHPStan 0 errors. Manual UAT walkthrough on all 5 backstage pages confirming:
- Each page renders 4 KPI cards above its existing body
- Values match hand spotcheck SQL
- Sparklines render or render gracefully on empty
- Light/dark theme parity
- Mobile collapse to single column
- No `transform: translate*` / `scale*` in any new CSS or JS
- DevTools Network: each `op=stats` proxy returns 200 with correct payload

Report all commit SHAs from both repos. Wait for "pushaa" before pushing either.

---

## Self-Review Notes

**Spec coverage:** every section in `2026-04-26-per-section-kpi-strips-design.md` §3-§8 maps to a Phase task here. §3 "architectural patterns" is the implicit basis for every task; §10 "acceptance criteria" is the final-verification block above.

**Placeholder scan:** the only "TBD"-shaped reference is in §Phase 7 Task 7.1 "(column TBD per planning — see below)" — this is a deliberate planning-step instruction with a documented verification command, not a plan placeholder. All KPI definitions, SQL queries, file paths, and commit messages are explicit.

**Type consistency:** `statsForTenant` is used on insight/event/project/applications repos (existing or new). Notifications uses `notificationStatsForTenant` on the 4 source repos to avoid colliding with `statsForTenant` semantics that other phases use. `dailyTransitionsForTenant`, `dailyRegistrationsForTenant`, `pendingStatsForTenant`, `clearedDailyForTenant`, `dismissedAppIdsFor` are all unique within their repo's surface. KPI ids are `snake_case` matching Phase 1+2 convention. JS uses identical 4-key shape per phase.

**Scope check:** five sub-phases of identical pattern; spec was already focused enough for one plan.
