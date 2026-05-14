# Membership Core v2 — Governance (0.6b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the governance workflow engine for MembershipCore v2 — boards + porrastetut toimikaudet, board-decision lifecycle (propose → vote → resolve) with dual-mode (async|sync) + threshold (unanimous|majority) + per-decision vote-visibility, standing delegations (whitelist), rich `member_expulsions` sub-flow with hearing + statement + appeal storage, GSA bootstrap + force-override audit, plus 4 dashboard widgets and 10 backstage pages.

**Architecture:** Clean Architecture across the `daems-platform` repo. New Domain namespace `Daems\Domain\Governance\*` for board + decisions + delegations. Additions to `Daems\Domain\Membership\*` for expulsions + sub-tier awards. Hybrid C engine: one `BoardDecision` aggregate carries lifecycle + tally; typed `Propose*` use cases per decision_type create the row + any side-table row (e.g. `member_expulsions`); `Resolve*Executor` services apply the effect after a Passed resolution. PHP 8.3, MySQL 8.4, PHPUnit 11, PHPStan level 9.

**Tech Stack:** PHP 8.3, MySQL 8.4, PHPUnit 11, PHPStan level 9, Clean Architecture (no framework deps in Domain). Migrations are `.sql` for DDL and `.php` for conditional logic with transactional control.

**Branch:** `membership-core-v2-governance` (already created off `dev`; spec committed as `c6f9391`).

**Spec reference:** `docs/superpowers/specs/2026-05-12-membership-core-v2-governance-design.md`

**Estimated commit count:** ~100–130 commits across 44 tasks.

**Cross-cutting reminders (apply to every task):**

- **Commit identity:** every commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. NEVER `Co-Authored-By:`. NEVER auto-push.
- **Never stage `.claude/`:** if it sneaks into the index run `git reset HEAD .claude/` before committing.
- **PHPStan gate:** every commit ends with `composer analyse` = 0 errors. If it fails, fix inline; do NOT commit.
- **DI BOTH containers:** every new use case / controller / SQL repository needs bindings in BOTH `bootstrap/app.php` (production) AND `tests/Support/KernelHarness.php` (test, with InMemory fakes). Grep for the new class name in both files before marking a task done.
- **Test suite names:** suites are `Unit` / `Integration` / `E2E` — capitalized. Lowercased names silently return "No tests executed!" per `feedback_phpunit_testsuite_names.md`.
- **MySQL CLI for migration smoke:** `"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/<file>.sql`. Test DB is `daems_db_test`.

---

## Pre-flight verification (run once before Task 1)

- [ ] **Verify branch + clean tree**

```bash
git rev-parse --abbrev-ref HEAD
git status --short
```

Expected: `membership-core-v2-governance` and an empty status (no working-tree changes beyond the spec commit `c6f9391`).

- [ ] **Verify spec is committed**

```bash
git log --oneline -3
```

Expected: top commit is `c6f9391 Add(docs/spec): MembershipCore v2 0.6b governance design — board + decisions + expulsions`.

- [ ] **Verify current migration baseline**

```bash
ls database/migrations/ | tail -5
```

Expected output ends with `077_seed_default_subtiers.php`. The first new migration is `078`.

- [ ] **Verify baseline tests + analysis green**

```bash
composer analyse && composer test && composer test:e2e
```

Expected: PHPStan 0 errors; all Unit / Integration / E2E tests pass.

If any pre-flight step fails STOP and fix before starting Task 1.

---

# Wave A — Database foundation (Tasks 1–8)

This wave creates the 11 migrations defined in the spec under "Database schema" and updates the `IsolationTestCase` baseline. After this wave the DB has every table the rest of the plan depends on. No PHP code beyond migrations.

## Task 1: Migration 078 — `boards` table

**Files:**

- Create: `database/migrations/078_create_boards.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/078_create_boards.sql`:

```sql
-- 078_create_boards.sql
-- One boards row per tenant. Seeded via GSA bootstrap UI, not by migration.

CREATE TABLE IF NOT EXISTS boards (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    bootstrapped_by_user_id  CHAR(36)  NOT NULL,
    bootstrapped_at          DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_board_tenant (tenant_id),
    CONSTRAINT fk_board_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_board_bootstrapper FOREIGN KEY (bootstrapped_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply locally + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/078_create_boards.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE boards;"
```

Expected: table is shown with the UNIQUE on tenant_id.

- [ ] **Step 3: Apply to test DB**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/078_create_boards.sql
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/078_create_boards.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 078 — boards table (one row per tenant, GSA-bootstrapped)"
```

---

## Task 2: Migration 079 — `board_members` table

**Files:**

- Create: `database/migrations/079_create_board_members.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/079_create_board_members.sql`:

```sql
-- 079_create_board_members.sql
-- Board members across history. term_ended_at IS NULL for active seats.

CREATE TABLE IF NOT EXISTS board_members (
    id                  CHAR(36)     NOT NULL,
    board_id            CHAR(36)     NOT NULL,
    user_id             CHAR(36)     NOT NULL,
    role                ENUM('chair','member') NOT NULL,
    term_started_at     DATETIME     NOT NULL,
    term_ends_at        DATETIME     NOT NULL,
    term_ended_at       DATETIME     NULL,
    term_ended_reason   ENUM('resigned','removed','lost_full_status','term_expired') NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_board (board_id),
    KEY idx_user (user_id),
    KEY idx_active (board_id, term_ended_at, term_ends_at),
    CONSTRAINT fk_bm_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply locally + test DB + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/079_create_board_members.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/079_create_board_members.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW INDEXES FROM board_members WHERE Key_name = 'idx_active';"
```

Expected: composite index on `(board_id, term_ended_at, term_ends_at)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/079_create_board_members.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 079 — board_members table + active-seat composite index"
```

---

## Task 3: Migration 080 — `board_decisions` table

**Files:**

- Create: `database/migrations/080_create_board_decisions.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/080_create_board_decisions.sql`:

```sql
-- 080_create_board_decisions.sql
-- Lifecycle row for every board decision. Typed payload columns instead of JSON
-- so PHPStan level 9 stays happy and indexing on payload_target_user_id works.

CREATE TABLE IF NOT EXISTS board_decisions (
    id                          CHAR(36)     NOT NULL,
    board_id                    CHAR(36)     NOT NULL,
    decision_type               ENUM(
        'approve_basic','invite_full','expel',
        'award_subtier','revoke_subtier','subtier_crud',
        'remove_board_member','delegate_authority','revoke_delegation'
    ) NOT NULL,
    threshold                   ENUM('unanimous','majority') NOT NULL,
    mode                        ENUM('async','sync') NOT NULL,
    vote_visibility             ENUM('visible','anonymous') NOT NULL,
    status                      ENUM('pending','passed','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
    proposed_by_user_id         CHAR(36)     NOT NULL,
    proposed_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                  DATETIME     NOT NULL,
    resolved_at                 DATETIME     NULL,
    meeting_reference           VARCHAR(255) NULL,
    withdrawal_reason           TEXT         NULL,
    via_delegation              TINYINT(1)   NOT NULL DEFAULT 0,
    delegation_id               CHAR(36)     NULL,

    payload_target_user_id      CHAR(36)     NULL,
    payload_application_id      CHAR(36)     NULL,
    payload_sub_tier_slug       VARCHAR(30)  NULL,
    payload_sub_tier_name       VARCHAR(80)  NULL,
    payload_sub_tier_rank       INT          NULL,
    payload_sub_tier_applies_to ENUM('SUPPORTING','BASIC') NULL,
    payload_sub_tier_operation  ENUM('create','update','delete') NULL,
    payload_board_member_id     CHAR(36)     NULL,
    payload_delegation_type     VARCHAR(40)  NULL,
    payload_delegated_to_role   VARCHAR(40)  NULL,
    payload_delegation_revoke_id CHAR(36)    NULL,
    payload_reason              TEXT         NULL,

    PRIMARY KEY (id),
    KEY idx_board_status (board_id, status),
    KEY idx_type (decision_type),
    KEY idx_expires (status, expires_at),
    CONSTRAINT fk_bd_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bd_proposer FOREIGN KEY (proposed_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply locally + test DB + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/080_create_board_decisions.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/080_create_board_decisions.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW COLUMNS FROM board_decisions;" | wc -l
```

Expected: 25 columns + header line = 26 lines.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/080_create_board_decisions.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 080 — board_decisions with typed payload columns + lifecycle indexes"
```

---

## Task 4: Migration 081 — `board_decision_votes` table

**Files:**

- Create: `database/migrations/081_create_board_decision_votes.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/081_create_board_decision_votes.sql`:

```sql
-- 081_create_board_decision_votes.sql
-- One row per (decision, board_member). Re-vote allowed via UPSERT in the repo;
-- UNIQUE constraint enforces it.

CREATE TABLE IF NOT EXISTS board_decision_votes (
    id                CHAR(36)  NOT NULL,
    decision_id       CHAR(36)  NOT NULL,
    board_member_id   CHAR(36)  NOT NULL,
    vote              ENUM('yes','no','abstain') NOT NULL,
    cast_at           DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vote (decision_id, board_member_id),
    KEY idx_decision (decision_id),
    CONSTRAINT fk_v_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_v_member FOREIGN KEY (board_member_id) REFERENCES board_members(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/081_create_board_decision_votes.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/081_create_board_decision_votes.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW INDEXES FROM board_decision_votes WHERE Key_name='uniq_vote';"
```

Expected: composite UNIQUE on `(decision_id, board_member_id)`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/081_create_board_decision_votes.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 081 — board_decision_votes with UNIQUE(decision_id, board_member_id)"
```

---

## Task 5: Migration 082 — `board_delegations` table

**Files:**

- Create: `database/migrations/082_create_board_delegations.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/082_create_board_delegations.sql`:

```sql
-- 082_create_board_delegations.sql
-- Standing delegations granted by a DelegateAuthority decision. Whitelist of types.

CREATE TABLE IF NOT EXISTS board_delegations (
    id                   CHAR(36)     NOT NULL,
    tenant_id            CHAR(36)     NOT NULL,
    decision_type        ENUM('approve_basic','invite_full','award_subtier') NOT NULL,
    delegated_to_role    ENUM('admin') NOT NULL,
    source_decision_id   CHAR(36)     NOT NULL,
    valid_from           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at           DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_active (tenant_id, decision_type, delegated_to_role, revoked_at),
    CONSTRAINT fk_deleg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_source FOREIGN KEY (source_decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/082_create_board_delegations.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/082_create_board_delegations.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE board_delegations;"
```

Expected: enum on `decision_type` lists exactly three values; `delegated_to_role` has only `admin`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/082_create_board_delegations.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 082 — board_delegations (whitelist enum + admin-only)"
```

---

## Task 6: Migrations 083 + 084 — `member_expulsions` and `member_sub_tier_awards`

**Files:**

- Create: `database/migrations/083_create_member_expulsions.sql`
- Create: `database/migrations/084_create_member_sub_tier_awards.sql`

- [ ] **Step 1: Write 083**

`database/migrations/083_create_member_expulsions.sql`:

```sql
-- 083_create_member_expulsions.sql
-- Rich aggregate for the bylaws § 4 expulsion process: hearing → statement →
-- decision → expelled → optional appeal.

CREATE TABLE IF NOT EXISTS member_expulsions (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    target_user_id           CHAR(36)  NOT NULL,
    proposed_by_user_id      CHAR(36)  NOT NULL,
    reason                   TEXT      NOT NULL,
    hearing_deadline_at      DATETIME  NOT NULL,
    statement_text           TEXT      NULL,
    statement_received_at    DATETIME  NULL,
    decision_id              CHAR(36)  NULL,
    decided_at               DATETIME  NULL,
    expelled_at              DATETIME  NULL,
    appeal_filed_at          DATETIME  NULL,
    appeal_text              TEXT      NULL,
    status                   ENUM('hearing','awaiting_vote','expelled','rejected','appealed') NOT NULL DEFAULT 'hearing',
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_status (tenant_id, status),
    KEY idx_target (target_user_id),
    CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_user FOREIGN KEY (target_user_id) REFERENCES users(id),
    CONSTRAINT fk_exp_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Write 084**

`database/migrations/084_create_member_sub_tier_awards.sql`:

```sql
-- 084_create_member_sub_tier_awards.sql
-- Audit + active-marker for sub-tier honors awarded by board decision.
-- revoked_at NULL means the award is currently in effect.

CREATE TABLE IF NOT EXISTS member_sub_tier_awards (
    id                    CHAR(36)     NOT NULL,
    tenant_id             CHAR(36)     NOT NULL,
    user_id               CHAR(36)     NOT NULL,
    sub_tier_slug         VARCHAR(30)  NOT NULL,
    decision_id           CHAR(36)     NOT NULL,
    awarded_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at            DATETIME     NULL,
    revoke_decision_id    CHAR(36)     NULL,
    PRIMARY KEY (id),
    KEY idx_user_active (user_id, revoked_at),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_award_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_award_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_award_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3: Apply both + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/083_create_member_expulsions.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/084_create_member_sub_tier_awards.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/083_create_member_expulsions.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/084_create_member_sub_tier_awards.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW TABLES LIKE 'member_%';"
```

Expected: lists at least `member_applications`, `member_expulsions`, `member_status_audits`, `member_sub_tier_awards`.

- [ ] **Step 4: Commit (single commit for both, related)**

```bash
git add database/migrations/083_create_member_expulsions.sql database/migrations/084_create_member_sub_tier_awards.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migrations 083+084 — member_expulsions + member_sub_tier_awards"
```

---

## Task 7: Migrations 085 + 086 + 087 — settings, GSA overrides, users column

**Files:**

- Create: `database/migrations/085_create_tenant_governance_settings.sql`
- Create: `database/migrations/086_create_gsa_overrides.sql`
- Create: `database/migrations/087_add_invited_to_full_at_to_users.sql`

- [ ] **Step 1: Write 085**

`database/migrations/085_create_tenant_governance_settings.sql`:

```sql
-- 085_create_tenant_governance_settings.sql
-- Per-tenant defaults for the governance workflow. Seed values added by 088.

CREATE TABLE IF NOT EXISTS tenant_governance_settings (
    tenant_id                  CHAR(36)  NOT NULL,
    expulsion_hearing_days     INT       NOT NULL DEFAULT 14,
    decision_expiration_days   INT       NOT NULL DEFAULT 60,
    updated_at                 DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tgs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Write 086**

`database/migrations/086_create_gsa_overrides.sql`:

```sql
-- 086_create_gsa_overrides.sql
-- Dedicated audit table for GSA force-overrides that bypass the board flow.

CREATE TABLE IF NOT EXISTS gsa_overrides (
    id              CHAR(36)  NOT NULL,
    gsa_user_id     CHAR(36)  NOT NULL,
    tenant_id       CHAR(36)  NOT NULL,
    action          ENUM('force_approve_basic') NOT NULL,
    target_id       CHAR(36)  NOT NULL,
    reason          TEXT      NOT NULL,
    performed_at    DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant (tenant_id),
    KEY idx_gsa (gsa_user_id),
    CONSTRAINT fk_gsa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_gsa_user FOREIGN KEY (gsa_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3: Write 087**

`database/migrations/087_add_invited_to_full_at_to_users.sql`:

```sql
-- 087_add_invited_to_full_at_to_users.sql
-- Timestamp set by InviteFullExecutor when the board confirms a BASIC → FULL promotion.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS invited_to_full_at DATETIME NULL AFTER membership_ended_at;
```

- [ ] **Step 4: Apply all three + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/085_create_tenant_governance_settings.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/086_create_gsa_overrides.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db      < database/migrations/087_add_invited_to_full_at_to_users.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/085_create_tenant_governance_settings.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/086_create_gsa_overrides.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/087_add_invited_to_full_at_to_users.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW COLUMNS FROM users WHERE Field='invited_to_full_at';"
```

Expected: `invited_to_full_at | datetime | YES | | NULL`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/085_create_tenant_governance_settings.sql database/migrations/086_create_gsa_overrides.sql database/migrations/087_add_invited_to_full_at_to_users.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migrations 085+086+087 — governance settings, gsa_overrides, users.invited_to_full_at"
```

---

## Task 8: Migration 088 — seed defaults + bump IsolationTestCase baseline

**Files:**

- Create: `database/migrations/088_seed_governance_settings_defaults.php`
- Modify: `tests/Isolation/IsolationTestCase.php` (one number)

- [ ] **Step 1: Write 088 PHP migration**

`database/migrations/088_seed_governance_settings_defaults.php`:

```php
<?php
// 088_seed_governance_settings_defaults.php
// Seed default governance settings (14 vrk hearing + 60 vrk expiration) for
// every existing tenant. Idempotent — INSERT IGNORE keeps re-runs safe.
//
// @var \PDO $pdo  — provided by MigrationRunner / MigrationTestCase

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_governance_settings (tenant_id, expulsion_hearing_days, decision_expiration_days)
     VALUES (?, 14, 60)'
);
$tenantIds = $pdo->query('SELECT id FROM tenants')->fetchAll(\PDO::FETCH_COLUMN);
foreach ($tenantIds as $tenantId) {
    if (!is_string($tenantId)) continue;
    $insert->execute([$tenantId]);
}
```

- [ ] **Step 2: Apply to both DBs + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW TABLES LIKE 'tenant_governance_settings'; SELECT COUNT(*) FROM tenants;"
php -r '$pdo = new PDO("mysql:host=127.0.0.1;dbname=daems_db", "root", "salasana"); require "database/migrations/088_seed_governance_settings_defaults.php";'
php -r '$pdo = new PDO("mysql:host=127.0.0.1;dbname=daems_db_test", "root", "salasana"); require "database/migrations/088_seed_governance_settings_defaults.php";'
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT COUNT(*) FROM tenant_governance_settings;"
```

Expected: count equals number of tenants (currently 2 — daems + sahegroup).

- [ ] **Step 3: Verify idempotency by re-running**

```bash
php -r '$pdo = new PDO("mysql:host=127.0.0.1;dbname=daems_db", "root", "salasana"); require "database/migrations/088_seed_governance_settings_defaults.php";'
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT COUNT(*) FROM tenant_governance_settings;"
```

Expected: same count (no duplicates inserted).

- [ ] **Step 4: Update IsolationTestCase baseline**

Edit `tests/Isolation/IsolationTestCase.php`:

```php
// before
$this->runMigrationsUpTo(77);

// after
$this->runMigrationsUpTo(88);
```

- [ ] **Step 5: Run isolation suite to confirm baseline still green**

```bash
composer test -- --testsuite Isolation
```

Expected: every isolation test still passes against the new baseline (none of them touch governance tables yet, so behaviour is unchanged).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/088_seed_governance_settings_defaults.php tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 088 seed defaults + bump IsolationTestCase baseline to 88"
```

---

# Wave B — Domain layer (Tasks 9–13)

This wave defines every Domain type the rest of the plan depends on: enums, Id value objects, entities, aggregates, repository ports, and domain exceptions. NO infrastructure code, NO use cases, NO controllers — those come in Waves C–H. All Unit tests in this wave are framework-free (no PDO, no HTTP).

## Task 9: Governance enums (8 enums)

**Files:**

- Create: `src/Domain/Governance/BoardMemberRole.php`
- Create: `src/Domain/Governance/BoardMemberTermEndedReason.php`
- Create: `src/Domain/Governance/BoardDecisionType.php`
- Create: `src/Domain/Governance/BoardDecisionThreshold.php`
- Create: `src/Domain/Governance/BoardDecisionMode.php`
- Create: `src/Domain/Governance/BoardDecisionStatus.php`
- Create: `src/Domain/Governance/BoardDecisionVoteVisibility.php`
- Create: `src/Domain/Governance/BoardDecisionSubTierCrudOperation.php`
- Create: `src/Domain/Governance/BoardDecisionVoteValue.php`
- Test: `tests/Unit/Domain/Governance/EnumsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Governance/EnumsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function test_board_member_role_values(): void
    {
        $this->assertSame('chair',  BoardMemberRole::Chair->value);
        $this->assertSame('member', BoardMemberRole::Member->value);
    }

    public function test_term_ended_reason_values(): void
    {
        $this->assertSame('resigned',         BoardMemberTermEndedReason::Resigned->value);
        $this->assertSame('removed',          BoardMemberTermEndedReason::Removed->value);
        $this->assertSame('lost_full_status', BoardMemberTermEndedReason::LostFullStatus->value);
        $this->assertSame('term_expired',     BoardMemberTermEndedReason::TermExpired->value);
    }

    public function test_decision_type_values(): void
    {
        $expected = [
            'approve_basic','invite_full','expel','award_subtier','revoke_subtier',
            'subtier_crud','remove_board_member','delegate_authority','revoke_delegation',
        ];
        $actual = array_map(fn(BoardDecisionType $c) => $c->value, BoardDecisionType::cases());
        $this->assertSame($expected, $actual);
    }

    public function test_threshold_mode_status_visibility(): void
    {
        $this->assertSame(['unanimous','majority'],
            array_map(fn(BoardDecisionThreshold $c) => $c->value, BoardDecisionThreshold::cases()));
        $this->assertSame(['async','sync'],
            array_map(fn(BoardDecisionMode $c) => $c->value, BoardDecisionMode::cases()));
        $this->assertSame(['pending','passed','rejected','expired','withdrawn'],
            array_map(fn(BoardDecisionStatus $c) => $c->value, BoardDecisionStatus::cases()));
        $this->assertSame(['visible','anonymous'],
            array_map(fn(BoardDecisionVoteVisibility $c) => $c->value, BoardDecisionVoteVisibility::cases()));
        $this->assertSame(['create','update','delete'],
            array_map(fn(BoardDecisionSubTierCrudOperation $c) => $c->value, BoardDecisionSubTierCrudOperation::cases()));
        $this->assertSame(['yes','no','abstain'],
            array_map(fn(BoardDecisionVoteValue $c) => $c->value, BoardDecisionVoteValue::cases()));
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

```bash
composer test -- --filter EnumsTest
```

Expected: errors saying every enum class is missing.

- [ ] **Step 3: Create the enums**

`src/Domain/Governance/BoardMemberRole.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardMemberRole: string
{
    case Chair  = 'chair';
    case Member = 'member';
}
```

`src/Domain/Governance/BoardMemberTermEndedReason.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardMemberTermEndedReason: string
{
    case Resigned       = 'resigned';
    case Removed        = 'removed';
    case LostFullStatus = 'lost_full_status';
    case TermExpired    = 'term_expired';
}
```

`src/Domain/Governance/BoardDecisionType.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionType: string
{
    case ApproveBasic       = 'approve_basic';
    case InviteFull         = 'invite_full';
    case Expel              = 'expel';
    case AwardSubTier       = 'award_subtier';
    case RevokeSubTier      = 'revoke_subtier';
    case SubTierCrud        = 'subtier_crud';
    case RemoveBoardMember  = 'remove_board_member';
    case DelegateAuthority  = 'delegate_authority';
    case RevokeDelegation   = 'revoke_delegation';

    /** Whitelist of types that may be delegated to the admin role. */
    public function isDelegatable(): bool
    {
        return match ($this) {
            self::ApproveBasic, self::InviteFull, self::AwardSubTier => true,
            default => false,
        };
    }
}
```

`src/Domain/Governance/BoardDecisionThreshold.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionThreshold: string
{
    case Unanimous = 'unanimous';
    case Majority  = 'majority';
}
```

`src/Domain/Governance/BoardDecisionMode.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionMode: string
{
    case Async = 'async';
    case Sync  = 'sync';
}
```

`src/Domain/Governance/BoardDecisionStatus.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionStatus: string
{
    case Pending    = 'pending';
    case Passed     = 'passed';
    case Rejected   = 'rejected';
    case Expired    = 'expired';
    case Withdrawn  = 'withdrawn';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
```

`src/Domain/Governance/BoardDecisionVoteVisibility.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionVoteVisibility: string
{
    case Visible   = 'visible';
    case Anonymous = 'anonymous';
}
```

`src/Domain/Governance/BoardDecisionSubTierCrudOperation.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionSubTierCrudOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
```

`src/Domain/Governance/BoardDecisionVoteValue.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionVoteValue: string
{
    case Yes     = 'yes';
    case No      = 'no';
    case Abstain = 'abstain';
}
```

- [ ] **Step 4: Run tests, expect green**

```bash
composer test -- --filter EnumsTest
composer analyse
```

Expected: EnumsTest passes; PHPStan 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Governance/ tests/Unit/Domain/Governance/EnumsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/governance): 9 enums (role, term-reason, type, threshold, mode, status, vote-visibility, subtier-crud-op, vote)"
```

---

## Task 10: Board + BoardMember + BoardDelegation entities + Id VOs + repository interfaces

**Files:**

- Create: `src/Domain/Governance/BoardId.php`
- Create: `src/Domain/Governance/BoardMemberId.php`
- Create: `src/Domain/Governance/BoardDelegationId.php`
- Create: `src/Domain/Governance/Board.php`
- Create: `src/Domain/Governance/BoardMember.php`
- Create: `src/Domain/Governance/BoardDelegation.php`
- Create: `src/Domain/Governance/BoardRepositoryInterface.php`
- Create: `src/Domain/Governance/BoardMemberRepositoryInterface.php`
- Create: `src/Domain/Governance/BoardDelegationRepositoryInterface.php`
- Test: `tests/Unit/Domain/Governance/BoardMemberTest.php`
- Test: `tests/Unit/Domain/Governance/BoardDelegationTest.php`

- [ ] **Step 1: Write BoardMember + BoardDelegation failing tests**

`tests/Unit/Domain/Governance/BoardMemberTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class BoardMemberTest extends TestCase
{
    public function test_active_when_within_term_and_no_end_marker(): void
    {
        $now = new \DateTimeImmutable('2026-05-12 10:00:00');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertTrue($m->isActive($now));
    }

    public function test_inactive_when_term_ended_at_set(): void
    {
        $now = new \DateTimeImmutable('2026-05-12');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       new \DateTimeImmutable('2026-04-01'),
            termEndedReason:   BoardMemberTermEndedReason::Resigned,
        );
        $this->assertFalse($m->isActive($now));
    }

    public function test_inactive_when_term_not_yet_started(): void
    {
        $now = new \DateTimeImmutable('2025-12-31');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Chair,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertFalse($m->isActive($now));
    }

    public function test_inactive_when_term_already_expired(): void
    {
        $now = new \DateTimeImmutable('2028-02-01');
        $m = new BoardMember(
            id:                BoardMemberId::generate(),
            boardId:           BoardId::generate(),
            userId:            UserId::generate(),
            role:              BoardMemberRole::Member,
            termStartedAt:     new \DateTimeImmutable('2026-01-01'),
            termEndsAt:        new \DateTimeImmutable('2028-01-01'),
            termEndedAt:       null,
            termEndedReason:   null,
        );
        $this->assertFalse($m->isActive($now));
    }
}
```

`tests/Unit/Domain/Governance/BoardDelegationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use PHPUnit\Framework\TestCase;

final class BoardDelegationTest extends TestCase
{
    public function test_construct_rejects_non_whitelisted_type(): void
    {
        $this->expectException(DelegationNotPermittedForType::class);
        new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::Expel,        // not delegatable
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-12'),
            revokedAt:         null,
        );
    }

    public function test_is_active_within_window(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::ApproveBasic,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-01'),
            revokedAt:         null,
        );
        $this->assertTrue($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }

    public function test_is_inactive_after_revoke(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::AwardSubTier,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-01'),
            revokedAt:         new \DateTimeImmutable('2026-05-10'),
        );
        $this->assertFalse($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }

    public function test_is_inactive_before_valid_from(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::InviteFull,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-06-01'),
            revokedAt:         null,
        );
        $this->assertFalse($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
composer test -- --filter "BoardMemberTest|BoardDelegationTest"
```

Expected: missing classes.

- [ ] **Step 3: Create the Id VOs**

`src/Domain/Governance/BoardId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class BoardId extends Uuid7Id {}
```

`src/Domain/Governance/BoardMemberId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class BoardMemberId extends Uuid7Id {}
```

`src/Domain/Governance/BoardDelegationId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class BoardDelegationId extends Uuid7Id {}
```

- [ ] **Step 4: Create the entities**

`src/Domain/Governance/Board.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class Board
{
    public function __construct(
        public readonly BoardId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $bootstrappedByUserId,
        public readonly \DateTimeImmutable $bootstrappedAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

`src/Domain/Governance/BoardMember.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\User\UserId;

final class BoardMember
{
    public function __construct(
        public readonly BoardMemberId $id,
        public readonly BoardId $boardId,
        public readonly UserId $userId,
        public readonly BoardMemberRole $role,
        public readonly \DateTimeImmutable $termStartedAt,
        public readonly \DateTimeImmutable $termEndsAt,
        public readonly ?\DateTimeImmutable $termEndedAt,
        public readonly ?BoardMemberTermEndedReason $termEndedReason,
    ) {}

    public function isActive(\DateTimeImmutable $at): bool
    {
        if ($this->termEndedAt !== null)             return false;
        if ($at < $this->termStartedAt)              return false;
        if ($at >= $this->termEndsAt)                return false;
        return true;
    }
}
```

`src/Domain/Governance/BoardDelegation.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

final class BoardDelegation
{
    public function __construct(
        public readonly BoardDelegationId $id,
        public readonly TenantId $tenantId,
        public readonly BoardDecisionType $decisionType,
        public readonly UserTenantRole $delegatedToRole,
        public readonly BoardDecisionId $sourceDecisionId,
        public readonly \DateTimeImmutable $validFrom,
        public readonly ?\DateTimeImmutable $revokedAt,
    ) {
        if (!$decisionType->isDelegatable()) {
            throw new DelegationNotPermittedForType(
                "Delegation not permitted for decision_type={$decisionType->value}"
            );
        }
        if ($delegatedToRole !== UserTenantRole::Admin) {
            throw new DelegationNotPermittedForType(
                "Only the admin role can be a delegatee; got {$delegatedToRole->value}"
            );
        }
    }

    public function isActive(\DateTimeImmutable $at): bool
    {
        if ($at < $this->validFrom)                  return false;
        if ($this->revokedAt !== null && $at >= $this->revokedAt) return false;
        return true;
    }
}
```

- [ ] **Step 5: Create the BoardDecisionId stub (used by BoardDelegation)**

`src/Domain/Governance/BoardDecisionId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class BoardDecisionId extends Uuid7Id {}
```

- [ ] **Step 6: Create the exception subdirectory + first exception**

`src/Domain/Governance/Exception/DelegationNotPermittedForType.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance\Exception;

final class DelegationNotPermittedForType extends \DomainException {}
```

- [ ] **Step 7: Create the repository interfaces**

`src/Domain/Governance/BoardRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

interface BoardRepositoryInterface
{
    public function findForTenant(TenantId $tenantId): ?Board;

    public function save(Board $board): void;
}
```

`src/Domain/Governance/BoardMemberRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardMemberRepositoryInterface
{
    /** @return list<BoardMember> */
    public function listForBoard(BoardId $boardId): array;

    /** @return list<BoardMember> */
    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array;

    public function find(BoardMemberId $id): ?BoardMember;

    public function save(BoardMember $member): void;
}
```

`src/Domain/Governance/BoardDelegationRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;

interface BoardDelegationRepositoryInterface
{
    public function findActive(
        TenantId $tenantId,
        BoardDecisionType $decisionType,
        UserTenantRole $delegatedToRole,
        \DateTimeImmutable $at,
    ): ?BoardDelegation;

    /** @return list<BoardDelegation> */
    public function listActive(TenantId $tenantId, \DateTimeImmutable $at): array;

    public function find(BoardDelegationId $id): ?BoardDelegation;

    public function save(BoardDelegation $delegation): void;
}
```

- [ ] **Step 8: Run tests + PHPStan**

```bash
composer test -- --filter "BoardMemberTest|BoardDelegationTest"
composer analyse
```

Expected: both tests pass; PHPStan 0 errors.

- [ ] **Step 9: Commit**

```bash
git add src/Domain/Governance/ tests/Unit/Domain/Governance/BoardMemberTest.php tests/Unit/Domain/Governance/BoardDelegationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/governance): Board + BoardMember + BoardDelegation entities + Id VOs + repository ports"
```

---

## Task 11: BoardDecision aggregate + BoardDecisionVote + invariants

**Files:**

- Create: `src/Domain/Governance/BoardDecision.php`
- Create: `src/Domain/Governance/BoardDecisionVote.php`
- Create: `src/Domain/Governance/BoardDecisionVoteId.php`
- Create: `src/Domain/Governance/Exception/AsyncRequiresUnanimous.php`
- Create: `src/Domain/Governance/Exception/DecisionAlreadyResolved.php`
- Create: `src/Domain/Governance/Exception/VoteAlreadyCast.php`
- Create: `src/Domain/Governance/BoardDecisionRepositoryInterface.php`
- Create: `src/Domain/Governance/BoardDecisionVoteRepositoryInterface.php`
- Test: `tests/Unit/Domain/Governance/BoardDecisionTest.php`

- [ ] **Step 1: Write failing test**

`tests/Unit/Domain/Governance/BoardDecisionTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\AsyncRequiresUnanimous;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class BoardDecisionTest extends TestCase
{
    public function test_async_with_majority_is_rejected(): void
    {
        $this->expectException(AsyncRequiresUnanimous::class);
        new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::AwardSubTier,
            threshold:        BoardDecisionThreshold::Majority,
            mode:             BoardDecisionMode::Async,    // illegal combo
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
    }

    public function test_async_unanimous_is_allowed(): void
    {
        $d = new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::ApproveBasic,
            threshold:        BoardDecisionThreshold::Unanimous,
            mode:             BoardDecisionMode::Async,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
    }

    public function test_sync_majority_is_allowed_with_meeting_reference(): void
    {
        $d = new BoardDecision(
            id:               BoardDecisionId::generate(),
            boardId:          BoardId::generate(),
            decisionType:     BoardDecisionType::AwardSubTier,
            threshold:        BoardDecisionThreshold::Majority,
            mode:             BoardDecisionMode::Sync,
            voteVisibility:   BoardDecisionVoteVisibility::Anonymous,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt:       new \DateTimeImmutable('2026-05-12'),
            expiresAt:        new \DateTimeImmutable('2026-07-12'),
            resolvedAt:       null,
            meetingReference: 'Hallituksen kokous 2026-05-20 — PK-12',
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
        );
        $this->assertSame('Hallituksen kokous 2026-05-20 — PK-12', $d->meetingReference);
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
composer test -- --filter BoardDecisionTest
```

- [ ] **Step 3: Create the exceptions**

`src/Domain/Governance/Exception/AsyncRequiresUnanimous.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance\Exception;

final class AsyncRequiresUnanimous extends \DomainException {}
```

`src/Domain/Governance/Exception/DecisionAlreadyResolved.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance\Exception;

final class DecisionAlreadyResolved extends \DomainException {}
```

`src/Domain/Governance/Exception/VoteAlreadyCast.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance\Exception;

final class VoteAlreadyCast extends \DomainException {}
```

- [ ] **Step 4: Create BoardDecisionVoteId + BoardDecisionVote**

`src/Domain/Governance/BoardDecisionVoteId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class BoardDecisionVoteId extends Uuid7Id {}
```

`src/Domain/Governance/BoardDecisionVote.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

final class BoardDecisionVote
{
    public function __construct(
        public readonly BoardDecisionVoteId $id,
        public readonly BoardDecisionId $decisionId,
        public readonly BoardMemberId $boardMemberId,
        public readonly BoardDecisionVoteValue $vote,
        public readonly \DateTimeImmutable $castAt,
    ) {}
}
```

- [ ] **Step 5: Create the BoardDecision aggregate**

`src/Domain/Governance/BoardDecision.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Governance\Exception\AsyncRequiresUnanimous;
use Daems\Domain\User\UserId;

/**
 * Aggregate root for the board-decision lifecycle. Typed payload columns
 * are carried as nullable fields; readers/executors inspect them based on
 * decisionType. Constructor enforces the § 7 invariant: async ⇒ unanimous.
 */
final class BoardDecision
{
    public function __construct(
        public readonly BoardDecisionId $id,
        public readonly BoardId $boardId,
        public readonly BoardDecisionType $decisionType,
        public readonly BoardDecisionThreshold $threshold,
        public readonly BoardDecisionMode $mode,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly BoardDecisionStatus $status,
        public readonly UserId $proposedByUserId,
        public readonly \DateTimeImmutable $proposedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly ?string $meetingReference,
        public readonly ?string $withdrawalReason,
        public readonly bool $viaDelegation,
        public readonly ?BoardDelegationId $delegationId,
        // Typed payload — only some are populated per decisionType.
        public readonly ?UserId $payloadTargetUserId = null,
        public readonly ?string $payloadApplicationId = null,
        public readonly ?string $payloadSubTierSlug = null,
        public readonly ?string $payloadSubTierName = null,
        public readonly ?int $payloadSubTierRank = null,
        public readonly ?string $payloadSubTierAppliesTo = null,
        public readonly ?BoardDecisionSubTierCrudOperation $payloadSubTierOperation = null,
        public readonly ?BoardMemberId $payloadBoardMemberId = null,
        public readonly ?BoardDecisionType $payloadDelegationType = null,
        public readonly ?string $payloadDelegatedToRole = null,
        public readonly ?BoardDelegationId $payloadDelegationRevokeId = null,
        public readonly ?string $payloadReason = null,
    ) {
        if ($mode === BoardDecisionMode::Async && $threshold !== BoardDecisionThreshold::Unanimous) {
            throw new AsyncRequiresUnanimous(
                "§ 7: mode=async requires threshold=unanimous; got threshold={$threshold->value}"
            );
        }
    }

    public function withStatus(
        BoardDecisionStatus $status,
        ?\DateTimeImmutable $resolvedAt = null,
        ?string $withdrawalReason = null,
    ): self {
        return new self(
            id:                       $this->id,
            boardId:                  $this->boardId,
            decisionType:             $this->decisionType,
            threshold:                $this->threshold,
            mode:                     $this->mode,
            voteVisibility:           $this->voteVisibility,
            status:                   $status,
            proposedByUserId:         $this->proposedByUserId,
            proposedAt:               $this->proposedAt,
            expiresAt:                $this->expiresAt,
            resolvedAt:               $resolvedAt ?? $this->resolvedAt,
            meetingReference:         $this->meetingReference,
            withdrawalReason:         $withdrawalReason ?? $this->withdrawalReason,
            viaDelegation:            $this->viaDelegation,
            delegationId:             $this->delegationId,
            payloadTargetUserId:      $this->payloadTargetUserId,
            payloadApplicationId:     $this->payloadApplicationId,
            payloadSubTierSlug:       $this->payloadSubTierSlug,
            payloadSubTierName:       $this->payloadSubTierName,
            payloadSubTierRank:       $this->payloadSubTierRank,
            payloadSubTierAppliesTo:  $this->payloadSubTierAppliesTo,
            payloadSubTierOperation:  $this->payloadSubTierOperation,
            payloadBoardMemberId:     $this->payloadBoardMemberId,
            payloadDelegationType:    $this->payloadDelegationType,
            payloadDelegatedToRole:   $this->payloadDelegatedToRole,
            payloadDelegationRevokeId:$this->payloadDelegationRevokeId,
            payloadReason:            $this->payloadReason,
        );
    }
}
```

- [ ] **Step 6: Create the two new repository interfaces**

`src/Domain/Governance/BoardDecisionRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardDecisionRepositoryInterface
{
    public function find(BoardDecisionId $id): ?BoardDecision;

    /** @return list<BoardDecision> */
    public function listForBoard(BoardId $boardId, ?BoardDecisionStatus $status = null, ?BoardDecisionType $type = null): array;

    /** @return list<BoardDecision> */
    public function listExpiredPending(\DateTimeImmutable $at, int $limit = 100): array;

    public function save(BoardDecision $decision): void;
}
```

`src/Domain/Governance/BoardDecisionVoteRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

interface BoardDecisionVoteRepositoryInterface
{
    /** @return list<BoardDecisionVote> */
    public function listForDecision(BoardDecisionId $decisionId): array;

    public function findByMember(BoardDecisionId $decisionId, BoardMemberId $memberId): ?BoardDecisionVote;

    public function upsert(BoardDecisionVote $vote): void;
}
```

- [ ] **Step 7: Run tests + PHPStan**

```bash
composer test -- --filter BoardDecisionTest
composer analyse
```

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Governance/ tests/Unit/Domain/Governance/BoardDecisionTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/governance): BoardDecision aggregate + Vote + invariant async⇒unanimous"
```

---

## Task 12: MemberExpulsion + MemberSubTierAward + TenantGovernanceSettings + IsEligibleForFullMembership

**Files:**

- Create: `src/Domain/Membership/MemberExpulsion.php`
- Create: `src/Domain/Membership/MemberExpulsionId.php`
- Create: `src/Domain/Membership/MemberExpulsionStatus.php`
- Create: `src/Domain/Membership/MemberExpulsionRepositoryInterface.php`
- Create: `src/Domain/Membership/MemberSubTierAward.php`
- Create: `src/Domain/Membership/MemberSubTierAwardId.php`
- Create: `src/Domain/Membership/MemberSubTierAwardRepositoryInterface.php`
- Create: `src/Domain/Membership/IsEligibleForFullMembership.php`
- Create: `src/Domain/Governance/TenantGovernanceSettings.php`
- Create: `src/Domain/Governance/TenantGovernanceSettingsRepositoryInterface.php`
- Test: `tests/Unit/Domain/Membership/IsEligibleForFullMembershipTest.php`

- [ ] **Step 1: Write failing test for the eligibility checker**

`tests/Unit/Domain/Membership/IsEligibleForFullMembershipTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\IsEligibleForFullMembership;
use Daems\Domain\Membership\MembershipType;
use PHPUnit\Framework\TestCase;

final class IsEligibleForFullMembershipTest extends TestCase
{
    public function test_exactly_12_months_is_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12'); // 12 mo
        $svc      = new IsEligibleForFullMembership();
        $this->assertTrue($svc->check(MembershipType::Basic, 'active', $joinedAt, $now));
    }

    public function test_one_day_short_of_12_months_is_not_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-11');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'active', $joinedAt, $now));
    }

    public function test_supporting_is_not_eligible_even_after_12_months(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Supporting, 'active', $joinedAt, $now));
    }

    public function test_non_active_status_is_not_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'suspended', $joinedAt, $now));
    }

    public function test_null_join_date_is_not_eligible(): void
    {
        $svc = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'active', null, new \DateTimeImmutable()));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
composer test -- --filter IsEligibleForFullMembershipTest
```

- [ ] **Step 3: Create the eligibility checker**

`src/Domain/Membership/IsEligibleForFullMembership.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

final class IsEligibleForFullMembership
{
    public function check(
        MembershipType $type,
        string $membershipStatus,
        ?\DateTimeImmutable $membershipStartedAt,
        \DateTimeImmutable $now,
    ): bool {
        if ($type !== MembershipType::Basic)        return false;
        if ($membershipStatus !== 'active')         return false;
        if ($membershipStartedAt === null)          return false;
        $twelveAgo = $now->modify('-12 months');
        return $membershipStartedAt <= $twelveAgo;
    }
}
```

- [ ] **Step 4: Create MemberExpulsion types**

`src/Domain/Membership/MemberExpulsionId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class MemberExpulsionId extends Uuid7Id {}
```

`src/Domain/Membership/MemberExpulsionStatus.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

enum MemberExpulsionStatus: string
{
    case Hearing       = 'hearing';
    case AwaitingVote  = 'awaiting_vote';
    case Expelled      = 'expelled';
    case Rejected      = 'rejected';
    case Appealed      = 'appealed';
}
```

`src/Domain/Membership/MemberExpulsion.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class MemberExpulsion
{
    public function __construct(
        public readonly MemberExpulsionId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $targetUserId,
        public readonly UserId $proposedByUserId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $hearingDeadlineAt,
        public readonly ?string $statementText,
        public readonly ?\DateTimeImmutable $statementReceivedAt,
        public readonly ?BoardDecisionId $decisionId,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?\DateTimeImmutable $expelledAt,
        public readonly ?\DateTimeImmutable $appealFiledAt,
        public readonly ?string $appealText,
        public readonly MemberExpulsionStatus $status,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

`src/Domain/Membership/MemberExpulsionRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

interface MemberExpulsionRepositoryInterface
{
    public function find(MemberExpulsionId $id): ?MemberExpulsion;

    /** @return list<MemberExpulsion> */
    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array;

    public function save(MemberExpulsion $expulsion): void;
}
```

- [ ] **Step 5: Create MemberSubTierAward types**

`src/Domain/Membership/MemberSubTierAwardId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class MemberSubTierAwardId extends Uuid7Id {}
```

`src/Domain/Membership/MemberSubTierAward.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class MemberSubTierAward
{
    public function __construct(
        public readonly MemberSubTierAwardId $id,
        public readonly TenantId $tenantId,
        public readonly UserId $userId,
        public readonly string $subTierSlug,
        public readonly BoardDecisionId $decisionId,
        public readonly \DateTimeImmutable $awardedAt,
        public readonly ?\DateTimeImmutable $revokedAt,
        public readonly ?BoardDecisionId $revokeDecisionId,
    ) {}

    public function isActive(\DateTimeImmutable $at): bool
    {
        return $this->revokedAt === null || $at < $this->revokedAt;
    }
}
```

`src/Domain/Membership/MemberSubTierAwardRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface MemberSubTierAwardRepositoryInterface
{
    public function findActive(TenantId $tenantId, UserId $userId, \DateTimeImmutable $at): ?MemberSubTierAward;

    /** @return list<MemberSubTierAward> */
    public function listForUser(TenantId $tenantId, UserId $userId): array;

    /** @return list<MemberSubTierAward> */
    public function listActiveForSubTierSlug(TenantId $tenantId, string $subTierSlug, \DateTimeImmutable $at): array;

    public function save(MemberSubTierAward $award): void;
}
```

- [ ] **Step 6: Create TenantGovernanceSettings**

`src/Domain/Governance/TenantGovernanceSettings.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

final class TenantGovernanceSettings
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly int $expulsionHearingDays,
        public readonly int $decisionExpirationDays,
    ) {
        if ($expulsionHearingDays < 1) {
            throw new \InvalidArgumentException('expulsionHearingDays must be >= 1');
        }
        if ($decisionExpirationDays < 1) {
            throw new \InvalidArgumentException('decisionExpirationDays must be >= 1');
        }
    }
}
```

`src/Domain/Governance/TenantGovernanceSettingsRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

interface TenantGovernanceSettingsRepositoryInterface
{
    public function find(TenantId $tenantId): ?TenantGovernanceSettings;

    public function save(TenantGovernanceSettings $settings): void;
}
```

- [ ] **Step 7: Run tests + PHPStan**

```bash
composer test -- --filter IsEligibleForFullMembershipTest
composer analyse
```

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Membership/ src/Domain/Governance/TenantGovernanceSettings.php src/Domain/Governance/TenantGovernanceSettingsRepositoryInterface.php tests/Unit/Domain/Membership/IsEligibleForFullMembershipTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/membership): MemberExpulsion + MemberSubTierAward + IsEligibleForFullMembership + TenantGovernanceSettings"
```

---

## Task 13: Remaining domain exceptions + GsaOverride types

**Files:**

- Create: `src/Domain/Governance/Exception/BoardNotBootstrapped.php`
- Create: `src/Domain/Governance/Exception/BoardAlreadyBootstrapped.php`
- Create: `src/Domain/Governance/Exception/NotABoardMember.php`
- Create: `src/Domain/Governance/Exception/BoardMemberTermExpired.php`
- Create: `src/Domain/Governance/Exception/NotEligibleForFull.php`
- Create: `src/Domain/Governance/Exception/InsufficientQuorum.php`
- Create: `src/Domain/Governance/Exception/DuplicateActiveDelegation.php`
- Create: `src/Domain/Governance/Exception/InvalidBootstrapRoster.php`
- Create: `src/Domain/Governance/Exception/BoardCandidateNotFull.php`
- Create: `src/Domain/Governance/Exception/LastBoardMemberCannotBeRemoved.php`
- Create: `src/Domain/Governance/Exception/GsaOverrideRequiresReason.php`
- Create: `src/Domain/Membership/Exception/ExpulsionHearingNotElapsed.php`
- Create: `src/Domain/Membership/Exception/ExpulsionAlreadyAdvanced.php`
- Create: `src/Domain/Membership/Exception/AppealAlreadyFiled.php`
- Create: `src/Domain/Membership/Exception/NoActiveSubTierToRevoke.php`
- Create: `src/Domain/Membership/Exception/DuplicateSubTierSlug.php`
- Create: `src/Domain/Membership/Exception/SubTierInUse.php`
- Create: `src/Domain/Audit/GsaOverride.php`
- Create: `src/Domain/Audit/GsaOverrideId.php`
- Create: `src/Domain/Audit/GsaOverrideAction.php`
- Create: `src/Domain/Audit/GsaOverrideRepositoryInterface.php`

- [ ] **Step 1: Create all 14 exception classes**

Each follows the pattern below — adapt class name and namespace.

`src/Domain/Governance/Exception/BoardNotBootstrapped.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Governance\Exception;

final class BoardNotBootstrapped extends \DomainException {}
```

Repeat the file with the same body for these classes (only class name changes):

- `Daems\Domain\Governance\Exception\BoardAlreadyBootstrapped`
- `Daems\Domain\Governance\Exception\NotABoardMember`
- `Daems\Domain\Governance\Exception\BoardMemberTermExpired`
- `Daems\Domain\Governance\Exception\NotEligibleForFull`
- `Daems\Domain\Governance\Exception\InsufficientQuorum`
- `Daems\Domain\Governance\Exception\DuplicateActiveDelegation`
- `Daems\Domain\Governance\Exception\InvalidBootstrapRoster`
- `Daems\Domain\Governance\Exception\BoardCandidateNotFull`
- `Daems\Domain\Governance\Exception\LastBoardMemberCannotBeRemoved`
- `Daems\Domain\Governance\Exception\GsaOverrideRequiresReason`
- `Daems\Domain\Membership\Exception\ExpulsionHearingNotElapsed`
- `Daems\Domain\Membership\Exception\ExpulsionAlreadyAdvanced`
- `Daems\Domain\Membership\Exception\AppealAlreadyFiled`
- `Daems\Domain\Membership\Exception\NoActiveSubTierToRevoke`
- `Daems\Domain\Membership\Exception\DuplicateSubTierSlug`
- `Daems\Domain\Membership\Exception\SubTierInUse`

(Use the same `final class … extends \DomainException {}` shape in each.)

- [ ] **Step 2: Create the GsaOverride domain types**

`src/Domain/Audit/GsaOverrideId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

use Daems\Domain\Shared\ValueObject\Uuid7Id;

final class GsaOverrideId extends Uuid7Id {}
```

`src/Domain/Audit/GsaOverrideAction.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

enum GsaOverrideAction: string
{
    case ForceApproveBasic = 'force_approve_basic';
}
```

`src/Domain/Audit/GsaOverride.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GsaOverride
{
    public function __construct(
        public readonly GsaOverrideId $id,
        public readonly UserId $gsaUserId,
        public readonly TenantId $tenantId,
        public readonly GsaOverrideAction $action,
        public readonly string $targetId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $performedAt,
    ) {
        if (strlen(trim($reason)) < 10) {
            throw new \Daems\Domain\Governance\Exception\GsaOverrideRequiresReason(
                'GSA override requires a reason of at least 10 characters'
            );
        }
    }
}
```

`src/Domain/Audit/GsaOverrideRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

use Daems\Domain\Tenant\TenantId;

interface GsaOverrideRepositoryInterface
{
    /** @return list<GsaOverride> */
    public function listForTenant(TenantId $tenantId, int $limit = 100): array;

    public function save(GsaOverride $override): void;
}
```

- [ ] **Step 3: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Governance/Exception/ src/Domain/Membership/Exception/ src/Domain/Audit/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain/governance,membership,audit): remaining domain exceptions + GsaOverride entity"
```

---

# Wave C — Repositories (Tasks 14–17)

Each task creates a real SQL repo against MySQL + an InMemory fake for tests + an Integration test (CRUD against `MigrationTestCase`) + an Isolation test (tenant A's data invisible to tenant B's calls). The pattern follows `SqlTenantMembershipSubTierRepository.php` exactly — same hydration shape with `is_string()` / `is_int()` guards.

## Task 14: Board + BoardMember SQL repositories + InMemory + isolation

**Files:**

- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlBoardRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlBoardMemberRepository.php`
- Create: `tests/Support/Fake/InMemoryBoardRepository.php`
- Create: `tests/Support/Fake/InMemoryBoardMemberRepository.php`
- Create: `tests/Integration/Persistence/SqlBoardRepositoryTest.php`
- Create: `tests/Integration/Persistence/SqlBoardMemberRepositoryTest.php`
- Create: `tests/Isolation/BoardIsolationTest.php`

- [ ] **Step 1: Write failing integration test for `SqlBoardRepository`**

`tests/Integration/Persistence/SqlBoardRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlBoardRepositoryTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);
        // seed a tenant + user
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('01958000-0000-7000-8000-aaaaaaaaaaaa', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin)
                          VALUES ('01958000-0000-7000-8000-bbbbbbbbbbbb', 'gsa', 'gsa@test', '1990-01-01', 1)");
    }

    public function test_save_and_find_for_tenant(): void
    {
        $repo  = new SqlBoardRepository($this->pdo);
        $board = new Board(
            id:                   BoardId::fromString('01958000-0000-7000-8000-cccccccccccc'),
            tenantId:             TenantId::fromString('01958000-0000-7000-8000-aaaaaaaaaaaa'),
            bootstrappedByUserId: UserId::fromString('01958000-0000-7000-8000-bbbbbbbbbbbb'),
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12 10:00:00'),
            createdAt:            new \DateTimeImmutable('2026-05-12 10:00:00'),
        );
        $repo->save($board);

        $found = $repo->findForTenant(TenantId::fromString('01958000-0000-7000-8000-aaaaaaaaaaaa'));
        $this->assertNotNull($found);
        $this->assertSame('01958000-0000-7000-8000-cccccccccccc', $found->id->value());
    }

    public function test_find_returns_null_for_unseeded_tenant(): void
    {
        $repo = new SqlBoardRepository($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('01958000-0000-7000-8000-dddddddddddd', 'uu', 'UU')");
        $this->assertNull(
            $repo->findForTenant(TenantId::fromString('01958000-0000-7000-8000-dddddddddddd'))
        );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```bash
composer test -- --testsuite Integration --filter SqlBoardRepositoryTest
```

- [ ] **Step 3: Implement `SqlBoardRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlBoardRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardRepository implements BoardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findForTenant(TenantId $tenantId): ?Board
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, bootstrapped_by_user_id, bootstrapped_at, created_at
               FROM boards
              WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(Board $board): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO boards (id, tenant_id, bootstrapped_by_user_id, bootstrapped_at, created_at)
             VALUES (:id, :tid, :bby, :bat, :cat)
             ON DUPLICATE KEY UPDATE bootstrapped_by_user_id = VALUES(bootstrapped_by_user_id)'
        );
        $stmt->execute([
            ':id'  => $board->id->value(),
            ':tid' => $board->tenantId->value(),
            ':bby' => $board->bootstrappedByUserId->value(),
            ':bat' => $board->bootstrappedAt->format('Y-m-d H:i:s'),
            ':cat' => $board->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): Board
    {
        $id   = is_string($row['id']                      ?? null) ? $row['id']                      : throw new \DomainException('Corrupt boards.id');
        $tid  = is_string($row['tenant_id']               ?? null) ? $row['tenant_id']               : throw new \DomainException('Corrupt boards.tenant_id');
        $bby  = is_string($row['bootstrapped_by_user_id'] ?? null) ? $row['bootstrapped_by_user_id'] : throw new \DomainException('Corrupt boards.bootstrapped_by_user_id');
        $bat  = is_string($row['bootstrapped_at']         ?? null) ? $row['bootstrapped_at']         : throw new \DomainException('Corrupt boards.bootstrapped_at');
        $cat  = is_string($row['created_at']              ?? null) ? $row['created_at']              : throw new \DomainException('Corrupt boards.created_at');

        return new Board(
            id:                   BoardId::fromString($id),
            tenantId:             TenantId::fromString($tid),
            bootstrappedByUserId: UserId::fromString($bby),
            bootstrappedAt:       new \DateTimeImmutable($bat),
            createdAt:            new \DateTimeImmutable($cat),
        );
    }
}
```

- [ ] **Step 4: Implement `SqlBoardMemberRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlBoardMemberRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardMemberTermEndedReason;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardMemberRepository implements BoardMemberRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForBoard(BoardId $boardId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members WHERE board_id = ? ORDER BY role DESC, term_started_at ASC'
        );
        $stmt->execute([$boardId->value()]);
        return $this->fetchAll($stmt);
    }

    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members
              WHERE board_id = ?
                AND term_ended_at IS NULL
                AND term_started_at <= ?
                AND term_ends_at > ?
              ORDER BY role DESC, term_started_at ASC'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$boardId->value(), $ts, $ts]);
        return $this->fetchAll($stmt);
    }

    public function find(BoardMemberId $id): ?BoardMember
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason
               FROM board_members WHERE id = ?'
        );
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(BoardMember $m): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_members
                (id, board_id, user_id, role, term_started_at, term_ends_at, term_ended_at, term_ended_reason)
             VALUES (:id, :bid, :uid, :role, :ts, :te, :ted, :ter)
             ON DUPLICATE KEY UPDATE
                role             = VALUES(role),
                term_ended_at    = VALUES(term_ended_at),
                term_ended_reason= VALUES(term_ended_reason)'
        );
        $stmt->execute([
            ':id'   => $m->id->value(),
            ':bid'  => $m->boardId->value(),
            ':uid'  => $m->userId->value(),
            ':role' => $m->role->value,
            ':ts'   => $m->termStartedAt->format('Y-m-d H:i:s'),
            ':te'   => $m->termEndsAt->format('Y-m-d H:i:s'),
            ':ted'  => $m->termEndedAt?->format('Y-m-d H:i:s'),
            ':ter'  => $m->termEndedReason?->value,
        ]);
    }

    /** @param \PDOStatement $stmt @return list<BoardMember> */
    private function fetchAll(\PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): BoardMember
    {
        $id   = is_string($row['id']              ?? null) ? $row['id']              : throw new \DomainException('Corrupt board_members.id');
        $bid  = is_string($row['board_id']        ?? null) ? $row['board_id']        : throw new \DomainException('Corrupt board_members.board_id');
        $uid  = is_string($row['user_id']         ?? null) ? $row['user_id']         : throw new \DomainException('Corrupt board_members.user_id');
        $role = is_string($row['role']            ?? null) ? $row['role']            : throw new \DomainException('Corrupt board_members.role');
        $ts   = is_string($row['term_started_at'] ?? null) ? $row['term_started_at'] : throw new \DomainException('Corrupt board_members.term_started_at');
        $te   = is_string($row['term_ends_at']    ?? null) ? $row['term_ends_at']    : throw new \DomainException('Corrupt board_members.term_ends_at');
        $ted  = is_string($row['term_ended_at']   ?? null) ? $row['term_ended_at']   : null;
        $ter  = is_string($row['term_ended_reason']?? null) ? $row['term_ended_reason'] : null;

        return new BoardMember(
            id:              BoardMemberId::fromString($id),
            boardId:         BoardId::fromString($bid),
            userId:          UserId::fromString($uid),
            role:            BoardMemberRole::from($role),
            termStartedAt:   new \DateTimeImmutable($ts),
            termEndsAt:      new \DateTimeImmutable($te),
            termEndedAt:     $ted !== null ? new \DateTimeImmutable($ted) : null,
            termEndedReason: $ter !== null ? BoardMemberTermEndedReason::from($ter) : null,
        );
    }
}
```

- [ ] **Step 5: Implement InMemory fakes**

`tests/Support/Fake/InMemoryBoardRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryBoardRepository implements BoardRepositoryInterface
{
    /** @var array<string, Board> keyed by tenant_id */
    private array $byTenant = [];

    public function findForTenant(TenantId $tenantId): ?Board
    {
        return $this->byTenant[$tenantId->value()] ?? null;
    }

    public function save(Board $board): void
    {
        $this->byTenant[$board->tenantId->value()] = $board;
    }
}
```

`tests/Support/Fake/InMemoryBoardMemberRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;

final class InMemoryBoardMemberRepository implements BoardMemberRepositoryInterface
{
    /** @var array<string, BoardMember> keyed by member id */
    private array $byId = [];

    public function listForBoard(BoardId $boardId): array
    {
        $out = [];
        foreach ($this->byId as $m) {
            if ($m->boardId->value() === $boardId->value()) $out[] = $m;
        }
        return $out;
    }

    public function listActiveForBoard(BoardId $boardId, \DateTimeImmutable $at): array
    {
        $out = [];
        foreach ($this->byId as $m) {
            if ($m->boardId->value() === $boardId->value() && $m->isActive($at)) $out[] = $m;
        }
        return $out;
    }

    public function find(BoardMemberId $id): ?BoardMember
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function save(BoardMember $member): void
    {
        $this->byId[$member->id->value()] = $member;
    }
}
```

- [ ] **Step 6: Write `BoardIsolationTest`**

`tests/Isolation/BoardIsolationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardMemberRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;

final class BoardIsolationTest extends IsolationTestCase
{
    public function test_tenant_a_board_invisible_to_tenant_b_lookup(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);

        $repo = new SqlBoardRepository($this->pdo);
        $repo->save(new Board(
            id:                   BoardId::fromString('01958000-0000-7000-8000-cccccccccc01'),
            tenantId:             $daems,
            bootstrappedByUserId: $gsa,
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12'),
            createdAt:            new \DateTimeImmutable('2026-05-12'),
        ));

        $this->assertNotNull($repo->findForTenant($daems));
        $this->assertNull($repo->findForTenant($sahe));
    }

    public function test_board_members_listed_only_for_their_board(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);
        $u1    = $this->seedUser('01958000-0000-7000-8000-ffffffffff01', 'u1@test');
        $u2    = $this->seedUser('01958000-0000-7000-8000-ffffffffff02', 'u2@test');

        $boards = new SqlBoardRepository($this->pdo);
        $members = new SqlBoardMemberRepository($this->pdo);

        $boardA = BoardId::fromString('01958000-0000-7000-8000-ccccccccccaa');
        $boardB = BoardId::fromString('01958000-0000-7000-8000-ccccccccccbb');
        $boards->save(new Board($boardA, $daems, $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));
        $boards->save(new Board($boardB, $sahe,  $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));

        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardA, userId: $u1,
            role: BoardMemberRole::Chair, termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt: new \DateTimeImmutable('2028-01-01'), termEndedAt: null, termEndedReason: null,
        ));
        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardB, userId: $u2,
            role: BoardMemberRole::Chair, termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt: new \DateTimeImmutable('2028-01-01'), termEndedAt: null, termEndedReason: null,
        ));

        $this->assertCount(1, $members->listForBoard($boardA));
        $this->assertCount(1, $members->listForBoard($boardB));
        $this->assertSame($u1->value(), $members->listForBoard($boardA)[0]->userId->value());
    }
}
```

- [ ] **Step 7: Run tests + PHPStan**

```bash
composer test -- --testsuite Integration --filter "SqlBoardRepositoryTest|SqlBoardMemberRepositoryTest"
composer test -- --testsuite Isolation  --filter BoardIsolationTest
composer analyse
```

Expected: all pass; PHPStan 0.

- [ ] **Step 8: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlBoard*.php tests/Support/Fake/InMemoryBoard*.php tests/Integration/Persistence/SqlBoard*Test.php tests/Isolation/BoardIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/persistence): SQL+InMemory repos for Board + BoardMember + isolation test"
```

---

## Task 15: BoardDecision + Votes + Delegation + GovernanceSettings repos

**Files:**

- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDecisionRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDecisionVoteRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDelegationRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php`
- Create: `tests/Support/Fake/InMemoryBoardDecisionRepository.php`
- Create: `tests/Support/Fake/InMemoryBoardDecisionVoteRepository.php`
- Create: `tests/Support/Fake/InMemoryBoardDelegationRepository.php`
- Create: `tests/Support/Fake/InMemoryTenantGovernanceSettingsRepository.php`
- Create: `tests/Integration/Persistence/SqlBoardDecisionRepositoryTest.php`
- Create: `tests/Integration/Persistence/SqlBoardDelegationRepositoryTest.php`
- Create: `tests/Isolation/BoardDecisionIsolationTest.php`
- Create: `tests/Isolation/BoardDelegationIsolationTest.php`

- [ ] **Step 1: Implement `SqlBoardDecisionRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDecisionRepository.php`:

The implementation follows the same hydrate-with-guards pattern as `SqlBoardMemberRepository` (Task 14). Key SQL:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionSubTierCrudOperation;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlBoardDecisionRepository implements BoardDecisionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(BoardDecisionId $id): ?BoardDecision
    {
        $stmt = $this->pdo->prepare('SELECT * FROM board_decisions WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listForBoard(BoardId $boardId, ?BoardDecisionStatus $status = null, ?BoardDecisionType $type = null): array
    {
        $sql    = 'SELECT * FROM board_decisions WHERE board_id = ?';
        $params = [$boardId->value()];
        if ($status !== null) { $sql .= ' AND status = ?';        $params[] = $status->value; }
        if ($type   !== null) { $sql .= ' AND decision_type = ?'; $params[] = $type->value;   }
        $sql .= ' ORDER BY proposed_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function listExpiredPending(\DateTimeImmutable $at, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM board_decisions
              WHERE status = 'pending' AND expires_at < ?
              ORDER BY expires_at ASC
              LIMIT {$limit}"
        );
        $stmt->execute([$at->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) $out[] = $this->hydrate($row);
        }
        return $out;
    }

    public function save(BoardDecision $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_decisions
                (id, board_id, decision_type, threshold, mode, vote_visibility, status,
                 proposed_by_user_id, proposed_at, expires_at, resolved_at,
                 meeting_reference, withdrawal_reason, via_delegation, delegation_id,
                 payload_target_user_id, payload_application_id, payload_sub_tier_slug,
                 payload_sub_tier_name, payload_sub_tier_rank, payload_sub_tier_applies_to,
                 payload_sub_tier_operation, payload_board_member_id, payload_delegation_type,
                 payload_delegated_to_role, payload_delegation_revoke_id, payload_reason)
             VALUES
                (:id, :bid, :dt, :th, :mo, :vv, :st,
                 :pby, :pat, :exp, :rat,
                 :mr, :wr, :vd, :did,
                 :ptu, :pai, :pss,
                 :psn, :psr, :psa,
                 :pso, :pbm, :pdt,
                 :pdr, :pdri, :prn)
             ON DUPLICATE KEY UPDATE
                status            = VALUES(status),
                resolved_at       = VALUES(resolved_at),
                withdrawal_reason = VALUES(withdrawal_reason)'
        );
        $stmt->execute([
            ':id'   => $d->id->value(),
            ':bid'  => $d->boardId->value(),
            ':dt'   => $d->decisionType->value,
            ':th'   => $d->threshold->value,
            ':mo'   => $d->mode->value,
            ':vv'   => $d->voteVisibility->value,
            ':st'   => $d->status->value,
            ':pby'  => $d->proposedByUserId->value(),
            ':pat'  => $d->proposedAt->format('Y-m-d H:i:s'),
            ':exp'  => $d->expiresAt->format('Y-m-d H:i:s'),
            ':rat'  => $d->resolvedAt?->format('Y-m-d H:i:s'),
            ':mr'   => $d->meetingReference,
            ':wr'   => $d->withdrawalReason,
            ':vd'   => $d->viaDelegation ? 1 : 0,
            ':did'  => $d->delegationId?->value(),
            ':ptu'  => $d->payloadTargetUserId?->value(),
            ':pai'  => $d->payloadApplicationId,
            ':pss'  => $d->payloadSubTierSlug,
            ':psn'  => $d->payloadSubTierName,
            ':psr'  => $d->payloadSubTierRank,
            ':psa'  => $d->payloadSubTierAppliesTo,
            ':pso'  => $d->payloadSubTierOperation?->value,
            ':pbm'  => $d->payloadBoardMemberId?->value(),
            ':pdt'  => $d->payloadDelegationType?->value,
            ':pdr'  => $d->payloadDelegatedToRole,
            ':pdri' => $d->payloadDelegationRevokeId?->value(),
            ':prn'  => $d->payloadReason,
        ]);
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): BoardDecision
    {
        $s = static fn(string $k): ?string => is_string($row[$k] ?? null) ? $row[$k] : null;
        $r = static fn(string $k): string  => is_string($row[$k] ?? null) ? $row[$k] : throw new \DomainException("Corrupt board_decisions.{$k}");
        $i = static fn(string $k): ?int    => is_int($row[$k] ?? null) ? $row[$k] : (is_string($row[$k] ?? null) && ctype_digit($row[$k]) ? (int) $row[$k] : null);

        return new BoardDecision(
            id:               BoardDecisionId::fromString($r('id')),
            boardId:          BoardId::fromString($r('board_id')),
            decisionType:     BoardDecisionType::from($r('decision_type')),
            threshold:        BoardDecisionThreshold::from($r('threshold')),
            mode:             BoardDecisionMode::from($r('mode')),
            voteVisibility:   BoardDecisionVoteVisibility::from($r('vote_visibility')),
            status:           BoardDecisionStatus::from($r('status')),
            proposedByUserId: UserId::fromString($r('proposed_by_user_id')),
            proposedAt:       new \DateTimeImmutable($r('proposed_at')),
            expiresAt:        new \DateTimeImmutable($r('expires_at')),
            resolvedAt:       $s('resolved_at') !== null ? new \DateTimeImmutable((string) $s('resolved_at')) : null,
            meetingReference: $s('meeting_reference'),
            withdrawalReason: $s('withdrawal_reason'),
            viaDelegation:    ((int) ($row['via_delegation'] ?? 0)) === 1,
            delegationId:     $s('delegation_id') !== null ? BoardDelegationId::fromString((string) $s('delegation_id')) : null,
            payloadTargetUserId:      $s('payload_target_user_id')  !== null ? UserId::fromString((string) $s('payload_target_user_id')) : null,
            payloadApplicationId:     $s('payload_application_id'),
            payloadSubTierSlug:       $s('payload_sub_tier_slug'),
            payloadSubTierName:       $s('payload_sub_tier_name'),
            payloadSubTierRank:       $i('payload_sub_tier_rank'),
            payloadSubTierAppliesTo:  $s('payload_sub_tier_applies_to'),
            payloadSubTierOperation:  $s('payload_sub_tier_operation') !== null ? BoardDecisionSubTierCrudOperation::from((string) $s('payload_sub_tier_operation')) : null,
            payloadBoardMemberId:     $s('payload_board_member_id') !== null ? BoardMemberId::fromString((string) $s('payload_board_member_id')) : null,
            payloadDelegationType:    $s('payload_delegation_type') !== null ? BoardDecisionType::from((string) $s('payload_delegation_type')) : null,
            payloadDelegatedToRole:   $s('payload_delegated_to_role'),
            payloadDelegationRevokeId:$s('payload_delegation_revoke_id') !== null ? BoardDelegationId::fromString((string) $s('payload_delegation_revoke_id')) : null,
            payloadReason:            $s('payload_reason'),
        );
    }
}
```

- [ ] **Step 2: Implement `SqlBoardDecisionVoteRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDecisionVoteRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionVote;
use Daems\Domain\Governance\BoardDecisionVoteId;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberId;
use PDO;

final class SqlBoardDecisionVoteRepository implements BoardDecisionVoteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForDecision(BoardDecisionId $decisionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, decision_id, board_member_id, vote, cast_at
               FROM board_decision_votes WHERE decision_id = ? ORDER BY cast_at ASC'
        );
        $stmt->execute([$decisionId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function findByMember(BoardDecisionId $decisionId, BoardMemberId $memberId): ?BoardDecisionVote
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, decision_id, board_member_id, vote, cast_at
               FROM board_decision_votes WHERE decision_id = ? AND board_member_id = ?'
        );
        $stmt->execute([$decisionId->value(), $memberId->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function upsert(BoardDecisionVote $vote): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_decision_votes (id, decision_id, board_member_id, vote, cast_at)
             VALUES (:id, :did, :bm, :v, :ca)
             ON DUPLICATE KEY UPDATE
                vote    = VALUES(vote),
                cast_at = VALUES(cast_at)'
        );
        $stmt->execute([
            ':id'  => $vote->id->value(),
            ':did' => $vote->decisionId->value(),
            ':bm'  => $vote->boardMemberId->value(),
            ':v'   => $vote->vote->value,
            ':ca'  => $vote->castAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): BoardDecisionVote
    {
        $g = static fn(string $k): string => is_string($r[$k] ?? null) ? $r[$k] : throw new \DomainException("Corrupt board_decision_votes.{$k}");
        return new BoardDecisionVote(
            id:            BoardDecisionVoteId::fromString($g('id')),
            decisionId:    BoardDecisionId::fromString($g('decision_id')),
            boardMemberId: BoardMemberId::fromString($g('board_member_id')),
            vote:          BoardDecisionVoteValue::from($g('vote')),
            castAt:        new \DateTimeImmutable($g('cast_at')),
        );
    }
}
```

- [ ] **Step 3: Implement `SqlBoardDelegationRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDelegationRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use PDO;

final class SqlBoardDelegationRepository implements BoardDelegationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActive(TenantId $tenantId, BoardDecisionType $decisionType, UserTenantRole $delegatedToRole, \DateTimeImmutable $at): ?BoardDelegation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_delegations
              WHERE tenant_id = ? AND decision_type = ? AND delegated_to_role = ?
                AND valid_from <= ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $decisionType->value, $delegatedToRole->value, $ts, $ts]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listActive(TenantId $tenantId, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_delegations
              WHERE tenant_id = ?
                AND valid_from <= ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY valid_from DESC'
        );
        $ts = $at->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $ts, $ts]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function find(BoardDelegationId $id): ?BoardDelegation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM board_delegations WHERE id = ?');
        $stmt->execute([$id->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function save(BoardDelegation $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO board_delegations
                (id, tenant_id, decision_type, delegated_to_role, source_decision_id, valid_from, revoked_at)
             VALUES (:id, :tid, :dt, :role, :src, :vf, :rev)
             ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at)'
        );
        $stmt->execute([
            ':id'   => $d->id->value(),
            ':tid'  => $d->tenantId->value(),
            ':dt'   => $d->decisionType->value,
            ':role' => $d->delegatedToRole->value,
            ':src'  => $d->sourceDecisionId->value(),
            ':vf'   => $d->validFrom->format('Y-m-d H:i:s'),
            ':rev'  => $d->revokedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): BoardDelegation
    {
        $g = static fn(string $k): string => is_string($r[$k] ?? null) ? $r[$k] : throw new \DomainException("Corrupt board_delegations.{$k}");
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;
        return new BoardDelegation(
            id:                BoardDelegationId::fromString($g('id')),
            tenantId:          TenantId::fromString($g('tenant_id')),
            decisionType:      BoardDecisionType::from($g('decision_type')),
            delegatedToRole:   UserTenantRole::from($g('delegated_to_role')),
            sourceDecisionId:  BoardDecisionId::fromString($g('source_decision_id')),
            validFrom:         new \DateTimeImmutable($g('valid_from')),
            revokedAt:         $opt('revoked_at') !== null ? new \DateTimeImmutable((string) $opt('revoked_at')) : null,
        );
    }
}
```

- [ ] **Step 4: Implement `SqlTenantGovernanceSettingsRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PDO;

final class SqlTenantGovernanceSettingsRepository implements TenantGovernanceSettingsRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(TenantId $tenantId): ?TenantGovernanceSettings
    {
        $stmt = $this->pdo->prepare(
            'SELECT tenant_id, expulsion_hearing_days, decision_expiration_days
               FROM tenant_governance_settings WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return null;
        $tid = is_string($r['tenant_id'] ?? null) ? $r['tenant_id'] : throw new \DomainException('Corrupt tenant_governance_settings.tenant_id');
        $eh  = is_int($r['expulsion_hearing_days']   ?? null) ? $r['expulsion_hearing_days']   : (int) (string) ($r['expulsion_hearing_days']   ?? 14);
        $de  = is_int($r['decision_expiration_days'] ?? null) ? $r['decision_expiration_days'] : (int) (string) ($r['decision_expiration_days'] ?? 60);
        return new TenantGovernanceSettings(TenantId::fromString($tid), $eh, $de);
    }

    public function save(TenantGovernanceSettings $s): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_governance_settings (tenant_id, expulsion_hearing_days, decision_expiration_days)
             VALUES (:tid, :eh, :de)
             ON DUPLICATE KEY UPDATE
                expulsion_hearing_days   = VALUES(expulsion_hearing_days),
                decision_expiration_days = VALUES(decision_expiration_days)'
        );
        $stmt->execute([
            ':tid' => $s->tenantId->value(),
            ':eh'  => $s->expulsionHearingDays,
            ':de'  => $s->decisionExpirationDays,
        ]);
    }
}
```

- [ ] **Step 5: Implement the 4 InMemory fakes**

Follow the exact pattern from `InMemoryBoardRepository` (Task 14). For `InMemoryBoardDecisionRepository`, keep decisions by id and filter `listForBoard`/`listExpiredPending` in PHP. For `InMemoryBoardDecisionVoteRepository`, key by `decision_id . '|' . board_member_id` to enforce uniqueness. For `InMemoryBoardDelegationRepository`, key by id and filter activity via `BoardDelegation::isActive($at)`. For `InMemoryTenantGovernanceSettingsRepository`, key by tenant id with a `defaults` fallback of (14, 60).

(File bodies are mechanical — write them and verify the interfaces compile.)

- [ ] **Step 6: Write Integration + Isolation tests**

Integration: `tests/Integration/Persistence/SqlBoardDecisionRepositoryTest.php` exercises save → find → listForBoard with status/type filters → listExpiredPending. Use the same seeding boilerplate as Task 14 (insert a tenant + user + board first).

Isolation: `tests/Isolation/BoardDecisionIsolationTest.php` + `tests/Isolation/BoardDelegationIsolationTest.php` follow the BoardIsolationTest pattern — two boards under different tenants, save a decision/delegation to each, assert the other tenant's repo calls do not surface it.

(Use `IsolationTestCase::tenantId('daems')` / `'sahegroup'` to get TenantIds; seed a `boards` row first since FK requires it.)

- [ ] **Step 7: Run all + PHPStan**

```bash
composer test -- --testsuite Integration --filter "SqlBoardDecisionRepositoryTest|SqlBoardDelegationRepositoryTest"
composer test -- --testsuite Isolation  --filter "BoardDecisionIsolationTest|BoardDelegationIsolationTest"
composer analyse
```

- [ ] **Step 8: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDecision*.php src/Infrastructure/Adapter/Persistence/Sql/SqlBoardDelegation*.php src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php tests/Support/Fake/InMemoryBoardDecision*.php tests/Support/Fake/InMemoryBoardDelegation*.php tests/Support/Fake/InMemoryTenantGovernanceSettingsRepository.php tests/Integration/Persistence/SqlBoardDecisionRepositoryTest.php tests/Integration/Persistence/SqlBoardDelegationRepositoryTest.php tests/Isolation/BoardDecisionIsolationTest.php tests/Isolation/BoardDelegationIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/persistence): SQL+InMemory repos for BoardDecision, Vote, Delegation, TenantGovernanceSettings + isolation"
```

---

## Task 16: MemberExpulsion + MemberSubTierAward repos

**Files:**

- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberExpulsionRepository.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberSubTierAwardRepository.php`
- Create: `tests/Support/Fake/InMemoryMemberExpulsionRepository.php`
- Create: `tests/Support/Fake/InMemoryMemberSubTierAwardRepository.php`
- Create: `tests/Integration/Persistence/SqlMemberExpulsionRepositoryTest.php`
- Create: `tests/Integration/Persistence/SqlMemberSubTierAwardRepositoryTest.php`
- Create: `tests/Isolation/MemberExpulsionIsolationTest.php`
- Create: `tests/Isolation/MemberSubTierAwardIsolationTest.php`

- [ ] **Step 1: Implement SqlMemberExpulsionRepository**

Same hydration pattern as before. Methods: `find`, `listForTenant(TenantId, ?MemberExpulsionStatus)`, `save`. Status filter is applied as `AND status = ?` when non-null.

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlMemberExpulsionRepository implements MemberExpulsionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(MemberExpulsionId $id): ?MemberExpulsion
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_expulsions WHERE id = ?');
        $stmt->execute([$id->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listForTenant(TenantId $tenantId, ?MemberExpulsionStatus $status = null): array
    {
        $sql = 'SELECT * FROM member_expulsions WHERE tenant_id = ?';
        $params = [$tenantId->value()];
        if ($status !== null) { $sql .= ' AND status = ?'; $params[] = $status->value; }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(MemberExpulsion $e): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_expulsions
                (id, tenant_id, target_user_id, proposed_by_user_id, reason,
                 hearing_deadline_at, statement_text, statement_received_at,
                 decision_id, decided_at, expelled_at, appeal_filed_at, appeal_text,
                 status, created_at)
             VALUES (:id, :tid, :tu, :pby, :rsn,
                     :hd, :st, :sr,
                     :did, :da, :ea, :af, :at,
                     :status, :cat)
             ON DUPLICATE KEY UPDATE
                statement_text       = VALUES(statement_text),
                statement_received_at= VALUES(statement_received_at),
                decision_id          = VALUES(decision_id),
                decided_at           = VALUES(decided_at),
                expelled_at          = VALUES(expelled_at),
                appeal_filed_at      = VALUES(appeal_filed_at),
                appeal_text          = VALUES(appeal_text),
                status               = VALUES(status)'
        );
        $stmt->execute([
            ':id'     => $e->id->value(),
            ':tid'    => $e->tenantId->value(),
            ':tu'     => $e->targetUserId->value(),
            ':pby'    => $e->proposedByUserId->value(),
            ':rsn'    => $e->reason,
            ':hd'     => $e->hearingDeadlineAt->format('Y-m-d H:i:s'),
            ':st'     => $e->statementText,
            ':sr'     => $e->statementReceivedAt?->format('Y-m-d H:i:s'),
            ':did'    => $e->decisionId?->value(),
            ':da'     => $e->decidedAt?->format('Y-m-d H:i:s'),
            ':ea'     => $e->expelledAt?->format('Y-m-d H:i:s'),
            ':af'     => $e->appealFiledAt?->format('Y-m-d H:i:s'),
            ':at'     => $e->appealText,
            ':status' => $e->status->value,
            ':cat'    => $e->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): MemberExpulsion
    {
        $g   = static fn(string $k): string => is_string($r[$k] ?? null) ? $r[$k] : throw new \DomainException("Corrupt member_expulsions.{$k}");
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;
        return new MemberExpulsion(
            id:                  MemberExpulsionId::fromString($g('id')),
            tenantId:            TenantId::fromString($g('tenant_id')),
            targetUserId:        UserId::fromString($g('target_user_id')),
            proposedByUserId:    UserId::fromString($g('proposed_by_user_id')),
            reason:              $g('reason'),
            hearingDeadlineAt:   new \DateTimeImmutable($g('hearing_deadline_at')),
            statementText:       $opt('statement_text'),
            statementReceivedAt: $opt('statement_received_at') !== null ? new \DateTimeImmutable((string) $opt('statement_received_at')) : null,
            decisionId:          $opt('decision_id') !== null ? BoardDecisionId::fromString((string) $opt('decision_id')) : null,
            decidedAt:           $opt('decided_at')  !== null ? new \DateTimeImmutable((string) $opt('decided_at')) : null,
            expelledAt:          $opt('expelled_at') !== null ? new \DateTimeImmutable((string) $opt('expelled_at')) : null,
            appealFiledAt:       $opt('appeal_filed_at') !== null ? new \DateTimeImmutable((string) $opt('appeal_filed_at')) : null,
            appealText:          $opt('appeal_text'),
            status:              MemberExpulsionStatus::from($g('status')),
            createdAt:           new \DateTimeImmutable($g('created_at')),
        );
    }
}
```

- [ ] **Step 2: Implement SqlMemberSubTierAwardRepository**

`src/Infrastructure/Adapter/Persistence/Sql/SqlMemberSubTierAwardRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardId;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlMemberSubTierAwardRepository implements MemberSubTierAwardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActive(TenantId $tenantId, UserId $userId, \DateTimeImmutable $at): ?MemberSubTierAward
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND user_id = ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY awarded_at DESC LIMIT 1'
        );
        $stmt->execute([$tenantId->value(), $userId->value(), $at->format('Y-m-d H:i:s')]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function listForUser(TenantId $tenantId, UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND user_id = ?
              ORDER BY awarded_at DESC'
        );
        $stmt->execute([$tenantId->value(), $userId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function listActiveForSubTierSlug(TenantId $tenantId, string $subTierSlug, \DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_sub_tier_awards
              WHERE tenant_id = ? AND sub_tier_slug = ?
                AND (revoked_at IS NULL OR revoked_at > ?)
              ORDER BY awarded_at DESC'
        );
        $stmt->execute([$tenantId->value(), $subTierSlug, $at->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(MemberSubTierAward $a): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_sub_tier_awards
                (id, tenant_id, user_id, sub_tier_slug, decision_id, awarded_at, revoked_at, revoke_decision_id)
             VALUES (:id, :tid, :uid, :slug, :did, :aa, :ra, :rdi)
             ON DUPLICATE KEY UPDATE
                revoked_at         = VALUES(revoked_at),
                revoke_decision_id = VALUES(revoke_decision_id)'
        );
        $stmt->execute([
            ':id'   => $a->id->value(),
            ':tid'  => $a->tenantId->value(),
            ':uid'  => $a->userId->value(),
            ':slug' => $a->subTierSlug,
            ':did'  => $a->decisionId->value(),
            ':aa'   => $a->awardedAt->format('Y-m-d H:i:s'),
            ':ra'   => $a->revokedAt?->format('Y-m-d H:i:s'),
            ':rdi'  => $a->revokeDecisionId?->value(),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): MemberSubTierAward
    {
        $g   = static fn(string $k): string => is_string($r[$k] ?? null) ? $r[$k] : throw new \DomainException("Corrupt member_sub_tier_awards.{$k}");
        $opt = static fn(string $k): ?string => is_string($r[$k] ?? null) ? $r[$k] : null;
        return new MemberSubTierAward(
            id:               MemberSubTierAwardId::fromString($g('id')),
            tenantId:         TenantId::fromString($g('tenant_id')),
            userId:           UserId::fromString($g('user_id')),
            subTierSlug:      $g('sub_tier_slug'),
            decisionId:       BoardDecisionId::fromString($g('decision_id')),
            awardedAt:        new \DateTimeImmutable($g('awarded_at')),
            revokedAt:        $opt('revoked_at') !== null ? new \DateTimeImmutable((string) $opt('revoked_at')) : null,
            revokeDecisionId: $opt('revoke_decision_id') !== null ? BoardDecisionId::fromString((string) $opt('revoke_decision_id')) : null,
        );
    }
}
```

- [ ] **Step 3: Implement the 2 InMemory fakes**

Each keys by id, with `findActive` filtering on `tenant_id` + `user_id` + `revoked_at IS NULL OR revoked_at > $at`. Same hash-map pattern as before.

- [ ] **Step 4: Write Integration + Isolation tests**

Pattern matches Task 14/15. Each Integration test seeds a tenant + user (+ board + decision for award FK) then exercises save → find → list. Isolation tests use the two seeded tenants.

- [ ] **Step 5: Run all + PHPStan + commit**

```bash
composer test -- --testsuite Integration --filter "SqlMemberExpulsionRepositoryTest|SqlMemberSubTierAwardRepositoryTest"
composer test -- --testsuite Isolation  --filter "MemberExpulsionIsolationTest|MemberSubTierAwardIsolationTest"
composer analyse
git add src/Infrastructure/Adapter/Persistence/Sql/SqlMember*.php tests/Support/Fake/InMemoryMember*.php tests/Integration/Persistence/SqlMember*RepositoryTest.php tests/Isolation/Member*IsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/persistence): SQL+InMemory repos for MemberExpulsion + MemberSubTierAward + isolation"
```

---

## Task 17: GsaOverride repo

**Files:**

- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlGsaOverrideRepository.php`
- Create: `tests/Support/Fake/InMemoryGsaOverrideRepository.php`
- Create: `tests/Integration/Persistence/SqlGsaOverrideRepositoryTest.php`
- Create: `tests/Isolation/GsaOverrideIsolationTest.php`

- [ ] **Step 1: Implement `SqlGsaOverrideRepository`**

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlGsaOverrideRepository implements GsaOverrideRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gsa_overrides WHERE tenant_id = ? ORDER BY performed_at DESC LIMIT {$limit}"
        );
        $stmt->execute([$tenantId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_array($r)) $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function save(GsaOverride $o): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gsa_overrides (id, gsa_user_id, tenant_id, action, target_id, reason, performed_at)
             VALUES (:id, :gsa, :tid, :act, :tgt, :rsn, :pa)'
        );
        $stmt->execute([
            ':id'  => $o->id->value(),
            ':gsa' => $o->gsaUserId->value(),
            ':tid' => $o->tenantId->value(),
            ':act' => $o->action->value,
            ':tgt' => $o->targetId,
            ':rsn' => $o->reason,
            ':pa'  => $o->performedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<mixed,mixed> $r */
    private function hydrate(array $r): GsaOverride
    {
        $g = static fn(string $k): string => is_string($r[$k] ?? null) ? $r[$k] : throw new \DomainException("Corrupt gsa_overrides.{$k}");
        return new GsaOverride(
            id:          GsaOverrideId::fromString($g('id')),
            gsaUserId:   UserId::fromString($g('gsa_user_id')),
            tenantId:    TenantId::fromString($g('tenant_id')),
            action:      GsaOverrideAction::from($g('action')),
            targetId:    $g('target_id'),
            reason:      $g('reason'),
            performedAt: new \DateTimeImmutable($g('performed_at')),
        );
    }
}
```

- [ ] **Step 2: InMemory + tests + commit**

InMemory: store by id, filter by tenant_id. Integration: save → listForTenant assertion. Isolation: tenant A's override invisible to tenant B's call. PHPStan green.

```bash
composer test -- --testsuite Integration --filter SqlGsaOverrideRepositoryTest
composer test -- --testsuite Isolation  --filter GsaOverrideIsolationTest
composer analyse
git add src/Infrastructure/Adapter/Persistence/Sql/SqlGsaOverrideRepository.php tests/Support/Fake/InMemoryGsaOverrideRepository.php tests/Integration/Persistence/SqlGsaOverrideRepositoryTest.php tests/Isolation/GsaOverrideIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/persistence): SQL+InMemory repo for GsaOverride + isolation"
```

---

# Wave D — Decision engine core (Tasks 18–20)

The three building blocks every Propose-use-case in Wave E will use: the resolution service that decides Pending → Passed | Rejected | Pending, the vote-cast use case, and the cron that expires stale decisions.

## Task 18: `BoardDecisionResolutionService` + unit tests (the math)

**Files:**

- Create: `src/Application/Governance/BoardDecisionResolutionService.php`
- Create: `tests/Unit/Application/Governance/BoardDecisionResolutionServiceTest.php`

- [ ] **Step 1: Write the failing test (matrix of cases)**

`tests/Unit/Application/Governance/BoardDecisionResolutionServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BoardDecisionResolutionService;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRole;
use PHPUnit\Framework\TestCase;

final class BoardDecisionResolutionServiceTest extends TestCase
{
    private BoardDecisionResolutionService $svc;
    protected function setUp(): void { $this->svc = new BoardDecisionResolutionService(); }

    /**
     * Active board roster passed as list of [role, vote|null].
     * vote=null means the member has not yet voted.
     *
     * @param list<array{role:BoardMemberRole, vote:?BoardDecisionVoteValue}> $roster
     */
    private function resolve(BoardDecisionThreshold $t, array $roster): BoardDecisionStatus
    {
        $active = count($roster);
        $tally  = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        $chairVoted = false;
        foreach ($roster as $m) {
            if ($m['vote'] === null) continue;
            $tally[$m['vote']->value]++;
            if ($m['role'] === BoardMemberRole::Chair) $chairVoted = true;
        }
        return $this->svc->resolve(
            threshold:    $t,
            activeCount:  $active,
            yes:          $tally['yes'],
            no:           $tally['no'],
            abstain:      $tally['abstain'],
            chairVoted:   $chairVoted,
        );
    }

    public function test_unanimous_passes_when_all_voted_yes(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
        ];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_rejects_on_any_no(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_rejects_on_any_abstain(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Abstain],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_unanimous_pending_when_not_everyone_voted_yet(): void
    {
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Unanimous, $r));
    }

    public function test_majority_passes_when_strict_majority_yes_and_quorum_met_with_chair(): void
    {
        // active=5, yes=3 ≥ strict_majority=3, chair voted, voted=3 ≥ ceil(5/2)=3
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_pending_when_no_chair_vote_yet(): void
    {
        // yes=3 of 5, but chair has not voted → quorum not met → still pending
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_rejects_when_max_possible_yes_below_threshold(): void
    {
        // active=5, votes=[no, no, no], strict_majority=3, max_possible_yes=2 → reject
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::No],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Rejected, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_pending_when_yes_short_but_more_could_come(): void
    {
        // active=4, votes=[chair yes, member yes], strict_majority=3, max_possible_yes=4 → pending
        $r = [
            ['role' => BoardMemberRole::Chair,  'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => BoardDecisionVoteValue::Yes],
            ['role' => BoardMemberRole::Member, 'vote' => null],
            ['role' => BoardMemberRole::Member, 'vote' => null],
        ];
        $this->assertSame(BoardDecisionStatus::Pending, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }

    public function test_majority_size_one_passes_with_chair_yes(): void
    {
        $r = [['role' => BoardMemberRole::Chair, 'vote' => BoardDecisionVoteValue::Yes]];
        $this->assertSame(BoardDecisionStatus::Passed, $this->resolve(BoardDecisionThreshold::Majority, $r));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
composer test -- --filter BoardDecisionResolutionServiceTest
```

- [ ] **Step 3: Implement the service**

`src/Application/Governance/BoardDecisionResolutionService.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;

/**
 * Pure decision-resolution math. Inputs are aggregate counts of active board
 * members + their cast votes + whether the chair voted. No PDO, no domain
 * objects — just numbers in, status out. Easy to test exhaustively.
 *
 * Logic per spec § "Decision-lifecycle (state machine)":
 *
 * Unanimous:
 *   no > 0 or abstain > 0  → Rejected
 *   yes == active          → Passed
 *   else                   → Pending
 *
 * Majority:
 *   strict_majority = floor(active/2) + 1
 *   max_possible_yes = (active - voted) + yes
 *   if quorum_met AND yes >= strict_majority  → Passed
 *   if max_possible_yes < strict_majority      → Rejected
 *   else                                        → Pending
 *
 * Quorum (Majority only): chair has voted AND voted >= ceil(active/2).
 * For async + unanimous, every active member must vote yes — quorum implicit.
 */
final class BoardDecisionResolutionService
{
    public function resolve(
        BoardDecisionThreshold $threshold,
        int $activeCount,
        int $yes,
        int $no,
        int $abstain,
        bool $chairVoted,
    ): BoardDecisionStatus {
        if ($activeCount <= 0) {
            return BoardDecisionStatus::Pending;
        }
        $voted = $yes + $no + $abstain;

        if ($threshold === BoardDecisionThreshold::Unanimous) {
            if ($no > 0 || $abstain > 0)  return BoardDecisionStatus::Rejected;
            if ($yes === $activeCount)    return BoardDecisionStatus::Passed;
            return BoardDecisionStatus::Pending;
        }

        // Majority
        $strictMajority  = intdiv($activeCount, 2) + 1;
        $maxPossibleYes  = ($activeCount - $voted) + $yes;
        $quorumNeeded    = (int) ceil($activeCount / 2);
        $quorumMet       = $chairVoted && $voted >= $quorumNeeded;

        if ($quorumMet && $yes >= $strictMajority) {
            return BoardDecisionStatus::Passed;
        }
        if ($maxPossibleYes < $strictMajority) {
            return BoardDecisionStatus::Rejected;
        }
        return BoardDecisionStatus::Pending;
    }
}
```

- [ ] **Step 4: Run + PHPStan + commit**

```bash
composer test -- --filter BoardDecisionResolutionServiceTest
composer analyse
git add src/Application/Governance/BoardDecisionResolutionService.php tests/Unit/Application/Governance/BoardDecisionResolutionServiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): BoardDecisionResolutionService — § 7 quorum + threshold math"
```

---

## Task 19: `CastBoardVote` + `WithdrawBoardDecision` + `ResolveBoardDecisionIfReady`

**Files:**

- Create: `src/Application/Governance/CastBoardVote.php`
- Create: `src/Application/Governance/WithdrawBoardDecision.php`
- Create: `src/Application/Governance/ResolveBoardDecisionIfReady.php`
- Create: `src/Application/Governance/BoardDecisionExecutorInterface.php`
- Create: `src/Application/Governance/BoardDecisionExecutorRegistry.php`
- Create: `tests/Unit/Application/Governance/CastBoardVoteTest.php`
- Create: `tests/Unit/Application/Governance/WithdrawBoardDecisionTest.php`

- [ ] **Step 1: Create the executor interface + registry first (consumers)**

`src/Application/Governance/BoardDecisionExecutorInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;

interface BoardDecisionExecutorInterface
{
    public function decisionType(): BoardDecisionType;

    public function execute(BoardDecision $decision, \DateTimeImmutable $at): void;
}
```

`src/Application/Governance/BoardDecisionExecutorRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionType;

final class BoardDecisionExecutorRegistry
{
    /** @var array<string, BoardDecisionExecutorInterface> keyed by decision_type value */
    private array $byType = [];

    public function register(BoardDecisionExecutorInterface $executor): void
    {
        $this->byType[$executor->decisionType()->value] = $executor;
    }

    public function for(BoardDecisionType $type): BoardDecisionExecutorInterface
    {
        return $this->byType[$type->value]
            ?? throw new \RuntimeException("No executor registered for decision_type {$type->value}");
    }
}
```

- [ ] **Step 2: Write failing test for `CastBoardVote`**

`tests/Unit/Application/Governance/CastBoardVoteTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BoardDecisionExecutorRegistry;
use Daems\Application\Governance\BoardDecisionResolutionService;
use Daems\Application\Governance\CastBoardVote;
use Daems\Application\Governance\ResolveBoardDecisionIfReady;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\BoardMemberTermExpired;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionVoteRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use PHPUnit\Framework\TestCase;

final class CastBoardVoteTest extends TestCase
{
    private function harness(): array
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $votes     = new InMemoryBoardDecisionVoteRepository();
        $members   = new InMemoryBoardMemberRepository();
        $resolve   = new ResolveBoardDecisionIfReady(
            $decisions, $votes, $members,
            new BoardDecisionResolutionService(),
            new BoardDecisionExecutorRegistry(),
        );
        $useCase = new CastBoardVote($decisions, $votes, $members, $resolve);
        return [$useCase, $decisions, $votes, $members];
    }

    public function test_rejects_non_board_member(): void
    {
        [$uc, $decisions, $votes, $members] = $this->harness();

        $boardId    = BoardId::generate();
        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $this->expectException(NotABoardMember::class);
        $uc->execute(
            decisionId:   $decisionId,
            actingUserId: UserId::generate(),  // never seeded as a board_members row
            vote:         BoardDecisionVoteValue::Yes,
            at:           new \DateTimeImmutable('2026-05-13'),
        );
    }

    public function test_rejects_when_decision_already_resolved(): void
    {
        [$uc, $decisions, $votes, $members] = $this->harness();
        $boardId  = BoardId::generate();
        $user     = UserId::generate();
        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardId, userId: $user,
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));

        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Passed,   // already resolved
            proposedByUserId: $user,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: new \DateTimeImmutable('2026-05-13'),
            meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $this->expectException(DecisionAlreadyResolved::class);
        $uc->execute($decisionId, $user, BoardDecisionVoteValue::Yes, new \DateTimeImmutable('2026-05-13'));
    }

    public function test_unanimous_decision_passes_when_single_chair_votes_yes(): void
    {
        [$uc, $decisions, $votes, $members] = $this->harness();
        $boardId = BoardId::generate();
        $chair   = UserId::generate();
        $members->save(new BoardMember(
            id: BoardMemberId::generate(), boardId: $boardId, userId: $chair,
            role: BoardMemberRole::Chair,
            termStartedAt: new \DateTimeImmutable('2026-01-01'),
            termEndsAt:    new \DateTimeImmutable('2028-01-01'),
            termEndedAt: null, termEndedReason: null,
        ));
        $decisionId = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $decisionId, boardId: $boardId,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: $chair,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        // Register a no-op executor so the resolution path runs cleanly.
        // (Done by ResolveBoardDecisionIfReady's registry — see Step 3.)
        $uc->execute($decisionId, $chair, BoardDecisionVoteValue::Yes, new \DateTimeImmutable('2026-05-13'));

        $found = $decisions->find($decisionId);
        $this->assertNotNull($found);
        $this->assertSame(BoardDecisionStatus::Passed, $found->status);
    }
}
```

- [ ] **Step 3: Implement `CastBoardVote`**

`src/Application/Governance/CastBoardVote.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionVote;
use Daems\Domain\Governance\BoardDecisionVoteId;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardMemberTermExpired;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;

final class CastBoardVote
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly ResolveBoardDecisionIfReady $resolve,
    ) {}

    public function execute(
        BoardDecisionId $decisionId,
        UserId $actingUserId,
        BoardDecisionVoteValue $vote,
        \DateTimeImmutable $at,
    ): void {
        $decision = $this->decisions->find($decisionId)
            ?? throw new \DomainException('Decision not found');
        if ($decision->status !== BoardDecisionStatus::Pending) {
            throw new DecisionAlreadyResolved("decision={$decisionId->value()} status={$decision->status->value}");
        }

        // Map actingUserId to a board_member row on the decision's board.
        $member = null;
        foreach ($this->members->listForBoard($decision->boardId) as $m) {
            if ($m->userId->equals($actingUserId)) { $member = $m; break; }
        }
        if ($member === null) {
            throw new NotABoardMember("user={$actingUserId->value()} not on board={$decision->boardId->value()}");
        }
        if (!$member->isActive($at)) {
            throw new BoardMemberTermExpired("member={$member->id->value()} not active at {$at->format('c')}");
        }

        $this->votes->upsert(new BoardDecisionVote(
            id:            BoardDecisionVoteId::generate(),
            decisionId:    $decisionId,
            boardMemberId: $member->id,
            vote:          $vote,
            castAt:        $at,
        ));

        // Re-resolve immediately after every vote.
        $this->resolve->execute($decisionId, $at);
    }
}
```

- [ ] **Step 4: Implement `ResolveBoardDecisionIfReady`**

`src/Application/Governance/ResolveBoardDecisionIfReady.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;

final class ResolveBoardDecisionIfReady
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly BoardDecisionResolutionService $service,
        private readonly BoardDecisionExecutorRegistry $executors,
    ) {}

    public function execute(BoardDecisionId $decisionId, \DateTimeImmutable $at): void
    {
        $decision = $this->decisions->find($decisionId);
        if ($decision === null || $decision->status !== BoardDecisionStatus::Pending) return;

        $active = $this->members->listActiveForBoard($decision->boardId, $at);
        $activeIds = [];
        $chairId = null;
        foreach ($active as $m) {
            $activeIds[$m->id->value()] = true;
            if ($m->role === BoardMemberRole::Chair) $chairId = $m->id->value();
        }

        $yes = 0; $no = 0; $abstain = 0; $chairVoted = false;
        foreach ($this->votes->listForDecision($decisionId) as $v) {
            // Ignore votes from non-active members (defensive — should not happen).
            if (!isset($activeIds[$v->boardMemberId->value()])) continue;
            match ($v->vote) {
                BoardDecisionVoteValue::Yes     => $yes++,
                BoardDecisionVoteValue::No      => $no++,
                BoardDecisionVoteValue::Abstain => $abstain++,
            };
            if ($v->boardMemberId->value() === $chairId) $chairVoted = true;
        }

        $next = $this->service->resolve(
            threshold:   $decision->threshold,
            activeCount: count($active),
            yes:         $yes,
            no:          $no,
            abstain:     $abstain,
            chairVoted:  $chairVoted,
        );
        if ($next === BoardDecisionStatus::Pending) return;

        $resolved = $decision->withStatus($next, resolvedAt: $at);
        $this->decisions->save($resolved);

        if ($next === BoardDecisionStatus::Passed) {
            $this->executors->for($resolved->decisionType)->execute($resolved, $at);
        }
    }
}
```

- [ ] **Step 5: Implement `WithdrawBoardDecision`**

`src/Application/Governance/WithdrawBoardDecision.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\DecisionAlreadyResolved;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;

final class WithdrawBoardDecision
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardMemberRepositoryInterface $members,
    ) {}

    public function execute(
        BoardDecisionId $decisionId,
        UserId $actingUserId,
        string $withdrawalReason,
        \DateTimeImmutable $at,
    ): void {
        $decision = $this->decisions->find($decisionId)
            ?? throw new \DomainException('Decision not found');
        if ($decision->status !== BoardDecisionStatus::Pending) {
            throw new DecisionAlreadyResolved("decision={$decisionId->value()} status={$decision->status->value}");
        }
        if (trim($withdrawalReason) === '') {
            throw new \InvalidArgumentException('withdrawal_reason required');
        }

        // Permission: proposer OR active chair.
        $allowed = $decision->proposedByUserId->equals($actingUserId);
        if (!$allowed) {
            foreach ($this->members->listActiveForBoard($decision->boardId, $at) as $m) {
                if ($m->role === BoardMemberRole::Chair && $m->userId->equals($actingUserId)) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot withdraw this decision");
        }

        $this->decisions->save($decision->withStatus(
            BoardDecisionStatus::Withdrawn,
            resolvedAt: $at,
            withdrawalReason: trim($withdrawalReason),
        ));
    }
}
```

- [ ] **Step 6: Write a small test for `WithdrawBoardDecision`**

`tests/Unit/Application/Governance/WithdrawBoardDecisionTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\WithdrawBoardDecision;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use PHPUnit\Framework\TestCase;

final class WithdrawBoardDecisionTest extends TestCase
{
    public function test_proposer_can_withdraw(): void
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $members   = new InMemoryBoardMemberRepository();
        $uc = new WithdrawBoardDecision($decisions, $members);

        $proposer = UserId::generate();
        $id = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $id, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: $proposer,
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $uc->execute($id, $proposer, 'changed my mind', new \DateTimeImmutable('2026-05-13'));
        $found = $decisions->find($id);
        $this->assertNotNull($found);
        $this->assertSame(BoardDecisionStatus::Withdrawn, $found->status);
        $this->assertSame('changed my mind', $found->withdrawalReason);
    }

    public function test_random_user_cannot_withdraw(): void
    {
        $decisions = new InMemoryBoardDecisionRepository();
        $members   = new InMemoryBoardMemberRepository();
        $uc = new WithdrawBoardDecision($decisions, $members);

        $id = BoardDecisionId::generate();
        $decisions->save(new BoardDecision(
            id: $id, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-05-12'),
            expiresAt:  new \DateTimeImmutable('2026-07-12'),
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $this->expectException(NotABoardMember::class);
        $uc->execute($id, UserId::generate(), 'no reason', new \DateTimeImmutable('2026-05-13'));
    }
}
```

- [ ] **Step 7: Run + PHPStan + commit**

```bash
composer test -- --filter "CastBoardVoteTest|WithdrawBoardDecisionTest"
composer analyse
git add src/Application/Governance/ tests/Unit/Application/Governance/CastBoardVoteTest.php tests/Unit/Application/Governance/WithdrawBoardDecisionTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): CastBoardVote + WithdrawBoardDecision + ResolveBoardDecisionIfReady + Executor registry"
```

---

## Task 20: `ExpireOverdueBoardDecisionsCron`

**Files:**

- Create: `src/Application/Governance/ExpireOverdueBoardDecisionsCron.php`
- Create: `tests/Unit/Application/Governance/ExpireOverdueBoardDecisionsCronTest.php`
- Create: `bin/governance-cron.php` (Cron entrypoint script)

- [ ] **Step 1: Write failing test**

`tests/Unit/Application/Governance/ExpireOverdueBoardDecisionsCronTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\ExpireOverdueBoardDecisionsCron;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use PHPUnit\Framework\TestCase;

final class ExpireOverdueBoardDecisionsCronTest extends TestCase
{
    public function test_expires_only_overdue_pending(): void
    {
        $repo = new InMemoryBoardDecisionRepository();

        $overdueId = BoardDecisionId::generate();
        $repo->save(new BoardDecision(
            id: $overdueId, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-01-01'),
            expiresAt:  new \DateTimeImmutable('2026-03-01'),  // past
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $futureId = BoardDecisionId::generate();
        $repo->save(new BoardDecision(
            id: $futureId, boardId: BoardId::generate(),
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            status: BoardDecisionStatus::Pending,
            proposedByUserId: UserId::generate(),
            proposedAt: new \DateTimeImmutable('2026-05-01'),
            expiresAt:  new \DateTimeImmutable('2026-07-01'),  // future
            resolvedAt: null, meetingReference: null, withdrawalReason: null,
            viaDelegation: false, delegationId: null,
        ));

        $cron = new ExpireOverdueBoardDecisionsCron($repo);
        $count = $cron->run(new \DateTimeImmutable('2026-05-12'));

        $this->assertSame(1, $count);
        $this->assertSame(BoardDecisionStatus::Expired, $repo->find($overdueId)?->status);
        $this->assertSame(BoardDecisionStatus::Pending, $repo->find($futureId)?->status);
    }
}
```

- [ ] **Step 2: Implement the cron**

`src/Application/Governance/ExpireOverdueBoardDecisionsCron.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;

final class ExpireOverdueBoardDecisionsCron
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
    ) {}

    /** Flips status=pending decisions whose expires_at < $at to Expired. Returns count expired. */
    public function run(\DateTimeImmutable $at, int $batchSize = 100): int
    {
        $count = 0;
        foreach ($this->decisions->listExpiredPending($at, $batchSize) as $d) {
            $this->decisions->save($d->withStatus(BoardDecisionStatus::Expired, resolvedAt: $at));
            $count++;
        }
        return $count;
    }
}
```

- [ ] **Step 3: Write the CLI entrypoint**

`bin/governance-cron.php`:

```php
<?php
declare(strict_types=1);

// CLI entry — invoke from system cron (Windows scheduled task or cron daemon):
//   php bin/governance-cron.php

require __DIR__ . '/../vendor/autoload.php';

/** @var \Daems\Infrastructure\Container\Container $container */
$container = require __DIR__ . '/../bootstrap/app.php';

$cron = $container->make(\Daems\Application\Governance\ExpireOverdueBoardDecisionsCron::class);
$expired = $cron->run(new \DateTimeImmutable());

fwrite(STDOUT, "expired={$expired}\n");
```

- [ ] **Step 4: Run + commit**

```bash
composer test -- --filter ExpireOverdueBoardDecisionsCronTest
composer analyse
git add src/Application/Governance/ExpireOverdueBoardDecisionsCron.php tests/Unit/Application/Governance/ExpireOverdueBoardDecisionsCronTest.php bin/governance-cron.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): ExpireOverdueBoardDecisionsCron + bin/governance-cron.php"
```

---

# Wave E — Bootstrap + propose-flows per decision type (Tasks 21–28)

Each task in this wave produces one propose-use case + (when applicable) its delegate variant + the executor. Pattern:

1. Propose-use case validates preconditions + creates a `BoardDecision` row (status=Pending OR status=Passed if delegation is active) + any side rows.
2. If delegation is active and type is delegatable, executor runs immediately and decision is saved with `status=Passed, via_delegation=true, resolved_at=NOW`.
3. Executor implements the side-effect that a Passed resolution triggers (called by `ResolveBoardDecisionIfReady` after a normal Pending → Passed transition, OR directly by the delegate-path).

Each use case has:

- A short Unit test exercising preconditions + happy-path output (using InMemory fakes).
- An E2E test slot — those land in Wave K once the controllers are wired.

## Task 21: `BootstrapBoard` use case + `BoardController.bootstrap`

**Files:**

- Create: `src/Application/Governance/BootstrapBoard.php`
- Create: `src/Application/Governance/BootstrapBoardInput.php`
- Create: `tests/Unit/Application/Governance/BootstrapBoardTest.php`

- [ ] **Step 1: Write failing test**

`tests/Unit/Application/Governance/BootstrapBoardTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BootstrapBoard;
use Daems\Application\Governance\BootstrapBoardInput;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\BoardAlreadyBootstrapped;
use Daems\Domain\Governance\Exception\BoardCandidateNotFull;
use Daems\Domain\Governance\Exception\InvalidBootstrapRoster;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use PHPUnit\Framework\TestCase;

final class BootstrapBoardTest extends TestCase
{
    /** Stub user-lookup that reports membership_type / status for a fixed map. */
    private function userMembershipLookup(array $map): callable
    {
        return static function (UserId $id) use ($map): array {
            return $map[$id->value()] ?? ['membership_type' => 'BASIC', 'membership_status' => 'active'];
        };
    }

    public function test_rejects_when_board_already_exists(): void
    {
        $boards  = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $tenant  = TenantId::generate();
        // Pre-seed a board so the use case sees one.
        $boards->save(new \Daems\Domain\Governance\Board(
            id: \Daems\Domain\Governance\BoardId::generate(),
            tenantId: $tenant,
            bootstrappedByUserId: UserId::generate(),
            bootstrappedAt: new \DateTimeImmutable('2026-04-01'),
            createdAt:      new \DateTimeImmutable('2026-04-01'),
        ));

        $uc = new BootstrapBoard($boards, $members, $this->userMembershipLookup([]));
        $this->expectException(BoardAlreadyBootstrapped::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: $tenant,
            gsaUserId: UserId::generate(),
            members: [['user_id' => UserId::generate()->value(), 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12']],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_size_outside_1_to_5(): void
    {
        $uc = new BootstrapBoard(new InMemoryBoardRepository(), new InMemoryBoardMemberRepository(), $this->userMembershipLookup([]));
        $this->expectException(InvalidBootstrapRoster::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [], // 0 members
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_when_not_exactly_one_chair(): void
    {
        $uc = new BootstrapBoard(new InMemoryBoardRepository(), new InMemoryBoardMemberRepository(),
            $this->userMembershipLookup([
                'u1' => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                'u2' => ['membership_type' => 'FULL', 'membership_status' => 'active'],
            ]));
        $this->expectException(InvalidBootstrapRoster::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [
                ['user_id' => 'u1', 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => 'u2', 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_when_candidate_not_full(): void
    {
        $uc = new BootstrapBoard(new InMemoryBoardRepository(), new InMemoryBoardMemberRepository(),
            $this->userMembershipLookup([
                'u1' => ['membership_type' => 'BASIC', 'membership_status' => 'active'],
            ]));
        $this->expectException(BoardCandidateNotFull::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [
                ['user_id' => 'u1', 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_happy_path_creates_board_and_members(): void
    {
        $boards  = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $uc = new BootstrapBoard($boards, $members,
            $this->userMembershipLookup([
                'u1' => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                'u2' => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                'u3' => ['membership_type' => 'FULL', 'membership_status' => 'active'],
            ]));

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $uc->execute(new BootstrapBoardInput(
            tenantId: $tenant,
            gsaUserId: $gsa,
            members: [
                ['user_id' => 'u1', 'role' => 'chair',  'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => 'u2', 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => 'u3', 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2030-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));

        $board = $boards->findForTenant($tenant);
        $this->assertNotNull($board);
        $list = $members->listForBoard($board->id);
        $this->assertCount(3, $list);
        $chair = array_values(array_filter($list, fn($m) => $m->role === BoardMemberRole::Chair));
        $this->assertCount(1, $chair);
    }
}
```

- [ ] **Step 2: Implement input + use case**

`src/Application/Governance/BootstrapBoardInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

/**
 * @phpstan-type RosterRow array{user_id:string, role:string, term_started_at:string, term_ends_at:string}
 */
final class BootstrapBoardInput
{
    /** @param list<RosterRow> $members */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $gsaUserId,
        public readonly array $members,
        public readonly \DateTimeImmutable $at,
    ) {}
}
```

`src/Application/Governance/BootstrapBoard.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardAlreadyBootstrapped;
use Daems\Domain\Governance\Exception\BoardCandidateNotFull;
use Daems\Domain\Governance\Exception\InvalidBootstrapRoster;
use Daems\Domain\User\UserId;

final class BootstrapBoard
{
    /** @param callable(UserId):array{membership_type:string, membership_status:string} $userLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private $userLookup,
    ) {}

    public function execute(BootstrapBoardInput $in): BoardId
    {
        if ($this->boards->findForTenant($in->tenantId) !== null) {
            throw new BoardAlreadyBootstrapped("tenant={$in->tenantId->value()}");
        }

        $n = count($in->members);
        if ($n < 1 || $n > 5) {
            throw new InvalidBootstrapRoster("Board must have 1–5 members; got {$n}");
        }
        $chairCount = 0;
        foreach ($in->members as $row) {
            if ($row['role'] === 'chair') $chairCount++;
        }
        if ($chairCount !== 1) {
            throw new InvalidBootstrapRoster("Exactly one chair required; got {$chairCount}");
        }

        // Eligibility + term-length validation.
        $lookup = $this->userLookup;
        foreach ($in->members as $row) {
            $uid = UserId::fromString($row['user_id']);
            $u   = $lookup($uid);
            if ($u['membership_type'] !== 'FULL' || $u['membership_status'] !== 'active') {
                throw new BoardCandidateNotFull("user={$row['user_id']} not FULL+active");
            }
            $ts = new \DateTimeImmutable($row['term_started_at']);
            $te = new \DateTimeImmutable($row['term_ends_at']);
            $years = (int) $ts->diff($te)->format('%y');
            if ($years < 1 || $years > 4) {
                throw new InvalidBootstrapRoster("Term length must be 1–4 years; got {$years} for user={$row['user_id']}");
            }
        }

        $board = new Board(
            id: BoardId::generate(),
            tenantId: $in->tenantId,
            bootstrappedByUserId: $in->gsaUserId,
            bootstrappedAt: $in->at,
            createdAt: $in->at,
        );
        $this->boards->save($board);

        foreach ($in->members as $row) {
            $this->members->save(new BoardMember(
                id: BoardMemberId::generate(),
                boardId: $board->id,
                userId: UserId::fromString($row['user_id']),
                role: BoardMemberRole::from($row['role']),
                termStartedAt: new \DateTimeImmutable($row['term_started_at']),
                termEndsAt:    new \DateTimeImmutable($row['term_ends_at']),
                termEndedAt: null,
                termEndedReason: null,
            ));
        }

        return $board->id;
    }
}
```

- [ ] **Step 3: Run + commit**

```bash
composer test -- --filter BootstrapBoardTest
composer analyse
git add src/Application/Governance/BootstrapBoard.php src/Application/Governance/BootstrapBoardInput.php tests/Unit/Application/Governance/BootstrapBoardTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): BootstrapBoard use case + 5 validation rules"
```

---

## Task 22: ApproveBasic — propose + delegate + executor

**Files:**

- Create: `src/Application/Governance/Propose/ProposeApproveBasic.php`
- Create: `src/Application/Governance/Delegate/ApproveBasicAsDelegate.php`
- Create: `src/Application/Governance/Executor/ApproveBasicExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeApproveBasicTest.php`

This is the template task. All 7 subsequent tasks follow the same shape: precondition checks → delegation lookup → create-decision-or-execute-immediately.

- [ ] **Step 1: Write failing test**

`tests/Unit/Application/Governance/ProposeApproveBasicTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDelegationRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProposeApproveBasicTest extends TestCase
{
    public function test_creates_pending_unanimous_async_decision(): void
    {
        $boards     = new InMemoryBoardRepository();
        $decisions  = new InMemoryBoardDecisionRepository();
        $delegations= new InMemoryBoardDelegationRepository();
        $settings   = new InMemoryTenantGovernanceSettingsRepository();

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $boards->save(new Board(
            id: BoardId::generate(), tenantId: $tenant,
            bootstrappedByUserId: $gsa,
            bootstrappedAt: new \DateTimeImmutable('2026-05-12'),
            createdAt:      new \DateTimeImmutable('2026-05-12'),
        ));
        $settings->save(new TenantGovernanceSettings($tenant, 14, 60));

        $uc = new ProposeApproveBasic($boards, $decisions, $delegations, $settings, /* applicationLookup */ static fn(string $id) => ['status' => 'pending']);
        $proposer = UserId::generate();

        $id = $uc->execute(new ProposeApproveBasicInput(
            tenantId: $tenant,
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            proposedByUserId: $proposer,
            voteVisibility: BoardDecisionVoteVisibility::Visible,
            at: new \DateTimeImmutable('2026-05-12 10:00:00'),
        ));

        $d = $decisions->find($id);
        $this->assertNotNull($d);
        $this->assertSame(BoardDecisionType::ApproveBasic, $d->decisionType);
        $this->assertSame(BoardDecisionThreshold::Unanimous, $d->threshold);
        $this->assertSame(BoardDecisionMode::Async, $d->mode);
        $this->assertSame(BoardDecisionStatus::Pending, $d->status);
        $this->assertFalse($d->viaDelegation);
        // expires_at = proposed_at + 60 days
        $this->assertSame('2026-07-11 10:00:00', $d->expiresAt->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2: Implement input + propose use case**

`src/Application/Governance/Propose/ProposeApproveBasicInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ProposeApproveBasicInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $applicationId,
        public readonly UserId $proposedByUserId,
        public readonly BoardDecisionVoteVisibility $voteVisibility,
        public readonly \DateTimeImmutable $at,
    ) {}
}
```

`src/Application/Governance/Propose/ProposeApproveBasic.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Propose;

use Daems\Application\Governance\BoardDecisionExecutorRegistry;
use Daems\Application\Governance\Delegate\ApproveBasicAsDelegate;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;

final class ProposeApproveBasic
{
    /** @param callable(string):array{status:string} $applicationLookup */
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private $applicationLookup,
    ) {}

    public function execute(ProposeApproveBasicInput $in): BoardDecisionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        $lookup = $this->applicationLookup;
        $app = $lookup($in->applicationId);
        if ($app['status'] !== 'pending') {
            throw new \DomainException("application={$in->applicationId} is not pending");
        }

        // Delegation check — short-circuits the board flow.
        $delegation = $this->delegations->findActive(
            $in->tenantId, BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $in->at
        );

        $settings = $this->settings->find($in->tenantId);
        $expiresInDays = $settings?->decisionExpirationDays ?? 60;
        $id = BoardDecisionId::generate();

        $status     = $delegation !== null ? BoardDecisionStatus::Passed  : BoardDecisionStatus::Pending;
        $resolvedAt = $delegation !== null ? $in->at                      : null;

        $decision = new BoardDecision(
            id: $id, boardId: $board->id,
            decisionType: BoardDecisionType::ApproveBasic,
            threshold: BoardDecisionThreshold::Unanimous,
            mode: BoardDecisionMode::Async,
            voteVisibility: $in->voteVisibility,
            status: $status,
            proposedByUserId: $in->proposedByUserId,
            proposedAt: $in->at,
            expiresAt:  $in->at->modify("+{$expiresInDays} days"),
            resolvedAt: $resolvedAt,
            meetingReference: null,
            withdrawalReason: null,
            viaDelegation: $delegation !== null,
            delegationId: $delegation?->id,
            payloadApplicationId: $in->applicationId,
        );
        $this->decisions->save($decision);
        return $id;
    }
}
```

- [ ] **Step 3: Implement delegate variant + executor**

`src/Application/Governance/Delegate/ApproveBasicAsDelegate.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Delegate;

use Daems\Application\Governance\Executor\ApproveBasicExecutor;
use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Tenant\UserTenantRole;

/**
 * Direct admin-side hook: when an admin clicks "Hyväksy" on an Application,
 * and a standing delegation for approve_basic→admin is active, this use case
 * runs the executor immediately and saves the decision in Passed status.
 *
 * Implementation: route through ProposeApproveBasic (which detects the active
 * delegation), then call the executor once to apply the side-effect. The
 * propose-flow already saves status=Passed when delegation is active.
 */
final class ApproveBasicAsDelegate
{
    public function __construct(
        private readonly ProposeApproveBasic $propose,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDelegationRepositoryInterface $delegations,
        private readonly ApproveBasicExecutor $executor,
    ) {}

    public function execute(ProposeApproveBasicInput $in): BoardDecisionId
    {
        // Guard: delegation MUST be active or this short-circuit is invalid.
        $deleg = $this->delegations->findActive($in->tenantId, BoardDecisionType::ApproveBasic, UserTenantRole::Admin, $in->at);
        if ($deleg === null) {
            throw new DelegationNotPermittedForType('No active approve_basic delegation; use ProposeApproveBasic instead');
        }
        $id = $this->propose->execute($in);
        $d  = $this->decisions->find($id) ?? throw new \RuntimeException('decision missing after propose');
        $this->executor->execute($d, $in->at);
        return $id;
    }
}
```

`src/Application/Governance/Executor/ApproveBasicExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;

/**
 * Marks the linked member_application as approved and triggers the existing
 * member-activation pipeline. Side-effect is delegated to an injected closure
 * so this class stays free of repository churn — the closure is bound in
 * bootstrap/app.php to the same routine `ApproveMemberApplication` uses today.
 */
final class ApproveBasicExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId):void $approve */
    public function __construct(private $approve) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::ApproveBasic;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadApplicationId === null) {
            throw new \DomainException('approve_basic decision missing payload_application_id');
        }
        $approve = $this->approve;
        $approve($d->payloadApplicationId, $at, $d->viaDelegation ? $d->id->value() : null);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
composer test -- --filter ProposeApproveBasicTest
composer analyse
git add src/Application/Governance/Propose/ProposeApproveBasic.php src/Application/Governance/Propose/ProposeApproveBasicInput.php src/Application/Governance/Delegate/ApproveBasicAsDelegate.php src/Application/Governance/Executor/ApproveBasicExecutor.php tests/Unit/Application/Governance/ProposeApproveBasicTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): ApproveBasic — propose + delegate + executor (template)"
```

---

## Task 23: InviteFull — propose + delegate + executor + 12 kk eligibility precondition

**Files:**

- Create: `src/Application/Governance/Propose/ProposeInviteFull.php`
- Create: `src/Application/Governance/Propose/ProposeInviteFullInput.php`
- Create: `src/Application/Governance/Delegate/InviteFullAsDelegate.php`
- Create: `src/Application/Governance/Executor/InviteFullExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeInviteFullTest.php`

Follow Task 22's template. Differences:

- Input carries `targetUserId: UserId` + `reason: string` + `voteVisibility`.
- Precondition: call `IsEligibleForFullMembership::check()` using a user-lookup closure. Throws `Daems\Domain\Governance\Exception\NotEligibleForFull` on failure.
- Threshold = Unanimous, mode = Async (so the executor finishes only when every board member votes yes).
- Executor sets `UPDATE users SET membership_type='FULL', invited_to_full_at = NOW WHERE id = ?` via an injected `userPromoter` closure (bound to a method in `bootstrap/app.php` that uses the existing user-repo).

- [ ] **Step 1: Test the eligibility precondition**

```php
public function test_rejects_when_user_not_yet_12_months(): void
{
    // Wire ProposeInviteFull with userLookup returning membership_started_at = NOW - 11 mo
    // Expect Daems\Domain\Governance\Exception\NotEligibleForFull
}
```

- [ ] **Step 2: Implement + commit**

```bash
composer test -- --filter ProposeInviteFullTest
composer analyse
git add src/Application/Governance/Propose/ProposeInviteFull.php src/Application/Governance/Propose/ProposeInviteFullInput.php src/Application/Governance/Delegate/InviteFullAsDelegate.php src/Application/Governance/Executor/InviteFullExecutor.php tests/Unit/Application/Governance/ProposeInviteFullTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): InviteFull — propose + delegate + executor + 12 mo precondition"
```

---

## Task 24: AwardSubTier — propose + delegate + executor

**Files:**

- Create: `src/Application/Governance/Propose/ProposeAwardSubTier.php`
- Create: `src/Application/Governance/Propose/ProposeAwardSubTierInput.php`
- Create: `src/Application/Governance/Delegate/AwardSubTierAsDelegate.php`
- Create: `src/Application/Governance/Executor/AwardSubTierExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeAwardSubTierTest.php`

Differences vs Task 22:

- Threshold = Majority, mode = Sync, requires `meeting_reference` (validate non-empty in input).
- Precondition 1: subtier exists for `(tenant, applies_to, slug)` — use `TenantMembershipSubTierRepositoryInterface::findBySlug` with applies_to derived from user's membership_type.
- Precondition 2: subtier.applies_to MUST match user's membership_type (`Daems\Domain\Membership\Exception\DuplicateSubTierSlug`? No — use a new `SubTierAppliesToMismatch` exception under Membership/Exception).
- Precondition 3: user does NOT already have an active sub-tier (`Daems\Domain\Membership\Exception\NoActiveSubTierToRevoke` is for revoke; create a new `Daems\Domain\Membership\Exception\AlreadyHasActiveSubTier` for award).
- Executor: `UPDATE users SET membership_subtier = ?` + insert `member_sub_tier_awards` row.

Add the two new exception classes in this task: `SubTierAppliesToMismatch` and `AlreadyHasActiveSubTier`. Both follow the `final class … extends \DomainException {}` pattern.

```bash
composer test -- --filter ProposeAwardSubTierTest
composer analyse
git add src/Application/Governance/Propose/ProposeAwardSubTier*.php src/Application/Governance/Delegate/AwardSubTierAsDelegate.php src/Application/Governance/Executor/AwardSubTierExecutor.php src/Domain/Membership/Exception/SubTierAppliesToMismatch.php src/Domain/Membership/Exception/AlreadyHasActiveSubTier.php tests/Unit/Application/Governance/ProposeAwardSubTierTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): AwardSubTier — propose + delegate + executor + applies_to validation"
```

---

## Task 25: RevokeSubTier — propose + executor

**Files:**

- Create: `src/Application/Governance/Propose/ProposeRevokeSubTier.php`
- Create: `src/Application/Governance/Propose/ProposeRevokeSubTierInput.php`
- Create: `src/Application/Governance/Executor/RevokeSubTierExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeRevokeSubTierTest.php`

NOT delegatable. Threshold = Majority, mode = Sync, requires meeting_reference.

Precondition: user MUST currently have an active sub-tier (`MemberSubTierAwardRepositoryInterface::findActive` returns non-null) — else `NoActiveSubTierToRevoke`.

Executor: `UPDATE users SET membership_subtier = NULL`, set `member_sub_tier_awards.revoked_at = NOW AND revoke_decision_id = ?` on the active row.

```bash
composer test -- --filter ProposeRevokeSubTierTest
composer analyse
git add src/Application/Governance/Propose/ProposeRevokeSubTier*.php src/Application/Governance/Executor/RevokeSubTierExecutor.php tests/Unit/Application/Governance/ProposeRevokeSubTierTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): RevokeSubTier — propose + executor (no delegate)"
```

---

## Task 26: SubTierCrud — propose + executor (Create/Update/Delete operations)

**Files:**

- Create: `src/Application/Governance/Propose/ProposeSubTierCrud.php`
- Create: `src/Application/Governance/Propose/ProposeSubTierCrudInput.php`
- Create: `src/Application/Governance/Executor/SubTierCrudExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeSubTierCrudTest.php`

NOT delegatable. Threshold = Majority, mode = Sync.

Input carries `operation` enum + slug + name?/rank?/applies_to? per the spec Domain rule 16:

- Create: slug+name+rank+applies_to all required; `findBySlug` MUST return null else `DuplicateSubTierSlug`.
- Update: applies_to+slug required + ≥1 of (name, rank, new slug) provided.
- Delete: applies_to+slug required; `MemberSubTierAwardRepositoryInterface::listActiveForSubTierSlug(...)` MUST be empty else `SubTierInUse`.

Executor performs the CRUD via `TenantMembershipSubTierRepositoryInterface::save / delete`.

```bash
composer test -- --filter ProposeSubTierCrudTest
composer analyse
git add src/Application/Governance/Propose/ProposeSubTierCrud*.php src/Application/Governance/Executor/SubTierCrudExecutor.php tests/Unit/Application/Governance/ProposeSubTierCrudTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): SubTierCrud — Create/Update/Delete decisions with body validation"
```

---

## Task 27: RemoveBoardMember — propose + executor

**Files:**

- Create: `src/Application/Governance/Propose/ProposeRemoveBoardMember.php`
- Create: `src/Application/Governance/Propose/ProposeRemoveBoardMemberInput.php`
- Create: `src/Application/Governance/Executor/RemoveBoardMemberExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeRemoveBoardMemberTest.php`

NOT delegatable. Threshold = Unanimous, mode = Sync (personal matter — needs meeting per practice). Requires meeting_reference.

Precondition: target `BoardMember` is active. If the target IS the only remaining member, throw `LastBoardMemberCannotBeRemoved`.

Executor: `UPDATE board_members SET term_ended_at = NOW, term_ended_reason = 'removed' WHERE id = ?`.

```bash
composer test -- --filter ProposeRemoveBoardMemberTest
composer analyse
git add src/Application/Governance/Propose/ProposeRemoveBoardMember*.php src/Application/Governance/Executor/RemoveBoardMemberExecutor.php tests/Unit/Application/Governance/ProposeRemoveBoardMemberTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): RemoveBoardMember — propose + executor + last-seat guard"
```

---

## Task 28: DelegateAuthority + RevokeDelegation — meta-decisions

**Files:**

- Create: `src/Application/Governance/Propose/ProposeDelegateAuthority.php`
- Create: `src/Application/Governance/Propose/ProposeDelegateAuthorityInput.php`
- Create: `src/Application/Governance/Executor/DelegateAuthorityExecutor.php`
- Create: `src/Application/Governance/Propose/ProposeRevokeDelegation.php`
- Create: `src/Application/Governance/Propose/ProposeRevokeDelegationInput.php`
- Create: `src/Application/Governance/Executor/RevokeDelegationExecutor.php`
- Create: `tests/Unit/Application/Governance/ProposeDelegateAuthorityTest.php`
- Create: `tests/Unit/Application/Governance/ProposeRevokeDelegationTest.php`

**ProposeDelegateAuthority:**

- NOT delegatable.
- Threshold = Unanimous, mode = Sync, requires meeting_reference.
- Precondition: `decisionType->isDelegatable()` returns true (one of approve_basic, invite_full, award_subtier). Throw `DelegationNotPermittedForType` otherwise.
- `payload_delegation_type` + `payload_delegated_to_role` populated on the decision.

**DelegateAuthorityExecutor:**

- If an active delegation already exists for `(tenant_id, decision_type, delegated_to_role)`: set its `revoked_at = NOW` first (per spec § "Delegation lifecycle" — automatic supersede).
- Insert new `board_delegations` row with `source_decision_id = $decision->id`, `valid_from = $at`, `revoked_at = null`.

**ProposeRevokeDelegation:**

- NOT delegatable. Threshold = Majority, mode = Sync, requires meeting_reference.
- Precondition: target delegation exists AND is currently active.

**RevokeDelegationExecutor:**

- `UPDATE board_delegations SET revoked_at = NOW WHERE id = ?`.

```bash
composer test -- --filter "ProposeDelegateAuthorityTest|ProposeRevokeDelegationTest"
composer analyse
git add src/Application/Governance/Propose/ProposeDelegateAuthority*.php src/Application/Governance/Propose/ProposeRevokeDelegation*.php src/Application/Governance/Executor/DelegateAuthorityExecutor.php src/Application/Governance/Executor/RevokeDelegationExecutor.php tests/Unit/Application/Governance/ProposeDelegateAuthorityTest.php tests/Unit/Application/Governance/ProposeRevokeDelegationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/governance): DelegateAuthority + RevokeDelegation — meta-decisions + auto-supersede"
```

---

# Wave F — Expulsion sub-flow (Tasks 29–30)

The expulsion sub-flow is its own state machine that emits a `BoardDecision` only after the hearing period closes (or the member submits a statement).

## Task 29: `InitiateMemberExpulsion` + `SubmitExpulsionStatement`

**Files:**

- Create: `src/Application/Membership/InitiateMemberExpulsion.php`
- Create: `src/Application/Membership/InitiateMemberExpulsionInput.php`
- Create: `src/Application/Membership/SubmitExpulsionStatement.php`
- Create: `tests/Unit/Application/Membership/InitiateMemberExpulsionTest.php`
- Create: `tests/Unit/Application/Membership/SubmitExpulsionStatementTest.php`

- [ ] **Step 1: Write failing test — only board member can initiate**

```php
public function test_rejects_when_proposer_not_active_board_member(): void
{
    // wire InitiateMemberExpulsion with InMemoryBoardRepository + BoardMemberRepository (empty for proposer)
    // expectException(Daems\Domain\Governance\Exception\NotABoardMember::class)
}
```

- [ ] **Step 2: Implement `InitiateMemberExpulsion`**

`src/Application/Membership/InitiateMemberExpulsionInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InitiateMemberExpulsionInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $targetUserId,
        public readonly UserId $proposedByUserId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $at,
    ) {}
}
```

`src/Application/Membership/InitiateMemberExpulsion.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;

final class InitiateMemberExpulsion
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
    ) {}

    public function execute(InitiateMemberExpulsionInput $in): MemberExpulsionId
    {
        $board = $this->boards->findForTenant($in->tenantId)
            ?? throw new BoardNotBootstrapped("tenant={$in->tenantId->value()}");

        // Proposer must be an active board member.
        $isBoardMember = false;
        foreach ($this->members->listActiveForBoard($board->id, $in->at) as $m) {
            if ($m->userId->equals($in->proposedByUserId)) { $isBoardMember = true; break; }
        }
        if (!$isBoardMember) {
            throw new NotABoardMember("user={$in->proposedByUserId->value()} cannot initiate expulsion");
        }

        if (trim($in->reason) === '') {
            throw new \InvalidArgumentException('reason required');
        }

        $settings   = $this->settings->find($in->tenantId);
        $hearingDays = $settings?->expulsionHearingDays ?? 14;

        $id = MemberExpulsionId::generate();
        $this->expulsions->save(new MemberExpulsion(
            id:                  $id,
            tenantId:            $in->tenantId,
            targetUserId:        $in->targetUserId,
            proposedByUserId:    $in->proposedByUserId,
            reason:              $in->reason,
            hearingDeadlineAt:   $in->at->modify("+{$hearingDays} days"),
            statementText:       null,
            statementReceivedAt: null,
            decisionId:          null,
            decidedAt:           null,
            expelledAt:          null,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::Hearing,
            createdAt:           $in->at,
        ));
        return $id;
    }
}
```

- [ ] **Step 3: Implement `SubmitExpulsionStatement`**

`src/Application/Membership/SubmitExpulsionStatement.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class SubmitExpulsionStatement
{
    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        string $statementText,
        \DateTimeImmutable $at,
    ): void {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");

        // Only the target user may submit their own statement here. (Chair-side
        // "submit on behalf" can ship later if needed; not required for 0.6b.)
        if (!$e->targetUserId->equals($actingUserId)) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot submit this statement");
        }
        if ($e->status !== MemberExpulsionStatus::Hearing) {
            throw new \DomainException("statement only accepted in 'hearing' status; current={$e->status->value}");
        }
        if ($at > $e->hearingDeadlineAt) {
            throw new \DomainException('hearing deadline has elapsed');
        }
        if (trim($statementText) === '') {
            throw new \InvalidArgumentException('statement_text required');
        }

        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       trim($statementText),
            statementReceivedAt: $at,
            decisionId:          $e->decisionId,
            decidedAt:           $e->decidedAt,
            expelledAt:          $e->expelledAt,
            appealFiledAt:       $e->appealFiledAt,
            appealText:          $e->appealText,
            status:              $e->status,
            createdAt:           $e->createdAt,
        ));
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
composer test -- --filter "InitiateMemberExpulsionTest|SubmitExpulsionStatementTest"
composer analyse
git add src/Application/Membership/InitiateMemberExpulsion*.php src/Application/Membership/SubmitExpulsionStatement.php tests/Unit/Application/Membership/InitiateMemberExpulsionTest.php tests/Unit/Application/Membership/SubmitExpulsionStatementTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/membership): InitiateMemberExpulsion + SubmitExpulsionStatement"
```

---

## Task 30: `AdvanceExpulsionToVote` + `FileExpulsionAppeal` + `ExpelExecutor`

**Files:**

- Create: `src/Application/Membership/AdvanceExpulsionToVote.php`
- Create: `src/Application/Membership/FileExpulsionAppeal.php`
- Create: `src/Application/Governance/Executor/ExpelExecutor.php`
- Create: `tests/Unit/Application/Membership/AdvanceExpulsionToVoteTest.php`
- Create: `tests/Unit/Application/Membership/FileExpulsionAppealTest.php`

- [ ] **Step 1: Implement `AdvanceExpulsionToVote`**

`src/Application/Membership/AdvanceExpulsionToVote.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Exception\ExpulsionAlreadyAdvanced;
use Daems\Domain\Membership\Exception\ExpulsionHearingNotElapsed;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class AdvanceExpulsionToVote
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        \DateTimeImmutable $at,
        ?string $meetingReference,
    ): BoardDecisionId {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");
        if ($e->status !== MemberExpulsionStatus::Hearing) {
            throw new ExpulsionAlreadyAdvanced("expulsion={$expulsionId->value()} status={$e->status->value}");
        }
        // Hearing deadline OR statement received
        if ($at < $e->hearingDeadlineAt && $e->statementReceivedAt === null) {
            throw new ExpulsionHearingNotElapsed("hearing_deadline_at={$e->hearingDeadlineAt->format('c')}");
        }

        // Permission: chair only.
        $board = $this->boards->findForTenant($e->tenantId)
            ?? throw new \DomainException('board not bootstrapped');
        $chair = null;
        foreach ($this->members->listActiveForBoard($board->id, $at) as $m) {
            if ($m->role === BoardMemberRole::Chair) { $chair = $m; break; }
        }
        if ($chair === null || !$chair->userId->equals($actingUserId)) {
            throw new NotABoardMember("only the chair may advance an expulsion");
        }

        $tg = $this->settings->find($e->tenantId);
        $expiresInDays = $tg?->decisionExpirationDays ?? 60;

        // Create the Expel decision.
        $decisionId = BoardDecisionId::generate();
        $this->decisions->save(new BoardDecision(
            id:               $decisionId,
            boardId:          $board->id,
            decisionType:     BoardDecisionType::Expel,
            threshold:        BoardDecisionThreshold::Unanimous,
            mode:             BoardDecisionMode::Sync,
            voteVisibility:   BoardDecisionVoteVisibility::Visible,
            status:           BoardDecisionStatus::Pending,
            proposedByUserId: $e->proposedByUserId,
            proposedAt:       $at,
            expiresAt:        $at->modify("+{$expiresInDays} days"),
            resolvedAt:       null,
            meetingReference: $meetingReference,
            withdrawalReason: null,
            viaDelegation:    false,
            delegationId:     null,
            payloadTargetUserId: $e->targetUserId,
            payloadReason:       $e->reason,
        ));

        // Move the expulsion to AwaitingVote and link the decision.
        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       $e->statementText,
            statementReceivedAt: $e->statementReceivedAt,
            decisionId:          $decisionId,
            decidedAt:           null,
            expelledAt:          null,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::AwaitingVote,
            createdAt:           $e->createdAt,
        ));

        return $decisionId;
    }
}
```

- [ ] **Step 2: Implement `FileExpulsionAppeal`**

`src/Application/Membership/FileExpulsionAppeal.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\Exception\AppealAlreadyFiled;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\User\UserId;

final class FileExpulsionAppeal
{
    public function __construct(
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function execute(
        MemberExpulsionId $expulsionId,
        UserId $actingUserId,
        string $appealText,
        \DateTimeImmutable $at,
    ): void {
        $e = $this->expulsions->find($expulsionId)
            ?? throw new \DomainException("expulsion={$expulsionId->value()} not found");
        if (!$e->targetUserId->equals($actingUserId)) {
            throw new NotABoardMember("user={$actingUserId->value()} cannot appeal this expulsion");
        }
        if ($e->status !== MemberExpulsionStatus::Expelled) {
            throw new \DomainException("appeal only allowed after expulsion is final; status={$e->status->value}");
        }
        if ($e->appealFiledAt !== null) {
            throw new AppealAlreadyFiled("expulsion={$expulsionId->value()}");
        }
        if (trim($appealText) === '') {
            throw new \InvalidArgumentException('appeal_text required');
        }

        $this->expulsions->save(new MemberExpulsion(
            id:                  $e->id,
            tenantId:            $e->tenantId,
            targetUserId:        $e->targetUserId,
            proposedByUserId:    $e->proposedByUserId,
            reason:              $e->reason,
            hearingDeadlineAt:   $e->hearingDeadlineAt,
            statementText:       $e->statementText,
            statementReceivedAt: $e->statementReceivedAt,
            decisionId:          $e->decisionId,
            decidedAt:           $e->decidedAt,
            expelledAt:          $e->expelledAt,
            appealFiledAt:       $at,
            appealText:          trim($appealText),
            status:              MemberExpulsionStatus::Appealed,
            createdAt:           $e->createdAt,
        ));
    }
}
```

- [ ] **Step 3: Implement `ExpelExecutor`**

`src/Application/Governance/Executor/ExpelExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Governance\Executor;

use Daems\Application\Governance\BoardDecisionExecutorInterface;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionRepositoryInterface;
use Daems\Domain\Membership\MemberExpulsionStatus;

/**
 * Sets users.membership_status='expelled' + updates the linked member_expulsions
 * row. The user-side UPDATE is delegated to an injected closure so this class
 * stays free of repository churn.
 */
final class ExpelExecutor implements BoardDecisionExecutorInterface
{
    /** @param callable(\Daems\Domain\User\UserId, string $reason, \DateTimeImmutable):void $expelUser */
    public function __construct(
        private $expelUser,
        private readonly MemberExpulsionRepositoryInterface $expulsions,
    ) {}

    public function decisionType(): BoardDecisionType
    {
        return BoardDecisionType::Expel;
    }

    public function execute(BoardDecision $d, \DateTimeImmutable $at): void
    {
        if ($d->payloadTargetUserId === null) {
            throw new \DomainException('expel decision missing payload_target_user_id');
        }

        // Find the expulsion row by decision_id — list+filter is fine for InMemory; SQL implements
        // this efficiently via the indexed decision_id FK.
        $expulsion = null;
        foreach ($this->expulsions->listForTenant(
            tenantId: \Daems\Domain\Tenant\TenantId::fromString('00000000-0000-0000-0000-000000000000'),
        ) as $_) { /* placeholder — SQL impl uses a dedicated finder; see below */ }

        // NOTE: implementations need a `findByDecisionId(BoardDecisionId)` on the expulsion repo.
        // Add this method to the interface + both implementations in this task as part of Step 1.

        $expulsion = $this->findByDecisionId($d);

        $expel = $this->expelUser;
        $expel($d->payloadTargetUserId, $d->payloadReason ?? 'Erottaminen § 4', $at);

        $this->expulsions->save(new MemberExpulsion(
            id:                  $expulsion->id,
            tenantId:            $expulsion->tenantId,
            targetUserId:        $expulsion->targetUserId,
            proposedByUserId:    $expulsion->proposedByUserId,
            reason:              $expulsion->reason,
            hearingDeadlineAt:   $expulsion->hearingDeadlineAt,
            statementText:       $expulsion->statementText,
            statementReceivedAt: $expulsion->statementReceivedAt,
            decisionId:          $expulsion->decisionId,
            decidedAt:           $at,
            expelledAt:          $at,
            appealFiledAt:       null,
            appealText:          null,
            status:              MemberExpulsionStatus::Expelled,
            createdAt:           $expulsion->createdAt,
        ));
    }

    private function findByDecisionId(BoardDecision $d): MemberExpulsion
    {
        if (method_exists($this->expulsions, 'findByDecisionId')) {
            $e = $this->expulsions->findByDecisionId($d->id);
            if ($e !== null) return $e;
        }
        throw new \DomainException("no expulsion linked to decision={$d->id->value()}");
    }
}
```

- [ ] **Step 4: Extend `MemberExpulsionRepositoryInterface` + both impls + InMemory with `findByDecisionId`**

Add to `src/Domain/Membership/MemberExpulsionRepositoryInterface.php`:

```php
public function findByDecisionId(\Daems\Domain\Governance\BoardDecisionId $decisionId): ?MemberExpulsion;
```

In `SqlMemberExpulsionRepository`:

```php
public function findByDecisionId(\Daems\Domain\Governance\BoardDecisionId $decisionId): ?MemberExpulsion
{
    $stmt = $this->pdo->prepare('SELECT * FROM member_expulsions WHERE decision_id = ? LIMIT 1');
    $stmt->execute([$decisionId->value()]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $this->hydrate($r) : null;
}
```

In `InMemoryMemberExpulsionRepository`:

```php
public function findByDecisionId(\Daems\Domain\Governance\BoardDecisionId $decisionId): ?MemberExpulsion
{
    foreach ($this->byId as $e) {
        if ($e->decisionId !== null && $e->decisionId->value() === $decisionId->value()) return $e;
    }
    return null;
}
```

- [ ] **Step 5: Run + commit**

```bash
composer test -- --filter "AdvanceExpulsionToVoteTest|FileExpulsionAppealTest"
composer analyse
git add src/Application/Membership/AdvanceExpulsionToVote.php src/Application/Membership/FileExpulsionAppeal.php src/Application/Governance/Executor/ExpelExecutor.php src/Domain/Membership/MemberExpulsionRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlMemberExpulsionRepository.php tests/Support/Fake/InMemoryMemberExpulsionRepository.php tests/Unit/Application/Membership/AdvanceExpulsionToVoteTest.php tests/Unit/Application/Membership/FileExpulsionAppealTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/membership): AdvanceExpulsionToVote + FileExpulsionAppeal + ExpelExecutor + findByDecisionId"
```

---

# Wave G — GSA override (Task 31)

## Task 31: `GsaForceApproveBasic`

**Files:**

- Create: `src/Application/Audit/GsaForceApproveBasic.php`
- Create: `src/Application/Audit/GsaForceApproveBasicInput.php`
- Create: `tests/Unit/Application/Audit/GsaForceApproveBasicTest.php`

- [ ] **Step 1: Implement input + use case**

`src/Application/Audit/GsaForceApproveBasicInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Audit;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GsaForceApproveBasicInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $gsaUserId,
        public readonly string $applicationId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $at,
    ) {}
}
```

`src/Application/Audit/GsaForceApproveBasic.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Audit;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;

final class GsaForceApproveBasic
{
    /** @param callable(string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId):void $approve */
    public function __construct(
        private readonly GsaOverrideRepositoryInterface $overrides,
        private $approve,
    ) {}

    public function execute(GsaForceApproveBasicInput $in): GsaOverrideId
    {
        // Constructor enforces reason ≥ 10 chars via GsaOverrideRequiresReason.
        $id = GsaOverrideId::generate();
        $this->overrides->save(new GsaOverride(
            id:          $id,
            gsaUserId:   $in->gsaUserId,
            tenantId:    $in->tenantId,
            action:      GsaOverrideAction::ForceApproveBasic,
            targetId:    $in->applicationId,
            reason:      $in->reason,
            performedAt: $in->at,
        ));

        // Apply effect: same code path as a passed decision's ApproveBasicExecutor.
        $approve = $this->approve;
        $approve($in->applicationId, $in->at, null /* via_delegation_decision_id N/A for GSA override */);

        return $id;
    }
}
```

- [ ] **Step 2: Test reason validation + happy path**

`tests/Unit/Application/Audit/GsaForceApproveBasicTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Audit;

use Daems\Application\Audit\GsaForceApproveBasic;
use Daems\Application\Audit\GsaForceApproveBasicInput;
use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryGsaOverrideRepository;
use PHPUnit\Framework\TestCase;

final class GsaForceApproveBasicTest extends TestCase
{
    public function test_rejects_short_reason(): void
    {
        $repo = new InMemoryGsaOverrideRepository();
        $uc = new GsaForceApproveBasic($repo, static fn() => null);
        $this->expectException(GsaOverrideRequiresReason::class);
        $uc->execute(new GsaForceApproveBasicInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason: 'too short',         // <10 chars
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_happy_path_writes_audit_and_runs_approve(): void
    {
        $repo = new InMemoryGsaOverrideRepository();
        $called = false;
        $uc = new GsaForceApproveBasic($repo, function (string $id, \DateTimeImmutable $at, ?string $deleg) use (&$called) {
            $called = true;
        });
        $uc->execute(new GsaForceApproveBasicInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            applicationId: '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason: 'Test mode: bootstrap-tenant edge case requires direct approval.',
            at: new \DateTimeImmutable('2026-05-12'),
        ));
        $this->assertTrue($called);
    }
}
```

- [ ] **Step 3: Run + commit**

```bash
composer test -- --filter GsaForceApproveBasicTest
composer analyse
git add src/Application/Audit/ tests/Unit/Application/Audit/GsaForceApproveBasicTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application/audit): GsaForceApproveBasic — audited bypass for exceptional cases"
```

---

# Wave H — HTTP layer (Tasks 32–34)

Controllers, routes, backstage proxies, and DI wiring. After this wave the backend HTTP API is fully reachable.

## Task 32: BoardController + BoardDecisionController + routing

**Files:**

- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/BoardController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/BoardDecisionController.php`
- Modify: `public/backstage/api-router.php` (register new proxies)
- Create: `public/backstage/api/governance-board.php` (session-auth proxy)
- Create: `public/backstage/api/governance-decisions.php` (session-auth proxy)

- [ ] **Step 1: Implement `BoardController`**

`src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/BoardController.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Governance\BootstrapBoard;
use Daems\Application\Governance\BootstrapBoardInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BoardController
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly BootstrapBoard $bootstrap,
    ) {}

    public function index(Request $req): Response
    {
        $actor  = $req->requireActingUser();
        $board  = $this->boards->findForTenant($actor->activeTenant);
        if ($board === null) {
            return Response::json(['board' => null, 'members' => []]);
        }
        $list = $this->members->listForBoard($board->id);
        return Response::json([
            'board'   => [
                'id'                       => $board->id->value(),
                'bootstrapped_by_user_id'  => $board->bootstrappedByUserId->value(),
                'bootstrapped_at'          => $board->bootstrappedAt->format(\DateTimeInterface::ATOM),
            ],
            'members' => array_map(static fn($m) => [
                'id'                 => $m->id->value(),
                'user_id'            => $m->userId->value(),
                'role'               => $m->role->value,
                'term_started_at'    => $m->termStartedAt->format(\DateTimeInterface::ATOM),
                'term_ends_at'       => $m->termEndsAt->format(\DateTimeInterface::ATOM),
                'term_ended_at'      => $m->termEndedAt?->format(\DateTimeInterface::ATOM),
                'term_ended_reason'  => $m->termEndedReason?->value,
            ], $list),
        ]);
    }

    public function bootstrap(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isPlatformAdmin()) {
            throw new ForbiddenException('gsa_required');
        }
        /** @var array{members?: array<int, array<string,string>>} $body */
        $body = $req->jsonBody();
        /** @var list<array{user_id:string, role:string, term_started_at:string, term_ends_at:string}> $members */
        $members = is_array($body['members'] ?? null) ? array_values($body['members']) : [];

        $boardId = $this->bootstrap->execute(new BootstrapBoardInput(
            tenantId: $actor->activeTenant,
            gsaUserId: $actor->id,
            members: $members,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['board_id' => $boardId->value()], 201);
    }
}
```

- [ ] **Step 2: Implement `BoardDecisionController` (dispatcher across all propose-types)**

`src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/BoardDecisionController.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Governance\BoardDecisionExecutorRegistry;
use Daems\Application\Governance\CastBoardVote;
use Daems\Application\Governance\Delegate\ApproveBasicAsDelegate;
use Daems\Application\Governance\Delegate\AwardSubTierAsDelegate;
use Daems\Application\Governance\Delegate\InviteFullAsDelegate;
use Daems\Application\Governance\Propose\ProposeApproveBasic;
use Daems\Application\Governance\Propose\ProposeApproveBasicInput;
use Daems\Application\Governance\Propose\ProposeAwardSubTier;
use Daems\Application\Governance\Propose\ProposeAwardSubTierInput;
use Daems\Application\Governance\Propose\ProposeDelegateAuthority;
use Daems\Application\Governance\Propose\ProposeDelegateAuthorityInput;
use Daems\Application\Governance\Propose\ProposeInviteFull;
use Daems\Application\Governance\Propose\ProposeInviteFullInput;
use Daems\Application\Governance\Propose\ProposeRemoveBoardMember;
use Daems\Application\Governance\Propose\ProposeRemoveBoardMemberInput;
use Daems\Application\Governance\Propose\ProposeRevokeDelegation;
use Daems\Application\Governance\Propose\ProposeRevokeDelegationInput;
use Daems\Application\Governance\Propose\ProposeRevokeSubTier;
use Daems\Application\Governance\Propose\ProposeRevokeSubTierInput;
use Daems\Application\Governance\Propose\ProposeSubTierCrud;
use Daems\Application\Governance\Propose\ProposeSubTierCrudInput;
use Daems\Application\Governance\WithdrawBoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionVoteValue;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\Exception\BoardNotBootstrapped;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BoardDecisionController
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly ProposeApproveBasic $proposeApproveBasic,
        private readonly ApproveBasicAsDelegate $approveBasicDelegate,
        private readonly ProposeInviteFull $proposeInviteFull,
        private readonly InviteFullAsDelegate $inviteFullDelegate,
        private readonly ProposeAwardSubTier $proposeAwardSubTier,
        private readonly AwardSubTierAsDelegate $awardSubTierDelegate,
        private readonly ProposeRevokeSubTier $proposeRevokeSubTier,
        private readonly ProposeSubTierCrud $proposeSubTierCrud,
        private readonly ProposeRemoveBoardMember $proposeRemoveBoardMember,
        private readonly ProposeDelegateAuthority $proposeDelegateAuthority,
        private readonly ProposeRevokeDelegation $proposeRevokeDelegation,
        private readonly CastBoardVote $castVote,
        private readonly WithdrawBoardDecision $withdraw,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $board = $this->boards->findForTenant($actor->activeTenant);
        if ($board === null) return Response::json(['data' => []]);

        $status = $req->queryParam('status');
        $type   = $req->queryParam('type');
        $statusE = is_string($status) && $status !== '' ? BoardDecisionStatus::tryFrom($status) : null;
        $typeE   = is_string($type)   && $type   !== '' ? BoardDecisionType::tryFrom($type)     : null;

        $rows = [];
        foreach ($this->decisions->listForBoard($board->id, $statusE, $typeE) as $d) {
            $tally = ['yes' => 0, 'no' => 0, 'abstain' => 0];
            foreach ($this->votes->listForDecision($d->id) as $v) {
                $tally[$v->vote->value]++;
            }
            $rows[] = [
                'id'                => $d->id->value(),
                'decision_type'     => $d->decisionType->value,
                'threshold'         => $d->threshold->value,
                'mode'              => $d->mode->value,
                'vote_visibility'   => $d->voteVisibility->value,
                'status'            => $d->status->value,
                'proposed_by'       => $d->proposedByUserId->value(),
                'proposed_at'       => $d->proposedAt->format(\DateTimeInterface::ATOM),
                'expires_at'        => $d->expiresAt->format(\DateTimeInterface::ATOM),
                'resolved_at'       => $d->resolvedAt?->format(\DateTimeInterface::ATOM),
                'via_delegation'    => $d->viaDelegation,
                'meeting_reference' => $d->meetingReference,
                'tally'             => $tally,
            ];
        }
        return Response::json(['data' => $rows]);
    }

    public function show(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $d     = $this->decisions->find(BoardDecisionId::fromString($id))
            ?? throw new \DomainException('decision not found');

        // Identify viewer's board_member for the vote-visibility test.
        $board = $this->boards->findForTenant($actor->activeTenant);
        $viewerMemberId = null;
        if ($board !== null) {
            foreach ($this->members->listForBoard($board->id) as $m) {
                if ($m->userId->equals($actor->id)) { $viewerMemberId = $m->id->value(); break; }
            }
        }
        $viewerVoted = false;
        $votes = $this->votes->listForDecision($d->id);
        foreach ($votes as $v) {
            if ($viewerMemberId !== null && $v->boardMemberId->value() === $viewerMemberId) { $viewerVoted = true; break; }
        }

        $voteRows = [];
        $showIndividual = $d->voteVisibility === BoardDecisionVoteVisibility::Visible
            || $actor->isPlatformAdmin()
            || $viewerVoted;
        if ($showIndividual) {
            foreach ($votes as $v) {
                $voteRows[] = [
                    'board_member_id' => $v->boardMemberId->value(),
                    'vote'            => $v->vote->value,
                    'cast_at'         => $v->castAt->format(\DateTimeInterface::ATOM),
                ];
            }
        }
        $tally = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        foreach ($votes as $v) $tally[$v->vote->value]++;

        return Response::json([
            'decision' => $this->serializeDecision($d),
            'tally'    => $tally,
            'votes'    => $voteRows,
        ]);
    }

    /** Helper used by index/show. */
    private function serializeDecision(\Daems\Domain\Governance\BoardDecision $d): array
    {
        return [
            'id'                => $d->id->value(),
            'decision_type'     => $d->decisionType->value,
            'threshold'         => $d->threshold->value,
            'mode'              => $d->mode->value,
            'vote_visibility'   => $d->voteVisibility->value,
            'status'            => $d->status->value,
            'proposed_by'       => $d->proposedByUserId->value(),
            'proposed_at'       => $d->proposedAt->format(\DateTimeInterface::ATOM),
            'expires_at'        => $d->expiresAt->format(\DateTimeInterface::ATOM),
            'resolved_at'       => $d->resolvedAt?->format(\DateTimeInterface::ATOM),
            'via_delegation'    => $d->viaDelegation,
            'meeting_reference' => $d->meetingReference,
            'payload'           => $this->serializePayload($d),
        ];
    }

    private function serializePayload(\Daems\Domain\Governance\BoardDecision $d): array
    {
        return array_filter([
            'target_user_id'     => $d->payloadTargetUserId?->value(),
            'application_id'     => $d->payloadApplicationId,
            'sub_tier_slug'      => $d->payloadSubTierSlug,
            'sub_tier_name'      => $d->payloadSubTierName,
            'sub_tier_rank'      => $d->payloadSubTierRank,
            'sub_tier_applies_to'=> $d->payloadSubTierAppliesTo,
            'sub_tier_operation' => $d->payloadSubTierOperation?->value,
            'board_member_id'    => $d->payloadBoardMemberId?->value(),
            'delegation_type'    => $d->payloadDelegationType?->value,
            'delegated_to_role'  => $d->payloadDelegatedToRole,
            'reason'             => $d->payloadReason,
        ], static fn($v) => $v !== null);
    }

    public function proposeApproveBasic(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->jsonBody();
        $vis   = BoardDecisionVoteVisibility::from(is_string($body['vote_visibility'] ?? null) ? $body['vote_visibility'] : 'visible');
        $appId = is_string($body['application_id'] ?? null) ? $body['application_id'] : throw new \DomainException('application_id required');

        $input = new ProposeApproveBasicInput(
            tenantId: $actor->activeTenant,
            applicationId: $appId,
            proposedByUserId: $actor->id,
            voteVisibility: $vis,
            at: new \DateTimeImmutable(),
        );
        // Route through delegate-flow if available; else through propose-flow.
        // The delegate use case itself guards against missing delegation; we use a
        // simple try/catch to fall back cleanly.
        try {
            $id = $this->approveBasicDelegate->execute($input);
        } catch (\Daems\Domain\Governance\Exception\DelegationNotPermittedForType) {
            $id = $this->proposeApproveBasic->execute($input);
        }

        return Response::json(['decision_id' => $id->value()], 201);
    }

    // proposeInviteFull, proposeAwardSubTier, proposeRevokeSubTier, proposeSubTierCrud,
    // proposeRemoveBoardMember, proposeDelegateAuthority, proposeRevokeDelegation
    // follow the exact same shape — pull body fields, build input, call use case.
    // (Implementations are mechanical; copy the proposeApproveBasic shape and adjust
    // the body keys + use-case binding per the spec's API contract.)

    public function vote(Request $req, string $id): Response
    {
        $actor = $req->requireActingUser();
        $body  = $req->jsonBody();
        $vote  = BoardDecisionVoteValue::from(is_string($body['vote'] ?? null) ? $body['vote'] : 'abstain');
        $this->castVote->execute(
            decisionId:   BoardDecisionId::fromString($id),
            actingUserId: $actor->id,
            vote:         $vote,
            at:           new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }

    public function withdraw(Request $req, string $id): Response
    {
        $actor  = $req->requireActingUser();
        $body   = $req->jsonBody();
        $reason = is_string($body['withdrawal_reason'] ?? null) ? $body['withdrawal_reason'] : '';
        $this->withdraw->execute(
            decisionId:       BoardDecisionId::fromString($id),
            actingUserId:     $actor->id,
            withdrawalReason: $reason,
            at:               new \DateTimeImmutable(),
        );
        return Response::json(['ok' => true]);
    }
}
```

- [ ] **Step 3: Register routes in the API router**

Update wherever `BoardDecisionController::class` is dispatched (the platform's HTTP router — open `src/Infrastructure/Framework/Http/RouteRegistrar.php` or the routes file used by `Daems\Infrastructure\Adapter\Api\Controller\BackstageController` — follow whichever pattern the existing `MembershipSubTiersController` uses; grep for it):

```bash
grep -rn 'MembershipSubTiersController\|membership-subtiers' --include='*.php' src public/api 2>/dev/null
```

Bind these routes (path → controller method) using the same pattern:

```text
GET    /api/v1/backstage/governance/board                                 BoardController::index
POST   /api/v1/backstage/governance/board/bootstrap                       BoardController::bootstrap

GET    /api/v1/backstage/governance/decisions                              BoardDecisionController::index
GET    /api/v1/backstage/governance/decisions/{id}                         BoardDecisionController::show
POST   /api/v1/backstage/governance/decisions/approve-basic                BoardDecisionController::proposeApproveBasic
POST   /api/v1/backstage/governance/decisions/invite-full                  BoardDecisionController::proposeInviteFull
POST   /api/v1/backstage/governance/decisions/award-subtier                BoardDecisionController::proposeAwardSubTier
POST   /api/v1/backstage/governance/decisions/revoke-subtier               BoardDecisionController::proposeRevokeSubTier
POST   /api/v1/backstage/governance/decisions/subtier-crud                 BoardDecisionController::proposeSubTierCrud
POST   /api/v1/backstage/governance/decisions/remove-board-member          BoardDecisionController::proposeRemoveBoardMember
POST   /api/v1/backstage/governance/decisions/delegate-authority           BoardDecisionController::proposeDelegateAuthority
POST   /api/v1/backstage/governance/decisions/revoke-delegation            BoardDecisionController::proposeRevokeDelegation
POST   /api/v1/backstage/governance/decisions/{id}/vote                    BoardDecisionController::vote
POST   /api/v1/backstage/governance/decisions/{id}/withdraw                BoardDecisionController::withdraw
```

- [ ] **Step 4: Add session-auth proxy files**

`public/backstage/api/governance-board.php`:

```php
<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

header('Content-Type: application/json');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $r = ApiClient::get('/backstage/governance/board');
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
if ($method === 'POST') {
    // bootstrap
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $r = ApiClient::post('/backstage/governance/board/bootstrap', $body);
    http_response_code((int) ($r['status'] ?? 500));
    echo json_encode($r['body'] ?? []);
    exit;
}
http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
```

`public/backstage/api/governance-decisions.php`:

Mirror the same pattern but dispatch by HTTP method + a `?op=` query string for the various propose endpoints, OR add separate proxy files per endpoint (`governance-decisions-approve-basic.php` etc.) following the existing convention in `public/backstage/api/applications.php`. Pick whichever the existing project pattern uses.

- [ ] **Step 5: Register proxies in `public/backstage/api-router.php`**

Edit the `$map` array — add entries:

```php
'/api/backstage/governance/board'                       => __DIR__ . '/api/governance-board.php',
'/api/backstage/governance/decisions'                   => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/approve-basic'     => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/invite-full'       => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/award-subtier'     => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/revoke-subtier'    => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/subtier-crud'      => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/remove-board-member' => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/delegate-authority' => __DIR__ . '/api/governance-decisions.php',
'/api/backstage/governance/decisions/revoke-delegation'  => __DIR__ . '/api/governance-decisions.php',
```

(For paths with `{id}` parameters — `/decisions/{id}`, `/decisions/{id}/vote`, `/decisions/{id}/withdraw` — extend the router to do prefix-matching, OR use query-string `?id=…`. Mirror the existing `applications.php` pattern.)

- [ ] **Step 6: Commit**

```bash
composer analyse
git add src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/ public/backstage/api/governance-*.php public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api/backstage): BoardController + BoardDecisionController + 13 governance routes + session proxies"
```

---

## Task 33: ExpulsionController + DelegationController + EligibilityController + GsaOverrideController

**Files:**

- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/ExpulsionController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/DelegationController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/EligibilityController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/GsaOverrideController.php`
- Create: `public/backstage/api/governance-expulsions.php`
- Create: `public/backstage/api/governance-delegations.php`
- Create: `public/backstage/api/governance-eligibility.php`
- Create: `public/backstage/api/governance-gsa-overrides.php`
- Modify: `public/backstage/api-router.php`

- [ ] **Step 1: Implement each controller**

Pattern is identical to `BoardDecisionController` — inject the relevant use cases / repositories, parse `Request`, return `Response::json(...)`. Specific endpoints per the spec § "API contract":

**ExpulsionController** methods:

- `index(Request)` → calls `MemberExpulsionRepositoryInterface::listForTenant`
- `show(Request, $id)` → `find` + serialize statement, decision-link
- `initiate(Request)` → calls `InitiateMemberExpulsion`
- `statement(Request, $id)` → calls `SubmitExpulsionStatement`
- `advance(Request, $id)` → calls `AdvanceExpulsionToVote`
- `appeal(Request, $id)` → calls `FileExpulsionAppeal`

**DelegationController:**

- `index(Request)` → `BoardDelegationRepositoryInterface::listActive`

**EligibilityController:**

- `fullMembership(Request)` → query BASIC members from members repo; filter by `IsEligibleForFullMembership::check` for `NOW`; return list.

**GsaOverrideController:**

- `forceApproveBasic(Request)` → calls `GsaForceApproveBasic`. Auth: GSA only (`$actor->isPlatformAdmin()` else 403).

- [ ] **Step 2: Add routes + proxies + commit**

```bash
composer analyse
git add src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/ExpulsionController.php src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/DelegationController.php src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/EligibilityController.php src/Infrastructure/Adapter/Api/Controller/Backstage/Governance/GsaOverrideController.php public/backstage/api/governance-*.php public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api/backstage): Expulsion, Delegation, Eligibility, GsaOverride controllers + proxies"
```

---

## Task 34: DI wiring (BOTH containers)

**Files:**

- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Add 9 repository + 9 use-case + 9 executor + 5 controller bindings to `bootstrap/app.php`**

Append after the existing `// Membership Core v2 / 0.6a` block at line ~604. Sample shape (repeat for every new class):

```php
// Membership Core v2 / 0.6b — Governance (board + decisions + expulsions)
$container->bind(
    \Daems\Domain\Governance\BoardRepositoryInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository(
        $c->make(Connection::class)->pdo(),
    ),
);
// ... 8 more SQL-repo bindings ...

$container->singleton(
    \Daems\Application\Governance\BoardDecisionResolutionService::class,
    static fn() => new \Daems\Application\Governance\BoardDecisionResolutionService(),
);
$container->singleton(
    \Daems\Application\Governance\BoardDecisionExecutorRegistry::class,
    static function (Container $c) {
        $reg = new \Daems\Application\Governance\BoardDecisionExecutorRegistry();
        $reg->register($c->make(\Daems\Application\Governance\Executor\ApproveBasicExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\InviteFullExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\ExpelExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\AwardSubTierExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\RevokeSubTierExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\SubTierCrudExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\RemoveBoardMemberExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\DelegateAuthorityExecutor::class));
        $reg->register($c->make(\Daems\Application\Governance\Executor\RevokeDelegationExecutor::class));
        return $reg;
    },
);
$container->bind(
    \Daems\Application\Governance\ResolveBoardDecisionIfReady::class,
    static fn(Container $c) => new \Daems\Application\Governance\ResolveBoardDecisionIfReady(
        $c->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
        $c->make(\Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface::class),
        $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
        $c->make(\Daems\Application\Governance\BoardDecisionResolutionService::class),
        $c->make(\Daems\Application\Governance\BoardDecisionExecutorRegistry::class),
    ),
);
// ... CastBoardVote, WithdrawBoardDecision, BootstrapBoard, every Propose*, every Delegate*,
//     every Executor*, InitiateMemberExpulsion, SubmitExpulsionStatement, AdvanceExpulsionToVote,
//     FileExpulsionAppeal, GsaForceApproveBasic ...

// Controllers
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance\BoardController(
        $c->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
        $c->make(\Daems\Domain\Governance\BoardMemberRepositoryInterface::class),
        $c->make(\Daems\Application\Governance\BootstrapBoard::class),
    ),
);
// ... BoardDecisionController, ExpulsionController, DelegationController, EligibilityController, GsaOverrideController ...
```

**Critical closures bound here** (the executor closures expecting application-side logic):

```php
// ApproveBasicExecutor.approve — re-use the existing ApproveMemberApplication
// use case under modules/members so platform code does not duplicate it.
$container->bind(\Daems\Application\Governance\Executor\ApproveBasicExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\ApproveBasicExecutor(
        static function (string $applicationId, \DateTimeImmutable $at, ?string $viaDelegationDecisionId) use ($c): void {
            $approve = $c->make(\Daems\Modules\Members\Backend\Application\Backstage\ApproveMemberApplication\ApproveMemberApplication::class);
            $approve->execute(/* applicationId */ $applicationId, /* approvedAt */ $at);
            // viaDelegationDecisionId can be persisted in audit if needed — not required by 0.6b spec.
        },
    ),
);

// InviteFullExecutor.userPromoter
$container->bind(\Daems\Application\Governance\Executor\InviteFullExecutor::class,
    static fn(Container $c) => new \Daems\Application\Governance\Executor\InviteFullExecutor(
        static function (\Daems\Domain\User\UserId $userId, \DateTimeImmutable $at) use ($c): void {
            $pdo = $c->make(Connection::class)->pdo();
            $stmt = $pdo->prepare("UPDATE users SET membership_type='FULL', invited_to_full_at = ? WHERE id = ?");
            $stmt->execute([$at->format('Y-m-d H:i:s'), $userId->value()]);
        },
    ),
);

// AwardSubTierExecutor + RevokeSubTierExecutor — set/clear users.membership_subtier directly.
// ExpelExecutor.expelUser — set users.membership_status='expelled' etc. as documented in spec § Executors.
```

- [ ] **Step 2: Mirror every binding in `tests/Support/KernelHarness.php`**

Use `InMemory*Repository` fakes. Executor closures use in-memory side stores. The harness MUST also seed `tenant_governance_settings` defaults (14, 60) for the test tenant on construction so propose-use-cases find a real settings row.

```php
// Inside KernelHarness::__construct after the existing 0.6a wiring:
$this->governanceSettings = new \Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository();
$this->governanceSettings->save(new \Daems\Domain\Governance\TenantGovernanceSettings(
    $this->tenantId, expulsionHearingDays: 14, decisionExpirationDays: 60,
));

$this->boardDecisions = new \Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository();
$this->boardVotes     = new \Daems\Tests\Support\Fake\InMemoryBoardDecisionVoteRepository();
// ... etc for every repo ...

// Build the resolution + executor registry exactly as bootstrap/app.php does, but with
// in-memory user/application backends.
```

- [ ] **Step 3: Run end-to-end tests + PHPStan**

```bash
composer test
composer analyse
```

Expected: Unit + Integration + Isolation + E2E all green; PHPStan 0 errors.

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(bootstrap+harness): wire 35+ governance bindings in BOTH containers"
```

---

# Wave I — Backstage UI (Tasks 35–39)

10 new pages + member-list integrations. Implementation follows the existing `public/backstage/pages/` pattern (PHP-rendered templates + vanilla JS that calls the proxy under `/api/backstage/governance/*`).

## Task 35: Hallitus-sivu + bootstrap-lomake

**Files:**

- Create: `public/backstage/pages/governance/board.php`
- Create: `public/backstage/pages/governance/board.js`
- Create: `public/backstage/pages/governance/board.css`
- Create: `public/backstage/pages/governance/_shared.php` (shared layout snippets)
- Modify: `public/backstage/pages/_sidebar-icons.php` (add "Hallinto" group)
- Modify: `public/backstage/router.php` (register `/backstage/governance/*` paths)

- [ ] **Step 1: Register the route + sidebar group**

In `public/backstage/router.php` add (mirror the existing pattern for `/backstage/members`):

```php
'/backstage/governance/board'        => __DIR__ . '/pages/governance/board.php',
'/backstage/governance/decisions'    => __DIR__ . '/pages/governance/decisions-list.php',
'/backstage/governance/decisions/new'=> __DIR__ . '/pages/governance/decisions-new.php',
'/backstage/governance/expulsions'   => __DIR__ . '/pages/governance/expulsions-list.php',
'/backstage/governance/expulsions/new'=> __DIR__ . '/pages/governance/expulsions-new.php',
'/backstage/governance/delegations'  => __DIR__ . '/pages/governance/delegations.php',
'/backstage/governance/settings'     => __DIR__ . '/pages/governance/settings.php',
```

(For `/backstage/governance/decisions/{id}` and `/backstage/governance/expulsions/{id}` the existing router pattern uses `?id=…` query strings — follow that.)

In `public/backstage/pages/_sidebar-icons.php` add a new group "Hallinto" with one entry per page, gated to render only when:

- The active tenant has a `boards` row OR the actor is GSA (so the bootstrap CTA stays reachable).
- (Implementation: small inline SQL or a GET to `/api/backstage/governance/board` cached in `$_SESSION`.)

- [ ] **Step 2: Implement `board.php`**

`public/backstage/pages/governance/board.php`:

```php
<?php
require_once __DIR__ . '/../_shared.php';
require_once __DIR__ . '/../../_guard.php';
$page = 'governance-board';
require __DIR__ . '/../layout.php';  // outputs header + sidebar + main wrapper
?>
<main class="page page--governance-board">
  <header class="page-header">
    <h1 data-i18n="governance.board.title">Hallitus</h1>
  </header>

  <section id="board-roster" hidden>
    <div class="board-cards" id="board-cards"></div>
  </section>

  <section id="board-bootstrap" hidden>
    <div class="bootstrap-banner">
      <h2 data-i18n="governance.board.bootstrap.title">Hallitus ei ole vielä istutettu</h2>
      <p data-i18n="governance.board.bootstrap.help">Vain GSA voi istuttaa ensimmäisen hallituksen.</p>
      <button id="open-bootstrap" class="btn-primary" data-i18n="governance.board.bootstrap.cta">Istuta hallitus</button>
    </div>

    <dialog id="bootstrap-modal">
      <form id="bootstrap-form">
        <h3 data-i18n="governance.board.bootstrap.title">Istuta hallitus</h3>
        <div id="bootstrap-rows"></div>
        <button type="button" id="add-row" class="btn-secondary" data-i18n="governance.board.bootstrap.add_row">+ Lisää jäsen</button>
        <div class="dialog-actions">
          <button type="button" id="cancel-bootstrap" class="btn-ghost" data-i18n="common.cancel">Peruuta</button>
          <button type="submit" class="btn-primary" data-i18n="governance.board.bootstrap.cta">Istuta hallitus</button>
        </div>
      </form>
    </dialog>
  </section>
</main>
<script src="/backstage/pages/governance/board.js" defer></script>
<link rel="stylesheet" href="/backstage/pages/governance/board.css">
```

- [ ] **Step 3: Implement `board.js`**

`public/backstage/pages/governance/board.js`:

```javascript
(async () => {
  const res = await fetch('/api/backstage/governance/board', { credentials: 'same-origin' });
  const data = await res.json();
  if (data.board) {
    document.querySelector('#board-roster').hidden = false;
    const list = document.querySelector('#board-cards');
    for (const m of data.members) {
      const div = document.createElement('div');
      div.className = 'board-card';
      div.dataset.role = m.role;
      div.innerHTML = `
        <div class="board-card__role">${m.role === 'chair' ? 'Puheenjohtaja' : 'Jäsen'}</div>
        <div class="board-card__user">${m.user_id}</div>
        <div class="board-card__term">${m.term_started_at.slice(0,10)} – ${m.term_ends_at.slice(0,10)}</div>
        ${m.term_ended_at ? `<div class="board-card__ended">Päättynyt: ${m.term_ended_reason}</div>` : ''}
      `;
      list.appendChild(div);
    }
  } else {
    document.querySelector('#board-bootstrap').hidden = false;
    wireBootstrap();
  }

  function wireBootstrap() {
    const open  = document.querySelector('#open-bootstrap');
    const modal = document.querySelector('#bootstrap-modal');
    const form  = document.querySelector('#bootstrap-form');
    open.addEventListener('click', () => modal.showModal());
    document.querySelector('#cancel-bootstrap').addEventListener('click', () => modal.close());

    document.querySelector('#add-row').addEventListener('click', () => addRow());
    addRow();  // start with one row

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const rows = [...document.querySelectorAll('.bootstrap-row')];
      const members = rows.map(r => ({
        user_id:         r.querySelector('[name=user_id]').value,
        role:            r.querySelector('[name=role]').value,
        term_started_at: r.querySelector('[name=term_started_at]').value,
        term_ends_at:    r.querySelector('[name=term_ends_at]').value,
      }));
      const r = await fetch('/api/backstage/governance/board', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ members }),
      });
      if (r.ok) location.reload();
      else alert((await r.json()).error || 'failed');
    });
  }

  function addRow() {
    const wrap = document.querySelector('#bootstrap-rows');
    const div  = document.createElement('div');
    div.className = 'bootstrap-row';
    div.innerHTML = `
      <input name="user_id"         placeholder="user_id"          required>
      <select name="role">
        <option value="chair">Pj</option>
        <option value="member" selected>Jäsen</option>
      </select>
      <input name="term_started_at" type="date" required>
      <input name="term_ends_at"    type="date" required>
      <button type="button" class="remove" aria-label="poista">×</button>
    `;
    div.querySelector('.remove').addEventListener('click', () => div.remove());
    wrap.appendChild(div);
  }
})();
```

- [ ] **Step 4: Commit**

```bash
git add public/backstage/pages/governance/board.* public/backstage/pages/_sidebar-icons.php public/backstage/router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): /governance/board page + bootstrap modal + sidebar group"
```

---

## Task 36: Decisions list + new + detail pages

**Files:**

- Create: `public/backstage/pages/governance/decisions-list.php`
- Create: `public/backstage/pages/governance/decisions-list.js`
- Create: `public/backstage/pages/governance/decisions-new.php`
- Create: `public/backstage/pages/governance/decisions-new.js`
- Create: `public/backstage/pages/governance/decisions-detail.php`
- Create: `public/backstage/pages/governance/decisions-detail.js`
- Create: `public/backstage/pages/governance/decisions.css` (shared)

- [ ] **Step 1: `decisions-list.php` + JS**

`decisions-list.php`:

```php
<?php require_once __DIR__ . '/../_shared.php'; require_once __DIR__ . '/../../_guard.php'; $page='governance-decisions'; require __DIR__ . '/../layout.php'; ?>
<main class="page page--governance-decisions">
  <header class="page-header">
    <h1 data-i18n="governance.decision.list.title">Hallituksen päätökset</h1>
    <a href="/backstage/governance/decisions/new" class="btn-primary" data-i18n="governance.decision.action.propose">Uusi ehdotus</a>
  </header>
  <section class="filters">
    <select id="filter-status">
      <option value="" data-i18n="governance.decision.filter.any_status">Kaikki tilat</option>
      <option value="pending"   data-i18n="governance.decision.status.pending">Vireillä</option>
      <option value="passed"    data-i18n="governance.decision.status.passed">Hyväksytty</option>
      <option value="rejected"  data-i18n="governance.decision.status.rejected">Hylätty</option>
      <option value="expired"   data-i18n="governance.decision.status.expired">Vanhentunut</option>
      <option value="withdrawn" data-i18n="governance.decision.status.withdrawn">Peruutettu</option>
    </select>
    <label><input type="checkbox" id="filter-mine"> <span data-i18n="governance.decision.filter.my_pending">Vain odottaa ääntäni</span></label>
  </section>
  <table id="decisions">
    <thead><tr>
      <th data-i18n="governance.decision.col.type">Tyyppi</th>
      <th data-i18n="governance.decision.col.threshold">Kynnys</th>
      <th data-i18n="governance.decision.col.tally">Äänet</th>
      <th data-i18n="governance.decision.col.status">Tila</th>
      <th data-i18n="governance.decision.col.expires_at">Vanhenee</th>
      <th></th>
    </tr></thead>
    <tbody></tbody>
  </table>
</main>
<script src="/backstage/pages/governance/decisions-list.js" defer></script>
<link rel="stylesheet" href="/backstage/pages/governance/decisions.css">
```

`decisions-list.js`:

```javascript
(async () => {
  const tbody = document.querySelector('#decisions tbody');
  async function load() {
    const status = document.querySelector('#filter-status').value;
    const mine   = document.querySelector('#filter-mine').checked;
    const url = new URL('/api/backstage/governance/decisions', location.origin);
    if (status) url.searchParams.set('status', status);
    if (mine)   url.searchParams.set('my_pending', '1');
    const r = await fetch(url, { credentials: 'same-origin' });
    const { data } = await r.json();
    tbody.innerHTML = '';
    for (const d of data) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${d.decision_type}</td>
        <td>${d.threshold} (${d.mode})</td>
        <td>${d.tally.yes}/${d.tally.no}/${d.tally.abstain}</td>
        <td><span class="badge badge--${d.status}">${d.status}</span></td>
        <td>${d.expires_at?.slice(0,10) ?? ''}</td>
        <td><a href="/backstage/governance/decisions?id=${d.id}">Avaa</a></td>
      `;
      tbody.appendChild(tr);
    }
  }
  document.querySelector('#filter-status').addEventListener('change', load);
  document.querySelector('#filter-mine').addEventListener('change', load);
  load();
})();
```

- [ ] **Step 2: `decisions-new.php` + JS — wizard with type-specific forms**

The wizard has a `<select id="type">` listing every decision_type, and shows the matching sub-form (target user picker for approve_basic/invite_full/expel; subtier picker for award; CRUD form for subtier_crud; etc.). All sub-forms include `vote_visibility` + `meeting_reference` fields (the latter hidden when mode would be Async). On submit, POST to the corresponding `/api/backstage/governance/decisions/<type>` endpoint.

- [ ] **Step 3: `decisions-detail.php` + JS — vote panel**

Renders the decision payload, tally bar, and vote list (only when visible OR viewer has voted OR viewer is GSA — server already redacts). Voting panel: three radio buttons (yes/no/abstain) + Cast button → POSTs to `/api/backstage/governance/decisions/<id>/vote`. Proposer/chair also see a Withdraw button.

- [ ] **Step 4: Commit**

```bash
git add public/backstage/pages/governance/decisions-*.php public/backstage/pages/governance/decisions-*.js public/backstage/pages/governance/decisions.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): /governance/decisions list + new wizard + detail with vote panel"
```

---

## Task 37: Expulsions list + new + detail pages

**Files:**

- Create: `public/backstage/pages/governance/expulsions-list.php`
- Create: `public/backstage/pages/governance/expulsions-list.js`
- Create: `public/backstage/pages/governance/expulsions-new.php`
- Create: `public/backstage/pages/governance/expulsions-new.js`
- Create: `public/backstage/pages/governance/expulsions-detail.php`
- Create: `public/backstage/pages/governance/expulsions-detail.js`

Pattern matches Task 36.

- **List page**: status filter (hearing / awaiting_vote / expelled / rejected / appealed). One row per `member_expulsions` row showing target name + status + hearing deadline.
- **New page**: target-user picker (autocomplete against members), reason textarea, hearing_deadline preview computed client-side as `NOW + tenant_governance_settings.expulsion_hearing_days` (fetched from `/api/backstage/tenant-settings`). Submit POSTs to `/api/backstage/governance/expulsions`.
- **Detail page**:
  - Shows reason + hearing_deadline + statement (if submitted).
  - Statement editor visible to the target user (auth check via session).
  - "Advance to vote" button visible to chair only when (`statement_received_at !== null` OR `now >= hearing_deadline_at`).
  - Once `decision_id` is set, shows a link to the linked decision.
  - After status=Expelled, shows an "Tee valitus" textarea for the target user (submits `/api/backstage/governance/expulsions/<id>/appeal`).

- [ ] **Step 1-4: Implement + commit**

```bash
git add public/backstage/pages/governance/expulsions-*.php public/backstage/pages/governance/expulsions-*.js
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): /governance/expulsions list + new + detail with hearing/statement/appeal flows"
```

---

## Task 38: Delegations + Settings pages

**Files:**

- Create: `public/backstage/pages/governance/delegations.php`
- Create: `public/backstage/pages/governance/delegations.js`
- Create: `public/backstage/pages/governance/settings.php`
- Create: `public/backstage/pages/governance/settings.js`

**Delegations** lists every active delegation (decision_type + delegated_to_role + valid_since). "Ehdota peruutusta" button → opens decision-new with type=revoke_delegation pre-filled.

**Settings** is a small form with two number inputs: `expulsion_hearing_days` + `decision_expiration_days`. Read-only for board members, editable for admin/GSA (toggle by checking `actor.role` / `actor.is_platform_admin`). Save POSTs to a new tenant-settings endpoint OR (simpler) re-uses the existing `tenant-settings` proxy by extending it — pick whichever fits.

The simplest path: add the two fields to the existing `tenant_settings`-like backend endpoint. Implementer can wire either by adding a new route or by extending `TenantSelfController`. Note this as a small free-text follow-up in the commit message.

- [ ] **Step 1: Implement + commit**

```bash
git add public/backstage/pages/governance/delegations.* public/backstage/pages/governance/settings.*
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): /governance/delegations + /governance/settings pages"
```

---

## Task 39: Members-page integrations + Applications-flow update

**Files:**

- Modify: `public/backstage/pages/members.php` (action menu) — file may need exploration first; look for the existing per-row actions block
- Modify: `public/backstage/pages/members.js` (or wherever members-listing JS lives) — add new actions
- Modify: `public/backstage/pages/applications.php` / corresponding JS — replace "Hyväksy" one-click with delegation-aware flow

- [ ] **Step 1: Add board-only row-action "Ehdota erottamista"**

In the per-row dropdown menu of `/backstage/members`, render the new option only when `actor.is_board_member === true`. Hook to `window.location = '/backstage/governance/expulsions/new?target_user_id=' + row.user_id`.

- [ ] **Step 2: Add member-detail buttons**

On the member-detail page, render two buttons (board-only):

- "Ehdota FULL-kutsua" — only shown when `eligibility.full_membership` returns true for this user (call `/api/backstage/governance/eligibility/full-membership` and check the response).
- "Ehdota sub-tier-myöntämistä" — always shown for board members when membership_type is SUPPORTING or BASIC.

- [ ] **Step 3: Update Applications "Hyväksy" flow**

When admin clicks "Hyväksy" on `/backstage/members?view=applications`:

1. Fetch `/api/backstage/governance/delegations` and check whether `approve_basic` is delegated to admin.
2. If YES: call `/api/backstage/governance/decisions/approve-basic` directly — backend handles delegation short-circuit and creates a Passed decision.
3. If NO: open a modal "Ehdota hyväksyntää hallitukselle?" with vote_visibility selector, then POST the same endpoint (which creates a Pending decision).
4. If the actor is GSA, also show a "GSA-override" button → opens a different modal asking for a >=10-char reason, then POSTs to `/api/backstage/governance/gsa-overrides/approve-basic`.

- [ ] **Step 4: Browser smoke + commit**

Spin up Laragon, log in as `dev@daems.fi`, navigate to `/backstage/governance/board` and confirm:

- Bootstrap modal renders for GSA on the daems tenant (which has no board).
- After bootstrap, roster cards render.
- `/backstage/governance/decisions` is reachable; "Uusi ehdotus" wizard accepts approve_basic.
- Applications page shows updated flow.

```bash
git add public/backstage/pages/members.* public/backstage/pages/applications.* public/backstage/pages/governance/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(backstage/ui): members + applications integrations for governance flow"
```

---

# Wave J — Dashboard widgets (Tasks 40–41)

## Task 40: 4 widget classes

**Files:**

- Create: `src/Infrastructure/Dashboard/CoreWidgets/PendingDecisionsForMeKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/EligibleForFullMembershipWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/OpenExpulsionsKpiWidget.php`
- Create: `src/Infrastructure/Dashboard/CoreWidgets/DelegationsActiveKpiWidget.php`
- Create: `tests/Unit/Infrastructure/Dashboard/GovernanceWidgetsTest.php`

Each widget follows the same shape as `MembersByTierKpiWidget` (already in this repo from 0.6a). Sample for the simplest:

`src/Infrastructure/Dashboard/CoreWidgets/PendingDecisionsForMeKpiWidget.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\WidgetInterface;
use Daems\Domain\Dashboard\WidgetRenderContext;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardRepositoryInterface;

final class PendingDecisionsForMeKpiWidget implements WidgetInterface
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
    ) {}

    public function id(): string             { return 'pending_decisions_for_me_kpi'; }
    public function defaultSpan(): int       { return 1; }
    public function labelKey(): string       { return 'backstage.dashboard.widget.pending_decisions_for_me_kpi.label'; }
    public function descriptionKey(): string { return 'backstage.dashboard.widget.pending_decisions_for_me_kpi.description'; }

    public function render(WidgetRenderContext $ctx): array
    {
        $board = $this->boards->findForTenant($ctx->tenantId);
        if ($board === null) return ['count' => 0];
        $pending = $this->decisions->listForBoard($board->id, BoardDecisionStatus::Pending);
        return ['count' => count($pending)];
    }
}
```

`EligibleForFullMembershipWidget` (span 2): queries the BASIC + 12-month list (already covered by the EligibilityController endpoint — widget can call the same domain query directly or re-implement via UserRepository).

`OpenExpulsionsKpiWidget` (span 1): count `member_expulsions` where status IN (`hearing`, `awaiting_vote`).

`DelegationsActiveKpiWidget` (span 1): `BoardDelegationRepositoryInterface::listActive` count.

- [ ] **Step 1: Implement all 4**
- [ ] **Step 2: Unit-test render()** — each test instantiates the widget with InMemory fakes, seeds a couple of rows, asserts the returned shape.

- [ ] **Step 3: Run + commit**

```bash
composer test -- --filter GovernanceWidgetsTest
composer analyse
git add src/Infrastructure/Dashboard/CoreWidgets/PendingDecisionsForMeKpiWidget.php src/Infrastructure/Dashboard/CoreWidgets/EligibleForFullMembershipWidget.php src/Infrastructure/Dashboard/CoreWidgets/OpenExpulsionsKpiWidget.php src/Infrastructure/Dashboard/CoreWidgets/DelegationsActiveKpiWidget.php tests/Unit/Infrastructure/Dashboard/GovernanceWidgetsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/dashboard): 4 governance widgets (pending-for-me, eligible-for-full, open-expulsions, active-delegations)"
```

---

## Task 41: Register widgets + update default layouts + DefaultLayoutsTest

**Files:**

- Modify: `bootstrap/app.php` (register the 4 widgets in `WidgetRegistry`)
- Modify: `tests/Support/KernelHarness.php` (register InMemory variants)
- Modify: `src/Domain/Dashboard/DefaultLayouts.php` (add to admin + GSA defaults)
- Modify: `tests/Unit/Domain/Dashboard/DefaultLayoutsTest.php` (expected widget count rises 9 → 13)

- [ ] **Step 1: Register widgets**

In `bootstrap/app.php` after the existing 0.6a `MembersByTierKpiWidget` block (~ line 692):

```php
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\PendingDecisionsForMeKpiWidget(
    $container->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
    $container->make(\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\EligibleForFullMembershipWidget(
    $container->make(\Daems\Domain\Governance\BoardRepositoryInterface::class),
    /* + the user-repo or domain query dependency */
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\OpenExpulsionsKpiWidget(
    $container->make(\Daems\Domain\Membership\MemberExpulsionRepositoryInterface::class),
));
$registry->register(new \Daems\Infrastructure\Dashboard\CoreWidgets\DelegationsActiveKpiWidget(
    $container->make(\Daems\Domain\Governance\BoardDelegationRepositoryInterface::class),
));
```

Mirror the same `WidgetRegistry::register(...)` calls in `KernelHarness`.

- [ ] **Step 2: Update default layouts**

`src/Domain/Dashboard/DefaultLayouts.php` — append the 4 widget ids to:

- `adminDefault()` — append `pending_decisions_for_me_kpi` (span 1), `eligible_for_full_membership` (span 2), `open_expulsions_kpi` (span 1), `delegations_active_kpi` (span 1).
- `gsaDefault()` — same 4.
- (Moderator default remains unchanged.)

- [ ] **Step 3: Update `DefaultLayoutsTest`**

The existing test (per `memory/project_kpi_strips_polish_backlog.md` and the 0.6a commit `2161668`) currently expects 9 widgets after MembersByTierKpiWidget. Bump expected to 13.

```php
public function test_admin_default_has_thirteen_widgets(): void
{
    $layout = DefaultLayouts::adminDefault();
    $this->assertCount(13, $layout->widgets);
}
```

- [ ] **Step 4: Run + commit**

```bash
composer test -- --filter DefaultLayoutsTest
composer test
composer analyse
git add bootstrap/app.php tests/Support/KernelHarness.php src/Domain/Dashboard/DefaultLayouts.php tests/Unit/Domain/Dashboard/DefaultLayoutsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(dashboard): register 4 governance widgets in admin+GSA default layouts (count 9→13)"
```

---

# Wave K — i18n + E2E + final verification (Tasks 42–44)

## Task 42: i18n keys × 3 locales

**Files:**

- Modify: `lang/fi_FI.php`
- Modify: `lang/en_GB.php`
- Modify: `lang/sw_TZ.php`

- [ ] **Step 1: Add the ~95 keys per the spec § "i18n-avaimet"**

Add each key in all three locale files. Use the values from the spec where given (Finnish in `fi_FI`, English in `en_GB`). For Swahili use a reasonable translation; consulting a native speaker is not a blocker.

Sample for the first cluster:

`lang/fi_FI.php` — append at end of the existing array:

```php
'governance.board.title'                                                => 'Hallitus',
'governance.board.bootstrap.title'                                      => 'Istuta hallitus',
'governance.board.bootstrap.cta'                                        => 'Istuta hallitus',
'governance.board.bootstrap.help'                                       => 'Vain GSA voi istuttaa ensimmäisen hallituksen.',
'governance.board.bootstrap.add_row'                                    => '+ Lisää jäsen',
'governance.board.members.empty'                                        => 'Ei hallitusta',
'governance.board.member.role.chair'                                    => 'Puheenjohtaja',
'governance.board.member.role.member'                                   => 'Jäsen',
'governance.board.member.term_starts'                                   => 'Toimikausi alkaa',
'governance.board.member.term_ends'                                     => 'Toimikausi päättyy',
'governance.board.member.days_remaining'                                => 'päivää jäljellä',
'governance.board.member.ended.resigned'                                => 'Eronnut',
'governance.board.member.ended.removed'                                 => 'Erotettu hallituksesta',
'governance.board.member.ended.lost_full_status'                        => 'Menetti FULL-statuksen',
'governance.board.member.ended.term_expired'                            => 'Toimikausi päättynyt',

'governance.decision.type.approve_basic'                                => 'Hyväksy perusjäseneksi',
'governance.decision.type.invite_full'                                  => 'Kutsu varsinaiseksi jäseneksi',
'governance.decision.type.expel'                                        => 'Erota jäsen',
'governance.decision.type.award_subtier'                                => 'Myönnä alatason kunnia',
'governance.decision.type.revoke_subtier'                               => 'Peru alatason kunnia',
'governance.decision.type.subtier_crud'                                 => 'Muokkaa kunniaportaikkoa',
'governance.decision.type.remove_board_member'                          => 'Poista hallituksesta',
'governance.decision.type.delegate_authority'                           => 'Delegoi päätösvalta',
'governance.decision.type.revoke_delegation'                            => 'Peru delegointi',

'governance.decision.threshold.unanimous'                               => 'Yksimielinen',
'governance.decision.threshold.majority'                                => 'Enemmistö',
'governance.decision.mode.async'                                        => 'Ilman kokousta',
'governance.decision.mode.sync'                                         => 'Kokouksessa',
'governance.decision.status.pending'                                    => 'Vireillä',
'governance.decision.status.passed'                                     => 'Hyväksytty',
'governance.decision.status.rejected'                                   => 'Hylätty',
'governance.decision.status.expired'                                    => 'Vanhentunut',
'governance.decision.status.withdrawn'                                  => 'Peruutettu',
'governance.decision.vote.yes'                                          => 'Kyllä',
'governance.decision.vote.no'                                           => 'Ei',
'governance.decision.vote.abstain'                                      => 'Tyhjä',
'governance.decision.vote_visibility.visible'                           => 'Äänet näkyvät',
'governance.decision.vote_visibility.anonymous'                         => 'Anonyymi',
'governance.decision.action.propose'                                    => 'Tee ehdotus',
'governance.decision.action.vote'                                       => 'Äänestä',
'governance.decision.action.withdraw'                                   => 'Peruuta',
'governance.decision.tally'                                             => 'Äänet',
'governance.decision.expires_in_days'                                   => 'vanhenee :days vrk:n päästä',
'governance.decision.via_delegation'                                    => 'Delegoinnin kautta',
'governance.decision.meeting_reference.label'                           => 'Kokousviite',
'governance.decision.meeting_reference.placeholder'                     => 'esim. hallituksen kokous 2026-05-20, PK-12',
'governance.decision.subtier_crud.operation.create'                     => 'Luo uusi',
'governance.decision.subtier_crud.operation.update'                     => 'Muokkaa',
'governance.decision.subtier_crud.operation.delete'                     => 'Poista',
'governance.decision.col.type'                                          => 'Tyyppi',
'governance.decision.col.threshold'                                     => 'Kynnys',
'governance.decision.col.tally'                                         => 'Äänet',
'governance.decision.col.status'                                        => 'Tila',
'governance.decision.col.expires_at'                                    => 'Vanhenee',
'governance.decision.list.title'                                        => 'Hallituksen päätökset',
'governance.decision.filter.any_status'                                 => 'Kaikki tilat',
'governance.decision.filter.my_pending'                                 => 'Vain odottaa ääntäni',

'governance.expulsion.status.hearing'                                   => 'Kuulemisessa',
'governance.expulsion.status.awaiting_vote'                             => 'Odottaa äänestystä',
'governance.expulsion.status.expelled'                                  => 'Erotettu',
'governance.expulsion.status.rejected'                                  => 'Erottaminen hylätty',
'governance.expulsion.status.appealed'                                  => 'Valitettu',
'governance.expulsion.hearing_deadline'                                 => 'Kuulemisaika päättyy',
'governance.expulsion.statement.label'                                  => 'Vastineesi',
'governance.expulsion.statement.empty'                                  => 'Ei vastinetta',
'governance.expulsion.statement.submit'                                 => 'Lähetä vastine',
'governance.expulsion.advance_to_vote'                                  => 'Vie äänestykseen',
'governance.expulsion.advance_blocked_until_deadline'                   => 'Kuulemisaika ei ole vielä päättynyt',
'governance.expulsion.appeal.label'                                     => 'Valitus vuosikokoukselle',
'governance.expulsion.appeal.submit'                                    => 'Tee valitus',
'governance.expulsion.appeal.note_deferred_to_next_meeting'             => 'Valituksesi käsitellään seuraavassa vuosikokouksessa.',

'governance.delegation.title'                                           => 'Delegoinnit',
'governance.delegation.delegated_to.admin'                              => 'Adminille',
'governance.delegation.active_since'                                    => 'Voimassa alkaen',
'governance.delegation.revoke'                                          => 'Ehdota peruutusta',

'governance.settings.title'                                             => 'Hallinnon asetukset',
'governance.settings.expulsion_hearing_days.label'                      => 'Erottamisen kuulemisaika (vrk)',
'governance.settings.expulsion_hearing_days.help'                       => 'Aika jonka jäsen saa antaa vastineensa ennen äänestystä.',
'governance.settings.decision_expiration_days.label'                    => 'Päätöksen vanhenemisaika (vrk)',
'governance.settings.decision_expiration_days.help'                     => 'Vireillä oleva päätös merkitään automaattisesti vanhentuneeksi.',

'governance.eligibility.full_membership.title'                          => 'FULL-kelpoiset jäsenet',
'governance.eligibility.full_membership.empty'                          => 'Ei tällä hetkellä kelpoisia',
'governance.eligibility.full_membership.months_since_join'              => 'kk jäsenenä',

'governance.gsa_override.approve_basic.title'                           => 'GSA-pakkohyväksyntä',
'governance.gsa_override.approve_basic.reason.label'                    => 'Perustelu (vähintään 10 merkkiä)',
'governance.gsa_override.approve_basic.confirm'                         => 'Pakkohyväksy',

'governance.error.not_a_board_member'                                   => 'Et ole hallituksen jäsen',
'governance.error.async_requires_unanimous'                             => 'Async-päätös vaatii yksimielisyyden (§ 7)',
'governance.error.board_already_bootstrapped'                           => 'Hallitus on jo istutettu',
'governance.error.board_not_bootstrapped'                               => 'Hallitusta ei ole vielä istutettu',
'governance.error.not_eligible_for_full'                                => 'Jäsen ei ole vielä FULL-kelpoinen',
'governance.error.vote_already_cast'                                    => 'Ääni on jo annettu',
'governance.error.decision_already_resolved'                            => 'Päätös on jo ratkaistu',
'governance.error.insufficient_quorum'                                  => 'Päätösvaltaisuus ei täyty',
'governance.error.delegation_not_permitted_for_type'                    => 'Tätä päätöstyyppiä ei voi delegoida',
'governance.error.duplicate_active_delegation'                          => 'Aktiivinen delegointi on jo voimassa',
'governance.error.gsa_override_requires_reason'                         => 'GSA-pakkohyväksyntä vaatii perustelun (≥10 merkkiä)',
'governance.error.invalid_bootstrap_roster_size'                        => 'Hallituksessa on oltava 1–5 jäsentä',
'governance.error.invalid_bootstrap_roster_chair'                       => 'Hallituksessa on oltava täsmälleen yksi puheenjohtaja',
'governance.error.board_candidate_not_full'                             => 'Vain FULL-jäsen voi olla hallituksessa',
'governance.error.expulsion_hearing_not_elapsed'                        => 'Kuulemisaika ei ole vielä päättynyt',
'governance.error.expulsion_already_advanced'                           => 'Erottaminen on jo edennyt äänestysvaiheeseen',
'governance.error.appeal_already_filed'                                 => 'Valitus on jo tehty',
'governance.error.last_board_member_cannot_be_removed'                  => 'Hallituksen viimeistä jäsentä ei voi poistaa',

'backstage.dashboard.widget.pending_decisions_for_me_kpi.label'         => 'Odottaa ääntäni',
'backstage.dashboard.widget.pending_decisions_for_me_kpi.description'   => 'Hallituksen päätökset, joihin et ole vielä äänestänyt.',
'backstage.dashboard.widget.eligible_for_full_membership.label'         => 'Kelpoiset FULL-kutsuun',
'backstage.dashboard.widget.eligible_for_full_membership.description'   => 'BASIC-jäsenet, jotka ovat olleet jäsenenä vähintään 12 kuukautta.',
'backstage.dashboard.widget.open_expulsions_kpi.label'                  => 'Avoimet erottamiset',
'backstage.dashboard.widget.open_expulsions_kpi.description'            => 'Erottamisprosessit kuulemis- tai äänestysvaiheessa.',
'backstage.dashboard.widget.delegations_active_kpi.label'               => 'Aktiiviset delegoinnit',
'backstage.dashboard.widget.delegations_active_kpi.description'         => 'Voimassa olevat delegoinnit.',

'backstage.sidebar.governance'                                          => 'Hallinto',
'common.cancel'                                                         => 'Peruuta',
```

- [ ] **Step 2: Repeat for `en_GB.php` and `sw_TZ.php`**

Use English and Swahili translations from the spec where given. For the few new keys, use idiomatic English and Swahili (e.g. `'Utawala'` for `backstage.sidebar.governance`, `'Cancel'` / `'Ghairi'`, etc.).

- [ ] **Step 3: Run i18n parity test**

```bash
composer test -- --filter I18nParityTest
```

Expected: all 3 locales hold the same key set.

- [ ] **Step 4: Commit**

```bash
git add lang/fi_FI.php lang/en_GB.php lang/sw_TZ.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(i18n/governance): ~95 keys × 3 locales (board, decisions, expulsions, delegations, widgets, errors)"
```

---

## Task 43: E2E test scenarios (7 scenarios)

**Files:**

- Create: `tests/E2E/Governance/BootstrapAndApproveBasicE2ETest.php`
- Create: `tests/E2E/Governance/InviteFullEligibilityE2ETest.php`
- Create: `tests/E2E/Governance/ExpulsionFullFlowE2ETest.php`
- Create: `tests/E2E/Governance/DelegationApproveBasicE2ETest.php`
- Create: `tests/E2E/Governance/AsyncMajorityRejectedE2ETest.php`
- Create: `tests/E2E/Governance/GsaOverrideApproveBasicE2ETest.php`
- Create: `tests/E2E/Governance/AwardSubTierAppliesToMismatchE2ETest.php`

Each E2E test uses `KernelHarness` (from `tests/Support/KernelHarness.php`) to issue HTTP-shaped calls against the in-memory container. Pattern matches existing `tests/E2E/*E2ETest.php` files (e.g. `MembershipSubTiersEndpointE2ETest`).

### Scenario 1: Bootstrap + ApproveBasic happy path

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Governance;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BootstrapAndApproveBasicE2ETest extends TestCase
{
    public function test_gsa_bootstraps_then_board_unanimously_approves_basic(): void
    {
        $harness = new KernelHarness();
        $harness->seedFullMember('u1');
        $harness->seedFullMember('u2');
        $harness->seedFullMember('u3');
        $harness->seedPendingApplication('app-1');

        // Bootstrap (GSA)
        $r = $harness->post('/api/v1/backstage/governance/board/bootstrap', [
            'members' => [
                ['user_id' => 'u1', 'role' => 'chair',  'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => 'u2', 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => 'u3', 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
            ],
        ], asGsa: true);
        $this->assertSame(201, $r->status);

        // Propose approve_basic (any board member)
        $r = $harness->post('/api/v1/backstage/governance/decisions/approve-basic', [
            'application_id'  => 'app-1',
            'vote_visibility' => 'visible',
        ], asUser: 'u2');
        $this->assertSame(201, $r->status);
        $decisionId = $r->body['decision_id'];

        // All three board members vote yes
        foreach (['u1','u2','u3'] as $uid) {
            $v = $harness->post("/api/v1/backstage/governance/decisions/{$decisionId}/vote", ['vote' => 'yes'], asUser: $uid);
            $this->assertSame(200, $v->status);
        }

        // Decision status = Passed
        $g = $harness->get("/api/v1/backstage/governance/decisions/{$decisionId}", asUser: 'u1');
        $this->assertSame('passed', $g->body['decision']['status']);

        // Application status = approved (via ApproveBasicExecutor)
        $this->assertSame('approved', $harness->getApplicationStatus('app-1'));
    }
}
```

### Scenarios 2-7 follow the same pattern

- **InviteFullEligibilityE2ETest**: seed a BASIC user with `membership_started_at = NOW - 11 months`; propose invite_full → expect 422 `not_eligible_for_full`.
- **ExpulsionFullFlowE2ETest**: bootstrap board → initiate expulsion → submit statement → advance to vote → all yes → user expelled. Then file appeal → status = appealed.
- **DelegationApproveBasicE2ETest**: bootstrap board → propose+pass `delegate_authority(approve_basic→admin)` → admin POSTs approve-basic → decision auto-passes with `via_delegation=true`.
- **AsyncMajorityRejectedE2ETest**: attempt to propose `award_subtier` (which is Majority) but explicitly request `mode=async` (e.g. via a manual decision-creation test path) → expect 400 `async_requires_unanimous`. (If the API doesn't surface the `mode` param to clients, test the Domain invariant via `BoardDecision` constructor in a separate unit test — that case is already in Task 11. Drop this scenario from E2E if the API doesn't expose `mode`.)
- **GsaOverrideApproveBasicE2ETest**: seed a tenant WITHOUT a board (no bootstrap) → GSA POST to `/governance/gsa-overrides/approve-basic` with a long reason → application is approved + `gsa_overrides` row exists.
- **AwardSubTierAppliesToMismatchE2ETest**: bootstrap board → propose award_subtier for a FULL user → expect 422 because FULL doesn't allow subtier.

- [ ] **Step 1: Implement all 7**
- [ ] **Step 2: Run + commit**

```bash
composer test -- --testsuite E2E --filter Governance
composer analyse
git add tests/E2E/Governance/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/e2e/governance): 7 scenarios (bootstrap+approve, 12mo gate, expulsion flow, delegation, async-majority, GSA override, applies_to)"
```

---

## Task 44: Final verification — full suite + manual smoke + summary commit

**Files:**

- Modify: nothing (verification-only); the commit body is a free-text summary.

- [ ] **Step 1: Full PHPStan + test sweep**

```bash
composer analyse
composer test
composer test:e2e
composer test:all
```

Expected:

- PHPStan level 9 = 0 errors.
- Unit suite — every Domain test green; resolution-service matrix green; widget tests green.
- Integration suite — every SQL repo CRUD green.
- Isolation suite — 6 governance + every pre-existing isolation test green.
- E2E suite — 7 governance scenarios + every pre-existing E2E test green.

If any failure, fix inline before committing.

- [ ] **Step 2: Migration smoke**

Re-run every governance migration against a fresh `daems_db_test` and confirm the schema is clean:

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# replay every migration 001..088 in order using the existing test bootstrap
composer test -- --testsuite Integration --filter MigrationTestCase
```

Expected: all migrations apply cleanly.

- [ ] **Step 3: Manual browser smoke (Laragon)**

1. Open `https://daems.local/backstage` → log in as `dev@daems.fi`.
2. Navigate to `/backstage/governance/board` → expect bootstrap CTA (no board yet for daems).
3. Bootstrap a board with the 3 existing FULL users.
4. Navigate to `/backstage/governance/decisions/new` → create `approve_basic` for an existing pending application → vote yes as each FULL user → confirm application is approved.
5. Spot-check `/backstage/governance/expulsions/new`, `/governance/delegations`, `/governance/settings`.
6. Spot-check the 4 new dashboard widgets render on the GSA dashboard.

- [ ] **Step 4: Final summary commit**

```bash
# No code changes — empty commit to mark milestone completion
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit --allow-empty -m "Done: MembershipCore v2 0.6b governance — bootstrap, decisions, expulsions, delegations, GSA override, UI, widgets, i18n, E2E"
```

- [ ] **Step 5: Wait for explicit "pushaa"**

Do NOT auto-push. Report the final commit SHA + a one-liner summary to the user and wait for the explicit instruction.

---

## Closing checklist

Before marking 0.6b complete:

- [ ] All 44 task checkboxes ticked.
- [ ] PHPStan level 9 = 0 errors.
- [ ] All test suites green.
- [ ] Manual browser smoke passed.
- [ ] No staged `.claude/` content.
- [ ] No `Co-Authored-By:` trailers in any of this branch's commits.
- [ ] Branch `membership-core-v2-governance` has ~100–130 commits and is awaiting `pushaa`.
