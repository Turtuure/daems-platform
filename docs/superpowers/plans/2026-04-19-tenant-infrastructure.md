# Tenant Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add multi-tenant infrastructure: `tenants`, `tenant_domains`, `user_tenants` tables; `users.is_platform_admin` flag with audit trigger; Domain layer for Tenant; `TenantContextMiddleware` + updated `AuthMiddleware`; new `GET /api/v1/auth/me` endpoint. Does **not** yet add `tenant_id` to per-tenant content tables (that's PR 3).

**Architecture:** 6 migrations (019–024), ~12 new Domain classes, 2 new SQL repositories, 1 new middleware, updates to `AuthMiddleware`, `ActingUser`, and `routes/api.php`. `users.role` column is dropped after `user_tenants` backfill. All new code passes PHPStan level 9 (baseline from PR 1).

**Tech Stack:** PHP 8.1+, MySQL 8.x, PHPUnit 10, Infection.

**Spec:** `docs/superpowers/specs/2026-04-19-DS_UPGRADE.md` sections 4–7.

**Prerequisite:** PR 1 (PHPStan level 9 baseline) merged to `dev`.

**PR target:** PR 2 of 3.

---

## File Structure

**Created — Database migrations:**
- `database/migrations/019_create_tenants_and_tenant_domains.sql`
- `database/migrations/020_add_is_platform_admin_to_users.sql`
- `database/migrations/021_backfill_is_platform_admin_from_role.sql`
- `database/migrations/022_create_user_tenants_pivot.sql`
- `database/migrations/023_backfill_user_tenants_from_users_role.sql`
- `database/migrations/024_drop_users_role_column.sql`

**Created — Domain:**
- `src/Domain/Tenant/Tenant.php`
- `src/Domain/Tenant/TenantId.php`
- `src/Domain/Tenant/TenantSlug.php`
- `src/Domain/Tenant/TenantDomain.php`
- `src/Domain/Tenant/UserTenantRole.php`
- `src/Domain/Tenant/TenantRepositoryInterface.php`
- `src/Domain/Tenant/UserTenantRepositoryInterface.php`
- `src/Domain/Tenant/TenantNotFoundException.php`

**Created — Application:**
- `src/Application/UseCase/Auth/GetAuthMe/GetAuthMe.php`
- `src/Application/UseCase/Auth/GetAuthMe/GetAuthMeOutput.php`

**Created — Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantRepository.php`
- `src/Infrastructure/Tenant/HostTenantResolver.php`
- `src/Infrastructure/Framework/Http/Middleware/TenantContextMiddleware.php`
- `config/tenant-fallback.php`

**Modified:**
- `src/Domain/Auth/ActingUser.php` — new shape (isPlatformAdmin, activeTenant, roleInActiveTenant)
- `src/Domain/User/Role.php` — **DELETED** (replaced by `UserTenantRole` in Tenant namespace)
- `src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php` — load role from user_tenants, handle `X-Daems-Tenant`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` — load `is_platform_admin`
- `src/Domain/User/User.php` — add `isPlatformAdmin` field
- `bootstrap/app.php` — wire Tenant services
- `routes/api.php` — add TenantContextMiddleware to all routes, register `/auth/me`
- `docs/api.md` — document `X-Daems-Tenant` header + `/auth/me`
- `docs/decisions.md` — append ADR-014

**Deleted:**
- `src/Domain/User/Role.php` (in Task 12)

---

## Task 1: Migration 019 — tenants + tenant_domains with seed

**Files:**
- Create: `database/migrations/019_create_tenants_and_tenant_domains.sql`
- Test: `tests/Integration/Migration/Migration019Test.php`

- [ ] **Step 1.1: Write the failing test**

Create `tests/Integration/Migration/Migration019Test.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration019Test extends MigrationTestCase
{
    public function test_tenants_table_exists_with_expected_columns(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $columns = $this->columnsOf('tenants');
        self::assertContains('id', $columns);
        self::assertContains('slug', $columns);
        self::assertContains('name', $columns);
        self::assertContains('created_at', $columns);
        self::assertContains('updated_at', $columns);
    }

    public function test_tenant_domains_table_has_fk_to_tenants(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $fks = $this->foreignKeysOf('tenant_domains');
        self::assertContains('fk_tenant_domains_tenant', $fks);
    }

    public function test_seed_inserts_daems_and_sahegroup(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $slugs = $this->pdo()
            ->query('SELECT slug FROM tenants ORDER BY slug')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['daems', 'sahegroup'], $slugs);
    }

    public function test_seed_inserts_primary_domain_for_each_tenant(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $daemsDomains = $this->pdo()
            ->query("SELECT domain FROM tenant_domains td JOIN tenants t ON td.tenant_id = t.id WHERE t.slug = 'daems' AND td.is_primary = TRUE")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('daems.fi', $daemsDomains);
    }

    public function test_domain_is_primary_key_preventing_duplicates(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $this->expectException(\PDOException::class);
        $this->pdo()->exec("INSERT INTO tenant_domains (domain, tenant_id, is_primary) VALUES ('daems.fi', '01958000-0000-7000-8000-000000000002', FALSE)");
    }
}
```

If `MigrationTestCase` helper does not yet exist, create it first (see Task 2 step 2.1 for skeleton — reuse it).

- [ ] **Step 1.2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration019Test.php`
Expected: FAIL — migration file doesn't exist.

- [ ] **Step 1.3: Write the migration SQL**

Create `database/migrations/019_create_tenants_and_tenant_domains.sql`:

```sql
-- Migration 019: create tenants + tenant_domains, seed daems + sahegroup

CREATE TABLE tenants (
    id         CHAR(36)     NOT NULL,
    slug       VARCHAR(64)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY tenants_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_domains (
    domain     VARCHAR(255) NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    is_primary BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (domain),
    KEY tenant_domains_tenant_idx (tenant_id),
    CONSTRAINT fk_tenant_domains_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: daems and sahegroup
INSERT INTO tenants (id, slug, name) VALUES
    ('01958000-0000-7000-8000-000000000001', 'daems',     'Daems Society'),
    ('01958000-0000-7000-8000-000000000002', 'sahegroup', 'Sahe Group');

INSERT INTO tenant_domains (domain, tenant_id, is_primary) VALUES
    ('daems.fi',          '01958000-0000-7000-8000-000000000001', TRUE),
    ('www.daems.fi',      '01958000-0000-7000-8000-000000000001', FALSE),
    ('sahegroup.com',     '01958000-0000-7000-8000-000000000002', TRUE),
    ('www.sahegroup.com', '01958000-0000-7000-8000-000000000002', FALSE);
```

- [ ] **Step 1.4: Run tests, verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration019Test.php`
Expected: PASS (5 tests).

- [ ] **Step 1.5: Apply migration to dev database**

Run: `mysql -u root daems_db < /c/laragon/www/daems-platform/database/migrations/019_create_tenants_and_tenant_domains.sql`

Verify:
```bash
mysql -u root daems_db -e "SELECT slug, name FROM tenants;"
```
Expected: two rows (daems, sahegroup).

- [ ] **Step 1.6: Commit**

```bash
git add database/migrations/019_create_tenants_and_tenant_domains.sql tests/Integration/Migration/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 019 — create tenants and tenant_domains tables, seed daems and sahegroup"
```

---

## Task 2: Create MigrationTestCase helper

Reusable base class for all migration tests.

**Files:**
- Create: `tests/Integration/MigrationTestCase.php`

- [ ] **Step 2.1: Write MigrationTestCase**

Create `tests/Integration/MigrationTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration;

use Daems\Infrastructure\Framework\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class MigrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a dedicated test database, e.g. daems_db_test
        $this->pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=daems_db_test;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->resetDatabase();
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    protected function runMigration(string $filename): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../database/migrations/' . $filename);
        // Split on `;` at line end; naive but sufficient for our migrations.
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            $this->pdo->exec($stmt);
        }
    }

    protected function runMigrationsUpTo(int $n): void
    {
        $files = glob(__DIR__ . '/../../database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $name = basename($f);
            $num = (int) substr($name, 0, 3);
            if ($num > $n) break;
            $this->runMigration($name);
        }
    }

    /** @return list<string> */
    protected function columnsOf(string $table): array
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
        return array_map(static fn (array $row): string => (string) $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    protected function foreignKeysOf(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table]);
        return array_map(static fn (array $row): string => (string) $row['CONSTRAINT_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$t}`");
        }
        // Drop triggers
        $triggers = $this->pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trg) {
            $this->pdo->exec("DROP TRIGGER IF EXISTS `{$trg['Trigger']}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

- [ ] **Step 2.2: Add test MySQL db creation note to docs/setup.md**

Append to `docs/setup.md`:
```markdown
## Test database

Integration and migration tests use `daems_db_test` (separate from `daems_db`).

Create it once:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

The `MigrationTestCase` base class drops all tables between tests.
```

- [ ] **Step 2.3: Create the test database**

Run: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`

- [ ] **Step 2.4: Commit**

```bash
git add tests/Integration/MigrationTestCase.php docs/setup.md
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Tests: add MigrationTestCase helper + test database setup docs"
```

---

## Task 3: Migration 020 — is_platform_admin + audit table + trigger

**Files:**
- Create: `database/migrations/020_add_is_platform_admin_to_users.sql`
- Test: `tests/Integration/Migration/Migration020Test.php`

- [ ] **Step 3.1: Write the failing test**

Create `tests/Integration/Migration/Migration020Test.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration020Test extends MigrationTestCase
{
    public function test_is_platform_admin_column_added(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        self::assertContains('is_platform_admin', $this->columnsOf('users'));
    }

    public function test_platform_admin_audit_table_created(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        self::assertContains('platform_admin_audit', $this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_trigger_fires_on_platform_admin_change(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        // Seed a user with role='member' (column exists from migration 006 + 008)
        $this->pdo()->exec("INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES ('u1', 'Sam', 'sam@t.fi', 'x', '1990-01-01', 'member')");

        $this->pdo()->exec("SET @app_actor_user_id = 'actor-1'");
        $this->pdo()->exec("UPDATE users SET is_platform_admin = TRUE WHERE id = 'u1'");

        $audit = $this->pdo()->query("SELECT action, changed_by FROM platform_admin_audit WHERE user_id = 'u1'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('granted', $audit['action']);
        self::assertSame('actor-1', $audit['changed_by']);
    }

    public function test_trigger_does_not_fire_on_unrelated_update(): void
    {
        $this->runMigrationsUpTo(19);
        $this->runMigration('020_add_is_platform_admin_to_users.sql');

        $this->pdo()->exec("INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES ('u2', 'S', 's@t.fi', 'x', '1990-01-01', 'member')");
        $this->pdo()->exec("UPDATE users SET name = 'Sammy' WHERE id = 'u2'");

        $count = $this->pdo()->query("SELECT COUNT(*) FROM platform_admin_audit WHERE user_id = 'u2'")->fetchColumn();
        self::assertSame(0, (int) $count);
    }
}
```

- [ ] **Step 3.2: Run test, verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration020Test.php`
Expected: FAIL.

- [ ] **Step 3.3: Write the migration**

Create `database/migrations/020_add_is_platform_admin_to_users.sql`:

```sql
-- Migration 020: add is_platform_admin flag to users + audit table + trigger

ALTER TABLE users
    ADD COLUMN is_platform_admin BOOLEAN NOT NULL DEFAULT FALSE AFTER password_hash;

CREATE TABLE platform_admin_audit (
    id          CHAR(36)     NOT NULL,
    user_id     CHAR(36)     NOT NULL,
    action      ENUM('granted','revoked') NOT NULL,
    changed_by  CHAR(36)     NULL,
    reason      VARCHAR(500) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY pa_audit_user_idx (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trigger: log every is_platform_admin change
-- Application sets @app_actor_user_id before the UPDATE.
DROP TRIGGER IF EXISTS trg_users_platform_admin_audit;

CREATE TRIGGER trg_users_platform_admin_audit
AFTER UPDATE ON users
FOR EACH ROW
INSERT INTO platform_admin_audit (id, user_id, action, changed_by, created_at)
SELECT
    UUID(),
    NEW.id,
    IF(NEW.is_platform_admin = TRUE, 'granted', 'revoked'),
    @app_actor_user_id,
    CURRENT_TIMESTAMP
WHERE NEW.is_platform_admin <> OLD.is_platform_admin;
```

**Note:** MigrationTestCase's naive `;\n` splitter requires the trigger as a single statement (no BEGIN/END block). The `INSERT ... SELECT ... WHERE` form works around this.

- [ ] **Step 3.4: Run tests, verify pass**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration020Test.php`
Expected: PASS (4 tests).

- [ ] **Step 3.5: Apply migration to dev database**

```bash
mysql -u root daems_db < /c/laragon/www/daems-platform/database/migrations/020_add_is_platform_admin_to_users.sql
```

- [ ] **Step 3.6: Commit**

```bash
git add database/migrations/020_add_is_platform_admin_to_users.sql tests/Integration/Migration/Migration020Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 020 — add is_platform_admin flag with audit trigger"
```

---

## Task 4: Migration 021 — backfill is_platform_admin from role

**Files:**
- Create: `database/migrations/021_backfill_is_platform_admin_from_role.sql`
- Test: `tests/Integration/Migration/Migration021Test.php`

- [ ] **Step 4.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration021Test extends MigrationTestCase
{
    public function test_gsa_users_become_platform_admin(): void
    {
        $this->runMigrationsUpTo(20);

        $this->pdo()->exec("INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES
            ('u1', 'GSA',    'gsa@t.fi',    'x', '1990-01-01', 'global_system_administrator'),
            ('u2', 'Admin',  'admin@t.fi',  'x', '1990-01-01', 'admin'),
            ('u3', 'Member', 'member@t.fi', 'x', '1990-01-01', 'member')");

        $this->runMigration('021_backfill_is_platform_admin_from_role.sql');

        $rows = $this->pdo()->query('SELECT id, is_platform_admin FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $rows[0]['is_platform_admin']);
        self::assertSame(0, (int) $rows[1]['is_platform_admin']);
        self::assertSame(0, (int) $rows[2]['is_platform_admin']);
    }
}
```

- [ ] **Step 4.2: Write the migration**

Create `database/migrations/021_backfill_is_platform_admin_from_role.sql`:

```sql
-- Migration 021: backfill is_platform_admin from legacy users.role column
SET @app_actor_user_id = 'system-migration';

UPDATE users SET is_platform_admin = TRUE WHERE role = 'global_system_administrator';
```

- [ ] **Step 4.3: Run tests, apply to dev, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration021Test.php
mysql -u root daems_db < database/migrations/021_backfill_is_platform_admin_from_role.sql
git add database/migrations/021_backfill_is_platform_admin_from_role.sql tests/Integration/Migration/Migration021Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 021 — backfill is_platform_admin from legacy GSA role"
```

---

## Task 5: Migration 022 — create user_tenants pivot

**Files:**
- Create: `database/migrations/022_create_user_tenants_pivot.sql`
- Test: `tests/Integration/Migration/Migration022Test.php`

- [ ] **Step 5.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration022Test extends MigrationTestCase
{
    public function test_user_tenants_table_created_with_composite_pk(): void
    {
        $this->runMigrationsUpTo(21);
        $this->runMigration('022_create_user_tenants_pivot.sql');

        $columns = $this->columnsOf('user_tenants');
        self::assertSame(['user_id', 'tenant_id', 'role', 'joined_at', 'left_at'], $columns);

        $fks = $this->foreignKeysOf('user_tenants');
        self::assertContains('fk_user_tenants_user', $fks);
        self::assertContains('fk_user_tenants_tenant', $fks);
    }

    public function test_role_enum_contains_expected_values(): void
    {
        $this->runMigrationsUpTo(21);
        $this->runMigration('022_create_user_tenants_pivot.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM user_tenants LIKE 'role'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString("'admin'", $row['Type']);
        self::assertStringContainsString("'moderator'", $row['Type']);
        self::assertStringContainsString("'member'", $row['Type']);
        self::assertStringContainsString("'supporter'", $row['Type']);
        self::assertStringContainsString("'registered'", $row['Type']);
        self::assertStringNotContainsString("global_system_administrator", $row['Type']);
    }
}
```

- [ ] **Step 5.2: Write the migration**

Create `database/migrations/022_create_user_tenants_pivot.sql`:

```sql
-- Migration 022: create user_tenants pivot (user ↔ tenant, with role per tenant)

CREATE TABLE user_tenants (
    user_id    CHAR(36)     NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    role       ENUM('admin','moderator','member','supporter','registered')
                            NOT NULL DEFAULT 'registered',
    joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at    DATETIME     NULL,
    PRIMARY KEY (user_id, tenant_id),
    KEY user_tenants_tenant_role_idx (tenant_id, role),
    CONSTRAINT fk_user_tenants_user
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_user_tenants_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 5.3: Run tests, apply to dev, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration022Test.php
mysql -u root daems_db < database/migrations/022_create_user_tenants_pivot.sql
git add database/migrations/022_create_user_tenants_pivot.sql tests/Integration/Migration/Migration022Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 022 — create user_tenants pivot table"
```

---

## Task 6: Migration 023 — backfill user_tenants from users.role

All existing users get `tenant='daems'` (since everything was single-tenant); their per-tenant role = their old `users.role` (except GSA which goes to `registered` since GSA is now a global flag, not a tenant role).

**Files:**
- Create: `database/migrations/023_backfill_user_tenants_from_users_role.sql`
- Test: `tests/Integration/Migration/Migration023Test.php`

- [ ] **Step 6.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration023Test extends MigrationTestCase
{
    public function test_every_existing_user_gets_daems_pivot_row(): void
    {
        $this->runMigrationsUpTo(22);

        $this->pdo()->exec("INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES
            ('u1', 'GSA',    'gsa@t.fi',    'x', '1990-01-01', 'global_system_administrator'),
            ('u2', 'Admin',  'admin@t.fi',  'x', '1990-01-01', 'admin'),
            ('u3', 'Member', 'member@t.fi', 'x', '1990-01-01', 'member'),
            ('u4', 'Mod',    'mod@t.fi',    'x', '1990-01-01', 'moderator')");

        $this->runMigration('023_backfill_user_tenants_from_users_role.sql');

        $rows = $this->pdo()->query(
            "SELECT u.id, ut.role FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             JOIN tenants t ON t.id = ut.tenant_id AND t.slug = 'daems'
             ORDER BY u.id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(4, $rows);
        self::assertSame('registered', $rows[0]['role']);   // GSA → registered in tenant (global flag handles GSA)
        self::assertSame('admin',      $rows[1]['role']);
        self::assertSame('member',     $rows[2]['role']);
        self::assertSame('moderator',  $rows[3]['role']);
    }
}
```

- [ ] **Step 6.2: Write the migration**

Create `database/migrations/023_backfill_user_tenants_from_users_role.sql`:

```sql
-- Migration 023: backfill user_tenants from legacy users.role column
-- Every existing user → member of 'daems' tenant, with their legacy role.
-- GSA users get 'registered' since GSA is now a global flag, not a tenant role.

INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
SELECT
    u.id,
    (SELECT id FROM tenants WHERE slug = 'daems'),
    CASE
        WHEN u.role = 'global_system_administrator' THEN 'registered'
        WHEN u.role IN ('admin','moderator','member','supporter','registered') THEN u.role
        ELSE 'registered'
    END,
    u.created_at
FROM users u
LEFT JOIN user_tenants ut ON ut.user_id = u.id
WHERE ut.user_id IS NULL;
```

- [ ] **Step 6.3: Run tests, apply to dev, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration023Test.php
mysql -u root daems_db < database/migrations/023_backfill_user_tenants_from_users_role.sql
git add database/migrations/023_backfill_user_tenants_from_users_role.sql tests/Integration/Migration/Migration023Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 023 — backfill user_tenants from legacy users.role"
```

---

## Task 7: Migration 024 — drop users.role column

**Caution:** This must run AFTER code has been updated to read `user_tenants.role` everywhere. Order in this plan puts code changes in Tasks 8–18; this migration becomes the last before middleware tasks.

**Files:**
- Create: `database/migrations/024_drop_users_role_column.sql`
- Test: `tests/Integration/Migration/Migration024Test.php`

- [ ] **Step 7.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration024Test extends MigrationTestCase
{
    public function test_role_column_dropped(): void
    {
        $this->runMigrationsUpTo(23);
        $this->runMigration('024_drop_users_role_column.sql');

        self::assertNotContains('role', $this->columnsOf('users'));
    }

    public function test_is_platform_admin_column_retained(): void
    {
        $this->runMigrationsUpTo(23);
        $this->runMigration('024_drop_users_role_column.sql');

        self::assertContains('is_platform_admin', $this->columnsOf('users'));
    }
}
```

- [ ] **Step 7.2: Write the migration**

Create `database/migrations/024_drop_users_role_column.sql`:

```sql
-- Migration 024: drop users.role column (role now lives in user_tenants per tenant)
ALTER TABLE users DROP COLUMN role;
```

- [ ] **Step 7.3: Do NOT apply to dev yet**

Hold off on `mysql ... < 024` until code in Tasks 8–18 is in place. Tests that run against the test DB are fine — those drop tables between tests.

- [ ] **Step 7.4: Commit (migration file only; apply later)**

```bash
git add database/migrations/024_drop_users_role_column.sql tests/Integration/Migration/Migration024Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 024 — drop users.role (prepared, applied after code migration)"
```

---

## Task 8: Domain — TenantId VO

**Files:**
- Create: `src/Domain/Tenant/TenantId.php`
- Test: `tests/Unit/Domain/Tenant/TenantIdTest.php`

- [ ] **Step 8.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantIdTest extends TestCase
{
    public function test_accepts_valid_uuid_v7(): void
    {
        $id = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        self::assertSame('01958000-0000-7000-8000-000000000001', $id->value());
    }

    public function test_rejects_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('not-a-uuid');
    }

    public function test_equals(): void
    {
        $a = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $b = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $c = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
```

- [ ] **Step 8.2: Write the VO**

Check first if `Uuid7Id` base class exists:
```bash
ls /c/laragon/www/daems-platform/src/Domain/Shared/ValueObject/
```

If `Uuid7Id.php` exists, extend it. If not, implement from scratch.

Assuming `Uuid7Id` base exists (per spec), create `src/Domain/Tenant/TenantId.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class TenantId extends Uuid7Id
{
}
```

If `Uuid7Id` does NOT exist, implement TenantId as a standalone VO with inline UUID validation — match the pattern of other `*Id` classes in the project (inspect e.g. `src/Domain/User/UserId.php`).

- [ ] **Step 8.3: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Tenant/TenantIdTest.php
git add src/Domain/Tenant/TenantId.php tests/Unit/Domain/Tenant/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): add TenantId value object"
```

---

## Task 9: Domain — TenantSlug VO

**Files:**
- Create: `src/Domain/Tenant/TenantSlug.php`
- Test: `tests/Unit/Domain/Tenant/TenantSlugTest.php`

- [ ] **Step 9.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantSlug;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantSlugTest extends TestCase
{
    public function test_accepts_valid_slug(): void
    {
        self::assertSame('daems',     TenantSlug::fromString('daems')->value());
        self::assertSame('sahegroup', TenantSlug::fromString('sahegroup')->value());
        self::assertSame('foo-bar',   TenantSlug::fromString('foo-bar')->value());
        self::assertSame('abc123',    TenantSlug::fromString('abc123')->value());
    }

    public function test_rejects_uppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('Daems');
    }

    public function test_rejects_too_short(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('ab');
    }

    public function test_rejects_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString(str_repeat('a', 65));
    }

    public function test_rejects_special_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantSlug::fromString('foo_bar');
    }
}
```

- [ ] **Step 9.2: Write the VO**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use InvalidArgumentException;

final readonly class TenantSlug
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'TenantSlug must be 3–64 chars, lowercase a-z0-9- only, no leading/trailing hyphen.'
            );
        }
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 9.3: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Tenant/TenantSlugTest.php
git add src/Domain/Tenant/TenantSlug.php tests/Unit/Domain/Tenant/TenantSlugTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): add TenantSlug value object"
```

---

## Task 10: Domain — UserTenantRole enum + Tenant entity + TenantDomain VO

Bundle three small classes together.

**Files:**
- Create: `src/Domain/Tenant/UserTenantRole.php`
- Create: `src/Domain/Tenant/Tenant.php`
- Create: `src/Domain/Tenant/TenantDomain.php`
- Test: `tests/Unit/Domain/Tenant/UserTenantRoleTest.php`
- Test: `tests/Unit/Domain/Tenant/TenantTest.php`
- Test: `tests/Unit/Domain/Tenant/TenantDomainTest.php`

- [ ] **Step 10.1: Write UserTenantRole**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

enum UserTenantRole: string
{
    case Admin      = 'admin';
    case Moderator  = 'moderator';
    case Member     = 'member';
    case Supporter  = 'supporter';
    case Registered = 'registered';

    public function label(): string
    {
        return match ($this) {
            self::Admin      => 'Administrator',
            self::Moderator  => 'Moderator',
            self::Member     => 'Member',
            self::Supporter  => 'Supporter',
            self::Registered => 'Member',
        };
    }

    public static function fromStringOrRegistered(string $value): self
    {
        return self::tryFrom($value) ?? self::Registered;
    }
}
```

Test:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\UserTenantRole;
use PHPUnit\Framework\TestCase;

final class UserTenantRoleTest extends TestCase
{
    public function test_enum_values(): void
    {
        self::assertSame('admin',      UserTenantRole::Admin->value);
        self::assertSame('registered', UserTenantRole::Registered->value);
    }

    public function test_no_global_system_administrator_case(): void
    {
        self::assertNull(UserTenantRole::tryFrom('global_system_administrator'));
    }

    public function test_fromStringOrRegistered_falls_back(): void
    {
        self::assertSame(UserTenantRole::Registered, UserTenantRole::fromStringOrRegistered('unknown-role'));
        self::assertSame(UserTenantRole::Admin,      UserTenantRole::fromStringOrRegistered('admin'));
    }
}
```

- [ ] **Step 10.2: Write TenantDomain VO**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use InvalidArgumentException;

final readonly class TenantDomain
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $v = strtolower(trim($value));
        if ($v === '' || strlen($v) > 255) {
            throw new InvalidArgumentException('TenantDomain must be 1–255 chars.');
        }
        // Accept: subdomain.example.com, localhost, xn-- punycode — use filter_var for generous validation
        if (!preg_match('/^(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|localhost)$/', $v)) {
            throw new InvalidArgumentException("TenantDomain invalid: {$value}");
        }
        return new self($v);
    }

    public function value(): string
    {
        return $this->value;
    }
}
```

Test:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantDomain;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantDomainTest extends TestCase
{
    public function test_accepts_common_domains(): void
    {
        self::assertSame('daems.fi',    TenantDomain::fromString('daems.fi')->value());
        self::assertSame('www.daems.fi', TenantDomain::fromString('www.daems.fi')->value());
        self::assertSame('localhost',   TenantDomain::fromString('localhost')->value());
        self::assertSame('daem-society.local', TenantDomain::fromString('daem-society.local')->value());
    }

    public function test_lowercases(): void
    {
        self::assertSame('daems.fi', TenantDomain::fromString('DAEMS.FI')->value());
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantDomain::fromString('');
    }

    public function test_rejects_invalid_syntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantDomain::fromString('not a domain');
    }
}
```

- [ ] **Step 10.3: Write Tenant entity**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DateTimeImmutable;

final readonly class Tenant
{
    public function __construct(
        public TenantId $id,
        public TenantSlug $slug,
        public string $name,
        public DateTimeImmutable $createdAt,
    ) {}
}
```

Test:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    public function test_construction(): void
    {
        $t = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2026-04-19T10:00:00+00:00'),
        );

        self::assertSame('daems', $t->slug->value());
        self::assertSame('Daems Society', $t->name);
    }
}
```

- [ ] **Step 10.4: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Tenant/
git add src/Domain/Tenant/ tests/Unit/Domain/Tenant/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): UserTenantRole enum, Tenant entity, TenantDomain VO"
```

---

## Task 11: Domain — Repository interfaces + TenantNotFoundException

**Files:**
- Create: `src/Domain/Tenant/TenantRepositoryInterface.php`
- Create: `src/Domain/Tenant/UserTenantRepositoryInterface.php`
- Create: `src/Domain/Tenant/TenantNotFoundException.php`

- [ ] **Step 11.1: Write TenantNotFoundException**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DomainException;

final class TenantNotFoundException extends DomainException
{
    public static function bySlug(string $slug): self
    {
        return new self("Tenant not found for slug: {$slug}");
    }

    public static function byDomain(string $domain): self
    {
        return new self("Tenant not found for domain: {$domain}");
    }
}
```

- [ ] **Step 11.2: Write TenantRepositoryInterface**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantRepositoryInterface
{
    public function findById(TenantId $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    public function findByDomain(string $domain): ?Tenant;

    /** @return list<Tenant> */
    public function findAll(): array;
}
```

- [ ] **Step 11.3: Write UserTenantRepositoryInterface**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;

interface UserTenantRepositoryInterface
{
    /** Returns null if user has no (active) membership in the given tenant. */
    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole;

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void;

    /** Soft-departure: sets left_at. */
    public function detach(UserId $userId, TenantId $tenantId): void;

    /** @return list<UserTenantRole> roles per tenant for this user (active memberships only) */
    public function rolesForUser(UserId $userId): array;
}
```

- [ ] **Step 11.4: Commit**

```bash
git add src/Domain/Tenant/TenantRepositoryInterface.php src/Domain/Tenant/UserTenantRepositoryInterface.php src/Domain/Tenant/TenantNotFoundException.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): tenant repository interfaces + TenantNotFoundException"
```

---

## Task 12: Delete Role.php, replace usage with UserTenantRole / isPlatformAdmin

This is a wide refactor. Every file that imports `Daems\Domain\User\Role` must change.

**Files:**
- Delete: `src/Domain/User/Role.php`
- Modify: every file that imports `Daems\Domain\User\Role`

- [ ] **Step 12.1: Find all usages**

Run: `grep -rl "Daems\\\\Domain\\\\User\\\\Role\|use.*\\\\Role;" src/ tests/`

List every file; each needs updating.

- [ ] **Step 12.2: For each usage — apply replacement pattern**

**Pattern A — tenant-scoped role check:**
```php
// Before:
use Daems\Domain\User\Role;
if ($user->role === Role::Admin) { ... }

// After:
use Daems\Domain\Tenant\UserTenantRole;
if ($actingUser->roleInActiveTenant === UserTenantRole::Admin) { ... }
```

**Pattern B — GSA check (was `Role::GlobalSystemAdministrator`):**
```php
// Before:
if ($user->role === Role::GlobalSystemAdministrator) { ... }

// After:
if ($actingUser->isPlatformAdmin()) { ... }
```

**Pattern C — "isAdmin" (admin OR GSA):**
```php
// Before:
if (in_array($user->role, [Role::Admin, Role::GlobalSystemAdministrator], true)) { ... }

// After:
if ($actingUser->isAdminIn($actingUser->activeTenant)) { ... }
```

- [ ] **Step 12.3: Update User entity**

In `src/Domain/User/User.php`:
- Remove `role` field
- Add `isPlatformAdmin: bool` field

In `src/Domain/User/UserRepositoryInterface.php`: no signature changes (role no longer returned).

- [ ] **Step 12.4: Update SqlUserRepository**

In `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php`:
- SQL SELECTs: remove `role`, add `is_platform_admin`
- User hydration: remove role, add isPlatformAdmin
- If SqlUserRepository stores user data outside what User entity has, move to a separate DTO

Specific diff patterns:
```sql
-- Before:
SELECT id, name, email, password_hash, date_of_birth, role, created_at FROM users WHERE id = ?
-- After:
SELECT id, name, email, password_hash, date_of_birth, is_platform_admin, created_at FROM users WHERE id = ?
```

```php
// Before:
return new User(... , Role::fromStringOrRegistered($row['role']), ...);
// After:
return new User(... , (bool) $row['is_platform_admin'], ...);
```

- [ ] **Step 12.5: Delete Role.php**

```bash
rm src/Domain/User/Role.php
```

- [ ] **Step 12.6: Run full suite**

```bash
composer analyse         # must be level 9, 0 errors
composer test
```

If any reference was missed, PHPStan will flag it.

- [ ] **Step 12.7: Commit**

```bash
git add -A src/ tests/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Refactor: replace Role enum with UserTenantRole + isPlatformAdmin flag"
```

---

## Task 13: Update ActingUser

**Files:**
- Modify: `src/Domain/Auth/ActingUser.php`
- Test: `tests/Unit/Domain/Auth/ActingUserTest.php`

- [ ] **Step 13.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ActingUserTest extends TestCase
{
    private function make(
        bool $isPlatformAdmin = false,
        ?UserTenantRole $role = null,
    ): ActingUser {
        return new ActingUser(
            UserId::fromString('01958000-0000-7000-8000-000000000011'),
            'sam@t.fi',
            $isPlatformAdmin,
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            $role,
        );
    }

    public function test_platform_admin_flag(): void
    {
        self::assertTrue($this->make(isPlatformAdmin: true)->isPlatformAdmin());
        self::assertFalse($this->make()->isPlatformAdmin());
    }

    public function test_roleIn_returns_role_for_active_tenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        self::assertSame(UserTenantRole::Admin, $u->roleIn($active));
    }

    public function test_roleIn_returns_null_for_other_tenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $other = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        self::assertNull($u->roleIn($other));
    }

    public function test_isAdminIn_true_for_platform_admin_everywhere(): void
    {
        $u = $this->make(isPlatformAdmin: true);
        $anyTenant = TenantId::fromString('01958000-0000-7000-8000-999999999999');
        self::assertTrue($u->isAdminIn($anyTenant));
    }

    public function test_isAdminIn_true_for_admin_in_active_tenant(): void
    {
        $u = $this->make(role: UserTenantRole::Admin);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        self::assertTrue($u->isAdminIn($active));
    }

    public function test_isAdminIn_false_for_member(): void
    {
        $u = $this->make(role: UserTenantRole::Member);
        $active = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        self::assertFalse($u->isAdminIn($active));
    }
}
```

- [ ] **Step 13.2: Update ActingUser**

Replace `src/Domain/Auth/ActingUser.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final readonly class ActingUser
{
    public function __construct(
        public UserId $id,
        public string $email,
        public bool $isPlatformAdmin,
        public TenantId $activeTenant,
        public ?UserTenantRole $roleInActiveTenant,
    ) {}

    public function isPlatformAdmin(): bool
    {
        return $this->isPlatformAdmin;
    }

    public function roleIn(TenantId $tenant): ?UserTenantRole
    {
        if (!$tenant->equals($this->activeTenant)) {
            return null;
        }
        return $this->roleInActiveTenant;
    }

    public function isAdminIn(TenantId $tenant): bool
    {
        if ($this->isPlatformAdmin) {
            return true;
        }
        return $this->roleIn($tenant) === UserTenantRole::Admin;
    }
}
```

- [ ] **Step 13.3: Fix compilation errors in other files**

`composer analyse` now shows errors in every file that constructs `ActingUser`. Fix each:

```php
// Before:
new ActingUser($userId, $email, $role);

// After:
new ActingUser(
    id:                 $userId,
    email:              $email,
    isPlatformAdmin:    $isPlatformAdmin,
    activeTenant:       $tenantId,
    roleInActiveTenant: $role,
);
```

Most construction sites are in:
- `AuthMiddleware` (will be updated in Task 16)
- Test helpers (update as encountered)

- [ ] **Step 13.4: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Auth/ActingUserTest.php
composer analyse
composer test
git add -A src/ tests/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Refactor: ActingUser — add isPlatformAdmin, activeTenant, roleInActiveTenant"
```

---

## Task 14: SqlTenantRepository

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php`
- Test: `tests/Integration/Persistence/Sql/SqlTenantRepositoryTest.php`

- [ ] **Step 14.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlTenantRepositoryTest extends MigrationTestCase
{
    private SqlTenantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(19);
        $this->repo = new SqlTenantRepository($this->pdo());
    }

    public function test_findBySlug_returns_seeded_tenant(): void
    {
        $t = $this->repo->findBySlug('daems');
        self::assertNotNull($t);
        self::assertSame('Daems Society', $t->name);
    }

    public function test_findBySlug_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->findBySlug('nonexistent'));
    }

    public function test_findByDomain_returns_tenant_for_primary_domain(): void
    {
        $t = $this->repo->findByDomain('daems.fi');
        self::assertNotNull($t);
        self::assertSame('daems', $t->slug->value());
    }

    public function test_findByDomain_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->findByDomain('unknown.example'));
    }

    public function test_findById(): void
    {
        $id = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $t = $this->repo->findById($id);
        self::assertNotNull($t);
        self::assertSame('daems', $t->slug->value());
    }

    public function test_findAll(): void
    {
        $all = $this->repo->findAll();
        self::assertCount(2, $all);
        $slugs = array_map(fn ($t) => $t->slug->value(), $all);
        sort($slugs);
        self::assertSame(['daems', 'sahegroup'], $slugs);
    }
}
```

- [ ] **Step 14.2: Write the repository**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use PDO;

final class SqlTenantRepository implements TenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(TenantId $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, name, created_at FROM tenants WHERE id = ?');
        $stmt->execute([$id->value()]);
        return $this->hydrate($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT id, slug, name, created_at FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return $this->hydrate($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.slug, t.name, t.created_at
             FROM tenants t
             JOIN tenant_domains td ON td.tenant_id = t.id
             WHERE td.domain = ? LIMIT 1'
        );
        $stmt->execute([strtolower(trim($domain))]);
        return $this->hydrate($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT id, slug, name, created_at FROM tenants ORDER BY slug')
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $t = $this->hydrate($row);
            if ($t !== null) $out[] = $t;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private function hydrate(?array $row): ?Tenant
    {
        if ($row === null) return null;
        return new Tenant(
            TenantId::fromString((string) $row['id']),
            TenantSlug::fromString((string) $row['slug']),
            (string) $row['name'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
```

- [ ] **Step 14.3: Run tests, commit**

```bash
vendor/bin/phpunit tests/Integration/Persistence/Sql/SqlTenantRepositoryTest.php
git add src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php tests/Integration/Persistence/Sql/SqlTenantRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(infra): SqlTenantRepository"
```

---

## Task 15: SqlUserTenantRepository

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantRepository.php`
- Test: `tests/Integration/Persistence/Sql/SqlUserTenantRepositoryTest.php`

- [ ] **Step 15.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlUserTenantRepositoryTest extends MigrationTestCase
{
    private SqlUserTenantRepository $repo;
    private UserId $user;
    private TenantId $daems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(22);
        $this->repo = new SqlUserTenantRepository($this->pdo());

        // Seed a user
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, role) VALUES
             ('01958000-0000-7000-8000-000000000011', 'Sam', 'sam@t.fi', 'x', '1990-01-01', 'member')"
        );
        $this->user  = UserId::fromString('01958000-0000-7000-8000-000000000011');
        $this->daems = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    public function test_attach_creates_row_with_role(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Admin);
        self::assertSame(UserTenantRole::Admin, $this->repo->findRole($this->user, $this->daems));
    }

    public function test_findRole_returns_null_when_not_member(): void
    {
        self::assertNull($this->repo->findRole($this->user, $this->daems));
    }

    public function test_findRole_returns_null_when_left(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->repo->detach($this->user, $this->daems);

        self::assertNull($this->repo->findRole($this->user, $this->daems));
    }

    public function test_detach_sets_left_at(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        $this->repo->detach($this->user, $this->daems);

        $leftAt = $this->pdo()->query(
            "SELECT left_at FROM user_tenants WHERE user_id = '01958000-0000-7000-8000-000000000011'"
        )->fetchColumn();
        self::assertNotNull($leftAt);
    }

    public function test_rolesForUser_returns_only_active(): void
    {
        $this->repo->attach($this->user, $this->daems, UserTenantRole::Member);
        self::assertSame([UserTenantRole::Member], $this->repo->rolesForUser($this->user));

        $this->repo->detach($this->user, $this->daems);
        self::assertSame([], $this->repo->rolesForUser($this->user));
    }
}
```

- [ ] **Step 15.2: Write the repository**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PDO;

final class SqlUserTenantRepository implements UserTenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return UserTenantRole::tryFrom((string) $row['role']);
    }

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at, left_at)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value(), $role->value]);
    }

    public function detach(UserId $userId, TenantId $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_tenants SET left_at = NOW() WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value(), $tenantId->value()]);
    }

    public function rolesForUser(UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM user_tenants WHERE user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $role = UserTenantRole::tryFrom((string) $value);
            if ($role !== null) $out[] = $role;
        }
        return $out;
    }
}
```

- [ ] **Step 15.3: Run tests, commit**

```bash
vendor/bin/phpunit tests/Integration/Persistence/Sql/SqlUserTenantRepositoryTest.php
git add src/Infrastructure/Adapter/Persistence/Sql/SqlUserTenantRepository.php tests/Integration/Persistence/Sql/SqlUserTenantRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(infra): SqlUserTenantRepository with soft-departure"
```

---

## Task 16: HostTenantResolver + config/tenant-fallback.php

**Files:**
- Create: `config/tenant-fallback.php`
- Create: `src/Infrastructure/Tenant/HostTenantResolver.php`
- Test: `tests/Unit/Infrastructure/Tenant/HostTenantResolverTest.php`

- [ ] **Step 16.1: Write the fallback config**

Create `config/tenant-fallback.php`:

```php
<?php

declare(strict_types=1);

return [
    'daem-society.local'   => 'daems',
    'daems-platform.local' => 'daems',
    'sahegroup.local'      => 'sahegroup',
    'localhost'            => 'daems',
];
```

- [ ] **Step 16.2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Infrastructure\Tenant\HostTenantResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HostTenantResolverTest extends TestCase
{
    private TenantRepositoryInterface $repo;
    private Tenant $daemsTenant;

    protected function setUp(): void
    {
        $this->daemsTenant = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2026-01-01'),
        );
        $this->repo = new class($this->daemsTenant) implements TenantRepositoryInterface {
            public function __construct(private readonly Tenant $daems) {}
            public function findById(TenantId $id): ?Tenant { return null; }
            public function findBySlug(string $slug): ?Tenant { return $slug === 'daems' ? $this->daems : null; }
            public function findByDomain(string $domain): ?Tenant { return $domain === 'daems.fi' ? $this->daems : null; }
            public function findAll(): array { return [$this->daems]; }
        };
    }

    public function test_db_match_returns_tenant(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        self::assertSame($this->daemsTenant, $r->resolve('daems.fi'));
    }

    public function test_fallback_when_db_misses(): void
    {
        $r = new HostTenantResolver($this->repo, ['daem-society.local' => 'daems']);
        self::assertSame($this->daemsTenant, $r->resolve('daem-society.local'));
    }

    public function test_db_primary_over_fallback(): void
    {
        $r = new HostTenantResolver($this->repo, ['daems.fi' => 'sahegroup']);  // fallback says sahegroup, but DB says daems
        self::assertSame($this->daemsTenant, $r->resolve('daems.fi'));
    }

    public function test_unknown_host_returns_null(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        self::assertNull($r->resolve('unknown.example'));
    }

    public function test_case_insensitive(): void
    {
        $r = new HostTenantResolver($this->repo, []);
        self::assertSame($this->daemsTenant, $r->resolve('DAEMS.FI'));
        self::assertSame($this->daemsTenant, $r->resolve('  daems.fi  '));
    }
}
```

- [ ] **Step 16.3: Write HostTenantResolver**

Create `src/Infrastructure/Tenant/HostTenantResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;

final class HostTenantResolver
{
    /**
     * @param array<string, string> $fallbackMap host => slug
     */
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly array $fallbackMap,
    ) {}

    public function resolve(string $host): ?Tenant
    {
        $host = strtolower(trim($host));
        if ($host === '') return null;

        $byDomain = $this->tenants->findByDomain($host);
        if ($byDomain !== null) return $byDomain;

        if (isset($this->fallbackMap[$host])) {
            return $this->tenants->findBySlug($this->fallbackMap[$host]);
        }

        return null;
    }
}
```

- [ ] **Step 16.4: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Tenant/HostTenantResolverTest.php
git add src/Infrastructure/Tenant/ config/tenant-fallback.php tests/Unit/Infrastructure/Tenant/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(infra): HostTenantResolver with DB primary + config fallback"
```

---

## Task 17: TenantContextMiddleware

**Files:**
- Create: `src/Infrastructure/Framework/Http/Middleware/TenantContextMiddleware.php`
- Test: `tests/Unit/Framework/Http/Middleware/TenantContextMiddlewareTest.php`

- [ ] **Step 17.1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Framework\Http\Middleware;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Tenant\HostTenantResolver;
use DateTimeImmutable;
use Daems\Domain\Http\NotFoundException;   // or appropriate exception class used in project
use PHPUnit\Framework\TestCase;

final class TenantContextMiddlewareTest extends TestCase
{
    public function test_attaches_tenant_to_request(): void
    {
        $tenant = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems',
            new DateTimeImmutable('2026-01-01'),
        );
        $resolver = $this->createMock(HostTenantResolver::class);
        $resolver->method('resolve')->with('daems.fi')->willReturn($tenant);

        $mw = new TenantContextMiddleware($resolver);
        $req = Request::forTesting('GET', '/', headers: ['Host' => 'daems.fi']);

        $passed = null;
        $mw->handle($req, function (Request $r) use (&$passed): Response {
            $passed = $r;
            return new Response(200, '');
        });

        self::assertSame($tenant, $passed?->attribute('tenant'));
    }

    public function test_unknown_host_throws(): void
    {
        $resolver = $this->createMock(HostTenantResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $mw = new TenantContextMiddleware($resolver);
        $req = Request::forTesting('GET', '/', headers: ['Host' => 'unknown.example']);

        $this->expectException(NotFoundException::class);
        $mw->handle($req, fn () => new Response(200, ''));
    }
}
```

Note: import the actual NotFoundException used by the project — check `src/Domain/Http/` or `src/Infrastructure/Framework/Http/` for the existing class.

- [ ] **Step 17.2: Write the middleware**

Create `src/Infrastructure/Framework/Http/Middleware/TenantContextMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Closure;
use Daems\Domain\Http\NotFoundException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Tenant\HostTenantResolver;

final class TenantContextMiddleware implements Middleware
{
    public function __construct(private readonly HostTenantResolver $resolver) {}

    public function handle(Request $req, Closure $next): Response
    {
        $host = $req->header('Host');
        $tenant = $this->resolver->resolve($host ?? '');

        if ($tenant === null) {
            throw new NotFoundException('unknown_tenant');
        }

        return $next($req->withAttribute('tenant', $tenant));
    }
}
```

Verify the `Middleware` interface location — check existing `AuthMiddleware.php` for the namespace/interface in use.

- [ ] **Step 17.3: Run tests, commit**

```bash
vendor/bin/phpunit tests/Unit/Framework/Http/Middleware/TenantContextMiddlewareTest.php
git add src/Infrastructure/Framework/Http/Middleware/TenantContextMiddleware.php tests/Unit/Framework/Http/Middleware/TenantContextMiddlewareTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): TenantContextMiddleware resolves Host to tenant"
```

---

## Task 18: Update AuthMiddleware

Add `X-Daems-Tenant` override handling + load `user_tenants.role` for `ActingUser`.

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php`
- Test: `tests/Unit/Framework/Http/Middleware/AuthMiddlewareTest.php` (update)

- [ ] **Step 18.1: Read current AuthMiddleware**

```bash
cat /c/laragon/www/daems-platform/src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php
```

Understand current shape before editing.

- [ ] **Step 18.2: Write tests for new behavior**

Append to `tests/Unit/Framework/Http/Middleware/AuthMiddlewareTest.php` (or create if missing):

```php
public function test_loads_role_from_user_tenants(): void
{
    // Arrange: stub token validation returning a user, and UserTenantRepository returning 'admin'
    // Act: middleware runs
    // Assert: request->attribute('actingUser') has roleInActiveTenant === UserTenantRole::Admin
}

public function test_gsa_override_header_changes_active_tenant(): void
{
    // Arrange: user.isPlatformAdmin=true, X-Daems-Tenant: sahegroup, request tenant = daems
    // Act: middleware runs
    // Assert: actingUser.activeTenant corresponds to sahegroup
}

public function test_non_gsa_with_override_header_throws_forbidden(): void
{
    // Arrange: user.isPlatformAdmin=false, X-Daems-Tenant: sahegroup
    // Act+Assert: ForbiddenException thrown with code 'tenant_override_forbidden'
}

public function test_unknown_override_slug_throws_not_found(): void
{
    // Arrange: user.isPlatformAdmin=true, X-Daems-Tenant: unknown
    // Act+Assert: NotFoundException with code 'unknown_tenant'
}

public function test_user_without_membership_gets_null_role(): void
{
    // Arrange: UserTenantRepository returns null
    // Act: middleware runs
    // Assert: actingUser.roleInActiveTenant === null, request still goes through
}
```

Fill each test with concrete stubs following the project's existing test patterns in `AuthMiddlewareTest`.

- [ ] **Step 18.3: Update AuthMiddleware**

Rewrite `src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Closure;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthService;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Http\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthMiddleware implements Middleware
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly TenantRepositoryInterface $tenantRepo,
        private readonly UserTenantRepositoryInterface $userTenantRepo,
    ) {}

    public function handle(Request $req, Closure $next): Response
    {
        $token = $req->bearerToken();
        if ($token === null) {
            throw new UnauthorizedException('missing_token');
        }

        $user = $this->authService->validateToken($token);  // returns user identity (id, email, isPlatformAdmin)

        $tenantAttr = $req->attribute('tenant');
        if (!$tenantAttr instanceof Tenant) {
            throw new \RuntimeException('TenantContextMiddleware must run before AuthMiddleware.');
        }
        $tenant = $tenantAttr;

        $override = $req->header('X-Daems-Tenant');
        if ($override !== null && $override !== '') {
            if (!$user->isPlatformAdmin) {
                throw new ForbiddenException('tenant_override_forbidden');
            }
            $overrideTenant = $this->tenantRepo->findBySlug($override);
            if ($overrideTenant === null) {
                throw new NotFoundException('unknown_tenant');
            }
            $tenant = $overrideTenant;
            $req = $req->withAttribute('tenant', $tenant);
        }

        $role = $this->userTenantRepo->findRole($user->id, $tenant->id);

        $actingUser = new ActingUser(
            id:                 $user->id,
            email:              $user->email,
            isPlatformAdmin:    $user->isPlatformAdmin,
            activeTenant:       $tenant->id,
            roleInActiveTenant: $role,
        );

        return $next($req->withActingUser($actingUser)->withAttribute('actingUser', $actingUser));
    }
}
```

**Note on `AuthService::validateToken()`:** Must return an object with `id: UserId`, `email: string`, `isPlatformAdmin: bool`. Update `AuthService` signature if current shape differs (in a separate small commit, or fold into this one).

- [ ] **Step 18.4: Update DI in bootstrap/app.php**

Ensure `AuthMiddleware` construction passes the two new dependencies:

```php
// Before:
$container->bind(AuthMiddleware::class, fn ($c) => new AuthMiddleware($c->make(AuthService::class)));

// After:
$container->bind(AuthMiddleware::class, fn ($c) => new AuthMiddleware(
    $c->make(AuthService::class),
    $c->make(TenantRepositoryInterface::class),
    $c->make(UserTenantRepositoryInterface::class),
));

// And bind tenant repositories:
$container->singleton(TenantRepositoryInterface::class, fn ($c) => new SqlTenantRepository($c->make(Connection::class)->pdo()));
$container->singleton(UserTenantRepositoryInterface::class, fn ($c) => new SqlUserTenantRepository($c->make(Connection::class)->pdo()));
$container->singleton(HostTenantResolver::class, fn ($c) => new HostTenantResolver(
    $c->make(TenantRepositoryInterface::class),
    (array) require __DIR__ . '/../config/tenant-fallback.php',
));
$container->bind(TenantContextMiddleware::class, fn ($c) => new TenantContextMiddleware($c->make(HostTenantResolver::class)));
```

- [ ] **Step 18.5: Run tests, commit**

```bash
composer analyse
composer test
git add src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php bootstrap/app.php tests/Unit/Framework/Http/Middleware/AuthMiddlewareTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): AuthMiddleware loads tenant-scoped role + handles X-Daems-Tenant override"
```

---

## Task 19: Apply migration 024 (drop users.role) to dev database

Now that code no longer reads `users.role`, it's safe to drop the column.

- [ ] **Step 19.1: Apply the migration**

```bash
mysql -u root daems_db < /c/laragon/www/daems-platform/database/migrations/024_drop_users_role_column.sql
```

- [ ] **Step 19.2: Verify**

```bash
mysql -u root daems_db -e "SHOW COLUMNS FROM users;"
```

Expected: `role` column absent, `is_platform_admin` column present.

- [ ] **Step 19.3: Run full suite**

```bash
composer test
composer analyse
```

- [ ] **Step 19.4: No commit needed** (migration file and test already committed in Task 7).

---

## Task 20: Create GetAuthMe use case + controller + route

**Files:**
- Create: `src/Application/UseCase/Auth/GetAuthMe/GetAuthMe.php`
- Create: `src/Application/UseCase/Auth/GetAuthMe/GetAuthMeOutput.php`
- Modify: an auth controller (e.g. `src/Infrastructure/Adapter/Api/Controller/AuthController.php`)
- Modify: `routes/api.php`
- Test: `tests/Unit/Application/UseCase/Auth/GetAuthMeTest.php`
- Test: `tests/E2E/AuthMeEndpointTest.php`

- [ ] **Step 20.1: Write GetAuthMeOutput**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\UseCase\Auth\GetAuthMe;

final readonly class GetAuthMeOutput
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public bool $isPlatformAdmin,
        public string $tenantSlug,
        public string $tenantName,
        public ?string $roleInTenant,
        public ?string $tokenExpiresAt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user' => [
                'id'                 => $this->userId,
                'name'               => $this->name,
                'email'              => $this->email,
                'is_platform_admin'  => $this->isPlatformAdmin,
            ],
            'tenant' => [
                'slug' => $this->tenantSlug,
                'name' => $this->tenantName,
            ],
            'role_in_tenant'    => $this->roleInTenant,
            'token_expires_at'  => $this->tokenExpiresAt,
        ];
    }
}
```

- [ ] **Step 20.2: Write GetAuthMe use case**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\UseCase\Auth\GetAuthMe;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final readonly class GetAuthMe
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TenantRepositoryInterface $tenants,
        private AuthTokenRepositoryInterface $tokens,
    ) {}

    public function execute(ActingUser $actor, string $bearerToken): GetAuthMeOutput
    {
        $user = $this->users->findById($actor->id)
            ?? throw new \RuntimeException('ActingUser points to missing user');
        $tenant = $this->tenants->findById($actor->activeTenant)
            ?? throw new \RuntimeException('ActingUser points to missing tenant');

        $expiresAt = $this->tokens->expiryOf($bearerToken);   // returns ?DateTimeImmutable

        return new GetAuthMeOutput(
            userId:           $actor->id->value(),
            name:             $user->name,
            email:            $actor->email,
            isPlatformAdmin:  $actor->isPlatformAdmin,
            tenantSlug:       $tenant->slug->value(),
            tenantName:       $tenant->name,
            roleInTenant:     $actor->roleInActiveTenant?->value,
            tokenExpiresAt:   $expiresAt?->format(DATE_ATOM),
        );
    }
}
```

If `AuthTokenRepositoryInterface::expiryOf(string)` doesn't exist, add it in a small preceding commit.

- [ ] **Step 20.3: Add controller handler**

In the existing auth controller (inspect e.g. `AdminController.php` pattern; create `AuthController` if none):

```php
public function me(Request $req): Response
{
    $actor = $req->requireActingUser();
    $token = (string) $req->bearerToken();
    $output = $this->getAuthMe->execute($actor, $token);

    return Response::json(['data' => $output->toArray()]);
}
```

- [ ] **Step 20.4: Register route**

In `routes/api.php`:
```php
$router->get('/api/v1/auth/me', static function (Request $req) use ($container): Response {
    return $container->make(AuthController::class)->me($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 20.5: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\UseCase\Auth;

// ... build in-memory repos, construct GetAuthMe, execute, assert output shape
```

Follow project's existing use case testing pattern (check `tests/Unit/Application/UseCase/` for examples).

- [ ] **Step 20.6: Write E2E test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\E2E\E2ETestCase;   // or existing project base

final class AuthMeEndpointTest extends E2ETestCase
{
    public function test_me_returns_user_tenant_and_role(): void
    {
        // 1) Seed user + daems tenant + user_tenants role='admin'
        // 2) POST /api/v1/auth/login → token
        // 3) GET /api/v1/auth/me with Bearer token and Host: daems.fi
        // 4) Assert 200, data.user.email, data.tenant.slug='daems', data.role_in_tenant='admin'
    }

    public function test_me_with_gsa_and_override_header_returns_other_tenant(): void
    {
        // 1) Seed GSA user + both tenants
        // 2) Login → token
        // 3) GET /auth/me with Bearer + Host: daems.fi + X-Daems-Tenant: sahegroup
        // 4) Assert 200, data.tenant.slug='sahegroup'
    }

    public function test_me_non_gsa_with_override_returns_403(): void
    {
        // similar but user is NOT platform admin → 403 tenant_override_forbidden
    }
}
```

- [ ] **Step 20.7: Run tests, commit**

```bash
composer test
composer test:e2e
git add src/Application/UseCase/Auth/GetAuthMe/ src/Infrastructure/Adapter/Api/Controller/ routes/api.php tests/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(api): GET /api/v1/auth/me for token + tenant validation"
```

---

## Task 21: Apply TenantContextMiddleware to all existing routes

Every route in `routes/api.php` must have `TenantContextMiddleware` as its first middleware.

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 21.1: Read current routes**

```bash
cat /c/laragon/www/daems-platform/routes/api.php
```

- [ ] **Step 21.2: Add TenantContextMiddleware to each route**

For every `$router->get(...)` / `$router->post(...)` call, ensure the middleware array starts with `TenantContextMiddleware::class`:

```php
// Before:
$router->get('/api/v1/events', ..., [/* no middleware */]);
$router->get('/api/v1/projects', ..., [AuthMiddleware::class]);

// After:
$router->get('/api/v1/events', ..., [TenantContextMiddleware::class]);
$router->get('/api/v1/projects', ..., [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Add `use` import at the top:
```php
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
```

- [ ] **Step 21.3: Run E2E suite**

```bash
composer test:e2e
```

All routes must still pass. If a route breaks because it's called from an unknown Host, inspect the test setup and set `Host: daems.fi` in the test's request construction.

- [ ] **Step 21.4: Commit**

```bash
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): apply TenantContextMiddleware to every route"
```

---

## Task 22: Documentation updates

**Files:**
- Modify: `docs/api.md`
- Modify: `docs/decisions.md`
- Modify: `docs/architecture.md`
- Modify: `docs/database.md`

- [ ] **Step 22.1: Append ADR-014 to decisions.md**

```markdown

## ADR-014: Multi-Tenant Data Isolation via Domain-Derived Tenant Context + Pivot Membership

**Status:** Accepted — 2026-04-19
**Context:** Platform must support multiple tenants (daems, sahegroup, …) sharing one codebase and database. Users should be global identities but have tenant-scoped memberships and roles.
**Decision:**
- Tenants and their domains stored in `tenants` + `tenant_domains` tables.
- Host → tenant resolution via `HostTenantResolver` (DB primary, config/tenant-fallback.php secondary for dev).
- `user_tenants` pivot stores per-tenant role (`admin`, `moderator`, `member`, `supporter`, `registered`).
- GSA represented as `users.is_platform_admin` global boolean (not a tenant role). Changes audited via MySQL trigger into `platform_admin_audit`.
- `TenantContextMiddleware` runs on every route; `AuthMiddleware` loads `user_tenants.role` and handles `X-Daems-Tenant` override for GSA cross-tenant queries.
- Per-tenant tables get `tenant_id` column with FK + NOT NULL (implemented in PR 3).

**Consequences:**
- Repository methods must take `TenantId` parameter explicitly; cross-tenant methods must be named `*CrossTenant`/`*AllTenants`.
- Same user account can be member of multiple tenants with different roles.
- Platform admins (GSA) can override tenant context via `X-Daems-Tenant` header; other users cannot (403).
- Onboarding a new tenant: INSERT into `tenants` + `tenant_domains` + configure web server to route the new domain to platform.
```

- [ ] **Step 22.2: Update api.md**

Document `X-Daems-Tenant` header + new `/auth/me` endpoint + new error codes (`unknown_tenant`, `tenant_override_forbidden`, `not_a_member`).

Location: under an "Authentication" or "Request headers" section. Follow existing api.md style.

- [ ] **Step 22.3: Update architecture.md**

Replace or extend the high-level architecture diagram with the multi-tenant view (based on spec section 4.1).

- [ ] **Step 22.4: Update database.md**

Add sections for:
- `tenants` table
- `tenant_domains` table
- `user_tenants` table
- `platform_admin_audit` table
- `users.is_platform_admin` column and the audit trigger

- [ ] **Step 22.5: Commit**

```bash
git add docs/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Docs: ADR-014 + api.md + architecture.md + database.md for multi-tenant infrastructure"
```

---

## Task 23: Final verification and PR prep

- [ ] **Step 23.1: Run all gates**

```bash
composer test
composer analyse         # level 9, 0 errors
composer test:mutation   # MSI ≥ 85 %
composer test:e2e
```

All must pass.

- [ ] **Step 23.2: Manual smoke**

With the dev database migrated to 024, verify:

```bash
# Login (should work as before)
curl -s -X POST http://daem-society.local/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -H 'Host: daems.fi' \
     -d '{"email":"<existing>","password":"<pw>"}' | jq

# /auth/me (new endpoint)
TOKEN=<copied from login>
curl -s http://daem-society.local/api/v1/auth/me \
     -H "Authorization: Bearer $TOKEN" \
     -H 'Host: daems.fi' | jq
# Expected: data.tenant.slug=daems, data.role_in_tenant set
```

- [ ] **Step 23.3: Review git log**

```bash
git log --oneline origin/dev..HEAD
```

Expected ~22 commits. Each is a single coherent change.

- [ ] **Step 23.4: Ask user for push approval**

> "PR 2 (tenant infrastructure) valmis. X committia paikallisesti. Pushaanko origin/dev:iin?"

---

## Completion criteria

- [ ] Migrations 019–024 applied, tests for each green
- [ ] `users.role` column dropped; `is_platform_admin` in place; audit trigger active
- [ ] `user_tenants` populated for all existing users (tenant='daems')
- [ ] `src/Domain/Tenant/` contains 8 new classes, all tested
- [ ] `SqlTenantRepository` and `SqlUserTenantRepository` implement interfaces, integration-tested
- [ ] `TenantContextMiddleware` applied to every route in `routes/api.php`
- [ ] `AuthMiddleware` loads `user_tenants.role` and handles `X-Daems-Tenant` override
- [ ] `GET /api/v1/auth/me` returns user + tenant + role (E2E tested)
- [ ] `Role::GlobalSystemAdministrator` removed from codebase
- [ ] PHPStan level 9, 0 errors, no baseline
- [ ] MSI ≥ 85 %
- [ ] ADR-014 committed; docs/api.md, architecture.md, database.md updated
