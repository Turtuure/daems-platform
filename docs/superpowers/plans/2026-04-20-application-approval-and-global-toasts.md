# Application Approval Flow + Global Toasts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `DecideApplication` actually activate approved members/supporters (create users, attach to tenant, assign member number, issue invite token), and lift the pending-applications toast out of the dashboard to appear on every backstage page with per-session dismiss semantics.

**Architecture:** Five forward-only migrations (036–040) add the schema for nullable credentials, member counters, invite tokens, and dismissal records. Backend adds Application-layer use cases (`IssueInvite`, `MemberActivationService`, `SupporterActivationService`, `DismissApplication`, `ListPendingApplicationsForAdmin`, `RedeemInvite`) composed inside a transactional `DecideApplication` approve path. Frontend adds a JS toast module included in the shared backstage layout.

**Tech Stack:** PHP 8.1, Clean Architecture (Domain/Application/Infrastructure), PDO/MySQL 8, PHPUnit, PHPStan level 9. Frontend: daem-society PHP + vanilla JS via existing `ApiClient`.

**Spec:** `docs/superpowers/specs/2026-04-20-application-approval-and-global-toasts-design.md`

**Commit identity (every commit):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."` — never use `Co-Authored-By`. Never `git add .claude/`. Never auto-push.

---

## File Inventory

**Migrations (new):**
- `database/migrations/036_make_users_password_hash_nullable.sql`
- `database/migrations/037_make_users_date_of_birth_nullable.sql`
- `database/migrations/038_create_tenant_member_counters.sql`
- `database/migrations/039_create_user_invites.sql`
- `database/migrations/040_create_admin_application_dismissals.sql`

**Domain (new):**
- `src/Domain/Invite/UserInvite.php`
- `src/Domain/Invite/InviteToken.php`
- `src/Domain/Invite/UserInviteRepositoryInterface.php`
- `src/Domain/Invite/TokenGeneratorInterface.php`
- `src/Domain/Tenant/TenantMemberCounterRepositoryInterface.php`
- `src/Domain/Dismissal/AdminApplicationDismissal.php`
- `src/Domain/Dismissal/AdminApplicationDismissalRepositoryInterface.php`
- `src/Domain/Config/BaseUrlResolverInterface.php`

**Domain (modified):**
- `src/Domain/User/User.php` — `passwordHash` and `dateOfBirth` become nullable.
- `src/Domain/User/UserRepositoryInterface.php` — add `createActivated(...)` for invite-based creation.

**Infrastructure (new):**
- `src/Infrastructure/Token/RandomTokenGenerator.php`
- `src/Infrastructure/Config/EnvBaseUrlResolver.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlUserInviteRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantMemberCounterRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminApplicationDismissalRepository.php`
- `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryUserInviteRepository.php`
- `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryTenantMemberCounterRepository.php`
- `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryAdminApplicationDismissalRepository.php`

**Infrastructure (modified):**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` — add `createActivated(...)`, allow NULL hydration.
- `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryUserRepository.php` — mirror.

**Application use cases (new):**
- `src/Application/Invite/IssueInvite/{IssueInvite.php, IssueInviteInput.php, IssueInviteOutput.php}`
- `src/Application/Invite/RedeemInvite/{RedeemInvite.php, RedeemInviteInput.php, RedeemInviteOutput.php}`
- `src/Application/Backstage/ActivateMember/MemberActivationService.php`
- `src/Application/Backstage/ActivateSupporter/SupporterActivationService.php`
- `src/Application/Backstage/DismissApplication/{DismissApplication.php, DismissApplicationInput.php}`
- `src/Application/Backstage/ListPendingApplications/{ListPendingApplicationsForAdmin.php, ListPendingApplicationsForAdminInput.php, ListPendingApplicationsForAdminOutput.php}`

**Application (modified):**
- `src/Application/Backstage/DecideApplication/DecideApplication.php` — new dependencies, transactional approve path.
- `src/Application/Backstage/DecideApplication/DecideApplicationOutput.php` — add `activatedUserId`, `inviteUrl`, `inviteExpiresAt`, `memberNumber`.
- `src/Application/Auth/LoginUser/LoginUser.php` — reject NULL hash; clear dismissals on success.

**HTTP (modified):**
- `src/Infrastructure/Http/Controller/BackstageController.php` — add `listPendingForAdmin`, `dismissApplication`.
- `src/Infrastructure/Http/Controller/AuthController.php` — add `redeemInvite`.
- `routes/api.php` — wire new endpoints.

**Wiring (modified):**
- `bootstrap/app.php` — bind all new classes.
- `tests/Support/KernelHarness.php` — bind InMemory variants.

**Frontend daem-society (new):**
- `sites/daem-society/public/pages/backstage/toasts.js`
- `sites/daem-society/public/pages/backstage/toasts.css`
- `sites/daem-society/public/pages/invite.php`

**Frontend daem-society (modified):**
- `sites/daem-society/public/pages/backstage/layout.php` — include toast module.
- `sites/daem-society/public/pages/backstage/index.php` — remove inline toast block.
- `sites/daem-society/public/pages/backstage/applications/index.php` — show invite-URL success toast, support `?highlight=` scroll.

**Tests (new):**
- `tests/Integration/Migration/Migration036Test.php` … `Migration040Test.php`
- `tests/Unit/Application/Invite/IssueInviteTest.php`
- `tests/Unit/Application/Invite/RedeemInviteTest.php`
- `tests/Unit/Application/Backstage/MemberActivationServiceTest.php`
- `tests/Unit/Application/Backstage/SupporterActivationServiceTest.php`
- `tests/Unit/Application/Backstage/DecideApplicationApproveMemberTest.php`
- `tests/Unit/Application/Backstage/DecideApplicationApproveSupporterTest.php`
- `tests/Unit/Application/Backstage/DismissApplicationTest.php`
- `tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php`
- `tests/Unit/Application/Auth/LoginUserNullHashTest.php`
- `tests/Integration/Application/MemberActivationIntegrationTest.php`
- `tests/Integration/Application/InviteRedemptionIntegrationTest.php`
- `tests/Isolation/ApplicationApprovalTenantIsolationTest.php`
- `tests/Isolation/AdminDismissalTenantIsolationTest.php`
- `tests/E2E/Backstage/ApproveAndInviteFlowTest.php`
- `tests/E2E/Backstage/PendingCountAndDismissTest.php`
- `tests/E2E/Auth/InviteRedeemEndpointTest.php`

---

## Task Order (dependency-correct)

Tasks 1–5 (migrations) must land before any Application-layer work. Tasks 6–10 (Domain/Infrastructure) must land before 11–18 (use cases). 19 (HTTP) depends on 11–18. 20 (wiring) depends on 19. 21–22 are cross-cutting tests that require 20. 23–24 (frontend) depend on 19+20.

---

### Task 1: Migration 036 — make `users.password_hash` nullable

**Files:**
- Create: `database/migrations/036_make_users_password_hash_nullable.sql`
- Create: `tests/Integration/Migration/Migration036Test.php`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Migration/Migration036Test.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration036Test extends MigrationTestCase
{
    public function test_password_hash_becomes_nullable(): void
    {
        $this->runMigrationsUpTo(35);
        $this->runMigration('036_make_users_password_hash_nullable.sql');

        $stmt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'password_hash'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('YES', $row['IS_NULLABLE']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration036Test.php`
Expected: failure because migration file does not exist.

- [ ] **Step 3: Create the migration**

`database/migrations/036_make_users_password_hash_nullable.sql`:

```sql
ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration036Test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/036_make_users_password_hash_nullable.sql tests/Integration/Migration/Migration036Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(auth): make users.password_hash nullable (invite-based activation)"
```

---

### Task 2: Migration 037 — make `users.date_of_birth` nullable

**Files:**
- Create: `database/migrations/037_make_users_date_of_birth_nullable.sql`
- Create: `tests/Integration/Migration/Migration037Test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration037Test extends MigrationTestCase
{
    public function test_date_of_birth_becomes_nullable(): void
    {
        $this->runMigrationsUpTo(36);
        $this->runMigration('037_make_users_date_of_birth_nullable.sql');

        $stmt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'date_of_birth'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('YES', $row['IS_NULLABLE']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration037Test.php`
Expected: failure (migration missing).

- [ ] **Step 3: Create the migration**

```sql
ALTER TABLE users MODIFY date_of_birth DATE NULL;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration037Test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/037_make_users_date_of_birth_nullable.sql tests/Integration/Migration/Migration037Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(users): make date_of_birth nullable (supporter applications have no DoB)"
```

---

### Task 3: Migration 038 — `tenant_member_counters` with backfill

**Files:**
- Create: `database/migrations/038_create_tenant_member_counters.sql`
- Create: `tests/Integration/Migration/Migration038Test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration038Test extends MigrationTestCase
{
    public function test_tenant_member_counters_table_created(): void
    {
        $this->runMigrationsUpTo(37);
        $this->runMigration('038_create_tenant_member_counters.sql');

        $cols = $this->columnsOf('tenant_member_counters');
        self::assertContains('tenant_id', $cols);
        self::assertContains('next_value', $cols);
        self::assertContains('updated_at', $cols);

        $fks = $this->foreignKeysOf('tenant_member_counters');
        self::assertContains('fk_tmc_tenant', $fks);
    }

    public function test_backfill_seeds_each_existing_tenant(): void
    {
        $this->runMigrationsUpTo(37);

        $this->pdo->exec(
            "INSERT INTO tenants (id, slug, name, status, created_at, updated_at)
             VALUES ('t-1','daems','Daems','active',NOW(),NOW()),
                    ('t-2','saheg','SaheGroup','active',NOW(),NOW())"
        );
        $this->pdo->exec(
            "INSERT INTO users (id, name, email, password_hash, country, address_street, address_zip,
                                address_city, address_country, membership_type, membership_status,
                                member_number, created_at)
             VALUES ('u-1','A','a@x','h','FI','','','','','individual','active','00007',NOW()),
                    ('u-2','B','b@x','h','FI','','','','','individual','active','00003',NOW())"
        );
        $this->pdo->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('u-1','t-1','member',NOW()),
                    ('u-2','t-2','member',NOW())"
        );

        $this->runMigration('038_create_tenant_member_counters.sql');

        $rows = $this->pdo->query(
            "SELECT tenant_id, next_value FROM tenant_member_counters ORDER BY tenant_id"
        )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        self::assertCount(2, $rows);
        self::assertSame(['tenant_id' => 't-1', 'next_value' => 8], $rows[0]);
        self::assertSame(['tenant_id' => 't-2', 'next_value' => 4], $rows[1]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration038Test.php`
Expected: failure (migration missing).

- [ ] **Step 3: Create the migration**

`database/migrations/038_create_tenant_member_counters.sql`:

```sql
CREATE TABLE tenant_member_counters (
    tenant_id  CHAR(36) NOT NULL,
    next_value INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tmc_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_member_counters (tenant_id, next_value, updated_at)
SELECT
    t.id,
    COALESCE(MAX(CAST(u.member_number AS UNSIGNED)), 0) + 1,
    NOW()
FROM tenants t
LEFT JOIN user_tenants ut
       ON ut.tenant_id = t.id AND ut.role = 'member' AND ut.left_at IS NULL
LEFT JOIN users u
       ON u.id = ut.user_id AND u.member_number IS NOT NULL
GROUP BY t.id;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration038Test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/038_create_tenant_member_counters.sql tests/Integration/Migration/Migration038Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(members): tenant_member_counters with backfill from existing member_numbers"
```

---

### Task 4: Migration 039 — `user_invites`

**Files:**
- Create: `database/migrations/039_create_user_invites.sql`
- Create: `tests/Integration/Migration/Migration039Test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration039Test extends MigrationTestCase
{
    public function test_user_invites_table_created(): void
    {
        $this->runMigrationsUpTo(38);
        $this->runMigration('039_create_user_invites.sql');

        $cols = $this->columnsOf('user_invites');
        self::assertContains('id', $cols);
        self::assertContains('user_id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('token_hash', $cols);
        self::assertContains('issued_at', $cols);
        self::assertContains('expires_at', $cols);
        self::assertContains('used_at', $cols);

        $fks = $this->foreignKeysOf('user_invites');
        self::assertContains('fk_user_invites_user', $fks);
        self::assertContains('fk_user_invites_tenant', $fks);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration039Test.php`
Expected: failure (table missing).

- [ ] **Step 3: Create the migration**

```sql
CREATE TABLE user_invites (
    id         CHAR(36) NOT NULL,
    user_id    CHAR(36) NOT NULL,
    tenant_id  CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    issued_at  DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_invites_token (token_hash),
    KEY idx_user_invites_user (user_id),
    CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_invites_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration039Test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/039_create_user_invites.sql tests/Integration/Migration/Migration039Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(invite): user_invites table (token_hash + expires_at)"
```

---

### Task 5: Migration 040 — `admin_application_dismissals`

**Files:**
- Create: `database/migrations/040_create_admin_application_dismissals.sql`
- Create: `tests/Integration/Migration/Migration040Test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration040Test extends MigrationTestCase
{
    public function test_admin_application_dismissals_table_created(): void
    {
        $this->runMigrationsUpTo(39);
        $this->runMigration('040_create_admin_application_dismissals.sql');

        $cols = $this->columnsOf('admin_application_dismissals');
        self::assertContains('id', $cols);
        self::assertContains('admin_id', $cols);
        self::assertContains('app_id', $cols);
        self::assertContains('app_type', $cols);
        self::assertContains('dismissed_at', $cols);

        $fks = $this->foreignKeysOf('admin_application_dismissals');
        self::assertContains('fk_aad_admin', $fks);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration040Test.php`
Expected: failure.

- [ ] **Step 3: Create the migration**

```sql
CREATE TABLE admin_application_dismissals (
    id           CHAR(36) NOT NULL,
    admin_id     CHAR(36) NOT NULL,
    app_id       CHAR(36) NOT NULL,
    app_type     ENUM('member','supporter') NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_app (admin_id, app_id),
    KEY idx_aad_admin (admin_id),
    CONSTRAINT fk_aad_admin FOREIGN KEY (admin_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration040Test.php`
Expected: PASS.

Also update `tests/Isolation/IsolationTestCase.php` to run migrations up to 40 — find the `runMigrationsUpTo(35)` call and change the argument to `40`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/040_create_admin_application_dismissals.sql tests/Integration/Migration/Migration040Test.php tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(toasts): admin_application_dismissals table + bump IsolationTestCase migration ceiling to 40"
```

---

### Task 6: Domain — Invite bounded context (entity + VO + repo interface)

**Files:**
- Create: `src/Domain/Invite/UserInvite.php`
- Create: `src/Domain/Invite/InviteToken.php`
- Create: `src/Domain/Invite/UserInviteRepositoryInterface.php`
- Create: `src/Domain/Invite/TokenGeneratorInterface.php`

- [ ] **Step 1: Create `InviteToken` value object**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

final class InviteToken
{
    public function __construct(
        public readonly string $raw,
        public readonly string $hash,
    ) {}

    public static function fromRaw(string $raw): self
    {
        return new self($raw, hash('sha256', $raw));
    }
}
```

- [ ] **Step 2: Create `UserInvite` entity**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

final class UserInvite
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly string $tokenHash,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $usedAt = null,
    ) {}

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $now < $this->expiresAt;
    }
}
```

- [ ] **Step 3: Create `UserInviteRepositoryInterface`**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

interface UserInviteRepositoryInterface
{
    public function save(UserInvite $invite): void;

    public function findByTokenHash(string $tokenHash): ?UserInvite;

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void;
}
```

- [ ] **Step 4: Create `TokenGeneratorInterface`**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

interface TokenGeneratorInterface
{
    /** Returns a url-safe random string of at least 32 bytes of entropy. */
    public function generate(): string;
}
```

- [ ] **Step 5: Run PHPStan to verify nothing broken**

Run: `composer analyse`
Expected: PASS, 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Invite/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(invite): domain — UserInvite entity, InviteToken VO, repo + token-generator interfaces"
```

---

### Task 7: Domain — TenantMemberCounter + AdminApplicationDismissal + BaseUrlResolver

**Files:**
- Create: `src/Domain/Tenant/TenantMemberCounterRepositoryInterface.php`
- Create: `src/Domain/Dismissal/AdminApplicationDismissal.php`
- Create: `src/Domain/Dismissal/AdminApplicationDismissalRepositoryInterface.php`
- Create: `src/Domain/Config/BaseUrlResolverInterface.php`

- [ ] **Step 1: Create `TenantMemberCounterRepositoryInterface`**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantMemberCounterRepositoryInterface
{
    /**
     * Atomically allocate the next member number for the given tenant.
     * Implementations MUST perform this under a row lock that serialises
     * concurrent callers. Returned value is zero-padded to 5 digits.
     */
    public function allocateNext(string $tenantId): string;
}
```

- [ ] **Step 2: Create `AdminApplicationDismissal` entity**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Dismissal;

final class AdminApplicationDismissal
{
    public function __construct(
        public readonly string $id,
        public readonly string $adminId,
        public readonly string $appId,
        public readonly string $appType, // 'member' | 'supporter'
        public readonly \DateTimeImmutable $dismissedAt,
    ) {}
}
```

- [ ] **Step 3: Create `AdminApplicationDismissalRepositoryInterface`**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Dismissal;

interface AdminApplicationDismissalRepositoryInterface
{
    /** Idempotent: upsert keyed by (admin_id, app_id). */
    public function save(AdminApplicationDismissal $dismissal): void;

    public function deleteByAdminId(string $adminId): void;

    public function deleteByAppId(string $appId): void;

    /** @return list<string> app_ids dismissed by this admin */
    public function listAppIdsDismissedByAdmin(string $adminId): array;
}
```

- [ ] **Step 4: Create `BaseUrlResolverInterface`**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Config;

interface BaseUrlResolverInterface
{
    /** Returns the public frontend base URL for the given tenant, no trailing slash. */
    public function resolveFrontendBaseUrl(string $tenantId): string;
}
```

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Tenant/ src/Domain/Dismissal/ src/Domain/Config/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): member-counter, admin-dismissal, base-url-resolver interfaces"
```

---

### Task 8: Domain User — nullable fields + `createActivated` contract

**Files:**
- Modify: `src/Domain/User/User.php`
- Modify: `src/Domain/User/UserRepositoryInterface.php`
- Modify: `src/Application/Auth/LoginUser/LoginUser.php` (defensive — property type change)

- [ ] **Step 1: Make `User::passwordHash` and `User::dateOfBirth` nullable**

Edit `src/Domain/User/User.php`:

Change constructor params:
```php
private readonly ?string $passwordHash,
private readonly ?string $dateOfBirth,
```

Change getters:
```php
public function passwordHash(): ?string { return $this->passwordHash; }
public function dateOfBirth(): ?string { return $this->dateOfBirth; }
```

- [ ] **Step 2: Extend `UserRepositoryInterface` with `createActivated`**

Edit `src/Domain/User/UserRepositoryInterface.php` — add method after `save`:

```php
/**
 * Insert a user that is active but has no password yet (pending invite).
 * Returns the persisted domain object.
 *
 * @param array{
 *     name: string,
 *     email: string,
 *     date_of_birth: ?string,
 *     country: string,
 *     membership_type: string,
 *     membership_status: string,
 *     member_number: ?string
 * } $fields
 */
public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User;
```

- [ ] **Step 3: Run PHPStan — fix any call sites**

Run: `composer analyse`
Expected: failures — `LoginUser` passes `$user?->passwordHash() ?? DUMMY` but PHPStan sees `?string`. That code path already tolerates null because the `??` falls through to the dummy. Fix by explicit null check:

In `src/Application/Auth/LoginUser/LoginUser.php` at L44, keep the existing line — it already works correctly when `passwordHash()` returns `null` because `?->` returns null and `??` falls through. Confirm PHPStan is still green.

- [ ] **Step 4: Run unit + integration tests**

Run: `composer test`
Expected: PASS. If existing tests construct `User` with non-null DoB and hash, they remain valid — nullability is widened, not narrowed.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/User/User.php src/Domain/User/UserRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Refactor(user): nullable password_hash/date_of_birth + createActivated contract"
```

---

### Task 9: Infrastructure — InMemory repositories

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryUserInviteRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryTenantMemberCounterRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryAdminApplicationDismissalRepository.php`
- Modify: `src/Infrastructure/Adapter/Persistence/InMemory/InMemoryUserRepository.php`

- [ ] **Step 1: Create `InMemoryUserInviteRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\InMemory;

use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;

final class InMemoryUserInviteRepository implements UserInviteRepositoryInterface
{
    /** @var array<string, UserInvite> */
    private array $byId = [];

    public function save(UserInvite $invite): void
    {
        $this->byId[$invite->id] = $invite;
    }

    public function findByTokenHash(string $tokenHash): ?UserInvite
    {
        foreach ($this->byId as $invite) {
            if ($invite->tokenHash === $tokenHash) {
                return $invite;
            }
        }
        return null;
    }

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void
    {
        if (!isset($this->byId[$inviteId])) {
            return;
        }
        $old = $this->byId[$inviteId];
        $this->byId[$inviteId] = new UserInvite(
            $old->id, $old->userId, $old->tenantId, $old->tokenHash,
            $old->issuedAt, $old->expiresAt, $usedAt
        );
    }
}
```

- [ ] **Step 2: Create `InMemoryTenantMemberCounterRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\InMemory;

use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;

final class InMemoryTenantMemberCounterRepository implements TenantMemberCounterRepositoryInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    public function allocateNext(string $tenantId): string
    {
        $next = $this->counters[$tenantId] ?? 1;
        $this->counters[$tenantId] = $next + 1;
        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function setNextForTesting(string $tenantId, int $value): void
    {
        $this->counters[$tenantId] = $value;
    }
}
```

- [ ] **Step 3: Create `InMemoryAdminApplicationDismissalRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\InMemory;

use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;

final class InMemoryAdminApplicationDismissalRepository implements AdminApplicationDismissalRepositoryInterface
{
    /** @var list<AdminApplicationDismissal> */
    private array $rows = [];

    public function save(AdminApplicationDismissal $d): void
    {
        foreach ($this->rows as $i => $existing) {
            if ($existing->adminId === $d->adminId && $existing->appId === $d->appId) {
                $this->rows[$i] = $d;
                return;
            }
        }
        $this->rows[] = $d;
    }

    public function deleteByAdminId(string $adminId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->adminId !== $adminId
        ));
    }

    public function deleteByAppId(string $appId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->appId !== $appId
        ));
    }

    public function listAppIdsDismissedByAdmin(string $adminId): array
    {
        return array_values(array_map(
            static fn (AdminApplicationDismissal $d): string => $d->appId,
            array_filter(
                $this->rows,
                static fn (AdminApplicationDismissal $d): bool => $d->adminId === $adminId
            )
        ));
    }
}
```

- [ ] **Step 4: Add `createActivated` to `InMemoryUserRepository`**

Read the existing file first; locate the class, add method mirroring the interface:

```php
public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User
{
    $user = new User(
        new UserId($userId),
        $fields['name'],
        $fields['email'],
        null,
        $fields['date_of_birth'],
        $fields['country'],
        '',
        '',
        '',
        '',
        $fields['membership_type'],
        $fields['membership_status'],
        $fields['member_number'],
        $now->format('Y-m-d H:i:s'),
        false
    );
    $this->save($user);
    return $user;
}
```

- [ ] **Step 5: Run PHPStan + unit tests**

Run: `composer analyse && vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/InMemory/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(infra): InMemory repos for invites, member-counter, dismissals + User.createActivated"
```

---

### Task 10: Infrastructure — Token generator + BaseUrlResolver + SQL repositories

**Files:**
- Create: `src/Infrastructure/Token/RandomTokenGenerator.php`
- Create: `src/Infrastructure/Config/EnvBaseUrlResolver.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserInviteRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantMemberCounterRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminApplicationDismissalRepository.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php`

- [ ] **Step 1: `RandomTokenGenerator`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Token;

use Daems\Domain\Invite\TokenGeneratorInterface;

final class RandomTokenGenerator implements TokenGeneratorInterface
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: `EnvBaseUrlResolver`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Config;

use Daems\Domain\Config\BaseUrlResolverInterface;

final class EnvBaseUrlResolver implements BaseUrlResolverInterface
{
    /** @param array<string,string> $map tenant_id => base URL (no trailing slash) */
    public function __construct(
        private readonly array $map,
        private readonly string $fallback,
    ) {}

    public function resolveFrontendBaseUrl(string $tenantId): string
    {
        return $this->map[$tenantId] ?? $this->fallback;
    }
}
```

- [ ] **Step 3: `SqlUserInviteRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use PDO;

final class SqlUserInviteRepository implements UserInviteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(UserInvite $invite): void
    {
        $sql = 'INSERT INTO user_invites
                  (id, user_id, tenant_id, token_hash, issued_at, expires_at, used_at)
                VALUES (?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute([
            $invite->id,
            $invite->userId,
            $invite->tenantId,
            $invite->tokenHash,
            $invite->issuedAt->format('Y-m-d H:i:s'),
            $invite->expiresAt->format('Y-m-d H:i:s'),
            $invite->usedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?UserInvite
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, tenant_id, token_hash, issued_at, expires_at, used_at
             FROM user_invites WHERE token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return new UserInvite(
            (string) $row['id'],
            (string) $row['user_id'],
            (string) $row['tenant_id'],
            (string) $row['token_hash'],
            new \DateTimeImmutable((string) $row['issued_at']),
            new \DateTimeImmutable((string) $row['expires_at']),
            $row['used_at'] !== null ? new \DateTimeImmutable((string) $row['used_at']) : null,
        );
    }

    public function markUsed(string $inviteId, \DateTimeImmutable $usedAt): void
    {
        $this->pdo->prepare('UPDATE user_invites SET used_at = ? WHERE id = ?')
            ->execute([$usedAt->format('Y-m-d H:i:s'), $inviteId]);
    }
}
```

- [ ] **Step 4: `SqlTenantMemberCounterRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use PDO;
use RuntimeException;

final class SqlTenantMemberCounterRepository implements TenantMemberCounterRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function allocateNext(string $tenantId): string
    {
        // Ensure a row exists for this tenant (idempotent for tenants created after 038 ran).
        $this->pdo->prepare(
            'INSERT IGNORE INTO tenant_member_counters (tenant_id, next_value) VALUES (?, 1)'
        )->execute([$tenantId]);

        $select = $this->pdo->prepare(
            'SELECT next_value FROM tenant_member_counters WHERE tenant_id = ? FOR UPDATE'
        );
        $select->execute([$tenantId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('tenant_member_counter_missing');
        }
        $next = (int) $row['next_value'];

        $this->pdo->prepare(
            'UPDATE tenant_member_counters SET next_value = next_value + 1 WHERE tenant_id = ?'
        )->execute([$tenantId]);

        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 5: `SqlAdminApplicationDismissalRepository`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use PDO;

final class SqlAdminApplicationDismissalRepository implements AdminApplicationDismissalRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(AdminApplicationDismissal $d): void
    {
        $sql = 'INSERT INTO admin_application_dismissals
                  (id, admin_id, app_id, app_type, dismissed_at)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE dismissed_at = VALUES(dismissed_at)';
        $this->pdo->prepare($sql)->execute([
            $d->id,
            $d->adminId,
            $d->appId,
            $d->appType,
            $d->dismissedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function deleteByAdminId(string $adminId): void
    {
        $this->pdo->prepare('DELETE FROM admin_application_dismissals WHERE admin_id = ?')
            ->execute([$adminId]);
    }

    public function deleteByAppId(string $appId): void
    {
        $this->pdo->prepare('DELETE FROM admin_application_dismissals WHERE app_id = ?')
            ->execute([$appId]);
    }

    public function listAppIdsDismissedByAdmin(string $adminId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT app_id FROM admin_application_dismissals WHERE admin_id = ?'
        );
        $stmt->execute([$adminId]);
        return array_values(array_map(
            static fn (array $r): string => (string) $r['app_id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    }
}
```

- [ ] **Step 6: Add `createActivated` to `SqlUserRepository`**

Read the existing file (`src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php`) to match its style, then append this method inside the class:

```php
public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User
{
    $sql = 'INSERT INTO users
              (id, name, email, password_hash, date_of_birth,
               country, address_street, address_zip, address_city, address_country,
               membership_type, membership_status, member_number, created_at, is_platform_admin)
            VALUES (?,?,?,NULL,?, ?,\'\',\'\',\'\',\'\', ?,?,?,?,0)';
    $this->pdo->prepare($sql)->execute([
        $userId,
        $fields['name'],
        $fields['email'],
        $fields['date_of_birth'],
        $fields['country'],
        $fields['membership_type'],
        $fields['membership_status'],
        $fields['member_number'],
        $now->format('Y-m-d H:i:s'),
    ]);
    $created = $this->findById($userId);
    if ($created === null) {
        throw new \RuntimeException('user_not_created');
    }
    return $created;
}
```

Also verify `SqlUserRepository::findById` hydrates `password_hash` and `date_of_birth` safely when NULL — update the User construction to pass `null` instead of `(string)` cast for those two columns when `$row['password_hash'] === null` / `$row['date_of_birth'] === null`.

- [ ] **Step 7: Run PHPStan**

Run: `composer analyse`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Infrastructure/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(infra): SQL repos for invites/member-counter/dismissals + RandomTokenGenerator + EnvBaseUrlResolver"
```

---

### Task 11: Use case — `IssueInvite`

**Files:**
- Create: `src/Application/Invite/IssueInvite/IssueInvite.php`
- Create: `src/Application/Invite/IssueInvite/IssueInviteInput.php`
- Create: `src/Application/Invite/IssueInvite/IssueInviteOutput.php`
- Create: `tests/Unit/Application/Invite/IssueInviteTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Invite;

use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Application\Invite\IssueInvite\IssueInviteInput;
use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryUserInviteRepository;
use PHPUnit\Framework\TestCase;

final class IssueInviteTest extends TestCase
{
    public function test_issues_invite_with_hashed_token_and_7_day_expiry(): void
    {
        $repo      = new InMemoryUserInviteRepository();
        $tokenGen  = new class implements TokenGeneratorInterface {
            public function generate(): string { return 'predictable-token'; }
        };
        $urls      = new class implements BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string
            { return 'https://frontend.test'; }
        };
        $clock     = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids       = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string { return 'invite-1'; }
        };

        $sut = new IssueInvite($repo, $tokenGen, $urls, $clock, $ids);
        $out = $sut->execute(new IssueInviteInput('user-1', 'tenant-1'));

        self::assertSame('predictable-token', $out->rawToken);
        self::assertSame('https://frontend.test/invite/predictable-token', $out->inviteUrl);
        self::assertSame('2026-04-27 12:00:00', $out->expiresAt->format('Y-m-d H:i:s'));

        $stored = $repo->findByTokenHash(hash('sha256', 'predictable-token'));
        self::assertNotNull($stored);
        self::assertSame('user-1', $stored->userId);
        self::assertSame('tenant-1', $stored->tenantId);
        self::assertNull($stored->usedAt);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Invite/IssueInviteTest.php`
Expected: class not found.

- [ ] **Step 3: Create `IssueInviteInput` + `IssueInviteOutput`**

`IssueInviteInput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Invite\IssueInvite;

final class IssueInviteInput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
    ) {}
}
```

`IssueInviteOutput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Invite\IssueInvite;

final class IssueInviteOutput
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $inviteUrl,
        public readonly \DateTimeImmutable $expiresAt,
    ) {}
}
```

- [ ] **Step 4: Implement `IssueInvite`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Invite\IssueInvite;

use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Domain\Invite\UserInvite;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;

final class IssueInvite
{
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly UserInviteRepositoryInterface $invites,
        private readonly TokenGeneratorInterface $tokens,
        private readonly BaseUrlResolverInterface $urls,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(IssueInviteInput $input): IssueInviteOutput
    {
        $now   = $this->clock->now();
        $raw   = $this->tokens->generate();
        $token = InviteToken::fromRaw($raw);
        $exp   = $now->add(new \DateInterval('P' . self::TTL_DAYS . 'D'));

        $this->invites->save(new UserInvite(
            $this->ids->generate(),
            $input->userId,
            $input->tenantId,
            $token->hash,
            $now,
            $exp,
            null,
        ));

        $url = rtrim($this->urls->resolveFrontendBaseUrl($input->tenantId), '/') . '/invite/' . $raw;

        return new IssueInviteOutput($raw, $url, $exp);
    }
}
```

- [ ] **Step 5: Run test — expect PASS**

Run: `vendor/bin/phpunit tests/Unit/Application/Invite/IssueInviteTest.php`
Expected: PASS.

If `IdGeneratorInterface` does not yet exist, grep for an existing generator. If absent, either use an existing UUID service (look for `Uuid` or `IdGenerator`) or create a simple `src/Domain/Shared/IdGeneratorInterface.php` and matching `src/Infrastructure/Id/RamseyUuidGenerator.php`.

- [ ] **Step 6: Commit**

```bash
git add src/Application/Invite/IssueInvite/ tests/Unit/Application/Invite/IssueInviteTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(invite): IssueInvite use case (hashed token, 7-day TTL, URL composition)"
```

---

### Task 12: Use case — `MemberActivationService`

**Files:**
- Create: `src/Application/Backstage/ActivateMember/MemberActivationService.php`
- Create: `tests/Unit/Application/Backstage/MemberActivationServiceTest.php`

The service owns the "create user + attach + audit" slice of the approve flow. It does NOT own transactions (that's the caller's job) and does NOT own invite issuance (that's `IssueInvite`). Output: `['userId' => string, 'memberNumber' => string]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ActivateMember\MemberActivationService;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryMemberStatusAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryTenantMemberCounterRepository;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryUserRepository;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class MemberActivationServiceTest extends TestCase
{
    public function test_activates_member_with_allocated_number_and_audit(): void
    {
        $users    = new InMemoryUserRepository();
        $userTen  = new InMemoryUserTenantRepository();
        $counter  = new InMemoryTenantMemberCounterRepository();
        $audit    = new InMemoryMemberStatusAuditRepository();
        $clock    = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids      = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            private int $i = 0;
            public function generate(): string { return 'id-' . (++$this->i); }
        };

        $counter->setNextForTesting('tenant-1', 42);

        $sut = new MemberActivationService($users, $userTen, $counter, $audit, $clock, $ids);
        $out = $sut->execute(
            tenantId: 'tenant-1',
            performingAdminId: 'admin-1',
            applicationFields: [
                'name' => 'Firstname Lastname',
                'email' => 'new@member.test',
                'date_of_birth' => '1990-05-15',
                'country' => 'FI',
            ],
        );

        self::assertSame('00042', $out['memberNumber']);
        $user = $users->findByEmail('new@member.test');
        self::assertNotNull($user);
        self::assertSame('00042', $user->memberNumber());
        self::assertNull($user->passwordHash());
        self::assertTrue($userTen->hasRole($user->id()->toString(), 'tenant-1', 'member'));
        self::assertCount(1, $audit->allForTenant('tenant-1'));
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/MemberActivationServiceTest.php`
Expected: class not found OR missing InMemory repos.

If `InMemoryMemberStatusAuditRepository` / `InMemoryUserTenantRepository` don't exist yet, check the codebase — they likely do. If the methods `hasRole` / `allForTenant` don't exist, add them as test-only convenience helpers on the existing InMemory classes (keep production repo interfaces unchanged).

- [ ] **Step 3: Implement `MemberActivationService`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ActivateMember;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Domain\User\UserTenantRepositoryInterface;

final class MemberActivationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly TenantMemberCounterRepositoryInterface $counters,
        private readonly MemberStatusAuditRepositoryInterface $audit,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    /**
     * @param array{name: string, email: string, date_of_birth: ?string, country: ?string} $applicationFields
     * @return array{userId: string, memberNumber: string}
     */
    public function execute(
        string $tenantId,
        string $performingAdminId,
        array $applicationFields,
    ): array {
        $now          = $this->clock->now();
        $userId       = $this->ids->generate();
        $memberNumber = $this->counters->allocateNext($tenantId);

        $this->users->createActivated($userId, [
            'name'              => $applicationFields['name'],
            'email'             => $applicationFields['email'],
            'date_of_birth'     => $applicationFields['date_of_birth'],
            'country'           => $applicationFields['country'] ?? '',
            'membership_type'   => 'individual',
            'membership_status' => 'active',
            'member_number'     => $memberNumber,
        ], $now);

        $this->userTenants->attach($userId, $tenantId, 'member', $now);

        $this->audit->save(new MemberStatusAudit(
            $this->ids->generate(),
            $tenantId,
            $userId,
            null,
            'active',
            'application_approved',
            $performingAdminId,
            $now,
        ));

        return ['userId' => $userId, 'memberNumber' => $memberNumber];
    }
}
```

Exact signatures for `MemberStatusAudit` constructor and `UserTenantRepositoryInterface::attach` must match what already exists — read those files first and adapt field order if needed. If `MemberStatusAudit` entity does not exist, create it as a simple data class.

- [ ] **Step 4: Run test — expect PASS**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/MemberActivationServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ActivateMember/ tests/Unit/Application/Backstage/MemberActivationServiceTest.php src/Domain/ src/Infrastructure/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(members): MemberActivationService (allocate number, create user, attach, audit)"
```

---

### Task 13: Use case — `SupporterActivationService`

**Files:**
- Create: `src/Application/Backstage/ActivateSupporter/SupporterActivationService.php`
- Create: `tests/Unit/Application/Backstage/SupporterActivationServiceTest.php`

Mirrors Task 12 but: no member number, no `member_status_audit`, `membership_type='supporter'`, `role='supporter'`, `date_of_birth = null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ActivateSupporter\SupporterActivationService;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryUserRepository;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class SupporterActivationServiceTest extends TestCase
{
    public function test_activates_supporter_without_member_number_or_audit(): void
    {
        $users   = new InMemoryUserRepository();
        $userTen = new InMemoryUserTenantRepository();
        $clock   = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids     = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string { return 'user-xyz'; }
        };

        $sut = new SupporterActivationService($users, $userTen, $clock, $ids);
        $out = $sut->execute(
            tenantId: 'tenant-1',
            applicationFields: [
                'name' => 'Jane Doe',
                'email' => 'jane@corp.test',
                'country' => 'FI',
            ],
        );

        self::assertSame('user-xyz', $out['userId']);
        $user = $users->findByEmail('jane@corp.test');
        self::assertNotNull($user);
        self::assertNull($user->memberNumber());
        self::assertNull($user->dateOfBirth());
        self::assertSame('supporter', $user->membershipType());
        self::assertTrue($userTen->hasRole('user-xyz', 'tenant-1', 'supporter'));
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/SupporterActivationServiceTest.php`
Expected: class not found.

- [ ] **Step 3: Implement `SupporterActivationService`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ActivateSupporter;

use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Domain\User\UserTenantRepositoryInterface;

final class SupporterActivationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    /**
     * @param array{name: string, email: string, country: ?string} $applicationFields
     * @return array{userId: string}
     */
    public function execute(string $tenantId, array $applicationFields): array
    {
        $now    = $this->clock->now();
        $userId = $this->ids->generate();

        $this->users->createActivated($userId, [
            'name'              => $applicationFields['name'],
            'email'             => $applicationFields['email'],
            'date_of_birth'     => null,
            'country'           => $applicationFields['country'] ?? '',
            'membership_type'   => 'supporter',
            'membership_status' => 'active',
            'member_number'     => null,
        ], $now);

        $this->userTenants->attach($userId, $tenantId, 'supporter', $now);

        return ['userId' => $userId];
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/SupporterActivationServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ActivateSupporter/ tests/Unit/Application/Backstage/SupporterActivationServiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(supporters): SupporterActivationService (create user + attach as supporter)"
```

---

### Task 14: Rework `DecideApplication` approve path

**Files:**
- Modify: `src/Application/Backstage/DecideApplication/DecideApplication.php`
- Modify: `src/Application/Backstage/DecideApplication/DecideApplicationOutput.php`
- Create: `tests/Unit/Application/Backstage/DecideApplicationApproveMemberTest.php`
- Create: `tests/Unit/Application/Backstage/DecideApplicationApproveSupporterTest.php`

The approve path MUST be transactional. Add a `Daems\Domain\Shared\TransactionManagerInterface` if one does not already exist: a `run(callable $fn): mixed` contract implemented by a PDO wrapper for prod and a passthrough for tests.

- [ ] **Step 1: Create `TransactionManagerInterface` (only if missing)**

Check: `grep -R "TransactionManager" src/ tests/` — skip this step if it already exists. Otherwise:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Shared;

interface TransactionManagerInterface
{
    /** @template T @param callable():T $fn @return T */
    public function run(callable $fn): mixed;
}
```

Plus `PdoTransactionManager` in `src/Infrastructure/` wrapping `PDO::beginTransaction/commit/rollback`, and an `ImmediateTransactionManager` in `src/Infrastructure/Adapter/Persistence/InMemory/` that just invokes the callable.

- [ ] **Step 2: Extend `DecideApplicationOutput`**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\DecideApplication;

final class DecideApplicationOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $activatedUserId = null,
        public readonly ?string $memberNumber = null,
        public readonly ?string $inviteUrl = null,
        public readonly ?string $inviteExpiresAt = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'success'           => $this->success,
            'activated_user_id' => $this->activatedUserId,
            'member_number'     => $this->memberNumber,
            'invite_url'        => $this->inviteUrl,
            'invite_expires_at' => $this->inviteExpiresAt,
        ], static fn ($v): bool => $v !== null);
    }
}
```

- [ ] **Step 3: Write failing tests — approve-member path**

`tests/Unit/Application/Backstage/DecideApplicationApproveMemberTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Domain\Auth\ActingUser;
// ... plus InMemory repos and activation services
use PHPUnit\Framework\TestCase;

final class DecideApplicationApproveMemberTest extends TestCase
{
    public function test_approve_member_activates_issues_invite_clears_dismissals(): void
    {
        // Arrange: wire InMemory repos, seed one pending member application,
        // inject MemberActivationService, IssueInvite, ImmediateTransactionManager.
        // Seed two dismissals for this app_id by different admins.

        // Act: execute DecideApplication with decision='approved'.

        // Assert:
        //   - output.activatedUserId, memberNumber, inviteUrl, inviteExpiresAt populated
        //   - users row exists with correct email / member_number
        //   - user_tenants attach
        //   - member_status_audit row
        //   - user_invites row with hashed token
        //   - both admin_application_dismissals rows for this app_id deleted
        //   - member_applications.status == 'approved' with decided_by / decided_at / decision_note
    }

    public function test_approve_member_is_idempotent_guard_on_already_decided(): void
    {
        // Arrange a member application with status='approved'.
        // Act: second execute with decision='approved'.
        // Assert ValidationException('already_decided').
    }

    public function test_reject_member_does_not_create_user_or_invite(): void
    {
        // Act: decision='rejected'.
        // Assert: no users row, no user_invites row, application.status = 'rejected'.
    }
}
```

Fill in arrange/act/assert with concrete code modelled on the existing `tests/Unit/Application/Backstage/` patterns. If there is no existing unit test for `DecideApplication`, create helpers inline.

- [ ] **Step 4: Write failing test — approve-supporter path**

Analogous to Step 3 but for supporters: no `member_number`, no audit row, `membership_type='supporter'`, `user_tenants.role='supporter'`.

- [ ] **Step 5: Run tests — expect failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/`
Expected: failures in the two new test classes (DecideApplication signature mismatch or behaviour missing).

- [ ] **Step 6: Rewrite `DecideApplication`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

use Daems\Application\Backstage\ActivateMember\MemberActivationService;
use Daems\Application\Backstage\ActivateSupporter\SupporterActivationService;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Application\Invite\IssueInvite\IssueInviteInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;

final class DecideApplication
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
        private readonly MemberActivationService $memberActivation,
        private readonly SupporterActivationService $supporterActivation,
        private readonly IssueInvite $issueInvite,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
    ) {}

    public function execute(DecideApplicationInput $input): DecideApplicationOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if (!in_array($input->decision, ['approved', 'rejected'], true)) {
            throw new ValidationException(['decision' => 'invalid_value']);
        }
        if (!in_array($input->type, ['member', 'supporter'], true)) {
            throw new ValidationException(['type' => 'invalid_value']);
        }

        $now = $this->clock->now();

        return $this->tx->run(function () use ($input, $tenantId, $now) {
            if ($input->type === 'member') {
                return $this->handleMember($input, $tenantId, $now);
            }
            return $this->handleSupporter($input, $tenantId, $now);
        });
    }

    private function handleMember(DecideApplicationInput $input, string $tenantId, \DateTimeImmutable $now): DecideApplicationOutput
    {
        $app = $this->memberApps->findByIdForTenant($input->id, $tenantId)
            ?? throw new NotFoundException('application_not_found');
        if ($app->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        if ($input->decision === 'rejected') {
            $this->memberApps->recordDecision($input->id, $tenantId, 'rejected', $input->acting->id, $input->note, $now);
            $this->dismissals->deleteByAppId($input->id);
            return new DecideApplicationOutput(true);
        }

        $activation = $this->memberActivation->execute(
            tenantId: $tenantId,
            performingAdminId: $input->acting->id,
            applicationFields: [
                'name'          => $app->name(),
                'email'         => $app->email(),
                'date_of_birth' => $app->dateOfBirth(),
                'country'       => $app->country(),
            ],
        );

        $invite = $this->issueInvite->execute(new IssueInviteInput($activation['userId'], $tenantId));

        $this->memberApps->recordDecision($input->id, $tenantId, 'approved', $input->acting->id, $input->note, $now);
        $this->dismissals->deleteByAppId($input->id);

        return new DecideApplicationOutput(
            success: true,
            activatedUserId: $activation['userId'],
            memberNumber: $activation['memberNumber'],
            inviteUrl: $invite->inviteUrl,
            inviteExpiresAt: $invite->expiresAt->format('Y-m-d H:i:s'),
        );
    }

    private function handleSupporter(DecideApplicationInput $input, string $tenantId, \DateTimeImmutable $now): DecideApplicationOutput
    {
        $app = $this->supporterApps->findByIdForTenant($input->id, $tenantId)
            ?? throw new NotFoundException('application_not_found');
        if ($app->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        if ($input->decision === 'rejected') {
            $this->supporterApps->recordDecision($input->id, $tenantId, 'rejected', $input->acting->id, $input->note, $now);
            $this->dismissals->deleteByAppId($input->id);
            return new DecideApplicationOutput(true);
        }

        $activation = $this->supporterActivation->execute(
            tenantId: $tenantId,
            applicationFields: [
                'name'    => $app->contactPerson(), // or whatever the supporter entity exposes
                'email'   => $app->email(),
                'country' => $app->country(),
            ],
        );

        $invite = $this->issueInvite->execute(new IssueInviteInput($activation['userId'], $tenantId));

        $this->supporterApps->recordDecision($input->id, $tenantId, 'approved', $input->acting->id, $input->note, $now);
        $this->dismissals->deleteByAppId($input->id);

        return new DecideApplicationOutput(
            success: true,
            activatedUserId: $activation['userId'],
            inviteUrl: $invite->inviteUrl,
            inviteExpiresAt: $invite->expiresAt->format('Y-m-d H:i:s'),
        );
    }
}
```

If `MemberApplication` / `SupporterApplication` entities don't expose `status()` / `contactPerson()` / etc., adapt the calls. Also: `MemberApplicationRepositoryInterface::recordDecision` and `findByIdForTenant` already exist — use them as-is.

- [ ] **Step 7: Run unit tests — expect PASS**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/DecideApplicationApproveMemberTest.php tests/Unit/Application/Backstage/DecideApplicationApproveSupporterTest.php`
Expected: PASS.

- [ ] **Step 8: Run full unit + PHPStan**

Run: `composer analyse && vendor/bin/phpunit --testsuite=unit`
Expected: both PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Application/Backstage/DecideApplication/ tests/Unit/Application/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): DecideApplication approve path — activate + invite + clear dismissals (transactional)"
```

---

### Task 15: Use case — `DismissApplication`

**Files:**
- Create: `src/Application/Backstage/DismissApplication/DismissApplication.php`
- Create: `src/Application/Backstage/DismissApplication/DismissApplicationInput.php`
- Create: `tests/Unit/Application/Backstage/DismissApplicationTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Infrastructure\Adapter\Persistence\InMemory\InMemoryAdminApplicationDismissalRepository;
use PHPUnit\Framework\TestCase;

final class DismissApplicationTest extends TestCase
{
    public function test_dismiss_stores_row_keyed_by_admin_and_app(): void
    {
        $repo  = new InMemoryAdminApplicationDismissalRepository();
        $clock = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids   = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string { return 'd-1'; }
        };
        $acting = // construct ActingUser with id='admin-1', tenant admin...

        $sut = new DismissApplication($repo, $clock, $ids);
        $sut->execute(new DismissApplicationInput($acting, 'app-1', 'member'));

        self::assertSame(['app-1'], $repo->listAppIdsDismissedByAdmin('admin-1'));
    }

    public function test_dismiss_is_idempotent(): void
    {
        // Execute twice — still only one row for (admin, app).
    }

    public function test_rejects_non_admin(): void
    {
        // ActingUser that is not admin in active tenant → ForbiddenException.
    }
}
```

Fill the `ActingUser` construction from the existing pattern in other tests.

- [ ] **Step 2: Run test — expect failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/DismissApplicationTest.php`
Expected: class not found.

- [ ] **Step 3: Create input + use case**

`DismissApplicationInput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\DismissApplication;
use Daems\Domain\Auth\ActingUser;

final class DismissApplicationInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $appId,
        public readonly string $appType, // 'member' | 'supporter'
    ) {}
}
```

`DismissApplication.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\DismissApplication;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;

final class DismissApplication
{
    public function __construct(
        private readonly AdminApplicationDismissalRepositoryInterface $repo,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(DismissApplicationInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if (!in_array($input->appType, ['member', 'supporter'], true)) {
            throw new ValidationException(['app_type' => 'invalid_value']);
        }

        $this->repo->save(new AdminApplicationDismissal(
            $this->ids->generate(),
            $input->acting->id,
            $input->appId,
            $input->appType,
            $this->clock->now(),
        ));
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/DismissApplicationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/DismissApplication/ tests/Unit/Application/Backstage/DismissApplicationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): DismissApplication use case (idempotent per admin+app)"
```

---

### Task 16: Use case — `ListPendingApplicationsForAdmin`

**Files:**
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php`
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdminInput.php`
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdminOutput.php`
- Create: `tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php`

Returns member+supporter pending applications for active tenant, excluding any that this admin has dismissed, capped at 50 items.

- [ ] **Step 1: Write failing test covering: merge + exclude dismissed + cap**

Test sets up 3 pending member apps, 2 pending supporter apps, dismisses one by the calling admin, asserts output has 4 items (two members + two supporters) with `total=4`.

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Create output + input + use case**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListPendingApplications;

final class ListPendingApplicationsForAdminOutput
{
    /** @param list<array{id:string,type:string,name:string,created_at:string}> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {}

    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total];
    }
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ActingUser;

final class ListPendingApplicationsForAdminInput
{
    public function __construct(public readonly ActingUser $acting) {}
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class ListPendingApplicationsForAdmin
{
    private const CAP = 50;

    public function __construct(
        private readonly MemberApplicationRepositoryInterface $members,
        private readonly SupporterApplicationRepositoryInterface $supporters,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    ) {}

    public function execute(ListPendingApplicationsForAdminInput $input): ListPendingApplicationsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $dismissed = array_flip($this->dismissals->listAppIdsDismissedByAdmin($input->acting->id));
        $items = [];

        foreach ($this->members->listPendingForTenant($tenantId) as $app) {
            if (isset($dismissed[$app->id()])) { continue; }
            $items[] = [
                'id'         => $app->id(),
                'type'       => 'member',
                'name'       => $app->name(),
                'created_at' => $app->createdAt(),
            ];
        }
        foreach ($this->supporters->listPendingForTenant($tenantId) as $app) {
            if (isset($dismissed[$app->id()])) { continue; }
            $items[] = [
                'id'         => $app->id(),
                'type'       => 'supporter',
                'name'       => $app->contactPerson(),
                'created_at' => $app->createdAt(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        $total = count($items);

        return new ListPendingApplicationsForAdminOutput(
            array_slice($items, 0, self::CAP),
            $total,
        );
    }
}
```

If the repositories don't already have `listPendingForTenant`, add it — they almost certainly already have a similar `listForTenant` method that filters by status.

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListPendingApplications/ tests/Unit/Application/Backstage/ListPendingApplicationsForAdminTest.php src/Domain/Membership/ src/Infrastructure/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): ListPendingApplicationsForAdmin (merge members+supporters, exclude dismissed, cap 50)"
```

---

### Task 17: Use case — `RedeemInvite`

**Files:**
- Create: `src/Application/Auth/RedeemInvite/RedeemInvite.php`
- Create: `src/Application/Auth/RedeemInvite/RedeemInviteInput.php`
- Create: `src/Application/Auth/RedeemInvite/RedeemInviteOutput.php`
- Create: `tests/Unit/Application/Invite/RedeemInviteTest.php`

- [ ] **Step 1: Write failing test — happy path, used-twice, expired, unknown-token**

Key assertions:
- valid token + password ≥ 8 chars + ≤ 72 chars → sets `users.password_hash`, marks invite used_at, returns success with the activated user.
- token with `used_at != null` → `ValidationException('invite_used')`.
- token past `expires_at` → `ValidationException('invite_expired')`.
- token not found → `ValidationException('invite_invalid')`.
- password < 8 chars → `ValidationException('password_too_short')`.
- password > 72 chars → `ValidationException('password_too_long')`.

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Create input + output + use case**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Auth\RedeemInvite;

final class RedeemInviteInput
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $password,
    ) {}
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Auth\RedeemInvite;

use Daems\Domain\User\User;

final class RedeemInviteOutput
{
    public function __construct(public readonly User $user) {}
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Auth\RedeemInvite;

use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\UserRepositoryInterface;

final class RedeemInvite
{
    public function __construct(
        private readonly UserInviteRepositoryInterface $invites,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(RedeemInviteInput $input): RedeemInviteOutput
    {
        if (strlen($input->password) < 8) {
            throw new ValidationException(['password' => 'password_too_short']);
        }
        if (strlen($input->password) > 72) {
            throw new ValidationException(['password' => 'password_too_long']);
        }

        $hash  = InviteToken::fromRaw($input->rawToken)->hash;
        $invite = $this->invites->findByTokenHash($hash);
        if ($invite === null) {
            throw new ValidationException(['token' => 'invite_invalid']);
        }
        $now = $this->clock->now();
        if ($invite->usedAt !== null) {
            throw new ValidationException(['token' => 'invite_used']);
        }
        if ($now >= $invite->expiresAt) {
            throw new ValidationException(['token' => 'invite_expired']);
        }

        $user = $this->users->findById($invite->userId)
            ?? throw new ValidationException(['token' => 'invite_invalid']);

        $this->users->updatePassword($user->id()->toString(), password_hash($input->password, PASSWORD_BCRYPT));
        $this->invites->markUsed($invite->id, $now);

        $fresh = $this->users->findById($user->id()->toString());
        if ($fresh === null) {
            throw new ValidationException(['token' => 'invite_invalid']);
        }
        return new RedeemInviteOutput($fresh);
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Auth/RedeemInvite/ tests/Unit/Application/Invite/RedeemInviteTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(invite): RedeemInvite use case (hash check, TTL, used-once, password bcrypt)"
```

---

### Task 18: `LoginUser` — reject NULL hash + clear dismissals on success

**Files:**
- Modify: `src/Application/Auth/LoginUser/LoginUser.php`
- Create: `tests/Unit/Application/Auth/LoginUserNullHashTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
// ... plus InMemory fakes

final class LoginUserNullHashTest extends \PHPUnit\Framework\TestCase
{
    public function test_login_with_null_password_hash_fails_generic(): void
    {
        // Arrange: seed a user with passwordHash=null (invite-pending).
        // Act: execute LoginUser with that email + any password.
        // Assert: output is failure with generic "Invalid email or password.".
    }

    public function test_login_success_clears_all_dismissals_for_admin(): void
    {
        // Arrange: seed a user with real hash, seed two dismissal rows for that admin.
        // Act: execute LoginUser.
        // Assert: success; dismissal repo has 0 rows for that admin id.
    }

    public function test_login_failure_does_not_clear_dismissals(): void
    {
        // Arrange: seed user, seed dismissal row, call with wrong password.
        // Assert: dismissal row still present.
    }
}
```

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Update `LoginUser`**

Add constructor dependency on `AdminApplicationDismissalRepositoryInterface`:

```php
public function __construct(
    private readonly UserRepositoryInterface $users,
    private readonly AuthLoginAttemptRepositoryInterface $attempts,
    private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    private readonly Clock $clock,
) {}
```

In `execute`, the existing `$candidateHash = $user?->passwordHash() ?? self::DUMMY_BCRYPT_HASH;` now handles null via the `??` (null password hash falls back to the dummy). No change needed there — but after computing `$ok`, add:

```php
if ($ok && $user !== null) {
    $this->dismissals->deleteByAdminId($user->id()->toString());
}
```

- [ ] **Step 4: Run test + full unit suite**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Auth/LoginUser/LoginUser.php tests/Unit/Application/Auth/LoginUserNullHashTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(auth): reject NULL-hash logins generically + clear admin dismissals on success"
```

---

### Task 19: HTTP endpoints

**Files:**
- Modify: `src/Infrastructure/Http/Controller/BackstageController.php`
- Modify: `src/Infrastructure/Http/Controller/AuthController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Add `BackstageController::listPendingForAdmin`**

```php
public function listPendingForAdmin(Request $request): Response
{
    $acting = $this->actingUserResolver->resolve($request);
    try {
        $out = $this->listPending->execute(
            new ListPendingApplicationsForAdminInput($acting)
        );
        return Response::json(200, $out->toArray());
    } catch (ForbiddenException) {
        return Response::json(403, ['error' => 'forbidden']);
    }
}
```

- [ ] **Step 2: Add `BackstageController::dismissApplication`**

```php
public function dismissApplication(Request $request, string $type, string $id): Response
{
    $acting = $this->actingUserResolver->resolve($request);
    try {
        $this->dismiss->execute(new DismissApplicationInput($acting, $id, $type));
        return Response::noContent();
    } catch (ForbiddenException) {
        return Response::json(403, ['error' => 'forbidden']);
    } catch (ValidationException $e) {
        return Response::json(422, ['error' => 'validation_failed', 'errors' => $e->errors()]);
    }
}
```

Constructor must gain `ListPendingApplicationsForAdmin $listPending` and `DismissApplication $dismiss`. Preserve existing constructor params.

- [ ] **Step 3: Add `AuthController::redeemInvite`**

```php
public function redeemInvite(Request $request): Response
{
    $body  = $request->jsonBody();
    $token = (string) ($body['token'] ?? '');
    $pwd   = (string) ($body['password'] ?? '');
    try {
        $out = $this->redeemInvite->execute(new RedeemInviteInput($token, $pwd));
        // Issue a session token exactly as LoginUser's controller does — reuse helper.
        return $this->buildAuthResponse($out->user);
    } catch (ValidationException $e) {
        return Response::json(422, ['error' => 'validation_failed', 'errors' => $e->errors()]);
    }
}
```

Look at how the existing login controller method builds its response and factor out the shared bit into a private `buildAuthResponse(User $user): Response` if it isn't already.

- [ ] **Step 4: Wire routes**

In `routes/api.php` add:

```php
$router->get('/api/v1/backstage/applications/pending-count', [BackstageController::class, 'listPendingForAdmin']);
$router->post('/api/v1/backstage/applications/{type}/{id}/dismiss', [BackstageController::class, 'dismissApplication']);
$router->post('/api/v1/auth/invites/redeem', [AuthController::class, 'redeemInvite']);
```

Match the existing route-registration syntax — it may be array-based, not fluent.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Http/Controller/ routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): endpoints — pending-count, dismiss, auth/invites/redeem"
```

---

### Task 20: DI wiring — bootstrap/app.php + KernelHarness

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

**CRITICAL:** Both files must be updated — if only KernelHarness is wired, E2E tests pass but production breaks (see `~/.claude/projects/C--laragon-www-daems-platform/memory/feedback_bootstrap_and_harness_must_both_wire.md`). Grep for each new class in BOTH files before marking this task done.

- [ ] **Step 1: Update `bootstrap/app.php`**

For every new class in Tasks 6–19, add a binding. Use the existing container's `bind`/`set`/array syntax. New bindings (container should resolve each):

- `TokenGeneratorInterface::class => RandomTokenGenerator::class`
- `BaseUrlResolverInterface::class => new EnvBaseUrlResolver([...from config/tenant-fallback.php or new config...], 'http://daems-platform.local')`
- `UserInviteRepositoryInterface::class => new SqlUserInviteRepository($pdo)`
- `TenantMemberCounterRepositoryInterface::class => new SqlTenantMemberCounterRepository($pdo)`
- `AdminApplicationDismissalRepositoryInterface::class => new SqlAdminApplicationDismissalRepository($pdo)`
- `TransactionManagerInterface::class => new PdoTransactionManager($pdo)` (only if created in Task 14 Step 1)
- `IssueInvite::class`, `MemberActivationService::class`, `SupporterActivationService::class`, `DismissApplication::class`, `ListPendingApplicationsForAdmin::class`, `RedeemInvite::class` — each resolved via constructor injection using above bindings.
- Updated `DecideApplication` binding with its new constructor signature.
- Updated `LoginUser` binding with its new constructor signature.
- `BackstageController` and `AuthController` constructor argument lists updated to include the new use cases.

- [ ] **Step 2: Update `tests/Support/KernelHarness.php`**

For every binding above, provide the InMemory or fake equivalent:
- `TokenGeneratorInterface` → deterministic test generator (counter-based)
- `BaseUrlResolverInterface` → constant `'https://test.local'`
- All three new repos → InMemory variants
- `TransactionManagerInterface` → `ImmediateTransactionManager`
- Use cases + controllers resolved the same way, just with fakes

- [ ] **Step 3: Sanity-grep**

Run (via Bash or Grep):
```
Grep for: SqlUserInviteRepository, SqlTenantMemberCounterRepository, SqlAdminApplicationDismissalRepository,
IssueInvite, MemberActivationService, SupporterActivationService, DismissApplication,
ListPendingApplicationsForAdmin, RedeemInvite
```
Every symbol must appear in BOTH `bootstrap/app.php` and `tests/Support/KernelHarness.php`.

- [ ] **Step 4: Boot the server smoke-check**

Run (from `C:\laragon\www\daems-platform`):
```
php -S 127.0.0.1:8090 -t public public/index.php
```
In another shell:
```
curl -i http://127.0.0.1:8090/api/v1/backstage/applications/pending-count
```
Expected: 401 (no auth) — the route is registered and the container resolves everything without throwing. Any 500 is a wiring bug; fix before committing.

- [ ] **Step 5: Run PHPStan + full unit suite**

Run: `composer analyse && vendor/bin/phpunit --testsuite=unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire: DI bindings for approval+toast flow in bootstrap/app.php AND KernelHarness"
```

---

### Task 21: Integration tests (real DB)

**Files:**
- Create: `tests/Integration/Application/MemberActivationIntegrationTest.php`
- Create: `tests/Integration/Application/InviteRedemptionIntegrationTest.php`

- [ ] **Step 1: `MemberActivationIntegrationTest`**

Extends `MigrationTestCase`. In `setUp` run migrations to 40, seed a tenant + admin user + `user_tenants(role='admin')` + a pending member application.

Test method: executes the real (PDO-backed) `DecideApplication` approve path via the real repositories + real `PdoTransactionManager`. Asserts:
- `users` row exists with `member_number = '00001'` (first for this tenant), `password_hash IS NULL`
- `user_tenants` row with `role='member'`
- `member_status_audit` row with `reason='application_approved'`
- `tenant_member_counters.next_value = 2`
- `user_invites` row with correct `user_id`, `tenant_id`, hashed token, `used_at IS NULL`
- `member_applications.status = 'approved'`
- No dismissals for this app_id (we didn't seed any, sanity check)

Also add a second test that approves two applications back-to-back and asserts the second gets `member_number = '00002'`.

- [ ] **Step 2: `InviteRedemptionIntegrationTest`**

Seeds a user + user_invite row, runs `RedeemInvite::execute`, asserts:
- `users.password_hash` is bcrypt-verifiable against the new password
- `user_invites.used_at` is set
- Second redemption attempt throws `ValidationException('invite_used')`

- [ ] **Step 3: Run**

Run: `vendor/bin/phpunit --testsuite=integration`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Application/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(int): member activation end-to-end + invite redemption against real MySQL"
```

---

### Task 22: Isolation tests

**Files:**
- Create: `tests/Isolation/ApplicationApprovalTenantIsolationTest.php`
- Create: `tests/Isolation/AdminDismissalTenantIsolationTest.php`

Extend `IsolationTestCase` (already set up in Task 5 Step 5 to include migration 40 + seed `daems` + `sahegroup` tenants).

- [ ] **Step 1: `ApplicationApprovalTenantIsolationTest`**

Seed an admin in `daems` tenant. Seed a pending member application in `sahegroup`. Try to approve it using the `daems` admin's `ActingUser`. Assert: `NotFoundException('application_not_found')`. Verify no `users` row was created, no `user_invites` row.

- [ ] **Step 2: `AdminDismissalTenantIsolationTest`**

Seed two admins (one per tenant) and one pending member application per tenant. Admin A dismisses their own tenant's application. Assert that admin B's `ListPendingApplicationsForAdmin` output still includes their own tenant's app (obvious) AND is not affected by admin A's dismissal.

- [ ] **Step 3: Run**

Run: `vendor/bin/phpunit tests/Isolation/`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Isolation/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(iso): approval + dismissal never cross tenant boundaries"
```

---

### Task 23: E2E tests (KernelHarness)

**Files:**
- Create: `tests/E2E/Backstage/ApproveAndInviteFlowTest.php`
- Create: `tests/E2E/Backstage/PendingCountAndDismissTest.php`
- Create: `tests/E2E/Auth/InviteRedeemEndpointTest.php`

- [ ] **Step 1: `ApproveAndInviteFlowTest`**

- `POST /backstage/applications/member/{id}/decision` with `{decision:'approved'}` returns 200 with JSON carrying `invite_url`, `invite_expires_at`, `activated_user_id`, `member_number`.
- Second identical request returns 422 `already_decided`.
- Supporter variant: same but no `member_number` field in response.

- [ ] **Step 2: `PendingCountAndDismissTest`**

- Seed 3 pending member apps.
- `GET /backstage/applications/pending-count` → `{items:[...3...], total:3}`.
- `POST /backstage/applications/member/{id}/dismiss` → 204.
- `GET /backstage/applications/pending-count` → `{items:[...2...], total:2}`.
- Second dismiss → 204 (idempotent).

- [ ] **Step 3: `InviteRedeemEndpointTest`**

- Issue invite programmatically via the harness.
- `POST /auth/invites/redeem` with `{token, password:'validpass123'}` → 200 with user payload (same shape as `POST /auth/login`).
- Second redeem with same token → 422 `invite_used`.

- [ ] **Step 4: Run**

Run: `composer test:e2e`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/E2E/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(e2e): approve flow, pending-count, dismiss, invite redemption"
```

---

### Task 24: Frontend — global toast JS/CSS + layout integration + dashboard cleanup

**Architecture note:** daem-society's JS cannot call the platform API directly — auth tokens live in the PHP session and all backend calls go through server-side `ApiClient` (see `public/api/*.php` proxies, `public/pages/backstage/applications/index.php`, etc.). The toast system follows the same pattern:
1. **Initial load data:** `layout.php` calls `ApiClient::get('/backstage/applications/pending-count')` server-side and injects the JSON into `<script>window.DAEMS_PENDING_APPS = ...</script>`.
2. **Dismiss action:** a new PHP proxy at `public/api/backstage/dismiss.php` receives the JS `fetch` and relays it through `ApiClient::post` — same pattern as existing `public/api/forum/reply.php`, etc.

**Files:**
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/toasts.js`
- Create: `C:/laragon/www/sites/daem-society/public/pages/backstage/toasts.css`
- Create: `C:/laragon/www/sites/daem-society/public/api/backstage/dismiss.php`
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/layout.php`
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/index.php`

- [ ] **Step 1: Create `toasts.css`**

```css
.pending-app-toast-stack {
    position: fixed;
    top: var(--space-6, 24px);
    right: var(--space-6, 24px);
    display: flex;
    flex-direction: column;
    gap: var(--space-3, 12px);
    z-index: 500;
    max-width: 360px;
}
.pending-app-toast {
    background: var(--color-surface-warning, #fef3c7);
    color: var(--color-text, #111);
    border-left: 4px solid var(--color-warning, #f59e0b);
    border-radius: 8px;
    padding: 12px 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    cursor: pointer;
    animation: pending-app-toast-slide-in 200ms ease-out;
}
.pending-app-toast__title { font-weight: 600; font-size: 14px; }
.pending-app-toast__meta  { font-size: 12px; opacity: 0.8; margin-top: 4px; }
.pending-app-toast__close {
    position: absolute;
    top: 4px; right: 8px;
    background: transparent; border: 0;
    font-size: 18px; cursor: pointer; color: inherit;
}
.pending-app-toast--rollup { background: var(--color-surface, #fff); border-left-color: var(--color-primary, #2563eb); }
@media (prefers-color-scheme: dark) {
    .pending-app-toast { background: var(--color-surface-warning-dark, #3b2f12); color: #fef3c7; }
    .pending-app-toast--rollup { background: var(--color-surface-dark, #1f2937); color: #e5e7eb; }
}
@keyframes pending-app-toast-slide-in {
    from { transform: translateX(100%); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
```

- [ ] **Step 2: Create `toasts.js`**

```javascript
(function () {
    'use strict';

    const initial = window.DAEMS_PENDING_APPS;
    if (!initial || !Array.isArray(initial.items)) { return; }

    const stack = document.createElement('div');
    stack.className = 'pending-app-toast-stack';
    document.body.appendChild(stack);

    function formatRelative(iso) {
        const then = new Date(iso).getTime();
        const delta = Math.round((Date.now() - then) / 60000);
        if (delta < 1) return 'just now';
        if (delta < 60) return delta + ' min ago';
        const hours = Math.round(delta / 60);
        if (hours < 24) return hours + ' h ago';
        return Math.round(hours / 24) + ' d ago';
    }

    function renderToast(item) {
        const el = document.createElement('div');
        el.className = 'pending-app-toast';
        el.innerHTML =
            '<button type="button" class="pending-app-toast__close" aria-label="Dismiss">×</button>' +
            '<div class="pending-app-toast__title">' +
                (item.type === 'member' ? 'New Member Application' : 'New Supporter Application') +
            '</div>' +
            '<div class="pending-app-toast__meta">' +
                escapeHtml(item.name) + ' — applied ' + formatRelative(item.created_at) +
            '</div>';

        el.addEventListener('click', function (e) {
            if (e.target.classList.contains('pending-app-toast__close')) { return; }
            window.location.href = '/backstage/applications?highlight=' + encodeURIComponent(item.id);
        });
        el.querySelector('.pending-app-toast__close').addEventListener('click', async function (e) {
            e.stopPropagation();
            try {
                const resp = await fetch('/api/backstage/dismiss.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ type: item.type, id: item.id }),
                });
                if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
                el.remove();
            } catch (err) { console.error('dismiss failed', err); }
        });
        return el;
    }

    function renderRollup(total) {
        const el = document.createElement('div');
        el.className = 'pending-app-toast pending-app-toast--rollup';
        el.innerHTML =
            '<div class="pending-app-toast__title">' + total + ' pending applications</div>' +
            '<div class="pending-app-toast__meta">Click to review</div>';
        el.addEventListener('click', function () {
            window.location.href = '/backstage/applications';
        });
        return el;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    function render() {
        const show = initial.items.slice(0, 3);
        for (const item of show) { stack.appendChild(renderToast(item)); }
        if (initial.total > 3) { stack.appendChild(renderRollup(initial.total)); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
```

- [ ] **Step 3: Fetch pending list + inject into `layout.php`**

Inside `layout.php` (after the existing admin-role guard at L13–18, before the `<head>`), add:

```php
$__pendingApps = ['items' => [], 'total' => 0];
if ($__backstageRole === 'global_system_administrator' || $__backstageRole === 'admin') {
    try {
        $resp = ApiClient::get('/backstage/applications/pending-count');
        if (is_array($resp) && isset($resp['items'], $resp['total'])) {
            $__pendingApps = $resp;
        }
    } catch (\Throwable $e) {
        // Silent — failing to render toasts must not break the page.
    }
}
```

In the `<head>`:
```php
<link rel="stylesheet" href="/pages/backstage/toasts.css">
```

Just before `</body>`:
```php
<script>window.DAEMS_PENDING_APPS = <?= json_encode($__pendingApps, JSON_UNESCAPED_SLASHES | JSON_HOT_TAGS); ?>;</script>
<script src="/pages/backstage/toasts.js" defer></script>
```

(If `JSON_HOT_TAGS` raises a warning on your PHP version, use `JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` — the goal is XSS-safe inline JSON.)

- [ ] **Step 4: Create dismiss proxy `public/api/backstage/dismiss.php`**

Use the same pattern as existing `public/api/forum/*.php` — check for logged-in admin session, read JSON body, call `ApiClient::post`, relay response code/body.

```php
<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../../lib/ApiClient.php'; // path to match existing proxies

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user || empty($user['is_platform_admin']) && ($user['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$type = $body['type'] ?? null;
$id   = $body['id'] ?? null;
if (!in_array($type, ['member', 'supporter'], true) || !is_string($id) || $id === '') {
    http_response_code(422);
    echo json_encode(['error' => 'invalid_input']);
    exit;
}

try {
    ApiClient::post("/backstage/applications/{$type}/{$id}/dismiss", []);
    http_response_code(204);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_failed']);
}
```

Adjust the `ApiClient` include path and session-user-shape checks to match the existing proxies exactly (read one first — `public/api/profile/delete.php` is a good reference).

- [ ] **Step 5: Remove inline toast from dashboard**

Edit `sites/daem-society/public/pages/backstage/index.php` — delete lines ~108–230 that render `<div id="pending-app-toast">...` plus its inline `<style>` and `<script>` blocks. Leave the `$applications` counter stat card intact.

- [ ] **Step 6: Manual smoke test**

Start Laragon (or `php -S 127.0.0.1:8080 -t public` from `sites/daem-society/public`). Visit `/backstage` logged in as an admin with pending applications. Verify: toast stack appears top-right, clicking a toast navigates with `?highlight=<id>`, clicking ✕ removes it, refreshing the page shows the remaining toasts (dismissed one gone). Visit `/backstage/members` and `/backstage/applications` — toast stack visible on both pages.

- [ ] **Step 7: Commit (in daem-society repo)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/toasts.js public/pages/backstage/toasts.css public/api/backstage/dismiss.php public/pages/backstage/layout.php public/pages/backstage/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): global pending-applications toast stack (per-session dismiss)"
```

---

### Task 25: Frontend — applications page success toast + invite page

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/pages/backstage/applications/index.php`
- Create: `C:/laragon/www/sites/daem-society/public/pages/invite.php`
- Create: `C:/laragon/www/sites/daem-society/public/api/auth/redeem-invite.php`

- [ ] **Step 1: Show invite URL in the approve-success branch**

In `applications/index.php`, find where approval returns (look for `ApiClient::post('/backstage/applications/{type}/{id}/decision', ...)` and the branch that handles a successful response). After success, if the response payload contains `invite_url`, render a banner + auto-dismissing green toast (use same CSS tokens as the pending stack but a success variant — add `.pending-app-toast--success { border-left-color: var(--color-success, #16a34a); }` in `toasts.css` if not already present, or inline style the new banner).

Banner copy:
> Application approved. Invite link (valid 7 days):
> `<a href="<invite_url>" id="invite-url-link"><invite_url></a>`
> [Copy]

The `[Copy]` button uses `navigator.clipboard.writeText(inviteUrl)`. Keep the invite URL visible on the approved application's detail card so a missed toast is not a missed link.

Also add: when the page URL has `?highlight=<id>`, scroll to that row and briefly highlight it (a 2-second `outline` pulse). Use `document.getElementById('app-' + id)?.scrollIntoView({ behavior: 'smooth' })`.

- [ ] **Step 2: Create proxy `public/api/auth/redeem-invite.php`**

Model on `public/api/auth/login.php` (which reads `$_POST`/JSON body, calls `ApiClient::post('/auth/login', ...)`, and on success populates `$_SESSION['user']` via the existing `projectUser()` helper).

```php
<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../../lib/ApiClient.php'; // match login.php's include
require_once __DIR__ . '/../../../lib/projectUser.php'; // helper used by login.php

header('Content-Type: application/json');

$body = json_decode((string) file_get_contents('php://input'), true);
$token = (string) ($body['token'] ?? '');
$password = (string) ($body['password'] ?? '');

try {
    $resp = ApiClient::post('/auth/invites/redeem', [
        'token' => $token,
        'password' => $password,
    ]);
    if (!is_array($resp) || !isset($resp['token']) || !isset($resp['user'])) {
        http_response_code(502);
        echo json_encode(['error' => 'bad_upstream']);
        exit;
    }
    $_SESSION['api_token'] = $resp['token'];
    $_SESSION['user'] = projectUser($resp['user']);
    echo json_encode(['ok' => true]);
} catch (ApiClientException $e) {
    http_response_code($e->getCode() ?: 422);
    echo $e->responseBody() ?: json_encode(['error' => 'redemption_failed']);
}
```

The exact class names (`ApiClient`, `ApiClientException`) and helper (`projectUser`) must match `public/api/auth/login.php` — read it first.

- [ ] **Step 3: Create `invite.php`**

`sites/daem-society/public/pages/invite.php`:

Minimal page. Reads `?token=` from query string into a hidden input. Form has two password inputs (new + confirm). On submit, JS calls `/api/auth/redeem-invite.php` (the proxy above). On success (`ok: true`) redirects to `/`. On failure, displays the 422 error under the form.

Keep styling consistent with the existing login page — reuse classes from `public/pages/login.php`. No custom CSS.

Routing: register `/invite` in whatever routing mechanism daem-society uses (examine `public/index.php` or `public/.htaccess` for existing patterns — likely a path→file map in `index.php`).

- [ ] **Step 4: Manual smoke test**

1. Approve a pending member → copy invite URL from success toast.
2. Open invite URL in a **different browser / incognito session**.
3. Set a password, submit.
4. Verify landed at `/` as the new member, `$_SESSION` populated.
5. Log in later with email + new password → success.

- [ ] **Step 5: Commit (in daem-society repo)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/applications/index.php public/pages/invite.php public/api/auth/redeem-invite.php public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): approve-success invite-URL toast + /invite redemption page"
```

---

## Verification — final checklist

Before declaring done, run all of these from `C:\laragon\www\daems-platform`:

- [ ] `composer analyse` — PHPStan level 9, 0 errors.
- [ ] `composer test` — unit + integration PASS.
- [ ] `composer test:e2e` — E2E PASS.
- [ ] `vendor/bin/phpunit tests/Isolation/` — PASS.
- [ ] Smoke: `php -S 127.0.0.1:8090 -t public public/index.php`, then `curl http://127.0.0.1:8090/api/v1/backstage/applications/pending-count` — returns `401` (not `500`).
- [ ] Manual UAT on `http://daem-society.local/backstage` with seeded 4 pending members + 2 pending supporters:
  - [ ] Pending toast stack appears on all backstage pages (dashboard, /members, /applications, /events stub).
  - [ ] Dismissing a toast hides it for the rest of the session; logging out and back in brings it back.
  - [ ] Approving a member shows invite URL in success toast; pending toast for that app disappears on next page load.
  - [ ] Opening the invite URL in a fresh browser session lets the new user set a password and log in.
  - [ ] Approving a supporter creates their user + supporter role; no `member_number` assigned.
- [ ] `git status` clean on both repos; commits in `dev` branch with Dev Team identity, no `Co-Authored-By`.
