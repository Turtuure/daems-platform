# Membership Core v2 — Tier System (0.6a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ad-hoc `users.membership_type` VARCHAR column with the four-value `MembershipType` enum (SUPPORTING / BASIC / FULL / HONORARY) defined by Daem Society bylaws § 3, plus a per-tenant configurable honor sub-tier catalog (bronze / silver / gold / platinum default), and surface tier counts in the backstage dashboard.

**Architecture:** Clean Architecture across `daems-platform` (Domain + Application + Infrastructure) with module-side adaptations in `modules/members`. New domain types live under `Daems\Domain\Membership\*`. A new `tenant_membership_subtiers` table holds the honor catalog per tenant. Sub-tier *award* logic (board majority decision) is explicitly deferred to milestone 0.6b — this milestone only ships the infrastructure plus a read-only API.

**Tech Stack:** PHP 8.3, MySQL 8.4, PHPUnit 11, PHPStan level 9, Clean Architecture (no framework deps in Domain). Migrations are `.sql` for plain DDL and `.php` for conditional UPDATEs with transactional control.

---

## Task 1: Migration 074 — add `users.membership_subtier` column

**Files:**
- Create: `database/migrations/074_membership_type_v2.sql`

- [ ] **Step 1: Create the SQL migration**

`database/migrations/074_membership_type_v2.sql`:

```sql
-- 074_membership_type_v2.sql
-- MembershipCore v2 / 0.6a: add membership_subtier column. The four-value
-- canonical migration of membership_type happens in 075 (PHP, transactional).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS membership_subtier VARCHAR(30) NULL AFTER membership_type;
```

- [ ] **Step 2: Run migration locally + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/074_membership_type_v2.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW COLUMNS FROM users WHERE Field = 'membership_subtier';"
```

Expected output: one row with `membership_subtier | varchar(30) | YES | | NULL`.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 074 — users.membership_subtier column"
```

---

## Task 2: Migration 075 — backfill legacy `membership_type` values

**Files:**
- Create: `database/migrations/075_backfill_membership_type.php`

- [ ] **Step 1: Create the PHP migration**

`database/migrations/075_backfill_membership_type.php`:

```php
<?php
// 075_backfill_membership_type.php
// Normalise users.membership_type to the four canonical values defined by
// Daem Society bylaws § 3: SUPPORTING, BASIC, FULL, HONORARY.
// Old value is preserved in users.membership_type_legacy for audit.
//
// The script is wrapped in one transaction so a partial-deploy failure does
// not leave the column half-normalised.
//
// @var \PDO $pdo  — provided by MigrationRunner

$pdo->beginTransaction();
try {
    // 1. Audit copy.
    $pdo->exec("
        UPDATE users
           SET membership_type_legacy = membership_type
         WHERE membership_type_legacy IS NULL
    ");

    // 2. Normalise to the four canonical values.
    $pdo->exec("UPDATE users SET membership_type = 'SUPPORTING' WHERE membership_type = 'supporter'");
    $pdo->exec("UPDATE users SET membership_type = 'BASIC'      WHERE membership_type IN ('basic', 'individual')");
    $pdo->exec("UPDATE users SET membership_type = 'FULL'       WHERE membership_type = 'full'");
    $pdo->exec("UPDATE users SET membership_type = 'HONORARY'   WHERE membership_type = 'honorary'");

    // 3. Fill membership_started_at where NULL — uses users.created_at as the
    // proxy join date. Required for the 12-month BASIC→FULL eligibility rule
    // implemented in milestone 0.6b.
    $pdo->exec("
        UPDATE users
           SET membership_started_at = created_at
         WHERE membership_started_at IS NULL
           AND membership_type IN ('BASIC', 'FULL', 'HONORARY')
    ");

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

- [ ] **Step 2: Run migration**

```bash
php database/migrations/075_backfill_membership_type.php
```

Expected: no output (silent on success). On error: stack trace.

- [ ] **Step 3: Verify normalised values**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT membership_type, COUNT(*) FROM users GROUP BY membership_type;"
```

Expected: only `SUPPORTING`, `BASIC`, `FULL`, `HONORARY` (counts match the prior shape: 4 SUPPORTING, 12 BASIC, 3 FULL, 0 HONORARY for daems tenant).

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 075 — backfill legacy membership_type values"
```

**Note:** the migration runner discovers `.php` files automatically (existing `MigrationRunner` uses `glob(__DIR__ . '/../../database/migrations/*.{sql,php}', GLOB_BRACE)`). The file is named so it sorts after `074` and before `076`.

---

## Task 3: Migration 076 — create `tenant_membership_subtiers` table

**Files:**
- Create: `database/migrations/076_tenant_membership_subtiers.sql`

- [ ] **Step 1: Create the SQL migration**

`database/migrations/076_tenant_membership_subtiers.sql`:

```sql
-- 076_tenant_membership_subtiers.sql
-- Honor sub-tier catalog per tenant. Award/revoke logic lives in 0.6b.
-- Sub-tier explicitly does NOT carry pricing (annual_fee_cents stays
-- absent — fee handling moves to 0.7 Billing).

CREATE TABLE tenant_membership_subtiers (
    id          CHAR(36)    NOT NULL,
    tenant_id   CHAR(36)    NOT NULL,
    slug        VARCHAR(30) NOT NULL,
    name        VARCHAR(80) NOT NULL,
    rank_order  INT         NOT NULL,
    applies_to  VARCHAR(20) NOT NULL,   -- 'SUPPORTING' or 'BASIC' only
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_applies_slug (tenant_id, applies_to, slug),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_subtier_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Run migration**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/076_tenant_membership_subtiers.sql
```

- [ ] **Step 3: Verify table created**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "DESCRIBE tenant_membership_subtiers;"
```

Expected: 8 columns including `applies_to VARCHAR(20)` and unique key on `(tenant_id, applies_to, slug)`.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 076 — tenant_membership_subtiers table"
```

---

## Task 4: Migration 077 — seed default sub-tier catalog

**Files:**
- Create: `database/migrations/077_seed_default_subtiers.php`

- [ ] **Step 1: Create the PHP seeder**

`database/migrations/077_seed_default_subtiers.php`:

```php
<?php
// 077_seed_default_subtiers.php
// Seed bronze/silver/gold/platinum × SUPPORTING + BASIC for every existing
// tenant. INSERT IGNORE so the script stays idempotent.

use Daems\Domain\Shared\ValueObject\Uuid7;

$defaults = [
    ['bronze',   'Bronze',   1],
    ['silver',   'Silver',   2],
    ['gold',     'Gold',     3],
    ['platinum', 'Platinum', 4],
];
$appliesToValues = ['SUPPORTING', 'BASIC'];

$tenantIds = $pdo->query('SELECT id FROM tenants')->fetchAll(\PDO::FETCH_COLUMN);
if ($tenantIds === false) {
    throw new \RuntimeException('Failed to read tenants for sub-tier seed');
}

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_membership_subtiers
        (id, tenant_id, slug, name, rank_order, applies_to)
     VALUES (?, ?, ?, ?, ?, ?)'
);

foreach ($tenantIds as $tenantId) {
    foreach ($appliesToValues as $appliesTo) {
        foreach ($defaults as [$slug, $name, $rank]) {
            $insert->execute([
                Uuid7::generate()->value(),
                $tenantId, $slug, $name, $rank, $appliesTo,
            ]);
        }
    }
}
```

- [ ] **Step 2: Run seeder**

```bash
php database/migrations/077_seed_default_subtiers.php
```

- [ ] **Step 3: Verify seed**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT applies_to, slug, COUNT(*) FROM tenant_membership_subtiers GROUP BY applies_to, slug ORDER BY applies_to, rank_order;"
```

Expected: 8 rows (4 per applies_to), each with count = number of tenants (3 — daems, sahegroup, edvin).

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 077 — seed default sub-tier catalog"
```

---

## Task 5: Domain — `MembershipType` enum

**Files:**
- Create: `src/Domain/Membership/MembershipType.php`
- Test: `tests/Unit/Domain/Membership/MembershipTypeTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Membership/MembershipTypeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\MembershipType;
use PHPUnit\Framework\TestCase;

final class MembershipTypeTest extends TestCase
{
    public function test_only_full_has_voting_rights(): void
    {
        self::assertFalse(MembershipType::Supporting->hasVotingRights());
        self::assertFalse(MembershipType::Basic->hasVotingRights());
        self::assertTrue(MembershipType::Full->hasVotingRights());
        self::assertFalse(MembershipType::Honorary->hasVotingRights());
    }

    public function test_only_full_is_eligible_for_board(): void
    {
        self::assertFalse(MembershipType::Supporting->isEligibleForBoard());
        self::assertFalse(MembershipType::Basic->isEligibleForBoard());
        self::assertTrue(MembershipType::Full->isEligibleForBoard());
        self::assertFalse(MembershipType::Honorary->isEligibleForBoard());
    }

    public function test_membership_fee_payers(): void
    {
        self::assertFalse(MembershipType::Supporting->paysMembershipFee());
        self::assertTrue(MembershipType::Basic->paysMembershipFee());
        self::assertTrue(MembershipType::Full->paysMembershipFee());
        self::assertFalse(MembershipType::Honorary->paysMembershipFee());
    }

    public function test_supporter_fee_payer(): void
    {
        self::assertTrue(MembershipType::Supporting->paysSupporterFee());
        self::assertFalse(MembershipType::Basic->paysSupporterFee());
        self::assertFalse(MembershipType::Full->paysSupporterFee());
        self::assertFalse(MembershipType::Honorary->paysSupporterFee());
    }

    public function test_sub_tier_only_for_supporting_and_basic(): void
    {
        self::assertTrue(MembershipType::Supporting->allowsSubTier());
        self::assertTrue(MembershipType::Basic->allowsSubTier());
        self::assertFalse(MembershipType::Full->allowsSubTier());
        self::assertFalse(MembershipType::Honorary->allowsSubTier());
    }

    /** @dataProvider legacyMappingCases */
    public function test_from_legacy_string(string $legacy, MembershipType $expected): void
    {
        self::assertSame($expected, MembershipType::fromLegacyString($legacy));
    }

    /** @return iterable<string, array{string, MembershipType}> */
    public static function legacyMappingCases(): iterable
    {
        yield 'supporter'   => ['supporter',   MembershipType::Supporting];
        yield 'basic'       => ['basic',       MembershipType::Basic];
        yield 'individual'  => ['individual',  MembershipType::Basic];
        yield 'full'        => ['full',        MembershipType::Full];
        yield 'honorary'    => ['honorary',    MembershipType::Honorary];
        yield 'uppercased'  => ['Supporter',   MembershipType::Supporting];
    }

    public function test_from_legacy_string_rejects_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MembershipType::fromLegacyString('platinum-elite');
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Domain/Membership/MembershipTypeTest.php
```

Expected: `Class "Daems\Domain\Membership\MembershipType" not found`.

- [ ] **Step 3: Create the enum**

`src/Domain/Membership/MembershipType.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

enum MembershipType: string
{
    case Supporting = 'SUPPORTING';
    case Basic      = 'BASIC';
    case Full       = 'FULL';
    case Honorary   = 'HONORARY';

    public function hasVotingRights(): bool       { return $this === self::Full; }
    public function isEligibleForBoard(): bool    { return $this === self::Full; }
    public function paysMembershipFee(): bool     { return $this === self::Basic || $this === self::Full; }
    public function paysSupporterFee(): bool      { return $this === self::Supporting; }
    public function allowsSubTier(): bool         { return $this === self::Supporting || $this === self::Basic; }

    public static function fromLegacyString(string $legacy): self
    {
        return match (strtolower($legacy)) {
            'supporter'           => self::Supporting,
            'basic', 'individual' => self::Basic,
            'full'                => self::Full,
            'honorary'            => self::Honorary,
            default               => throw new \InvalidArgumentException("Unknown legacy membership_type: {$legacy}"),
        };
    }
}
```

- [ ] **Step 4: Run test, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Domain/Membership/MembershipTypeTest.php
```

Expected: 7+ tests pass.

- [ ] **Step 5: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/membership): MembershipType enum + rights methods + legacy mapping"
```

---

## Task 6: Domain — `TenantMembershipSubTierId` Uuid7Id subclass

**Files:**
- Create: `src/Domain/Membership/TenantMembershipSubTierId.php`

- [ ] **Step 1: Create the ID class**

`src/Domain/Membership/TenantMembershipSubTierId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class TenantMembershipSubTierId extends Uuid7Id {}
```

- [ ] **Step 2: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/membership): TenantMembershipSubTierId Uuid7Id"
```

---

## Task 7: Domain — `TenantMembershipSubTier` entity

**Files:**
- Create: `src/Domain/Membership/TenantMembershipSubTier.php`
- Create: `src/Domain/Membership/Exception/InvalidSubTierAppliesTo.php`
- Test: `tests/Unit/Domain/Membership/TenantMembershipSubTierTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Membership/TenantMembershipSubTierTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\Exception\InvalidSubTierAppliesTo;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantMembershipSubTierTest extends TestCase
{
    public function test_construct_with_supporting(): void
    {
        $st = new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Supporting,
        );

        self::assertSame('bronze', $st->slug);
        self::assertSame(MembershipType::Supporting, $st->appliesTo);
    }

    public function test_construct_with_basic(): void
    {
        $st = new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'gold',
            name:       'Gold',
            rankOrder:  3,
            appliesTo:  MembershipType::Basic,
        );

        self::assertSame(MembershipType::Basic, $st->appliesTo);
    }

    public function test_rejects_full_applies_to(): void
    {
        $this->expectException(InvalidSubTierAppliesTo::class);
        new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Full,
        );
    }

    public function test_rejects_honorary_applies_to(): void
    {
        $this->expectException(InvalidSubTierAppliesTo::class);
        new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Honorary,
        );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Domain/Membership/TenantMembershipSubTierTest.php
```

Expected: class not found.

- [ ] **Step 3: Create exception class**

`src/Domain/Membership/Exception/InvalidSubTierAppliesTo.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Exception;

final class InvalidSubTierAppliesTo extends \InvalidArgumentException {}
```

- [ ] **Step 4: Create entity**

`src/Domain/Membership/TenantMembershipSubTier.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Membership\Exception\InvalidSubTierAppliesTo;
use Daems\Domain\Tenant\TenantId;

final class TenantMembershipSubTier
{
    public function __construct(
        public readonly TenantMembershipSubTierId $id,
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly string $name,
        public readonly int $rankOrder,
        public readonly MembershipType $appliesTo,
    ) {
        if (!$appliesTo->allowsSubTier()) {
            throw new InvalidSubTierAppliesTo(
                'Sub-tier only allowed on SUPPORTING or BASIC, got: ' . $appliesTo->value
            );
        }
    }
}
```

- [ ] **Step 5: Run test, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Domain/Membership/TenantMembershipSubTierTest.php
```

Expected: 4 tests pass.

- [ ] **Step 6: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/membership): TenantMembershipSubTier entity + applies-to validation"
```

---

## Task 8: Domain — `TenantMembershipSubTierRepositoryInterface`

**Files:**
- Create: `src/Domain/Membership/TenantMembershipSubTierRepositoryInterface.php`

- [ ] **Step 1: Create the port**

`src/Domain/Membership/TenantMembershipSubTierRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

interface TenantMembershipSubTierRepositoryInterface
{
    /** @return list<TenantMembershipSubTier> */
    public function listForTenant(TenantId $tenantId): array;

    public function findBySlug(
        TenantId $tenantId,
        MembershipType $appliesTo,
        string $slug,
    ): ?TenantMembershipSubTier;

    public function save(TenantMembershipSubTier $subTier): void;

    public function delete(TenantMembershipSubTierId $id): void;
}
```

- [ ] **Step 2: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/membership): TenantMembershipSubTierRepositoryInterface port"
```

---

## Task 9: Infrastructure — `InMemoryTenantMembershipSubTierRepository` (test fake)

**Files:**
- Create: `tests/Support/Fake/InMemoryTenantMembershipSubTierRepository.php`
- Test: `tests/Unit/Tests/Support/Fake/InMemoryTenantMembershipSubTierRepositoryTest.php`

- [ ] **Step 1: Write failing test**

`tests/Unit/Tests/Support/Fake/InMemoryTenantMembershipSubTierRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Tests\Support\Fake;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryTenantMembershipSubTierRepositoryTest extends TestCase
{
    public function test_save_and_list(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        );
        $repo->save($st);

        $list = $repo->listForTenant($tenantId);
        self::assertCount(1, $list);
        self::assertSame('bronze', $list[0]->slug);
    }

    public function test_find_by_slug_returns_match(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        );
        $repo->save($st);

        $found = $repo->findBySlug($tenantId, MembershipType::Basic, 'gold');
        self::assertNotNull($found);
        self::assertSame('Gold', $found->name);
    }

    public function test_find_by_slug_returns_null_when_wrong_applies_to(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        );
        $repo->save($st);

        self::assertNull(
            $repo->findBySlug($tenantId, MembershipType::Supporting, 'gold')
        );
    }

    public function test_list_isolates_by_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantA, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        ));
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantB, 'silver', 'Silver', 2, MembershipType::Supporting,
        ));

        self::assertCount(1, $repo->listForTenant($tenantA));
        self::assertCount(1, $repo->listForTenant($tenantB));
    }

    public function test_delete_removes(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $id = TenantMembershipSubTierId::generate();
        $tenantId = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            $id, $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        ));
        $repo->delete($id);
        self::assertSame([], $repo->listForTenant($tenantId));
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Tests/Support/Fake/InMemoryTenantMembershipSubTierRepositoryTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the fake**

`tests/Support/Fake/InMemoryTenantMembershipSubTierRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryTenantMembershipSubTierRepository implements TenantMembershipSubTierRepositoryInterface
{
    /** @var array<string, TenantMembershipSubTier> id-keyed */
    private array $byId = [];

    public function listForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();
        $out = [];
        foreach ($this->byId as $st) {
            if ($st->tenantId->value() === $tid) {
                $out[] = $st;
            }
        }
        usort($out, static fn($a, $b) => $a->appliesTo->value <=> $b->appliesTo->value
            ?: $a->rankOrder <=> $b->rankOrder);
        return $out;
    }

    public function findBySlug(
        TenantId $tenantId,
        MembershipType $appliesTo,
        string $slug,
    ): ?TenantMembershipSubTier {
        foreach ($this->byId as $st) {
            if ($st->tenantId->value() === $tenantId->value()
                && $st->appliesTo === $appliesTo
                && $st->slug === $slug) {
                return $st;
            }
        }
        return null;
    }

    public function save(TenantMembershipSubTier $subTier): void
    {
        $this->byId[$subTier->id->value()] = $subTier;
    }

    public function delete(TenantMembershipSubTierId $id): void
    {
        unset($this->byId[$id->value()]);
    }
}
```

- [ ] **Step 4: Run test, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Tests/Support/Fake/InMemoryTenantMembershipSubTierRepositoryTest.php
```

Expected: 5 tests pass.

- [ ] **Step 5: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/fake): InMemoryTenantMembershipSubTierRepository"
```

---

## Task 10: Infrastructure — `SqlTenantMembershipSubTierRepository`

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepository.php`
- Test: `tests/Integration/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepositoryTest.php`

- [ ] **Step 1: Write the failing Integration test**

`tests/Integration/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlTenantMembershipSubTierRepositoryTest extends MigrationTestCase
{
    private SqlTenantMembershipSubTierRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(77);
        $this->repo = new SqlTenantMembershipSubTierRepository($this->pdo());
    }

    public function test_seed_present_after_migration_077(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-a');
        $list = $this->repo->listForTenant($tenantId);
        // Seed runs against all existing tenants — including the one we just created.
        self::assertCount(8, $list); // 4 slugs × 2 appliesTo
    }

    public function test_save_and_find_by_slug(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-b');
        $id = TenantMembershipSubTierId::generate();
        $st = new TenantMembershipSubTier(
            $id, $tenantId, 'diamond', 'Diamond', 5, MembershipType::Basic,
        );
        $this->repo->save($st);

        $found = $this->repo->findBySlug($tenantId, MembershipType::Basic, 'diamond');
        self::assertNotNull($found);
        self::assertSame('Diamond', $found->name);
        self::assertSame(5, $found->rankOrder);
    }

    public function test_delete_removes_row(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-c');
        $list = $this->repo->listForTenant($tenantId);
        $first = $list[0];
        $this->repo->delete($first->id);

        $after = $this->repo->listForTenant($tenantId);
        self::assertCount(count($list) - 1, $after);
    }

    private function seedTenantAndGetId(string $slug): TenantId
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, default_locale, supported_locales)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $slug, $slug, 'en_GB', 'en_GB']);

        // Re-run sub-tier seed for this newly-added tenant (077 only ran at migration time).
        $defaults = [['bronze',1],['silver',2],['gold',3],['platinum',4]];
        foreach (['SUPPORTING','BASIC'] as $appliesTo) {
            foreach ($defaults as [$s,$r]) {
                $this->pdo()->prepare(
                    'INSERT INTO tenant_membership_subtiers (id,tenant_id,slug,name,rank_order,applies_to)
                     VALUES (?,?,?,?,?,?)'
                )->execute([Uuid7::generate()->value(), $id, $s, ucfirst($s), $r, $appliesTo]);
            }
        }

        return TenantId::fromString($id);
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```bash
vendor/bin/phpunit tests/Integration/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepositoryTest.php
```

Expected: `SqlTenantMembershipSubTierRepository` class not found.

- [ ] **Step 3: Create the SQL repository**

`src/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PDO;

final class SqlTenantMembershipSubTierRepository implements TenantMembershipSubTierRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForTenant(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, slug, name, rank_order, applies_to
               FROM tenant_membership_subtiers
              WHERE tenant_id = ?
           ORDER BY applies_to ASC, rank_order ASC'
        );
        $stmt->execute([$tenantId->value()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function findBySlug(TenantId $tenantId, MembershipType $appliesTo, string $slug): ?TenantMembershipSubTier
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, slug, name, rank_order, applies_to
               FROM tenant_membership_subtiers
              WHERE tenant_id = ? AND applies_to = ? AND slug = ?'
        );
        $stmt->execute([$tenantId->value(), $appliesTo->value, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(TenantMembershipSubTier $subTier): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_membership_subtiers
                 (id, tenant_id, slug, name, rank_order, applies_to)
             VALUES (:id, :tid, :slug, :name, :rank, :applies)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 rank_order = VALUES(rank_order)'
        );
        $stmt->execute([
            ':id'      => $subTier->id->value(),
            ':tid'     => $subTier->tenantId->value(),
            ':slug'    => $subTier->slug,
            ':name'    => $subTier->name,
            ':rank'    => $subTier->rankOrder,
            ':applies' => $subTier->appliesTo->value,
        ]);
    }

    public function delete(TenantMembershipSubTierId $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tenant_membership_subtiers WHERE id = ?');
        $stmt->execute([$id->value()]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): TenantMembershipSubTier
    {
        return new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::fromString((string) $row['id']),
            tenantId:   TenantId::fromString((string) $row['tenant_id']),
            slug:       (string) $row['slug'],
            name:       (string) $row['name'],
            rankOrder:  (int) $row['rank_order'],
            appliesTo:  MembershipType::from((string) $row['applies_to']),
        );
    }
}
```

- [ ] **Step 4: Bump Integration HWM to 77**

The `IsolationTestCase` and `MigrationTestCase` track the highest migration number. Open `tests/Isolation/IsolationTestCase.php` and bump `$this->runMigrationsUpTo(73)` to `$this->runMigrationsUpTo(77)`. Search the codebase for other `runMigrationsUpTo(73)` calls and bump those too:

```bash
grep -rn "runMigrationsUpTo(73)" tests/
```

Replace each with `runMigrationsUpTo(77)`.

- [ ] **Step 5: Run test, expect pass**

```bash
vendor/bin/phpunit tests/Integration/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepositoryTest.php
```

Expected: 3 tests pass.

- [ ] **Step 6: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/persistence): SqlTenantMembershipSubTierRepository + integration test"
```

---

## Task 11: Application — `ListMembershipSubTiers` use case

**Files:**
- Create: `src/Application/Membership/ListMembershipSubTiers/ListMembershipSubTiers.php`
- Create: `src/Application/Membership/ListMembershipSubTiers/ListMembershipSubTiersOutput.php`
- Test: `tests/Unit/Application/Membership/ListMembershipSubTiersTest.php`

- [ ] **Step 1: Write failing test**

`tests/Unit/Application/Membership/ListMembershipSubTiersTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class ListMembershipSubTiersTest extends TestCase
{
    public function test_returns_seeded_sub_tiers_for_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        ));
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'silver', 'Silver', 2, MembershipType::Supporting,
        ));

        $uc = new ListMembershipSubTiers($repo);
        $output = $uc->execute($tenantId);

        self::assertCount(2, $output->items);
        self::assertSame('bronze', $output->items[0]->slug);
        self::assertSame('silver', $output->items[1]->slug);
    }

    public function test_returns_empty_for_unseeded_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $uc = new ListMembershipSubTiers($repo);

        $output = $uc->execute(TenantId::generate());

        self::assertSame([], $output->items);
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Application/Membership/ListMembershipSubTiersTest.php
```

Expected: class not found.

- [ ] **Step 3: Create Output value object**

`src/Application/Membership/ListMembershipSubTiers/ListMembershipSubTiersOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\ListMembershipSubTiers;

use Daems\Domain\Membership\TenantMembershipSubTier;

final class ListMembershipSubTiersOutput
{
    /** @param list<TenantMembershipSubTier> $items */
    public function __construct(public readonly array $items) {}
}
```

- [ ] **Step 4: Create use case**

`src/Application/Membership/ListMembershipSubTiers/ListMembershipSubTiers.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\ListMembershipSubTiers;

use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class ListMembershipSubTiers
{
    public function __construct(
        private readonly TenantMembershipSubTierRepositoryInterface $repo,
    ) {}

    public function execute(TenantId $tenantId): ListMembershipSubTiersOutput
    {
        return new ListMembershipSubTiersOutput($this->repo->listForTenant($tenantId));
    }
}
```

- [ ] **Step 5: Run, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Application/Membership/ListMembershipSubTiersTest.php
```

Expected: 2 tests pass.

- [ ] **Step 6: PHPStan + commit**

```bash
composer analyse 2>&1 | tail -3
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/membership): ListMembershipSubTiers use case"
```

---

## Task 12: DI wiring — `bootstrap/app.php` + `KernelHarness`

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Wire bootstrap/app.php**

Open `bootstrap/app.php` and find the dashboard wiring block (the `WidgetRegistry` singleton — search for `\Daems\Domain\Dashboard\WidgetRegistry::class`). Add **before** that block:

```php
// Membership Core v2 / 0.6a — tier system + sub-tier honor catalog.
$container->bind(
    \Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
$container->bind(
    \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers::class,
    static fn(Container $c) => new \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers(
        $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
    ),
);
```

- [ ] **Step 2: Wire KernelHarness.php**

Open `tests/Support/KernelHarness.php`. Find the dashboard wiring (`WidgetRegistry::class` singleton). Add **before** that block:

```php
        $container->singleton(
            \Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class,
            fn() => new \Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository(),
        );
        $container->bind(
            \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers::class,
            static fn(Container $c) => new \Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers(
                $c->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class),
            ),
        );
```

- [ ] **Step 3: Seed sub-tiers in KernelHarness for the test tenant**

After the binding block above, inside the `KernelHarness::__construct()` body, seed the test tenant with the four default sub-tier rows so E2E tests have data to query. Add right after the binding (still inside `__construct`):

```php
        $subTierRepo = $container->make(\Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface::class);
        $defaults = [['bronze','Bronze',1],['silver','Silver',2],['gold','Gold',3],['platinum','Platinum',4]];
        foreach ([\Daems\Domain\Membership\MembershipType::Supporting, \Daems\Domain\Membership\MembershipType::Basic] as $appliesTo) {
            foreach ($defaults as [$slug, $name, $rank]) {
                $subTierRepo->save(new \Daems\Domain\Membership\TenantMembershipSubTier(
                    \Daems\Domain\Membership\TenantMembershipSubTierId::generate(),
                    $this->testTenantId,
                    $slug, $name, $rank, $appliesTo,
                ));
            }
        }
```

- [ ] **Step 4: Verify**

```bash
composer analyse 2>&1 | tail -3
composer test:e2e 2>&1 | tail -5
```

Expected: PHPStan 0 errors, E2E all green (no new tests yet but existing must still pass).

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(bootstrap+harness/membership): wire TenantMembershipSubTier repository + use case"
```

---

## Task 13: Infrastructure — `MembershipSubTiersController` + route

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/MembershipSubTiersController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php` (DI binding for controller)
- Modify: `tests/Support/KernelHarness.php` (same)

- [ ] **Step 1: Create the controller**

`src/Infrastructure/Adapter/Api/Controller/Backstage/MembershipSubTiersController.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage;

use Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class MembershipSubTiersController
{
    public function __construct(
        private readonly ListMembershipSubTiers $listSubTiers,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isPlatformAdmin() && $actor->roleInActiveTenant?->value !== 'admin') {
            throw new ForbiddenException('admin_or_gsa_required');
        }

        $output = $this->listSubTiers->execute($actor->activeTenant);

        $items = array_map(
            static fn($st) => [
                'id'         => $st->id->value(),
                'slug'       => $st->slug,
                'name'       => $st->name,
                'rank_order' => $st->rankOrder,
                'applies_to' => $st->appliesTo->value,
            ],
            $output->items,
        );

        return Response::json(['data' => $items]);
    }
}
```

- [ ] **Step 2: Add the route**

Open `routes/api.php`. Find another `/api/v1/backstage/tenant-settings/...` route (search for `tenant-settings`) and add nearby:

```php
$router->get('/api/v1/backstage/tenant-settings/membership-subtiers', static function (Request $req) use ($container): Response {
    return $container->make(
        \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController::class
    )->index($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

Add this import at the top of the file if not already present:

```php
use Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController;
```

- [ ] **Step 3: Bind controller in bootstrap/app.php**

Near the Membership wiring from Task 12, add:

```php
$container->bind(
    \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController(
        $c->make(\Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers::class),
    ),
);
```

- [ ] **Step 4: Same binding in KernelHarness**

Add the same `bind()` call to `tests/Support/KernelHarness.php`.

- [ ] **Step 5: Smoke test the live route**

```bash
curl -sS -i -o /dev/null -w "%{http_code}\n" http://daems-platform.local/api/v1/backstage/tenant-settings/membership-subtiers
```

Expected: 401 (no auth — middleware rejects).

- [ ] **Step 6: PHPStan + commit**

```bash
composer analyse 2>&1 | tail -3
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api/backstage): MembershipSubTiersController GET endpoint"
```

---

## Task 14: E2E test — sub-tier endpoint

**Files:**
- Create: `tests/E2E/MembershipSubTiersEndpointE2ETest.php`

- [ ] **Step 1: Write the test**

`tests/E2E/MembershipSubTiersEndpointE2ETest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\KernelHarness;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class MembershipSubTiersEndpointE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(new FrozenClock(new \DateTimeImmutable('2026-05-11')));
    }

    public function test_admin_can_list_sub_tiers(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant-settings/membership-subtiers', $token);

        self::assertSame(200, $resp->status());
        $body = $resp->json();
        self::assertArrayHasKey('data', $body);
        self::assertCount(8, $body['data']); // 4 slugs × 2 appliesTo (SUPPORTING + BASIC)
    }

    public function test_member_cannot_list_sub_tiers(): void
    {
        $member = $this->h->seedUser('member@x.com', 'pass1234'); // no admin role
        $token = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant-settings/membership-subtiers', $token);

        self::assertSame(403, $resp->status());
    }

    public function test_unauthenticated_blocked(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/tenant-settings/membership-subtiers', '');
        self::assertSame(401, $resp->status());
    }
}
```

- [ ] **Step 2: Run E2E**

```bash
composer test:e2e 2>&1 | tail -10
```

Expected: 3 new tests pass, total suite still green.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/e2e): MembershipSubTiersEndpointE2ETest — 3 cases"
```

---

## Task 15: Isolation test — cross-tenant sub-tier isolation

**Files:**
- Create: `tests/Isolation/TenantMembershipSubTierIsolationTest.php`

- [ ] **Step 1: Write the test**

`tests/Isolation/TenantMembershipSubTierIsolationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository;

final class TenantMembershipSubTierIsolationTest extends IsolationTestCase
{
    private SqlTenantMembershipSubTierRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlTenantMembershipSubTierRepository($this->pdo());
    }

    public function test_subtier_added_to_tenant_a_invisible_to_tenant_b(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        // Add a unique honor only to tenant A.
        $this->repo->save(new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   $tenantA,
            slug:       'diamond-A',
            name:       'Diamond (A only)',
            rankOrder:  5,
            appliesTo:  MembershipType::Basic,
        ));

        $aListSlugs = array_map(fn($s) => $s->slug, $this->repo->listForTenant($tenantA));
        $bListSlugs = array_map(fn($s) => $s->slug, $this->repo->listForTenant($tenantB));

        self::assertContains('diamond-A', $aListSlugs);
        self::assertNotContains('diamond-A', $bListSlugs, 'tenant-A sub-tier must not leak to tenant-B');
    }

    public function test_find_by_slug_isolates_by_tenant(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $this->repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantA, 'only-A', 'Only A', 9, MembershipType::Supporting,
        ));

        self::assertNotNull($this->repo->findBySlug($tenantA, MembershipType::Supporting, 'only-A'));
        self::assertNull($this->repo->findBySlug($tenantB, MembershipType::Supporting, 'only-A'));
    }
}
```

- [ ] **Step 2: Run**

```bash
vendor/bin/phpunit tests/Isolation/TenantMembershipSubTierIsolationTest.php
```

Expected: 2 tests pass.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/isolation): cross-tenant sub-tier isolation"
```

---

## Task 16: Module-side update — `MemberActivationService` + `SupporterActivationService`

**Files (in `c:/laragon/www/modules/members/`):**
- Modify: `backend/src/Application/Backstage/ActivateMember/MemberActivationService.php`
- Modify: `backend/src/Application/Backstage/ActivateSupporter/SupporterActivationService.php`
- Test: `backend/tests/Unit/Application/Backstage/ActivateMember/MemberActivationServiceTest.php` (if exists; otherwise create)

- [ ] **Step 1: Update `MemberActivationService`**

Open `c:/laragon/www/modules/members/backend/src/Application/Backstage/ActivateMember/MemberActivationService.php` and find the hardcoded:

```php
'membership_type'   => 'individual',
```

Replace with:

```php
'membership_type'   => \Daems\Domain\Membership\MembershipType::Basic->value,
```

Add this `use` line at the top of the file if not already present:

```php
use Daems\Domain\Membership\MembershipType;
```

(Then the inline reference can be the short form `MembershipType::Basic->value`.)

- [ ] **Step 2: Update `SupporterActivationService`**

Open `c:/laragon/www/modules/members/backend/src/Application/Backstage/ActivateSupporter/SupporterActivationService.php` and replace:

```php
'membership_type'   => 'supporter',
```

with:

```php
'membership_type'   => \Daems\Domain\Membership\MembershipType::Supporting->value,
```

Add the corresponding `use` line.

- [ ] **Step 3: Find existing tests and update assertions**

```bash
grep -rn "'individual'\|'supporter'" modules/members/backend/tests/
```

For any test asserting the old literal `'individual'` or `'supporter'`, replace the expected value with `MembershipType::Basic->value` or `MembershipType::Supporting->value` respectively (importing the enum at the top of each test file).

- [ ] **Step 4: Run module tests**

```bash
cd c:/laragon/www/daems-platform
vendor/bin/phpunit ../modules/members/backend/tests/Unit 2>&1 | tail -8
```

Expected: all green.

- [ ] **Step 5: Commit in members module repo**

```bash
cd c:/laragon/www/modules/members
git add backend/src/Application/Backstage/ActivateMember backend/src/Application/Backstage/ActivateSupporter backend/tests/Unit
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(members): activation services emit canonical MembershipType values"
```

---

## Task 17: Module-side update — `MemberRecord` carries sub-tier slug

**Files (in `c:/laragon/www/modules/members/`):**
- Modify: `backend/src/Infrastructure/SqlMemberDirectoryRepository.php`
- Modify: `backend/src/Infrastructure/SqlPublicMemberRepository.php`
- Modify: corresponding `MemberRecord` / `MemberDirectoryRow` value objects (search for them)

- [ ] **Step 1: Locate the row VO**

```bash
grep -rn "membershipType\|membership_type" modules/members/backend/src/Domain/ modules/members/backend/src/Application/
```

Find the read-model VO returned by `SqlMemberDirectoryRepository::list()` and `SqlPublicMemberRepository::findByMemberNumber()`. Typical name: `MemberRecord` or `MemberDirectoryRow` or `PublicMemberView`.

- [ ] **Step 2: Add `subTierSlug` field**

In the VO, add a new constructor parameter and getter:

```php
public function __construct(
    // ... existing fields ...
    public readonly ?string $subTierSlug,
) {}
```

- [ ] **Step 3: Update SQL select + hydration**

In `SqlMemberDirectoryRepository::list()` (around the existing `SELECT u.id, u.name, ...`):

- Add `u.membership_subtier` to the SELECT list.
- Pass `$row['membership_subtier']` (cast to `?string`) to the VO constructor.

Do the same in `SqlPublicMemberRepository::findByMemberNumber()`.

- [ ] **Step 4: Update existing tests**

Find module tests that construct the VO directly. Add `subTierSlug: null` to those test fixtures. PHPStan will fail until all call sites are updated.

```bash
cd c:/laragon/www/daems-platform
composer analyse 2>&1 | tail -3
```

Iterate until 0 errors.

- [ ] **Step 5: Render Tier column in the members backstage page**

Find the members listing template:

```bash
ls c:/laragon/www/modules/members/frontend/backstage/members/
```

Typical location: `members/index.php` or `members/_table.php`. Search for the existing `<th>` headers and `<td>` cells in the table body (grep for `membership_status` or `member_number` in the file to locate the row template).

Add a new `<th>` and corresponding `<td>` showing the tier:

```php
<th><?= I18n::e('backstage.members.col.tier') ?></th>
```

In the row cell:

```php
<td>
    <?= htmlspecialchars(I18n::t('membership.type.' . strtolower($row->membershipType->value)), ENT_QUOTES, 'UTF-8') ?>
    <?php if ($row->subTierSlug !== null && $row->subTierSlug !== ''): ?>
        <span class="badge badge--honor"><?= htmlspecialchars(I18n::t('membership.subtier.' . $row->subTierSlug, fallback: $row->subTierSlug), ENT_QUOTES, 'UTF-8') ?></span>
    <?php endif; ?>
</td>
```

Add the `backstage.members.col.tier` key to all three lang files in Task 21 (already covered there — see "Tier" string).

- [ ] **Step 6: Run module tests**

```bash
vendor/bin/phpunit ../modules/members/backend/tests 2>&1 | tail -5
```

Expected: all green.

- [ ] **Step 7: Commit in members module repo**

```bash
cd c:/laragon/www/modules/members
git add backend/src backend/tests frontend/backstage/members
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(members): MemberRecord + listing table now carry sub-tier slug"
```

---

## Task 18: AdminStats — `getMembersByTier` method

**Files:**
- Modify: `src/Domain/Admin/AdminStatsRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminRepository.php`
- Modify: `tests/Unit/Infrastructure/Dashboard/CoreWidgets/CoreWidgetsTest.php` (inline fake update)
- Modify: `tests/Support/KernelHarness.php` (inline fake update)

- [ ] **Step 1: Add the interface method**

Open `src/Domain/Admin/AdminStatsRepositoryInterface.php` and add at the end of the interface body:

```php
    /** @return array{supporting:int, basic:int, full:int, honorary:int} */
    public function getMembersByTier(TenantId $tenantId): array;
```

- [ ] **Step 2: Implement in SqlAdminRepository**

Open `src/Infrastructure/Adapter/Persistence/Sql/SqlAdminRepository.php` and append the method:

```php
    /** @return array{supporting:int, basic:int, full:int, honorary:int} */
    public function getMembersByTier(TenantId $tenantId): array
    {
        $stmt = $this->db->queryAll(
            "SELECT u.membership_type AS t, COUNT(DISTINCT u.id) AS n
               FROM users u
               JOIN user_tenants ut ON ut.user_id = u.id
              WHERE ut.tenant_id = ?
                AND ut.left_at IS NULL
                AND u.membership_status = 'active'
           GROUP BY u.membership_type",
            [$tenantId->value()],
        );

        $result = ['supporting' => 0, 'basic' => 0, 'full' => 0, 'honorary' => 0];
        foreach ($stmt as $row) {
            $t = is_array($row) && is_string($row['t'] ?? null) ? strtolower($row['t']) : '';
            $n = is_array($row) && is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
            if (array_key_exists($t, $result)) {
                $result[$t] = $n;
            }
        }
        return $result;
    }
```

(If `Connection` does not expose `queryAll`, use the same `query` helper that the rest of the file uses — copy-paste a similar block from earlier in the file and adapt.)

- [ ] **Step 3: Update inline fake in CoreWidgetsTest**

Open `tests/Unit/Infrastructure/Dashboard/CoreWidgets/CoreWidgetsTest.php`. Find the inline anonymous class implementing `AdminStatsRepositoryInterface` (search for `getStatsForTenant`). Add the new method:

```php
            public function getMembersByTier(TenantId $tenantId): array
            {
                return ['supporting' => 4, 'basic' => 12, 'full' => 3, 'honorary' => 0];
            }
```

Do the same for the inline fake inside `tests/Support/KernelHarness.php` (the one bound to `AdminStatsRepositoryInterface::class`).

- [ ] **Step 4: Run tests + PHPStan**

```bash
composer analyse 2>&1 | tail -3
composer test:e2e 2>&1 | tail -5
vendor/bin/phpunit tests/Unit/Infrastructure/Dashboard 2>&1 | tail -5
```

Expected: 0 errors / all green.

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(admin/stats): getMembersByTier — group active members by canonical MembershipType"
```

---

## Task 19: Dashboard widget — `MembersByTierKpiWidget`

**Files:**
- Create: `src/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidget.php`
- Test: `tests/Unit/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidgetTest.php`

- [ ] **Step 1: Write failing test**

`tests/Unit/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidgetTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Admin\AdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Dashboard\CoreWidgets\MembersByTierKpiWidget;
use PHPUnit\Framework\TestCase;

final class MembersByTierKpiWidgetTest extends TestCase
{
    public function test_metadata(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        self::assertSame('members.members_by_tier_kpi', $w->id());
        self::assertSame(WidgetCategory::Numbers, $w->category());
        self::assertSame(2, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_data_returns_four_tier_counts(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        $d = $w->data(TenantId::generate());

        self::assertSame(4, $d['supporting']);
        self::assertSame(12, $d['basic']);
        self::assertSame(3, $d['full']);
        self::assertSame(0, $d['honorary']);
    }

    public function test_render_contains_all_four_tier_labels_in_html(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        $html = $w->render(TenantId::generate(), $this->fakeUser());
        self::assertNotSame('', $html);
        self::assertStringContainsString('4', $html);
        self::assertStringContainsString('12', $html);
        self::assertStringContainsString('3', $html);
    }

    private function fakeRepo(): AdminStatsRepositoryInterface
    {
        return new class implements AdminStatsRepositoryInterface {
            public function getStatsForTenant(TenantId $tenantId): AdminStats {
                throw new \RuntimeException('not used');
            }
            public function getMemberGrowthForTenant(string $period, TenantId $tenantId): array {
                return ['labels' => [], 'series' => []];
            }
            public function getMembersByTier(TenantId $tenantId): array {
                return ['supporting' => 4, 'basic' => 12, 'full' => 3, 'honorary' => 0];
            }
        };
    }

    private function fakeUser(): \Daems\Domain\User\User
    {
        $class = new \ReflectionClass(\Daems\Domain\User\User::class);
        return $class->newInstanceWithoutConstructor();
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidgetTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the widget**

`src/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidget.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Frontend\I18n;

final class MembersByTierKpiWidget extends Widget
{
    public function __construct(
        private readonly AdminStatsRepositoryInterface $repo,
    ) {}

    public function id(): string             { return 'members.members_by_tier_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(2); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.members_by_tier_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.members_by_tier_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId);
        $title = htmlspecialchars(I18n::t($this->labelKey()), ENT_QUOTES, 'UTF-8');

        $rows = '';
        $labels = [
            'supporting' => I18n::t('membership.type.supporting'),
            'basic'      => I18n::t('membership.type.basic'),
            'full'       => I18n::t('membership.type.full'),
            'honorary'   => I18n::t('membership.type.honorary'),
        ];
        foreach ($labels as $key => $label) {
            $count = (int) ($d[$key] ?? 0);
            $rows .= '<div class="tier-row">'
                . '<span class="tier-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span class="tier-count">' . $count . '</span>'
                . '</div>';
        }

        return '<div class="card"><div class="card__body">'
            . '<p class="card__title">' . $title . '</p>'
            . '<div class="tier-grid">' . $rows . '</div>'
            . '</div></div>';
    }

    public function data(TenantId $tenantId): array
    {
        return $this->repo->getMembersByTier($tenantId);
    }
}
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Dashboard/CoreWidgets/MembersByTierKpiWidgetTest.php
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/dashboard): MembersByTierKpiWidget — 4 tier counts grid"
```

---

## Task 20: Widget DI wiring + dashboard exposure

**Files:**
- Modify: `bootstrap/app.php` (widget registration)
- Modify: `tests/Support/KernelHarness.php` (same)
- Modify: `src/Frontend/Dashboard/DefaultLayouts.php` (add widget to admin defaults)

- [ ] **Step 1: Register widget in bootstrap**

Open `bootstrap/app.php`. Find the existing widget-registration block (search for `MembersKpiWidget`). Add the new widget registration right after `MembersKpiWidget`:

```php
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\MembersByTierKpiWidget(
    $container->make(\Daems\Domain\Admin\AdminStatsRepositoryInterface::class),
));
```

- [ ] **Step 2: Register in KernelHarness**

Open `tests/Support/KernelHarness.php`. Add the same registration after the `MembersKpiWidget` line.

- [ ] **Step 3: Add to admin default layout**

Open `src/Frontend/Dashboard/DefaultLayouts.php`. In `admin()` method, add the new entry **after** `core.applications_kpi`:

```php
new LayoutEntry('members.members_by_tier_kpi', WidgetSpan::of(2)),
```

Also add it to `gsa()` method (since GSA sees both platform AND tenant-tier KPIs — same insertion point after `applications_kpi`):

```php
new LayoutEntry('members.members_by_tier_kpi', WidgetSpan::of(2)),
```

- [ ] **Step 4: Verify**

```bash
composer analyse 2>&1 | tail -3
composer test:e2e 2>&1 | tail -5
```

Expected: 0 errors / E2E green.

- [ ] **Step 5: Browser smoke (admin or GSA logged in)**

```bash
# Already-stored cookie from earlier session
curl -sS -b /tmp/c.txt -o /tmp/bs-tier.html -w "STATUS:%{http_code}\n" http://daems.local/backstage
grep -c "members_by_tier_kpi" /tmp/bs-tier.html
```

Expected: 200 status; widget id appears once in DOM.

- [ ] **Step 6: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(dashboard): wire MembersByTierKpiWidget into admin+GSA default layouts"
```

---

## Task 21: i18n keys

**Files:**
- Modify: `lang/en_GB.php`
- Modify: `lang/fi_FI.php`
- Modify: `lang/sw_TZ.php`

- [ ] **Step 1: Add keys to all three locales**

In each lang file, locate the existing `backstage.dashboard.widget.*` section. Add these blocks (per-locale strings shown below; insert each language into the corresponding file):

**en_GB.php**:

```php
    'membership.type.supporting'         => 'Supporting member',
    'membership.type.basic'              => 'Basic member',
    'membership.type.full'               => 'Full member',
    'membership.type.honorary'           => 'Honorary member',

    'membership.subtier.bronze'          => 'Bronze',
    'membership.subtier.silver'          => 'Silver',
    'membership.subtier.gold'            => 'Gold',
    'membership.subtier.platinum'        => 'Platinum',

    'backstage.dashboard.widget.members_by_tier_kpi.label'       => 'Members by tier',
    'backstage.dashboard.widget.members_by_tier_kpi.description' => 'Member count grouped by the four bylaws-defined membership groups.',

    'backstage.members.col.tier'         => 'Tier',
```

**fi_FI.php**:

```php
    'membership.type.supporting'         => 'Kannattava jäsen',
    'membership.type.basic'              => 'Perusjäsen',
    'membership.type.full'               => 'Varsinainen jäsen',
    'membership.type.honorary'           => 'Kunniajäsen',

    'membership.subtier.bronze'          => 'Pronssi',
    'membership.subtier.silver'          => 'Hopea',
    'membership.subtier.gold'            => 'Kulta',
    'membership.subtier.platinum'        => 'Platina',

    'backstage.dashboard.widget.members_by_tier_kpi.label'       => 'Jäsenet jäsenryhmittäin',
    'backstage.dashboard.widget.members_by_tier_kpi.description' => 'Jäsenten määrä jaettuna sääntöjen mukaisiin neljään jäsenryhmään.',

    'backstage.members.col.tier'         => 'Jäsenryhmä',
```

**sw_TZ.php**:

```php
    'membership.type.supporting'         => 'Mwanachama wa kuunga mkono',
    'membership.type.basic'              => 'Mwanachama wa kawaida',
    'membership.type.full'               => 'Mwanachama kamili',
    'membership.type.honorary'           => 'Mwanachama wa heshima',

    'membership.subtier.bronze'          => 'Shaba',
    'membership.subtier.silver'          => 'Fedha',
    'membership.subtier.gold'            => 'Dhahabu',
    'membership.subtier.platinum'        => 'Platinamu',

    'backstage.dashboard.widget.members_by_tier_kpi.label'       => 'Wanachama kwa kiwango',
    'backstage.dashboard.widget.members_by_tier_kpi.description' => 'Idadi ya wanachama imegawanywa katika makundi manne yaliyofafanuliwa katika katiba.',

    'backstage.members.col.tier'         => 'Kiwango',
```

- [ ] **Step 2: Lint all three**

```bash
php -l lang/en_GB.php && php -l lang/fi_FI.php && php -l lang/sw_TZ.php
```

Expected: "No syntax errors detected" × 3.

- [ ] **Step 3: Smoke test (browser)**

Visit `http://daems.local/backstage` while logged in (cookie already stored). Verify the new "Jäsenet jäsenryhmittäin" widget shows four tier labels in Finnish.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(i18n/membership): tier + sub-tier + widget keys (fi_FI/en_GB/sw_TZ)"
```

---

## Task 22: Final verification

- [ ] **Step 1: PHPStan**

```bash
composer analyse 2>&1 | tail -3
```

Expected: 0 errors.

- [ ] **Step 2: Full Unit suite**

```bash
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
```

Expected: all green.

- [ ] **Step 3: Full E2E suite**

```bash
composer test:e2e 2>&1 | tail -5
```

Expected: all green (new MembershipSubTiersEndpointE2ETest cases included).

- [ ] **Step 4: Integration — sub-tier repository**

```bash
vendor/bin/phpunit tests/Integration/Infrastructure/Adapter/Persistence/Sql/SqlTenantMembershipSubTierRepositoryTest.php 2>&1 | tail -5
```

Expected: 3 tests pass.

- [ ] **Step 5: Isolation — sub-tier**

```bash
vendor/bin/phpunit tests/Isolation/TenantMembershipSubTierIsolationTest.php 2>&1 | tail -5
```

Expected: 2 tests pass.

- [ ] **Step 6: Browser smoke for three roles**

For each role, log in to `daems.local/backstage` and verify:
- **Admin**: Members KPI shows active-only count; `MembersByTierKpiWidget` shows 4 tier numbers; Members list has a "Tier" column populated with new enum values.
- **Moderator**: same KPI is visible (admin minRole — moderator sees it via catalog if they add it; not in default).
- **GSA (`playwright-admin@dev.local` / `Playwright-Dev-Test-2026!`)**: Same as admin plus platform widgets — the tier KPI must appear at the top of the GSA layout.

- [ ] **Step 7: Verify daems-tenant data matches**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "
SELECT membership_type, COUNT(*) AS n
  FROM users u JOIN user_tenants ut ON ut.user_id = u.id
 WHERE ut.tenant_id = (SELECT id FROM tenants WHERE slug='daems')
   AND ut.left_at IS NULL
   AND u.membership_status = 'active'
 GROUP BY membership_type;"
```

Compare numbers to the widget's `tier-count` values rendered in the page. They must match.

- [ ] **Step 8: Final commit (if anything changed during verification)**

If verification surfaced a small fix (e.g. a stray PHPStan warning), commit it as a single follow-up. Otherwise no commit needed.

- [ ] **Step 9: Report completion**

Print a one-paragraph summary of what shipped, total commit count on `membership-core-v2-tier` branch, and the next steps (push when user requests + open 0.6b brainstorm).

---

## Out-of-scope reminder (do NOT implement in 0.6a)

The following items are explicitly **deferred** to later milestones. Do not be tempted to add them here:

- **Sub-tier award/revoke workflow** — needs Board entity + board-majority decision. → 0.6b.
- **Sub-tier CRUD UI in backstage settings** — needs the above workflow first. → 0.6b.
- **12-month BASIC → FULL eligibility check + invitation** — needs Board majority decision. → 0.6b.
- **Resignation, expulsion, appeal, HONORARY invitation, auto-resignation at 2 years unpaid** — lifecycle workflows. → 0.6c.
- **Per-tier fees + annual_fee_cents column** — pricing belongs to MembershipBilling. → 0.7.

Add reminders to the milestone's git log / spec rather than inline TODO comments.
