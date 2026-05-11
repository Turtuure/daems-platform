# Membership Core v2 — Tier System (0.6a) — Design

**Date:** 2026-05-11
**Branch target:** `membership-core-v2-tier` (new, off `dev`)
**Status:** Design — pending review
**Milestone:** 0.6a (first of three sub-milestones — 0.6b governance, 0.6c lifecycle follow)

## Problem

The platform currently models membership with a single `users.membership_type` VARCHAR column whose values evolved ad hoc: `basic`, `full`, `individual`, `supporter`. Distribution today (daems tenant):

| Legacy value | Count | Comment                                |
| ------------ | ----- | -------------------------------------- |
| `basic`      | 5     | Newer joiners (membership_started_at NULL or recent) |
| `full`       | 3     | Long-standing members (141 mo / ~12 y) |
| `individual` | 7     | All test users (`@example.fi` / `@test.local`), `membership_started_at` NULL |
| `supporter`  | 4     | Donors who joined as supporters        |

This shape does not match Daem Society ry's bylaws (hyväksytty 18.4.2026 § 3) which require four membership groups with distinct rights:

1. **Kannattavat jäsenet (SUPPORTING)** — pay support fee, no voting rights
2. **Perusjäsenet (BASIC)** — board accepts unanimously, no voting rights in administrative matters
3. **Varsinaiset jäsenet (FULL)** — invited by board's unanimous decision, full voting rights + board eligibility
4. **Kunniajäsenet (HONORARY)** — invited by general meeting on board's motion, speaking + attendance rights but no voting (unless also FULL), no fees

A FULL member must have been a member ≥ 12 months and shown commitment (§ 3 paragraph 6).

In addition, the user wants a **sub-tier honor system** layered on top: SUPPORTING and BASIC members may receive a sub-tier honor (e.g. `bronze`, `silver`, `gold`, `platinum`) **awarded by the board** as recognition. Sub-tiers are NOT pricing tiers — they are awards, like medals. FULL and HONORARY have no sub-tiers.

## Goal

Establish the **four-tier model** plus **per-tenant configurable sub-tier honor system** as infrastructure. This milestone (0.6a) covers:

- The `MembershipType` enum (Domain) and its rights-encoding methods.
- The `tenant_membership_subtiers` table and `TenantMembershipSubTier` Domain object — tenant-configurable honor levels.
- Database migration mapping legacy `membership_type` values to the new four values.
- Read-only API to list configured sub-tiers per tenant.
- Members listing + KPI surfaces showing tier (and sub-tier badge when present).

## Non-goals (deferred)

- **0.6b governance**: Board entity, sub-tier *award* and *revoke* workflows (board majority decision), `member_sub_tier_awards` audit table, 12-month BASIC → FULL eligibility check + invitation workflow.
- **0.6c lifecycle**: resignation, expulsion + appeal, HONORARY invitation (requires general-meeting decision), 2-year unpaid-fee auto-resignation.
- **0.7 Billing**: membership fees and supporter fees per tier — sub-tier carries NO price information.
- Sub-tier CRUD UI in backstage — deferred to 0.6b (governance UI block).

## Architecture

### Layered components (Clean Architecture)

```
Domain/Membership/                       (existing)
  MembershipType.php                       NEW — enum (4 values + rights methods)
  MembershipSubTierSlug.php                NEW — VO (slug + tenant-id binding)
  TenantMembershipSubTier.php              NEW — entity (id, tenant, slug, name, rank, appliesTo)
  TenantMembershipSubTierId.php            NEW — Uuid7Id subclass
  TenantMembershipSubTierRepositoryInterface.php   NEW — port

Application/Membership/
  ListMembershipSubTiers.php               NEW — use case (read-only)

Infrastructure/Adapter/Persistence/Sql/
  SqlTenantMembershipSubTierRepository.php NEW — PDO impl

Infrastructure/Adapter/Api/Controller/
  MembershipSubTiersController.php         NEW — GET /api/v1/backstage/tenant-settings/membership-subtiers
```

Module-side updates (`modules/members/backend/`):

- `Application/Backstage/ActivateMember/MemberActivationService.php` — hardcoded `'individual'` → `MembershipType::Basic->value`.
- `Application/Backstage/ActivateSupporter/SupporterActivationService.php` — `'supporter'` → `MembershipType::Supporting->value`.
- `Infrastructure/SqlMemberDirectoryRepository.php` — Output `MemberRecord` carries `membershipType: MembershipType` (typed) + `subTierSlug: ?string`.
- `Infrastructure/SqlPublicMemberRepository.php` — same typing in public profile.

### Domain contracts

**`MembershipType` enum** (`src/Domain/Membership/MembershipType.php`):

```php
enum MembershipType: string {
    case Supporting = 'SUPPORTING';
    case Basic      = 'BASIC';
    case Full       = 'FULL';
    case Honorary   = 'HONORARY';

    public function hasVotingRights(): bool       { return $this === self::Full; }
    public function isEligibleForBoard(): bool    { return $this === self::Full; }
    public function paysMembershipFee(): bool     { return $this === self::Basic || $this === self::Full; }
    public function paysSupporterFee(): bool      { return $this === self::Supporting; }
    public function allowsSubTier(): bool         { return $this === self::Supporting || $this === self::Basic; }

    public static function fromLegacyString(string $legacy): self {
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

The `fromLegacyString()` method is the seam used by the 075 backfill migration and by `SqlMemberDirectoryRepository` when reading rows whose `membership_type` column has not yet been normalised (defensive read during the deployment window). Once migration 075 has run cleanly in production, only the four canonical values exist in storage and the legacy keys in the match arm become unreachable — they stay as a guard against rollback or partial-deploy weirdness.

**`TenantMembershipSubTier` entity** (`src/Domain/Membership/TenantMembershipSubTier.php`):

```php
final class TenantMembershipSubTier {
    public function __construct(
        public readonly TenantMembershipSubTierId $id,
        public readonly TenantId $tenantId,
        public readonly string $slug,        // 'bronze', 'silver', 'gold', 'platinum', etc.
        public readonly string $name,        // displayed in UI (i18n applied separately)
        public readonly int $rankOrder,      // 1 = lowest honor, 4 = highest (or however many tiers)
        public readonly MembershipType $appliesTo,  // SUPPORTING or BASIC only
    ) {
        if (!$appliesTo->allowsSubTier()) {
            throw new \InvalidArgumentException(
                'Sub-tier only allowed on SUPPORTING or BASIC, got: ' . $appliesTo->value
            );
        }
    }
}
```

**Repository port** (`TenantMembershipSubTierRepositoryInterface`):

```php
interface TenantMembershipSubTierRepositoryInterface {
    /** @return list<TenantMembershipSubTier> */
    public function listForTenant(TenantId $tenantId): array;

    public function findBySlug(TenantId $tenantId, MembershipType $appliesTo, string $slug): ?TenantMembershipSubTier;

    public function save(TenantMembershipSubTier $subTier): void;

    public function delete(TenantMembershipSubTierId $id): void;
}
```

### Database schema

**Migration 074 — `074_membership_type_v2.sql`**:

```sql
-- 074_membership_type_v2.sql
-- Step 1: ensure legacy column exists for audit (was added in earlier migration; idempotent here).
-- Step 2: add membership_subtier column (NULL until board awards a sub-tier — 0.6b).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS membership_subtier VARCHAR(30) NULL AFTER membership_type;
```

**Migration 075 — `075_backfill_membership_type.php`** (PHP because conditional `UPDATE`s benefit from explicit transactional control):

```php
<?php
// 075_backfill_membership_type.php
$pdo->beginTransaction();
try {
    // Save the old value into membership_type_legacy for auditability.
    $pdo->exec("
        UPDATE users
           SET membership_type_legacy = membership_type
         WHERE membership_type_legacy IS NULL
    ");

    // Normalise to the four canonical values.
    $pdo->exec("UPDATE users SET membership_type = 'SUPPORTING' WHERE membership_type = 'supporter'");
    $pdo->exec("UPDATE users SET membership_type = 'BASIC'      WHERE membership_type IN ('basic', 'individual')");
    $pdo->exec("UPDATE users SET membership_type = 'FULL'       WHERE membership_type = 'full'");
    // 'honorary' is not currently used but normalised proactively in case any data drifts in.
    $pdo->exec("UPDATE users SET membership_type = 'HONORARY'   WHERE membership_type = 'honorary'");

    // Fill membership_started_at where it's NULL (notably the 7 'individual' test users).
    // We use users.created_at as the start date — for genuine members this is the join
    // date; for test fixtures it's the seed time, which is acceptable for the 12-month
    // eligibility rule that runs in 0.6b.
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

**Migration 076 — `076_tenant_membership_subtiers.sql`** (table + seed for existing tenants):

```sql
CREATE TABLE tenant_membership_subtiers (
    id          CHAR(36)    NOT NULL,
    tenant_id   CHAR(36)    NOT NULL,
    slug        VARCHAR(30) NOT NULL,
    name        VARCHAR(80) NOT NULL,
    rank_order  INT         NOT NULL,
    applies_to  VARCHAR(20) NOT NULL,    -- SUPPORTING or BASIC
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_applies_slug (tenant_id, applies_to, slug),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_subtier_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 077 — `077_seed_default_subtiers.php`** — seed `bronze / silver / gold / platinum` for each existing tenant × each `appliesTo`:

```php
<?php
// 077_seed_default_subtiers.php
$defaults = [
    ['bronze',   'Bronze',   1],
    ['silver',   'Silver',   2],
    ['gold',     'Gold',     3],
    ['platinum', 'Platinum', 4],
];
$appliesTo = ['SUPPORTING', 'BASIC'];

$tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(\PDO::FETCH_COLUMN);

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_membership_subtiers
        (id, tenant_id, slug, name, rank_order, applies_to)
     VALUES (?, ?, ?, ?, ?, ?)'
);

foreach ($tenants as $tenantId) {
    foreach ($appliesTo as $type) {
        foreach ($defaults as [$slug, $name, $rank]) {
            $insert->execute([
                \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
                $tenantId, $slug, $name, $rank, $type,
            ]);
        }
    }
}
```

The seed uses `INSERT IGNORE` so it stays idempotent — re-running it does not error on duplicate `(tenant_id, applies_to, slug)`.

### Resolution order

The platform always reads `users.membership_type` as the canonical four-value enum after migration 075. The `membership_type_legacy` column is kept for audit but never consulted by application code.

The `users.membership_subtier` column stays NULL in 0.6a. Award logic comes in 0.6b — when the board grants `gold` to a member, that's an `UPDATE users SET membership_subtier = 'gold' WHERE id = ?` plus an audit row in `member_sub_tier_awards` (0.6b table).

## API contract

One read-only endpoint added in 0.6a:

```
GET /api/v1/backstage/tenant-settings/membership-subtiers
    Response:
    {
      "data": [
        { "id": "...", "slug": "bronze",   "name": "Bronze",   "rank_order": 1, "applies_to": "SUPPORTING" },
        { "id": "...", "slug": "silver",   "name": "Silver",   "rank_order": 2, "applies_to": "SUPPORTING" },
        ...
      ]
    }
```

Auth: GSA OR admin on the active tenant (same as existing tenant-settings routes).

CRUD endpoints (POST/PUT/DELETE) are out-of-scope here — defer to 0.6b when board-decision logic is built and the UI needs to mutate the catalog.

## Members list + KPI surfaces

**Members listing** (existing `/backstage/members` page): adds a "Tier" column showing `MembershipType` label, plus a small honor badge when `membership_subtier` is non-null. Backend: `SqlMemberDirectoryRepository::MemberRecord` already returns `membershipType` and adds `subTierSlug: ?string`.

**Dashboard KPI** (`MembersKpiWidget` already exists and counts active members): unchanged in 0.6a. A new **members-by-tier KPI widget** is added (`MembersByTierKpiWidget`, span 2) that shows four numbers in a 2×2 grid:

```
SUPPORTING: 4    BASIC: 12
FULL:       3    HONORARY: 0
```

The new widget gets the count via a new `getMembersByTier(TenantId)` method on `AdminStatsRepositoryInterface` (extension of existing port) returning `array{supporting:int, basic:int, full:int, honorary:int}`.

The widget is registered in `bootstrap/app.php` and `KernelHarness`, added to module-aware admin defaults, available in catalog for moderator + GSA too.

## i18n keys

Add to `lang/{fi_FI,en_GB,sw_TZ}.php`:

```
membership.type.supporting          Kannattava jäsen / Supporting member / Mwanachama wa kuunga mkono
membership.type.basic               Perusjäsen / Basic member / Mwanachama wa kawaida
membership.type.full                Varsinainen jäsen / Full member / Mwanachama kamili
membership.type.honorary            Kunniajäsen / Honorary member / Mwanachama wa heshima

membership.subtier.bronze           Pronssi / Bronze / Shaba
membership.subtier.silver           Hopea / Silver / Fedha
membership.subtier.gold             Kulta / Gold / Dhahabu
membership.subtier.platinum         Platina / Platinum / Platinamu

backstage.dashboard.widget.members_by_tier_kpi.label         Jäsenet jakaumana / Members by tier / Wanachama kwa kiwango
backstage.dashboard.widget.members_by_tier_kpi.description   Jäsenten määrä jaettuna sääntöjen mukaisten neljän jäsenryhmän kesken. / Member count grouped by the four bylaws-defined membership groups. / Idadi ya wanachama imegawanywa katika makundi manne yaliyofafanuliwa katika katiba.
```

If a tenant later adds custom sub-tier slugs (e.g. `diamond`), they fall back to displaying the raw `name` column from `tenant_membership_subtiers` until a translation exists.

## Validation rules

`TenantMembershipSubTier::__construct` rejects creation where:

- `appliesTo` is `FULL` or `HONORARY` (only `SUPPORTING` / `BASIC` allow honors).
- `slug` is empty.
- `rankOrder` < 1.

There is no length cap beyond the column type (`VARCHAR(30)` for slug, `VARCHAR(80)` for name) — DB-level constraint is sufficient.

Duplicate slugs within the same `(tenant_id, applies_to)` are rejected at the database layer via `UNIQUE KEY uniq_tenant_applies_slug`. The repository surfaces this as a domain exception (`DuplicateSubTierSlug`).

## Testing

| Suite       | Coverage                                                                                                                                                                                          |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit        | `MembershipType` enum — all rights methods (voting / board / fees / sub-tier eligibility) for each of four cases; `fromLegacyString` — all legacy mappings + invalid input throws; `TenantMembershipSubTier` constructor validation — rejects FULL/HONORARY appliesTo, accepts SUPPORTING/BASIC. |
| Integration | `SqlTenantMembershipSubTierRepository` CRUD against MySQL via `MigrationTestCase`; migration 074–077 idempotency (re-running 075/077 doesn't change state).                                       |
| Isolation   | `TenantMembershipSubTierIsolationTest` — tenant A's sub-tier configuration is invisible to tenant B's repo calls.                                                                                 |
| E2E         | `MembershipSubTiersEndpointE2ETest` — GET endpoint returns seeded sub-tiers, role-gated to admin/GSA.                                                                                             |
| Static      | PHPStan level 9 = 0 errors.                                                                                                                                                                       |
| i18n        | Parity check across fi_FI/en_GB/sw_TZ for the new keys.                                                                                                                                            |

## DI wiring (BOTH containers)

Each new class is bound in:

- `bootstrap/app.php` — production with `SqlTenantMembershipSubTierRepository`.
- `tests/Support/KernelHarness.php` — test with an `InMemoryTenantMembershipSubTierRepository` fake under `tests/Support/Fake/`.

The new fake also needs seed in `KernelHarness::__construct()` so E2E tests can hit the GET endpoint with the four default sub-tiers already present for the test tenant.

## Migration of currently running tenants

- The 074+075 pair runs on every existing tenant — no data loss because legacy values are preserved in `membership_type_legacy`.
- Existing `MembersKpiWidget` count drops only when 075 normalises an already-active row whose old `membership_type` was unrecognised, which cannot happen since the four legacy values all map cleanly.
- Sub-tier seed in 077 adds 16 rows for current tenants (4 slugs × 2 appliesTo × 2 tenants). No user rows reference these slugs yet — sub-tier remains NULL until 0.6b.

## Out-of-scope tickets created in passing

Log as backlog when implementing — do NOT address inline:

- Members backstage page: filter dropdown for "Tier" + "Sub-tier" (UI improvement — 0.6b adds the filter as part of the listing refresh).
- Member CSV export: include `membership_type` + `membership_subtier` columns (currently exports lower-case legacy values). One-line fix when 0.6b refreshes the export.
- Public profile (`/members/{n}`): display the honor badge if `membership_subtier` is set. Deferred until 0.6b actually awards them.

## Pre-resolved questions for 0.6b

From this design conversation:

- **Board decision threshold for awarding sub-tier:** majority decision (not unanimous, not single-board-member authorisation). This applies to award, revoke, and tier-catalog CRUD operations. Encoded in 0.6b's `BoardDecision` workflow.
