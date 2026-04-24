# Member Card QR + Public Profile + Tenant Prefix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the QR code on the member card, the public verification page at `/members/{number}`, the tenant-configurable member-number prefix, and the per-member public-photo privacy toggle.

**Architecture:** Two repos, two PRs. Platform PR ships first (3 sub-packages: tenant prefix, public profile endpoint, member privacy). Society PR ships second (Settings UI, QR rendering, public profile page, profile-settings toggle, e2e).

**Tech Stack:** PHP 8.x clean architecture, MySQL 8.4, vanilla JS + `qrcode-generator` (~3KB MIT npm), Playwright for E2E.

**Spec:** `docs/superpowers/specs/2026-04-24-member-card-qr-public-profile-design.md`

**Branches:** `member-card-qr` in both `daems-platform` and `daem-society` repos.

---

## File structure

| Path | Repo | Action |
|---|---|---|
| `database/migrations/057_add_member_number_prefix_to_tenants.sql` | platform | Create |
| `database/migrations/058_add_public_avatar_visible_to_users.sql` | platform | Create |
| `src/Domain/Tenant/Tenant.php` | platform | Modify (add `?string $memberNumberPrefix`) |
| `src/Domain/User/User.php` | platform | Modify (add `bool $publicAvatarVisible`) |
| `src/Domain/Member/PublicMemberProfile.php` | platform | Create (value object) |
| `src/Application/Backstage/UpdateTenantSettings/{UseCase,Input,Output}.php` | platform | Create |
| `src/Application/Member/GetPublicMemberProfile/{UseCase,Input,Output}.php` | platform | Create |
| `src/Application/Profile/UpdateMyPublicProfilePrivacy/{UseCase,Input,Output}.php` | platform | Create |
| `src/Application/Auth/GetAuthMe/GetAuthMeOutput.php` | platform | Modify (extend `tenant` + add `privacy` keys) |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlPublicMemberRepository.php` | platform | Create |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php` | platform | Modify (add `updatePrefix`, hydrate prefix) |
| `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` | platform | Modify (add `updatePublicAvatarVisible`, hydrate flag) |
| `src/Infrastructure/Adapter/Api/Controller/MemberController.php` | platform | Create |
| `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` | platform | Modify (add `updateTenantSettings`) |
| `src/Infrastructure/Adapter/Api/Controller/UserController.php` | platform | Modify (add `updateMyPrivacy`) |
| `routes/api.php` | platform | Add 3 routes |
| `bootstrap/app.php` | platform | Wire 3 new bindings |
| `tests/Support/KernelHarness.php` | platform | Wire 3 new bindings + extend fakes |
| `tests/Support/Fake/InMemoryTenantRepository.php` | platform | Modify (prefix support) |
| `tests/Support/Fake/InMemoryUserRepository.php` | platform | Modify (privacy support) |
| `tests/Unit/Application/Backstage/UpdateTenantSettingsTest.php` | platform | Create |
| `tests/Unit/Application/Member/GetPublicMemberProfileTest.php` | platform | Create |
| `tests/Unit/Application/Profile/UpdateMyPublicProfilePrivacyTest.php` | platform | Create |
| `tests/Integration/Application/PublicMemberProfileIntegrationTest.php` | platform | Create |
| `package.json` + `package-lock.json` | society | Modify (add `qrcode-generator`) |
| `public/index.php` | society | Modify (add `^/members/(\d+)$` route BEFORE existing slug handler) |
| `public/pages/members/profile.php` | society | Create |
| `public/pages/profile/overview.php` | society | Modify (QR canvas + format member_number) |
| `public/pages/profile/settings.php` | society | Modify (privacy toggle) |
| `public/pages/backstage/settings/index.php` | society | Modify (Membership card section) |
| `public/assets/css/daems.css` | society | Modify (relocate logo + new `.member-card-qr` rule) |
| `public/assets/js/daems.js` | society | Modify (QR rendering + canvas-export QR) |
| `tests/e2e/public-member-profile.spec.ts` | society | Create |

---

## Conventions

- **Commit identity:** every commit `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. NO `Co-Authored-By:` trailer. Never `git push` without explicit user instruction.
- **Working directory:** Tasks 1-12 in `C:\laragon\www\daems-platform`. Tasks 13-22 in `C:\laragon\www\sites\daem-society`. Bash `cd` is reset between tool calls — prefix every command with `cd /c/laragon/www/<repo>/ && <cmd>`.
- **No `.claude/` staging.** **Forbidden tool:** never invoke `mcp__code-review-graph__*`.
- **DI BOTH-wire:** every new use case / repo / controller binds in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php`. PR 4 already taught us this lesson; don't repeat it.
- **PHPStan stays at lvl 9 = 0 errors. PHPUnit Unit + Integration stay green.**

---

## PLATFORM PR — sub-package 1: Tenant member-number prefix

### Task 1: Migration 057 + apply

**Files:**
- Create: `database/migrations/057_add_member_number_prefix_to_tenants.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 057_add_member_number_prefix_to_tenants.sql
-- Tenants choose how their members' card numbers are displayed.
-- NULL = raw number ("123"). "DAEMS" → "DAEMS-123". Frontend formatter
-- strips leading zeros from the raw value and joins with the prefix.

ALTER TABLE tenants
    ADD COLUMN member_number_prefix VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Display prefix for member numbers; NULL = no prefix';
```

- [ ] **Step 2: Apply to dev DB**

```bash
cd /c/laragon/www/daems-platform && php scripts/apply_pending_migrations.php
```

Expected: `Applying 057_add_member_number_prefix_to_tenants.sql … OK`.

- [ ] **Step 3: Verify column landed**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db -e "SHOW COLUMNS FROM tenants LIKE 'member_number_prefix';" 2>&1 | grep -v Warning
```

Expected: row showing `member_number_prefix | varchar(20) | YES | | NULL`.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add database/migrations/057_add_member_number_prefix_to_tenants.sql && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Chore(db): migration 057 adds tenants.member_number_prefix"
```

### Task 2: Tenant domain + repo

**Files:**
- Modify: `src/Domain/Tenant/Tenant.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php`
- Modify: `tests/Support/Fake/InMemoryTenantRepository.php`

- [ ] **Step 1: Extend Tenant value object**

Replace the constructor in `src/Domain/Tenant/Tenant.php`:

```php
final class Tenant
{
    public function __construct(
        public readonly TenantId $id,
        public readonly TenantSlug $slug,
        public readonly string $name,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $memberNumberPrefix = null,
    ) {}
}
```

The new field is optional (default null) so existing callers that build `Tenant` directly without the prefix keep working.

- [ ] **Step 2: Extend SqlTenantRepository: hydrate the new column + add `updatePrefix`**

Find the existing `hydrate` (or equivalent) in `SqlTenantRepository.php`. After reading the existing fields, add:

```php
$prefix = isset($row['member_number_prefix']) && is_string($row['member_number_prefix'])
    ? $row['member_number_prefix']
    : null;
```

And include `$prefix` as the 5th constructor arg when building `new Tenant(...)`. Locate every `SELECT * FROM tenants` or named SELECT — they should now return the new column automatically.

Add a new public method:

```php
public function updatePrefix(TenantId $tenantId, ?string $prefix): void
{
    $this->db->execute(
        'UPDATE tenants SET member_number_prefix = ? WHERE id = ?',
        [$prefix, $tenantId->value()],
    );
}
```

If a `TenantRepositoryInterface` exists in `src/Domain/Tenant/`, add the method signature there too.

- [ ] **Step 3: Mirror `updatePrefix` in InMemoryTenantRepository**

In `tests/Support/Fake/InMemoryTenantRepository.php`, add the same method that mutates the in-memory tenant record's `memberNumberPrefix` field. If the fake stores tenants as immutable `Tenant` objects, replace the stored entry with a freshly-constructed `Tenant` carrying the new prefix.

- [ ] **Step 4: PHPStan**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3
```

Expected: `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Domain/Tenant/Tenant.php src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php tests/Support/Fake/InMemoryTenantRepository.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Refactor(tenant): Tenant carries memberNumberPrefix; repos read+write the column"
```

### Task 3: UpdateTenantSettings use case

**Files:**
- Create: `src/Application/Backstage/UpdateTenantSettings/UpdateTenantSettings.php`
- Create: `src/Application/Backstage/UpdateTenantSettings/UpdateTenantSettingsInput.php`
- Create: `src/Application/Backstage/UpdateTenantSettings/UpdateTenantSettingsOutput.php`
- Create: `tests/Unit/Application/Backstage/UpdateTenantSettingsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Application/Backstage/UpdateTenantSettingsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use PHPUnit\Framework\TestCase;

final class UpdateTenantSettingsTest extends TestCase
{
    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    public function test_admin_sets_prefix(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, slug: 'daems', name: 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), 'DAEMS'));
        self::assertSame('DAEMS', $repo->find($this->tenantId)?->memberNumberPrefix);
    }

    public function test_admin_clears_prefix_with_null(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, slug: 'daems', name: 'Daems', prefix: 'DAEMS');
        $uc = new UpdateTenantSettings($repo);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), null));
        self::assertNull($repo->find($this->tenantId)?->memberNumberPrefix);
    }

    public function test_non_admin_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, slug: 'daems', name: 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingMember(), 'X'));
    }

    public function test_too_long_prefix_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, slug: 'daems', name: 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ValidationException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), str_repeat('A', 21)));
    }

    public function test_invalid_chars_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, slug: 'daems', name: 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ValidationException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), 'DAEMS$'));
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-00000000a001'),
            email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: $this->tenantId,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function actingMember(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-00000000a002'),
            email: 'mem@x',
            isPlatformAdmin: false,
            activeTenant: $this->tenantId,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }
}
```

The fake helpers (`seedTenant`, `find`) may not exist yet on InMemoryTenantRepository — add them as part of this task with whatever signatures the test needs.

- [ ] **Step 2: Implement Input + Output**

`UpdateTenantSettingsInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

use Daems\Domain\Auth\ActingUser;

final class UpdateTenantSettingsInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly ?string $memberNumberPrefix,
    ) {}
}
```

`UpdateTenantSettingsOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

final class UpdateTenantSettingsOutput
{
    public function __construct(
        public readonly ?string $memberNumberPrefix,
    ) {}
}
```

- [ ] **Step 3: Implement the use case**

`UpdateTenantSettings.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantRepositoryInterface;

final class UpdateTenantSettings
{
    public function __construct(private readonly TenantRepositoryInterface $tenants) {}

    public function execute(UpdateTenantSettingsInput $input): UpdateTenantSettingsOutput
    {
        $tenantId = $input->actor->activeTenant;
        if (!$input->actor->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $prefix = $input->memberNumberPrefix;
        if ($prefix !== null) {
            $prefix = trim($prefix);
            if ($prefix === '') {
                $prefix = null;
            } elseif (strlen($prefix) > 20) {
                throw new ValidationException(['member_number_prefix' => 'max_20_chars']);
            } elseif (!preg_match('/^[A-Z0-9\-]+$/', $prefix)) {
                throw new ValidationException(['member_number_prefix' => 'invalid_chars']);
            }
        }

        $this->tenants->updatePrefix($tenantId, $prefix);
        return new UpdateTenantSettingsOutput($prefix);
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Application/Backstage/UpdateTenantSettingsTest.php 2>&1 | tail -8
```

Expected: `OK (5 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Application/Backstage/UpdateTenantSettings/ tests/Unit/Application/Backstage/UpdateTenantSettingsTest.php tests/Support/Fake/InMemoryTenantRepository.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(backstage): UpdateTenantSettings use case (member_number_prefix only for now)"
```

### Task 4: Wire UpdateTenantSettings to BackstageController + route + DI

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Add controller method**

In `BackstageController.php`, near other tenant-related methods, add:

```php
public function updateTenantSettings(Request $request): Response
{
    $actor = $request->requireActingUser();
    $rawPrefix = $request->input('member_number_prefix');
    $prefix = is_string($rawPrefix) ? $rawPrefix : null;

    try {
        $out = $this->updateTenantSettings->execute(
            new UpdateTenantSettingsInput($actor, $prefix),
        );
    } catch (ForbiddenException) {
        return Response::json(['error' => 'forbidden'], 403);
    } catch (ValidationException $e) {
        return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
    }

    return Response::json(['data' => ['member_number_prefix' => $out->memberNumberPrefix]]);
}
```

Add the constructor parameter `private readonly UpdateTenantSettings $updateTenantSettings` to the controller's constructor list. Add the matching `use` import.

- [ ] **Step 2: Add route**

In `routes/api.php`, near the other `/api/v1/backstage/...` routes:

```php
$router->patch('/api/v1/backstage/tenant/settings', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateTenantSettings($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

If the router doesn't have a `patch` method, use `post` instead and document. Check the existing routes for the pattern actually used.

- [ ] **Step 3: Wire DI in bootstrap/app.php**

Find the bindings block. Add:

```php
$c->bind(\Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings::class, fn ($c) =>
    new \Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings(
        $c->make(\Daems\Domain\Tenant\TenantRepositoryInterface::class),
    ),
);
```

(Match the exact factory pattern the existing bindings use — could be `singleton` or different signature.)

- [ ] **Step 4: Mirror in KernelHarness**

In `tests/Support/KernelHarness.php` — bind the same use case using the InMemoryTenantRepository fake.

- [ ] **Step 5: PHPStan + commit**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3
```

Expected: `[OK] No errors`.

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php routes/api.php bootstrap/app.php tests/Support/KernelHarness.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(api): PATCH /api/v1/backstage/tenant/settings"
```

---

## PLATFORM PR — sub-package 2: Public profile endpoint

### Task 5: PublicMemberProfile VO + GetPublicMemberProfile use case + test

**Files:**
- Create: `src/Domain/Member/PublicMemberProfile.php`
- Create: `src/Application/Member/GetPublicMemberProfile/GetPublicMemberProfile.php`
- Create: `src/Application/Member/GetPublicMemberProfile/GetPublicMemberProfileInput.php`
- Create: `src/Application/Member/GetPublicMemberProfile/GetPublicMemberProfileOutput.php`
- Create: `src/Domain/Member/PublicMemberRepositoryInterface.php`
- Create: `tests/Unit/Application/Member/GetPublicMemberProfileTest.php`

- [ ] **Step 1: Define repository interface**

`src/Domain/Member/PublicMemberRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Member;

interface PublicMemberRepositoryInterface
{
    /**
     * Look up a member by raw member_number across ALL tenants.
     * Returns null when no user has that number or the user is soft-deleted.
     */
    public function findByMemberNumber(string $memberNumber): ?PublicMemberProfile;
}
```

- [ ] **Step 2: Define value object**

`src/Domain/Member/PublicMemberProfile.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Member;

final class PublicMemberProfile
{
    public function __construct(
        public readonly string $memberNumberRaw,
        public readonly string $name,
        public readonly string $memberType,
        public readonly ?string $role,
        public readonly ?string $joinedAt,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly bool $publicAvatarVisible,
        public readonly string $avatarInitials,
        public readonly ?string $avatarUrl,
    ) {}
}
```

- [ ] **Step 3: Write failing test**

`tests/Unit/Application/Member/GetPublicMemberProfileTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Member;

use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use PHPUnit\Framework\TestCase;

final class GetPublicMemberProfileTest extends TestCase
{
    public function test_returns_profile_for_known_number(): void
    {
        $repo = $this->fakeRepoWith('123', name: 'Sam', tenantSlug: 'daems', prefix: 'DAEMS', avatarVisible: true, avatarUrl: '/avatars/sam.webp');
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput('123'));

        self::assertSame('Sam', $out->name);
        self::assertSame('DAEMS', $out->tenantMemberNumberPrefix);
        self::assertSame('/avatars/sam.webp', $out->avatarUrl);
        self::assertTrue($out->publicAvatarVisible);
    }

    public function test_returns_null_avatar_when_visibility_off(): void
    {
        $repo = $this->fakeRepoWith('456', name: 'Juma', tenantSlug: 'daems', prefix: null, avatarVisible: false, avatarUrl: null);
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput('456'));

        self::assertFalse($out->publicAvatarVisible);
        self::assertNull($out->avatarUrl);
    }

    public function test_throws_not_found_for_unknown_number(): void
    {
        $repo = new class implements PublicMemberRepositoryInterface {
            public function findByMemberNumber(string $n): ?PublicMemberProfile { return null; }
        };
        $uc = new GetPublicMemberProfile($repo);
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetPublicMemberProfileInput('999'));
    }

    private function fakeRepoWith(string $number, string $name, string $tenantSlug, ?string $prefix, bool $avatarVisible, ?string $avatarUrl): PublicMemberRepositoryInterface
    {
        $profile = new PublicMemberProfile(
            memberNumberRaw: $number,
            name: $name,
            memberType: 'basic',
            role: 'member',
            joinedAt: '2024-06-11',
            tenantSlug: $tenantSlug,
            tenantName: ucfirst($tenantSlug),
            tenantMemberNumberPrefix: $prefix,
            publicAvatarVisible: $avatarVisible,
            avatarInitials: strtoupper(substr($name, 0, 2)),
            avatarUrl: $avatarUrl,
        );
        return new class ($profile) implements PublicMemberRepositoryInterface {
            public function __construct(private readonly PublicMemberProfile $p) {}
            public function findByMemberNumber(string $n): ?PublicMemberProfile {
                return $n === $this->p->memberNumberRaw ? $this->p : null;
            }
        };
    }
}
```

- [ ] **Step 4: Implement Input + Output + UseCase**

`GetPublicMemberProfileInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Member\GetPublicMemberProfile;

final class GetPublicMemberProfileInput
{
    public function __construct(public readonly string $memberNumber) {}
}
```

`GetPublicMemberProfileOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Member\GetPublicMemberProfile;

use Daems\Domain\Member\PublicMemberProfile;

final class GetPublicMemberProfileOutput
{
    public function __construct(
        public readonly string $memberNumberRaw,
        public readonly string $name,
        public readonly string $memberType,
        public readonly ?string $role,
        public readonly ?string $joinedAt,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly bool $publicAvatarVisible,
        public readonly string $avatarInitials,
        public readonly ?string $avatarUrl,
    ) {}

    public static function fromProfile(PublicMemberProfile $p): self
    {
        return new self(
            $p->memberNumberRaw, $p->name, $p->memberType, $p->role, $p->joinedAt,
            $p->tenantSlug, $p->tenantName, $p->tenantMemberNumberPrefix,
            $p->publicAvatarVisible, $p->avatarInitials, $p->avatarUrl,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'member_number_raw'           => $this->memberNumberRaw,
            'name'                        => $this->name,
            'member_type'                 => $this->memberType,
            'role'                        => $this->role,
            'joined_at'                   => $this->joinedAt,
            'tenant_slug'                 => $this->tenantSlug,
            'tenant_name'                 => $this->tenantName,
            'tenant_member_number_prefix' => $this->tenantMemberNumberPrefix,
            'public_avatar_visible'       => $this->publicAvatarVisible,
            'avatar_initials'             => $this->avatarInitials,
            'avatar_url'                  => $this->avatarUrl,
        ];
    }
}
```

`GetPublicMemberProfile.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Member\GetPublicMemberProfile;

use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class GetPublicMemberProfile
{
    public function __construct(private readonly PublicMemberRepositoryInterface $repo) {}

    public function execute(GetPublicMemberProfileInput $input): GetPublicMemberProfileOutput
    {
        $profile = $this->repo->findByMemberNumber($input->memberNumber)
            ?? throw new NotFoundException('member_not_found');
        return GetPublicMemberProfileOutput::fromProfile($profile);
    }
}
```

- [ ] **Step 5: Run test**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Application/Member/GetPublicMemberProfileTest.php 2>&1 | tail -5
```

Expected: `OK (3 tests, …)`.

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Domain/Member/ src/Application/Member/ tests/Unit/Application/Member/ && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(member): GetPublicMemberProfile use case + PublicMemberProfile VO"
```

### Task 6: SqlPublicMemberRepository + Integration test

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlPublicMemberRepository.php`
- Create: `tests/Integration/Application/PublicMemberProfileIntegrationTest.php`

- [ ] **Step 1: Implement the SQL repository**

`SqlPublicMemberRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlPublicMemberRepository implements PublicMemberRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findByMemberNumber(string $memberNumber): ?PublicMemberProfile
    {
        $row = $this->db->queryOne(
            'SELECT u.id, u.name, u.first_name, u.last_name, u.member_number,
                    u.membership_type, u.public_avatar_visible,
                    u.membership_started_at,
                    t.slug AS tenant_slug, t.name AS tenant_name, t.member_number_prefix,
                    ut.role
             FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             JOIN tenants t ON t.id = ut.tenant_id
             WHERE u.member_number = ? AND u.deleted_at IS NULL
             ORDER BY ut.joined_at ASC
             LIMIT 1',
            [$memberNumber],
        );
        if ($row === null) return null;

        $first = (string) ($row['first_name'] ?? '');
        $last  = (string) ($row['last_name']  ?? '');
        $initials = strtoupper(
            ($first !== '' ? mb_substr($first, 0, 1) : '') .
            ($last  !== '' ? mb_substr($last,  0, 1) : '')
        );
        if ($initials === '') {
            $initials = strtoupper(mb_substr((string) ($row['name'] ?? '?'), 0, 2));
        }

        $avatarUrl = $this->avatarPublicUrl((string) $row['id'], (bool) ($row['public_avatar_visible'] ?? 1));

        return new PublicMemberProfile(
            memberNumberRaw: (string) $row['member_number'],
            name: (string) $row['name'],
            memberType: (string) ($row['membership_type'] ?? 'basic'),
            role: isset($row['role']) ? (string) $row['role'] : null,
            joinedAt: isset($row['membership_started_at']) ? substr((string) $row['membership_started_at'], 0, 10) : null,
            tenantSlug: (string) $row['tenant_slug'],
            tenantName: (string) $row['tenant_name'],
            tenantMemberNumberPrefix: isset($row['member_number_prefix']) && is_string($row['member_number_prefix']) ? $row['member_number_prefix'] : null,
            publicAvatarVisible: (bool) ($row['public_avatar_visible'] ?? 1),
            avatarInitials: $initials,
            avatarUrl: $avatarUrl,
        );
    }

    private function avatarPublicUrl(string $userId, bool $visible): ?string
    {
        if (!$visible) return null;
        // Mirror the path convention used by overview.php's avatar resolution.
        // The actual disk path is on daem-society. This URL is built for the
        // daem-society host to serve.
        $safeId = preg_replace('/[^a-f0-9\-]/', '', $userId);
        if (!is_string($safeId) || $safeId === '') return null;
        return '/uploads/avatars/' . $safeId . '.webp';
    }
}
```

Caveats:
- This relies on `users.first_name`, `users.last_name` existing. If the schema only has `users.name`, fall back to splitting on whitespace OR use the full name as initials seed (the code above already falls back).
- The `avatarPublicUrl` returns a path on the daem-society host. The frontend prepends scheme+host when rendering.

- [ ] **Step 2: Write integration test**

`tests/Integration/Application/PublicMemberProfileIntegrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlPublicMemberRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class PublicMemberProfileIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(58);

        $this->conn = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, member_number_prefix, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'daems-pmp', 'Daems PMP', 'DAEMS']);

        $this->userId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, first_name, last_name, email, password_hash, date_of_birth, is_platform_admin, member_number, public_avatar_visible, membership_type, membership_started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?, ?)'
        )->execute([
            $this->userId, 'Sam Hammersmith', 'Sam', 'Hammersmith',
            'sam-pmp@test.com', password_hash('x', PASSWORD_BCRYPT), '1989-04-23',
            '000123', 'founding', '2024-06-11 12:00:00',
        ]);

        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->userId, $this->tenantId, 'member']);
    }

    public function test_finds_existing_member_with_tenant_prefix(): void
    {
        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $out = $uc->execute(new GetPublicMemberProfileInput('000123'));

        self::assertSame('Sam Hammersmith', $out->name);
        self::assertSame('DAEMS', $out->tenantMemberNumberPrefix);
        self::assertSame('founding', $out->memberType);
        self::assertSame('member', $out->role);
        self::assertSame('2024-06-11', $out->joinedAt);
        self::assertTrue($out->publicAvatarVisible);
        self::assertSame('SH', $out->avatarInitials);
    }

    public function test_throws_not_found_for_unknown_number(): void
    {
        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetPublicMemberProfileInput('999999'));
    }

    public function test_returns_null_avatar_url_when_visibility_off(): void
    {
        $this->pdo()->prepare('UPDATE users SET public_avatar_visible = 0 WHERE id = ?')
            ->execute([$this->userId]);

        $uc = new GetPublicMemberProfile(new SqlPublicMemberRepository($this->conn));
        $out = $uc->execute(new GetPublicMemberProfileInput('000123'));

        self::assertFalse($out->publicAvatarVisible);
        self::assertNull($out->avatarUrl);
    }
}
```

If `users.first_name` / `users.last_name` columns don't exist, drop them from the seed INSERT and adjust the assertion.

- [ ] **Step 3: Run integration test**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit tests/Integration/Application/PublicMemberProfileIntegrationTest.php 2>&1 | tail -8
```

Expected: `OK (3 tests, …)`.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Infrastructure/Adapter/Persistence/Sql/SqlPublicMemberRepository.php tests/Integration/Application/PublicMemberProfileIntegrationTest.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(member): SqlPublicMemberRepository + integration test"
```

### Task 7: MemberController + route + DI

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Controller/MemberController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Implement controller**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class MemberController
{
    public function __construct(
        private readonly GetPublicMemberProfile $getPublicMemberProfile,
    ) {}

    /** @param array<string, string> $params */
    public function getPublicProfile(Request $request, array $params): Response
    {
        $number = (string) ($params['number'] ?? '');
        if ($number === '') {
            return Response::json(['error' => 'invalid_member_number'], 400);
        }

        try {
            $out = $this->getPublicMemberProfile->execute(new GetPublicMemberProfileInput($number));
        } catch (NotFoundException) {
            return Response::json(['error' => 'member_not_found'], 404);
        }

        return Response::json(['data' => $out->toArray()]);
    }
}
```

- [ ] **Step 2: Add route — PUBLIC (no AuthMiddleware)**

In `routes/api.php`:

```php
$router->get('/api/v1/members/{number}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MemberController::class)->getPublicProfile($req, $params);
}, []);  // empty middleware list = public
```

If existing public routes use a different convention (e.g. an explicit `[]` is wrong), check at least one other public route in the file and copy that style.

- [ ] **Step 3: Wire DI in BOTH containers**

`bootstrap/app.php`:

```php
$c->bind(\Daems\Domain\Member\PublicMemberRepositoryInterface::class, fn ($c) =>
    new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlPublicMemberRepository(
        $c->make(\Daems\Infrastructure\Framework\Database\Connection::class),
    ),
);

$c->bind(\Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile::class, fn ($c) =>
    new \Daems\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile(
        $c->make(\Daems\Domain\Member\PublicMemberRepositoryInterface::class),
    ),
);
```

`tests/Support/KernelHarness.php` — bind a fake `PublicMemberRepositoryInterface` if E2E tests need it (otherwise skip; integration tests use the SQL implementation directly).

- [ ] **Step 4: PHPStan + smoke**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3
```

Expected: `[OK] No errors`.

Smoke (after starting dev server or via apache):
```bash
curl -i http://daems-platform.local/api/v1/members/000123 2>&1 | head -10
```

Expected: 200 with JSON `data` envelope OR 404 if no member exists with that number locally.

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Infrastructure/Adapter/Api/Controller/MemberController.php routes/api.php bootstrap/app.php tests/Support/KernelHarness.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(api): public GET /api/v1/members/{number} (no auth)"
```

---

## PLATFORM PR — sub-package 3: Member privacy toggle

### Task 8: Migration 058 + apply

**Files:**
- Create: `database/migrations/058_add_public_avatar_visible_to_users.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 058_add_public_avatar_visible_to_users.sql
-- Per-member opt-out for displaying their photo on the public /members/{n} page.
-- Default 1 = visible (matches current behaviour where anyone with the URL
-- already saw whatever was on the user's own profile).

ALTER TABLE users
    ADD COLUMN public_avatar_visible TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Show member photo on the public profile page; 0 = monogram only';
```

- [ ] **Step 2: Apply + verify + commit**

```bash
cd /c/laragon/www/daems-platform && php scripts/apply_pending_migrations.php
```

Expected: `Applying 058_add_public_avatar_visible_to_users.sql … OK`.

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h 127.0.0.1 -u root -psalasana daems_db -e "SHOW COLUMNS FROM users LIKE 'public_avatar_visible';" 2>&1 | grep -v Warning
```

Expected: `public_avatar_visible | tinyint(1) | NO | | 1`.

```bash
cd /c/laragon/www/daems-platform && \
  git add database/migrations/058_add_public_avatar_visible_to_users.sql && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Chore(db): migration 058 adds users.public_avatar_visible"
```

### Task 9: User domain + repo + UpdateMyPublicProfilePrivacy use case

**Files:**
- Modify: `src/Domain/User/User.php` (add `bool $publicAvatarVisible` field + getter)
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` (hydrate + add `updatePublicAvatarVisible(UserId, bool)` method)
- Modify: `tests/Support/Fake/InMemoryUserRepository.php` (mirror)
- Create: `src/Application/Profile/UpdateMyPublicProfilePrivacy/UpdateMyPublicProfilePrivacy.php`
- Create: `src/Application/Profile/UpdateMyPublicProfilePrivacy/UpdateMyPublicProfilePrivacyInput.php`
- Create: `src/Application/Profile/UpdateMyPublicProfilePrivacy/UpdateMyPublicProfilePrivacyOutput.php`
- Create: `tests/Unit/Application/Profile/UpdateMyPublicProfilePrivacyTest.php`

The user-domain change mirrors Task 2's tenant change. Add `public readonly bool $publicAvatarVisible = true` to the `User` constructor (default true keeps existing test fixtures working). Hydrate from the new column in SqlUserRepository.

UseCase pattern:

```php
final class UpdateMyPublicProfilePrivacy
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function execute(UpdateMyPublicProfilePrivacyInput $input): UpdateMyPublicProfilePrivacyOutput
    {
        $this->users->updatePublicAvatarVisible($input->actor->id, $input->publicAvatarVisible);
        return new UpdateMyPublicProfilePrivacyOutput($input->publicAvatarVisible);
    }
}
```

No authorization check beyond "actor is logged in" — actor edits their own privacy via `actor->id`; no admin gate.

Test: 1 happy path (toggle off persists), 1 toggle on, 1 idempotency check.

- [ ] **Step 1: Implement all files (User domain + repo + use case + test)**

Follow the patterns from Task 2 (tenant) and Task 3 (UpdateTenantSettings) — both serve as templates here.

- [ ] **Step 2: Run unit test**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit tests/Unit/Application/Profile/UpdateMyPublicProfilePrivacyTest.php 2>&1 | tail -5
```

Expected: `OK (3 tests, …)`.

- [ ] **Step 3: PHPStan + commit**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3
```

```bash
cd /c/laragon/www/daems-platform && \
  git add src/Domain/User/User.php src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php tests/Support/Fake/InMemoryUserRepository.php src/Application/Profile/UpdateMyPublicProfilePrivacy/ tests/Unit/Application/Profile/UpdateMyPublicProfilePrivacyTest.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(profile): UpdateMyPublicProfilePrivacy use case + User.publicAvatarVisible"
```

### Task 10: UserController::updateMyPrivacy + route + DI

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/UserController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Add controller method to existing UserController**

Add the use case as a constructor parameter, then add:

```php
public function updateMyPrivacy(Request $request): Response
{
    $actor = $request->requireActingUser();
    $rawValue = $request->input('public_avatar_visible');
    $value = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null) {
        return Response::json(['error' => 'invalid_value', 'errors' => ['public_avatar_visible' => 'must_be_boolean']], 422);
    }

    $out = $this->updateMyPrivacy->execute(
        new UpdateMyPublicProfilePrivacyInput($actor, $value),
    );

    return Response::json(['data' => ['public_avatar_visible' => $out->publicAvatarVisible]]);
}
```

- [ ] **Step 2: Add route + wire DI in BOTH**

```php
$router->patch('/api/v1/me/privacy', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\UserController::class)->updateMyPrivacy($req);
}, [AuthMiddleware::class]);
```

Bind `UpdateMyPublicProfilePrivacy` use case in both `bootstrap/app.php` and `tests/Support/KernelHarness.php`.

- [ ] **Step 3: PHPStan + commit**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3 && \
  git add src/Infrastructure/Adapter/Api/Controller/UserController.php routes/api.php bootstrap/app.php tests/Support/KernelHarness.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(api): PATCH /api/v1/me/privacy"
```

### Task 11: Extend GetAuthMe with prefix + privacy

**Files:**
- Modify: `src/Application/Auth/GetAuthMe/GetAuthMeOutput.php`
- Modify: `src/Application/Auth/GetAuthMe/GetAuthMe.php` (UseCase) — read prefix from tenant, privacy from user
- Modify: `tests/Unit/Application/Auth/GetAuthMeTest.php` if exists; otherwise smoke

- [ ] **Step 1: Extend output**

```php
final class GetAuthMeOutput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isPlatformAdmin,
        public readonly bool $publicAvatarVisible,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly ?string $roleInTenant,
        public readonly ?string $tokenExpiresAt,
    ) {}

    public function toArray(): array
    {
        return [
            'user' => [
                'id'                    => $this->userId,
                'name'                  => $this->name,
                'email'                 => $this->email,
                'is_platform_admin'     => $this->isPlatformAdmin,
                'public_avatar_visible' => $this->publicAvatarVisible,
            ],
            'tenant' => [
                'slug'                 => $this->tenantSlug,
                'name'                 => $this->tenantName,
                'member_number_prefix' => $this->tenantMemberNumberPrefix,
            ],
            'role_in_tenant'   => $this->roleInTenant,
            'token_expires_at' => $this->tokenExpiresAt,
        ];
    }
}
```

- [ ] **Step 2: Update the UseCase**

Wherever `GetAuthMe::execute()` builds the Output object, pass the new fields. Read `$user->publicAvatarVisible()` and `$tenant->memberNumberPrefix` (the field added in Task 2).

- [ ] **Step 3: Update existing tests**

If `tests/Unit/Application/Auth/GetAuthMeTest.php` exists, add the new field assertions. Otherwise skip — covered indirectly by integration tests.

- [ ] **Step 4: PHPStan + commit**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3 && \
  git add src/Application/Auth/GetAuthMe/ tests/Unit/Application/Auth/ 2>/dev/null && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(auth): /auth/me response includes tenant.member_number_prefix + user.public_avatar_visible"
```

### Task 12: Push platform branch + verify CI + PR + merge

- [ ] **Step 1: Run full test suite locally**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit 2>&1 | tail -5
```

Expected: `OK (NNN tests, …)` — N should be ~785 (added ~6 tests). Time: ~12 min.

- [ ] **Step 2: Push branch**

```bash
cd /c/laragon/www/daems-platform && git push -u origin member-card-qr 2>&1 | tail -3
```

- [ ] **Step 3: Open draft PR and watch CI**

```bash
cd /c/laragon/www/daems-platform && \
  gh pr create --draft --base dev --head member-card-qr \
    --title "WIP: Member card QR + public profile + tenant prefix + member privacy (backend)" \
    --body "Draft to trigger CI."
```

```bash
cd /c/laragon/www/daems-platform && \
  RUN_ID=$(gh run list --branch member-card-qr --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

Expected: both `PHP 8.1` and `PHP 8.3` jobs green.

- [ ] **Step 4: Move PR to ready + report URL**

```bash
cd /c/laragon/www/daems-platform && \
  PR_NUM=$(gh pr list --head member-card-qr --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Member card QR + public profile + tenant prefix + member privacy (backend)" \
    --body "$(cat <<'EOF'
## Summary
- Migration 057: tenants.member_number_prefix (NULL = no prefix; "DAEMS" → "DAEMS-123")
- Migration 058: users.public_avatar_visible (default 1; member opt-out for public profile photo)
- New use case UpdateTenantSettings (admin) + PATCH /api/v1/backstage/tenant/settings
- New use case GetPublicMemberProfile + public GET /api/v1/members/{number}
- New use case UpdateMyPublicProfilePrivacy + PATCH /api/v1/me/privacy
- /auth/me response extended with tenant.member_number_prefix + user.public_avatar_visible

## Test plan
- [x] composer analyse green (0 errors)
- [x] vendor/bin/phpunit green (Unit + Integration + E2E)
- [x] CI both matrix legs green

## Spec / Plan
- Spec: docs/superpowers/specs/2026-04-24-member-card-qr-public-profile-design.md
- Plan: docs/superpowers/plans/2026-04-24-member-card-qr-public-profile.md

Frontend follows in daem-society PR.
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Platform PR #$PR_NUM ready"
```

Wait for explicit "mergaa platform PR" before merging:

```bash
cd /c/laragon/www/daems-platform && \
  PR_NUM=$(gh pr list --head member-card-qr --json number --jq '.[0].number') && \
  gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

---

## SOCIETY PR — frontend (all 3 sub-packages)

### Task 13: Branch + add qrcode-generator dep

**Files:**
- Modify: `package.json`, `package-lock.json`

- [ ] **Step 1: Branch**

```bash
cd /c/laragon/www/sites/daem-society && git checkout -b member-card-qr dev && git branch --show-current
```

Expected: `member-card-qr`.

- [ ] **Step 2: Add the library**

```bash
cd /c/laragon/www/sites/daem-society && npm install qrcode-generator --save 2>&1 | tail -5
```

Expected: lockfile updates with `qrcode-generator` at the latest 1.x version. No transitive deps added.

- [ ] **Step 3: Verify import path works**

```bash
cd /c/laragon/www/sites/daem-society && node -e "var q=require('qrcode-generator'); var qr=q(0,'L'); qr.addData('test'); qr.make(); console.log('module count:', qr.getModuleCount());"
```

Expected: `module count: 21` (or similar small integer).

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add package.json package-lock.json && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Chore(deps): add qrcode-generator (3KB MIT) for member card QR"
```

### Task 14: PHP helper for formatMemberNumber

**Files:**
- Create: `src/MemberNumberFormatter.php` (or wherever utility helpers live in this repo)

- [ ] **Step 1: Implement helper**

```php
<?php
declare(strict_types=1);

/**
 * Format a raw member_number string for display per tenant convention.
 *
 * - Strips leading zeros from the raw number (a single "0" survives)
 * - Joins with the tenant prefix via "-" if a prefix is set
 * - Returns the bare stripped number when prefix is null/empty
 */
final class MemberNumberFormatter
{
    public static function format(?string $rawNumber, ?string $prefix): string
    {
        $raw = $rawNumber ?? '';
        $stripped = ltrim($raw, '0');
        if ($stripped === '') {
            $stripped = $raw === '' ? '' : '0';
        }

        $prefix = is_string($prefix) ? trim($prefix) : '';
        if ($prefix === '') {
            return $stripped;
        }
        return $prefix . '-' . $stripped;
    }
}
```

- [ ] **Step 2: Unit test (PHPUnit on society side, or smoke if no test runner)**

If `tests/Unit/MemberNumberFormatterTest.php` is reasonable in this repo, add it. Otherwise smoke:

```bash
cd /c/laragon/www/sites/daem-society && \
  php -r "require 'src/MemberNumberFormatter.php'; \
    var_dump(MemberNumberFormatter::format('000123', 'DAEMS')); \
    var_dump(MemberNumberFormatter::format('000123', null)); \
    var_dump(MemberNumberFormatter::format('000', 'X')); \
    var_dump(MemberNumberFormatter::format(null, 'X'));"
```

Expected:
```
string(9) "DAEMS-123"
string(3) "123"
string(3) "X-0"
string(0) ""
```

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add src/MemberNumberFormatter.php tests/Unit/MemberNumberFormatterTest.php 2>/dev/null && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(member): MemberNumberFormatter::format() helper (prefix + strip leading zeros)"
```

### Task 15: Update `/profile/overview.php` to format the number + render QR markup

**Files:**
- Modify: `public/pages/profile/overview.php`

- [ ] **Step 1: Format member_number for display**

Find the line `<span class="mcf-val"><?= htmlspecialchars($user['member_number'] ?? '—') ?></span>`. Replace with:

```php
<span class="mcf-val"><?php
    require_once __DIR__ . '/../../../src/MemberNumberFormatter.php';
    $tenantPrefix = $_SESSION['tenant']['member_number_prefix'] ?? null;
    $formatted = MemberNumberFormatter::format($user['member_number'] ?? null, is_string($tenantPrefix) ? $tenantPrefix : null);
    echo htmlspecialchars($formatted !== '' ? $formatted : '—');
?></span>
```

If `$_SESSION['tenant']` doesn't yet carry `member_number_prefix`, add that population in `daem-society/public/api/auth/login.php` (search for where `$_SESSION['tenant']` is set after login):

```php
$_SESSION['tenant']['member_number_prefix'] = $body['data']['tenant']['member_number_prefix'] ?? null;
```

- [ ] **Step 2: Add QR canvas to the card markup**

Inside the `member-card` div, BEFORE the closing `</div>` of the card, add:

```php
<canvas
    class="member-card-qr"
    id="member-card-qr-canvas"
    width="320" height="320"
    data-member-number="<?= htmlspecialchars((string) ($user['member_number'] ?? '')) ?>"
    aria-label="QR code linking to public verification page"
></canvas>
```

The `width=320 height=320` is the canvas internal resolution (high-DPI ready); CSS displays at 64×64.

- [ ] **Step 3: Relocate the existing logo**

Find `<img ... class="member-card-logo" />` in the card markup. Move it earlier in the markup (it's already in the markup; just CSS positions it). The relocation happens in CSS — see Task 17. No markup change needed here yet.

- [ ] **Step 4: PHP lint + commit**

```bash
cd /c/laragon/www/sites/daem-society && php -l public/pages/profile/overview.php
```

Expected: `No syntax errors detected in public/pages/profile/overview.php`.

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/pages/profile/overview.php public/api/auth/login.php 2>/dev/null && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(profile): format member_number with tenant prefix + add QR canvas to card"
```

### Task 16: QR rendering + canvas-PNG export integration

**Files:**
- Modify: `public/assets/js/daems.js`

- [ ] **Step 1: Add QR render-on-page-load**

Append a new IIFE at the end of `daems.js`:

```javascript
/* ---- Member card QR rendering ---- */
(function () {
    var canvas = document.getElementById('member-card-qr-canvas');
    if (!canvas) return;

    var raw = canvas.getAttribute('data-member-number') || '';
    if (raw === '') return;  // No member number → don't render

    var url = window.location.origin + '/members/' + raw;

    // qrcode-generator API (loaded via require/import in build, or as global)
    // For a no-build setup: load from /node_modules/qrcode-generator/qrcode.js
    // via a <script> tag added to overview.php (see Task 15).
    if (typeof qrcode === 'undefined') {
        console.warn('qrcode-generator not loaded; skipping QR render');
        return;
    }

    var qr = qrcode(0, 'L');  // type 0 = auto-size, 'L' = low error correction
    qr.addData(url);
    qr.make();

    var ctx = canvas.getContext('2d');
    var size = canvas.width;  // 320
    var modules = qr.getModuleCount();
    var cell = size / modules;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000000';
    for (var r = 0; r < modules; r++) {
        for (var c = 0; c < modules; c++) {
            if (qr.isDark(r, c)) {
                ctx.fillRect(c * cell, r * cell, cell, cell);
            }
        }
    }
})();
```

- [ ] **Step 2: Wire qrcode-generator into the page**

In `public/pages/profile/overview.php`, near the bottom where other scripts load, add:

```php
<script src="/node_modules/qrcode-generator/qrcode.js"></script>
```

Verify the path exists:

```bash
cd /c/laragon/www/sites/daem-society && ls node_modules/qrcode-generator/qrcode.js
```

If the file is at a different path (e.g. `qrcode.min.js` or `dist/qrcode.js`), use that.

If `node_modules` isn't web-served by the dev setup, copy the file to `public/assets/js/qrcode-generator.js` and reference that:

```bash
cd /c/laragon/www/sites/daem-society && cp node_modules/qrcode-generator/qrcode.js public/assets/js/qrcode-generator.js
```

Then `<script src="/assets/js/qrcode-generator.js"></script>` in overview.php.

- [ ] **Step 3: Extend generateCardPNG() to draw QR onto canvas**

Find the existing `generateCardPNG` function in `daems.js`. Inside, after the existing card-rendering code but before the canvas → PNG conversion, add:

```javascript
// Draw QR onto the export canvas at proportional position (bottom-right)
var memberNumberRaw = '';
var qrCanvasEl = document.getElementById('member-card-qr-canvas');
if (qrCanvasEl) {
    memberNumberRaw = qrCanvasEl.getAttribute('data-member-number') || '';
}
if (memberNumberRaw && typeof qrcode !== 'undefined') {
    var qrUrl = window.location.origin + '/members/' + memberNumberRaw;
    var qr2 = qrcode(0, 'L');
    qr2.addData(qrUrl);
    qr2.make();

    // Card export canvas dimensions are set elsewhere in generateCardPNG;
    // the values below assume the export canvas is 1015x638 (300dpi ID-1).
    // Adjust to actual export canvas dimensions if different.
    var exportCanvasSize = ctx.canvas.width;  // re-use the 'ctx' variable from this scope
    var qrPixelSize = Math.floor(exportCanvasSize * 0.16);  // ~16% of card width
    var qrPixelX = exportCanvasSize - qrPixelSize - Math.floor(exportCanvasSize * 0.04);
    var cardHeight = ctx.canvas.height;
    var qrPixelY = cardHeight - qrPixelSize - Math.floor(cardHeight * 0.06);

    var qmodules = qr2.getModuleCount();
    var qcell = qrPixelSize / qmodules;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(qrPixelX, qrPixelY, qrPixelSize, qrPixelSize);
    ctx.fillStyle = '#000000';
    for (var rr = 0; rr < qmodules; rr++) {
        for (var cc = 0; cc < qmodules; cc++) {
            if (qr2.isDark(rr, cc)) {
                ctx.fillRect(qrPixelX + cc * qcell, qrPixelY + rr * qcell, qcell, qcell);
            }
        }
    }
}
```

The variable name `ctx` and the export-canvas detection depend on the existing `generateCardPNG` code structure — read the function first and match its variables.

- [ ] **Step 4: Manual smoke (browser)**

Open `http://daems.local/profile` as a logged-in member. Verify:
- QR is visible bottom-right on the card
- Member № reads `DAEMS-123` (or whatever your prefix + number is)
- (GSA only) Click "Download card" → resulting PNG includes the QR

- [ ] **Step 5: Commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/assets/js/daems.js public/pages/profile/overview.php public/assets/js/qrcode-generator.js 2>/dev/null && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(profile): render QR on member card (HTML canvas + canvas-export integration)"
```

### Task 17: CSS — relocate logo to bottom-left + add `.member-card-qr` rule

**Files:**
- Modify: `public/assets/css/daems.css`

- [ ] **Step 1: Find current `.member-card-logo` rule and relocate to bottom-left**

The current rule pins the logo to `bottom: Xpx; right: Xpx`. Change `right` to `left` (preserve the same offset value).

- [ ] **Step 2: Add the QR rule**

Append at the end of `daems.css`:

```css
/* --- Member card QR (bottom-right, passport-style diagonal-cut) --- */
.member-card-qr {
    position: absolute;
    bottom: 16px;
    right: 16px;
    width: 64px;
    height: 64px;
    background: #ffffff;
    /* matches .member-card-avatar clip path style, scaled to 64x64 square */
    clip-path: path('M 0,8 L 0,60 Q 0,64 4,64 L 56,64 Q 58,64 60,62 L 62,60 Q 64,58 64,56 L 64,4 Q 64,0 60,0 L 8,0 Q 5,0 3,2 L 2,3 Q 0,5 0,8 Z');
}
.member-card-qr[data-member-number=""] {
    display: none;  /* hide if no member number to encode */
}
```

- [ ] **Step 3: Manual visual smoke + commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/assets/css/daems.css && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Style(profile): logo bottom-left, QR bottom-right with diagonal-cut clip"
```

### Task 18: Public profile page `/members/{n}`

**Files:**
- Modify: `public/index.php` (route)
- Create: `public/pages/members/profile.php`

- [ ] **Step 1: Add route BEFORE the existing slug handler**

In `public/index.php`, find the line `if (preg_match('#^/members/([a-z0-9\-]+)$#', $uri, $m)) {` (around line 291). IMMEDIATELY BEFORE it, add:

```php
// Public member verification page: /members/{numeric}
if (preg_match('#^/members/(\d+)$#', $uri, $m)) {
    $__memberNumber = $m[1];
    require __DIR__ . '/pages/members/profile.php';
    exit;
}
```

The numeric-only regex `\d+` doesn't collide with the existing `/members/benefits|board-minutes|guides` slug handler.

- [ ] **Step 2: Create the profile page**

`public/pages/members/profile.php`:

```php
<?php
/**
 * Public member verification page — /members/{number}.
 * No login required. Renders name, type, role, joined date, and either avatar
 * (if member's privacy toggle allows) or initials. Disabled "+ Add as friend"
 * button is a placeholder for the future friend system.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../src/MemberNumberFormatter.php';

$pageTitle = 'Member verification';
$memberNumber = $__memberNumber ?? '';

$profile = null;
$fetchError = null;
try {
    $headers = ['Accept: application/json', 'Host: daems-platform.local'];
    $ch = curl_init('http://daems-platform.local/api/v1/members/' . urlencode($memberNumber));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404) {
        http_response_code(404);
        require __DIR__ . '/../errors/404.php';
        exit;
    }
    if ($code >= 200 && $code < 300 && is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $profile = $decoded['data'];
        }
    }
} catch (\Throwable $e) {
    $fetchError = $e->getMessage();
}

if ($profile === null) {
    http_response_code(503);
    $pageTitle = 'Service unavailable';
    require __DIR__ . '/../errors/_template.php';
    exit;
}

$displayedNumber = MemberNumberFormatter::format(
    (string) ($profile['member_number_raw'] ?? ''),
    isset($profile['tenant_member_number_prefix']) ? (string) $profile['tenant_member_number_prefix'] : null,
);

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="public-member-page">
    <div class="public-member-card">

        <div class="public-member-badge">
            <span aria-hidden="true">✓</span>
            Verified <?= $esc((string) $profile['tenant_name']) ?> member
        </div>

        <?php if (!empty($profile['public_avatar_visible']) && !empty($profile['avatar_url'])): ?>
            <img class="public-member-avatar" src="<?= $esc((string) $profile['avatar_url']) ?>" alt="" />
        <?php else: ?>
            <div class="public-member-avatar public-member-avatar--initials"><?= $esc((string) $profile['avatar_initials']) ?></div>
        <?php endif; ?>

        <h1 class="public-member-name"><?= $esc((string) $profile['name']) ?></h1>
        <p class="public-member-sub"><?= $esc(ucfirst((string) ($profile['role'] ?? 'member'))) ?> · <?= $esc((string) $profile['tenant_name']) ?></p>

        <dl class="public-member-fields">
            <div><dt>Member №</dt><dd><?= $esc($displayedNumber) ?></dd></div>
            <div><dt>Type</dt><dd><?= $esc(ucfirst((string) ($profile['member_type'] ?? 'basic'))) ?></dd></div>
            <div><dt>Role</dt><dd><?= $esc(ucfirst((string) ($profile['role'] ?? 'member'))) ?></dd></div>
            <div><dt>Since</dt><dd><?= $esc((string) ($profile['joined_at'] ?? '—')) ?></dd></div>
        </dl>

        <div class="public-member-actions">
            <button type="button" class="btn btn-disabled" disabled>+ Add as friend</button>
            <small class="public-member-soon">Coming soon</small>
        </div>

        <p class="public-member-disclaimer">This is a public verification page. Daem Society does not share contact details or private information here.</p>
    </div>
</div>

<link rel="stylesheet" href="/assets/css/public-member-page.css">

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../layout/_layout.php';
```

Layout path may differ — check existing public-page templates (e.g., `_layout.php`) for the actual include pattern.

- [ ] **Step 3: Create `public-member-page.css`**

`public/assets/css/public-member-page.css`:

```css
.public-member-page { max-width: 540px; margin: 48px auto; padding: 0 16px; }
.public-member-card { padding: 32px; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); text-align: center; }
.public-member-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; background: #d1fae5; color: #065f46; border-radius: 999px; font-size: 13px; font-weight: 600; margin-bottom: 24px; }
.public-member-avatar { width: 96px; height: 124px; margin: 0 auto 16px; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700; clip-path: path('M 0,11 L 0,114 Q 0,124 10,124 L 84,124 Q 88,124 91,121 L 93,118 Q 96,115 96,108 L 96,4 Q 96,0 92,0 L 12,0 Q 8,0 5,3 L 3,5 Q 0,8 0,11 Z'); }
.public-member-avatar--initials { object-fit: cover; }
.public-member-name { font-size: 28px; color: #111827; margin: 0 0 6px; }
.public-member-sub { color: #6b7280; font-size: 15px; margin: 0 0 20px; }
.public-member-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; padding-top: 20px; border-top: 1px solid #f3f4f6; text-align: left; margin: 0; }
.public-member-fields > div > dt { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.05em; margin: 0; }
.public-member-fields > div > dd { font-size: 15px; color: #111827; margin: 2px 0 0; font-weight: 500; }
.public-member-actions { margin-top: 28px; padding-top: 20px; border-top: 1px solid #f3f4f6; }
.public-member-actions .btn-disabled { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: not-allowed; opacity: 0.7; }
.public-member-soon { display: block; margin-top: 8px; font-size: 11px; color: #9ca3af; }
.public-member-disclaimer { margin: 28px 0 0; font-size: 12px; color: #9ca3af; }
```

- [ ] **Step 4: Manual smoke + commit**

```bash
cd /c/laragon/www/sites/daem-society && php -l public/pages/members/profile.php public/index.php
```

Expected: both files lint clean.

Manual smoke (incognito browser, no login): visit `http://daems.local/members/{seeded-number}`. Should render verified badge + name + fields. Visit `/members/9999999` → 404 page.

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/index.php public/pages/members/profile.php public/assets/css/public-member-page.css && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(public): /members/{number} verification page (no auth, fetches from platform)"
```

### Task 19: Settings page — Membership card section

**Files:**
- Modify: `public/pages/backstage/settings/index.php`

- [ ] **Step 1: Add section markup**

Inside the existing `<div class="settings-grid">`, add a new card BEFORE the "Future: branding" stub (or replace that stub entirely if it's now superseded by this concrete setting):

```php
<!-- Membership card -->
<div class="card">
    <div class="card__body">
        <h2 class="card__title"><i class="bi bi-credit-card"></i> Membership card</h2>
        <p class="settings-help">Choose how member numbers display on the membership card. Leave empty for raw numbers (123).</p>
        <form id="settings-form-membership-card" class="settings-form">
            <label class="settings-field">
                <span class="settings-field-label">Member number prefix</span>
                <input type="text" id="settings-member-number-prefix" maxlength="20"
                    pattern="[A-Z0-9\-]+"
                    placeholder="e.g. DAEMS"
                    value="<?= htmlspecialchars((string) ($tenant['member_number_prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </label>
            <button type="submit" class="btn btn--primary btn--sm">Save</button>
            <span id="settings-membership-card-status" class="settings-status" aria-live="polite"></span>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Add JS handler**

At the bottom of `settings/index.php`:

```php
<script>
(function () {
    var form = document.getElementById('settings-form-membership-card');
    if (!form) return;
    var input = document.getElementById('settings-member-number-prefix');
    var status = document.getElementById('settings-membership-card-status');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var raw = input.value.trim();
        var payload = { member_number_prefix: raw === '' ? null : raw };
        status.textContent = 'Saving…';

        fetch('/api/proxy/backstage/tenant/settings', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (res.ok) {
                status.textContent = 'Saved.';
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Membership card prefix saved.', 'success');
            } else {
                status.textContent = 'Error: ' + (res.body && res.body.error || 'unknown');
            }
        })
        .catch(function (err) { status.textContent = 'Network error: ' + err.message; });
    });
})();
</script>
```

The fetch URL `/api/proxy/backstage/tenant/settings` assumes daem-society proxies API calls through `/api/proxy/...` to the platform — check the existing `public/api/` directory structure. If the convention is direct URLs (e.g. `http://daems-platform.local/api/v1/...`), adjust accordingly. Look at how `event-modal.js` or `project-modal.js` make admin PATCH requests — copy that pattern exactly.

- [ ] **Step 3: PHP lint + commit**

```bash
cd /c/laragon/www/sites/daem-society && php -l public/pages/backstage/settings/index.php
```

Expected: lint clean.

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/pages/backstage/settings/index.php && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(settings): Membership card section — admin sets member number prefix"
```

### Task 20: Profile settings — privacy toggle

**Files:**
- Modify: `public/pages/profile/settings.php`

- [ ] **Step 1: Add Privacy section**

Add a new section in the settings form layout (match existing card/section structure in this file):

```php
<div class="card">
    <div class="card__body">
        <h2 class="card__title">Privacy</h2>
        <label class="settings-toggle">
            <input type="checkbox" id="profile-privacy-avatar"
                <?= !empty($_SESSION['user']['public_avatar_visible']) ? 'checked' : '' ?> />
            <span>Show my photo on the public profile page (the QR target)</span>
        </label>
        <span id="profile-privacy-status" class="settings-status" aria-live="polite"></span>
    </div>
</div>

<script>
(function () {
    var cb = document.getElementById('profile-privacy-avatar');
    if (!cb) return;
    var status = document.getElementById('profile-privacy-status');
    cb.addEventListener('change', function () {
        var value = cb.checked;
        status.textContent = 'Saving…';
        fetch('/api/proxy/me/privacy', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ public_avatar_visible: value }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            status.textContent = d && d.error ? ('Error: ' + d.error) : 'Saved.';
            if (window.DAEMS_TOASTS && (!d || !d.error)) {
                window.DAEMS_TOASTS.show('Privacy preference saved.', 'success');
            }
        })
        .catch(function (err) { status.textContent = 'Network error: ' + err.message; });
    });
})();
</script>
```

If `$_SESSION['user']['public_avatar_visible']` isn't populated at login, add it in the same login.php where `member_number_prefix` is added (Task 15 Step 1):

```php
$_SESSION['user']['public_avatar_visible'] = !empty($body['data']['user']['public_avatar_visible']);
```

- [ ] **Step 2: PHP lint + commit**

```bash
cd /c/laragon/www/sites/daem-society && php -l public/pages/profile/settings.php
```

Expected: lint clean.

```bash
cd /c/laragon/www/sites/daem-society && \
  git add public/pages/profile/settings.php public/api/auth/login.php 2>/dev/null && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Feat(profile): Privacy toggle for public-profile photo visibility"
```

### Task 21: E2E spec — public member profile

**Files:**
- Create: `tests/e2e/public-member-profile.spec.ts`

- [ ] **Step 1: Write the spec**

```typescript
import { test, expect } from '@playwright/test';

/**
 * /members/{number} — public verification page (no auth required).
 *
 * This spec runs against the dev server's seeded data; if the dev DB has
 * no member with member_number "1" the test_existing_member case will 404
 * and skip itself.
 */

test.describe('Public member verification page', () => {
    test('unknown member number returns 404', async ({ page }) => {
        const resp = await page.goto('/members/99999999');
        expect(resp?.status()).toBe(404);
    });

    test('existing member shows verification badge', async ({ page }) => {
        // Try a low number; skip if the dev seed doesn't include it
        const resp = await page.goto('/members/1');
        if (resp?.status() === 404) { test.skip(); return; }
        await expect(page.locator('.public-member-badge')).toBeVisible();
        await expect(page.locator('.public-member-name')).toBeVisible();
    });

    test('disabled "Add as friend" button is shown', async ({ page }) => {
        const resp = await page.goto('/members/1');
        if (resp?.status() === 404) { test.skip(); return; }
        const btn = page.locator('.public-member-actions .btn-disabled', { hasText: 'Add as friend' });
        await expect(btn).toBeVisible();
        await expect(btn).toBeDisabled();
    });
});
```

- [ ] **Step 2: Run on chromium**

```bash
cd /c/laragon/www/sites/daem-society && npx playwright test --project=chromium tests/e2e/public-member-profile.spec.ts --reporter=line 2>&1 | tail -10
```

Expected: 1 passing (404), 2 skipped (if no member #1) OR all 3 passing.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add tests/e2e/public-member-profile.spec.ts && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Test(e2e): public member profile (404 + badge + disabled friend button)"
```

### Task 22: Push society branch + verify CI + PR + merge

- [ ] **Step 1: Push**

```bash
cd /c/laragon/www/sites/daem-society && git push -u origin member-card-qr 2>&1 | tail -3
```

- [ ] **Step 2: Open draft PR + wait for CI**

```bash
cd /c/laragon/www/sites/daem-society && \
  gh pr create --draft --base dev --head member-card-qr \
    --title "WIP: Member card QR + public profile + tenant prefix + member privacy (frontend)" \
    --body "Draft to trigger CI."
```

```bash
cd /c/laragon/www/sites/daem-society && \
  RUN_ID=$(gh run list --branch member-card-qr --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

Expected: PHP 8.1, PHP 8.3, Playwright (chromium, smoke) all green.

- [ ] **Step 3: Move PR to ready + report URL**

```bash
cd /c/laragon/www/sites/daem-society && \
  PR_NUM=$(gh pr list --head member-card-qr --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "Member card QR + public profile + tenant prefix + member privacy (frontend)" \
    --body "$(cat <<'EOF'
## Summary
- Added qrcode-generator (3KB MIT) for QR rendering on member card and in card-export PNG
- New helper MemberNumberFormatter::format(rawNumber, prefix) — strips leading zeros, joins with prefix
- /profile/overview.php: member № displayed as "DAEMS-123"; QR canvas in card; logo relocated bottom-left
- /profile/settings.php: Privacy toggle for public-profile photo
- /backstage/settings: Membership card section with prefix input
- /members/{n}: NEW public verification page (no auth, server-side cURL to platform API)
- E2E spec: 404 + badge + disabled friend button

## Test plan
- [x] PHP lint clean across modified files
- [x] CI matrix legs green
- [x] Playwright smoke green (home.spec.ts unaffected)
- [ ] Manual: scan QR on real card → opens /members/{n} on phone

## Spec / Plan
- Spec: daems-platform/docs/superpowers/specs/2026-04-24-member-card-qr-public-profile-design.md
- Plan: daems-platform/docs/superpowers/plans/2026-04-24-member-card-qr-public-profile.md

Depends on platform PR (already merged).
EOF
)" && \
  gh pr ready "$PR_NUM" && \
  echo "Society PR #$PR_NUM ready"
```

- [ ] **Step 4: Wait for explicit "mergaa society PR" before merging**

```bash
cd /c/laragon/www/sites/daem-society && \
  PR_NUM=$(gh pr list --head member-card-qr --json number --jq '.[0].number') && \
  gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

- [ ] **Step 5: Final report**

Report to user:
- Both PRs merged (with merge-commit SHAs)
- Migrations 057 + 058 applied to dev DB
- Manual verification: visit `/profile`, see QR, scan it, lands on `/members/{n}`. Visit `/backstage/settings`, set/clear prefix, observe re-format on `/profile`. Toggle privacy on `/profile/settings`, refresh public profile in incognito to confirm avatar swap.
