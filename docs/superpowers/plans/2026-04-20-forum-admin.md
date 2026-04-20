# Forum Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Parallelism cap: 3 subagents simultaneously.**

**Goal:** Ship `/backstage/forum` — a full moderator console (report queue, pin/lock/delete/edit topic+post, category CRUD, user warnings, audit log) with a user-facing "Raportoi" entry on posts and topics, integrated into the existing admin pending-toast stack.

**Architecture:** Migrations 047–049 add `forum_topics.locked`, `forum_posts.edited_at`, three new tables (`forum_reports`, `forum_moderation_audit`, `forum_user_warnings`), and extend the `admin_application_dismissals.app_type` enum with `forum_report`. ~18 new Application-layer use cases under `src/Application/Backstage/Forum/` plus one user-side use case cover reporting, resolution (delete/lock/warn/edit), direct moderation, category CRUD, and warnings. `ListPendingApplicationsForAdmin` gains a fourth branch for aggregated open forum reports. Frontend: one admin page with four tabs (reports/topics/categories/audit), two daem-society proxies, a reusable report dialog component on public pages, locked-topic banner, moderator-edited caption, and a one-line routing branch in `toasts.js`.

**Tech Stack:** PHP 8.1, Clean Architecture (Domain / Application / Infrastructure), PDO/MySQL 8, PHPStan level 9, PHPUnit. Frontend: daem-society PHP + vanilla JS.

**Spec:** `docs/superpowers/specs/2026-04-20-forum-admin-design.md`

**Commit identity (every commit):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No `Co-Authored-By`. Never stage `.claude/`. Never auto-push.

**Project conventions (critical — these bit us in previous PRs):**
- PHPUnit testsuite names CAPITALISED: `Unit` / `Integration` / `E2E`. Lowercased silently returns "No tests executed!" — always verify the count.
- InMemory fakes at `tests/Support/Fake/` with namespace `Daems\Tests\Support\Fake`.
- DI bindings must exist in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php`. Missing bootstrap = live 500 while E2E stays green.
- SQL repo ctor types vary (Connection vs PDO). Match ctor signature; use `$c->make(Connection::class)->pdo()` when PDO is required. Live-smoke with `APP_DEBUG=true` + curl before declaring wiring done.
- Ignore the "PHPStan 2.x is available" banner — noise, not an error.
- Never call any `mcp__code-review-graph__*` tool (hangs indefinitely).

---

## Wave Order (max 3 parallel subagents)

| Wave | Parallel tasks | Reason |
|---|---|---|
| W0 | 1 solo | Migrations — blocks everything |
| W1 | 2 solo | Forum entity + repo extensions (shared files) |
| W2 | 3, 4, 5 | Three independent new repos (report, audit, warnings) |
| W3 | 6, 7, 20 | User report UC + admin list UC + LPAFA extend (independent) |
| W4 | 8, 9, 10 | Resolve by Delete / Lock / Warn |
| W5 | 11, 12, 13 | Resolve by Edit + Dismiss + direct pin/unpin/lock/unlock |
| W6 | 14, 15, 16 | Direct delete + edit post + warn user |
| W7 | 17, 18, 19 | Category CRUD + audit list + CreateForumPost locked-guard |
| W8 | 21 solo | BackstageController + ForumController merge (shared files) |
| W9 | 22 solo | Routes (shared file) |
| W10 | 23 solo | DI wiring (shared files, must precede tests) |
| W11 | 24, 25, 26 | Integration + Isolation + E2E tests |
| W12 | 27, 28, 29 | Frontend admin page + public dialog + toast routing |
| W13 | 30 solo | Final verification |

---

## File Inventory

### Backend — new

**Migrations:**
- `database/migrations/047_add_locked_to_forum_topics.sql`
- `database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql`
- `database/migrations/049_extend_dismissals_enum_forum_report.sql`

**Domain:**
- `src/Domain/Forum/ForumReport.php`
- `src/Domain/Forum/ForumReportId.php`
- `src/Domain/Forum/ForumReportRepositoryInterface.php`
- `src/Domain/Forum/AggregatedForumReport.php` (read-model DTO)
- `src/Domain/Forum/ForumModerationAuditEntry.php`
- `src/Domain/Forum/ForumModerationAuditId.php`
- `src/Domain/Forum/ForumModerationAuditRepositoryInterface.php`
- `src/Domain/Forum/ForumUserWarning.php`
- `src/Domain/Forum/ForumUserWarningId.php`
- `src/Domain/Forum/ForumUserWarningRepositoryInterface.php`
- `src/Domain/Forum/TopicLockedException.php`

**Application (one directory per use case with `Input`/`Output`/handler siblings):**
- `src/Application/Forum/ReportForumTarget/` (user-side)
- `src/Application/Backstage/Forum/ListForumReportsForAdmin/`
- `src/Application/Backstage/Forum/GetForumReportDetail/`
- `src/Application/Backstage/Forum/ResolveForumReportByDelete/`
- `src/Application/Backstage/Forum/ResolveForumReportByLock/`
- `src/Application/Backstage/Forum/ResolveForumReportByWarn/`
- `src/Application/Backstage/Forum/ResolveForumReportByEdit/`
- `src/Application/Backstage/Forum/DismissForumReport/`
- `src/Application/Backstage/Forum/PinForumTopic/`
- `src/Application/Backstage/Forum/UnpinForumTopic/`
- `src/Application/Backstage/Forum/LockForumTopic/`
- `src/Application/Backstage/Forum/UnlockForumTopic/`
- `src/Application/Backstage/Forum/DeleteForumTopicAsAdmin/`
- `src/Application/Backstage/Forum/DeleteForumPostAsAdmin/`
- `src/Application/Backstage/Forum/EditForumPostAsAdmin/`
- `src/Application/Backstage/Forum/WarnForumUser/`
- `src/Application/Backstage/Forum/CreateForumCategoryAsAdmin/`
- `src/Application/Backstage/Forum/UpdateForumCategoryAsAdmin/`
- `src/Application/Backstage/Forum/DeleteForumCategoryAsAdmin/`
- `src/Application/Backstage/Forum/ListForumModerationAuditForAdmin/`

**Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumUserWarningRepository.php`

**Test fakes:**
- `tests/Support/Fake/InMemoryForumReportRepository.php`
- `tests/Support/Fake/InMemoryForumModerationAuditRepository.php`
- `tests/Support/Fake/InMemoryForumUserWarningRepository.php`

**Tests:** one unit test file per use case under `tests/Unit/Application/**/`, plus:
- `tests/Integration/Migration/Migration047Test.php`, `Migration048Test.php`, `Migration049Test.php`
- `tests/Integration/Application/ForumReportLifecycleIntegrationTest.php`
- `tests/Integration/Application/ForumCategoryCrudIntegrationTest.php`
- `tests/Integration/Application/ForumLockedTopicRejectsPostsIntegrationTest.php`
- `tests/Integration/Application/ForumReportDismissalToastIntegrationTest.php`
- `tests/Isolation/ForumAdminTenantIsolationTest.php`
- `tests/E2E/Backstage/ForumAdminEndpointsTest.php`
- `tests/E2E/Backstage/AdminInboxIncludesForumReportsTest.php`

### Backend — modified

- `src/Domain/Forum/ForumTopic.php` — add `bool $locked` (default false) + `locked()` getter.
- `src/Domain/Forum/ForumPost.php` — add `?string $editedAt` (default null) + `editedAt()` getter.
- `src/Domain/Forum/ForumRepositoryInterface.php` — add ~12 methods (see Task 2).
- `src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php` — implement new methods; hydrate + save cover `locked` + `edited_at`.
- `tests/Support/Fake/InMemoryForumRepository.php` — mirror new methods (file must be created if missing — check first).
- `src/Application/Forum/CreateForumPost/CreateForumPost.php` — guard locked topic (throws `TopicLockedException`).
- `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php` — fourth branch for aggregated forum reports.
- `src/Application/Backstage/DismissApplication/DismissApplication.php` — accept `'forum_report'` in `appType` whitelist; allow compound `appId` (`<type>:<uuid>`).
- `src/Infrastructure/Adapter/Api/Controller/ForumController.php` — add `createReport`.
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add ~22 admin methods.
- `routes/api.php` — register ~23 new routes.
- `bootstrap/app.php` + `tests/Support/KernelHarness.php` — bind everything in both containers.
- `tests/Isolation/IsolationTestCase.php` — bump `runMigrationsUpTo(49)`.

### Frontend daem-society — new

- `public/pages/backstage/forum/index.php`
- `public/pages/backstage/forum/forum-admin.js`
- `public/pages/backstage/forum/forum-admin.css`
- `public/pages/backstage/forum/edit-post-modal.js`
- `public/pages/backstage/forum/category-modal.js`
- `public/api/backstage/forum.php`
- `public/api/forum/report.php`
- `public/pages/forum/_report-dialog.js`
- `public/pages/forum/_report-dialog.css`

### Frontend daem-society — modified

- `public/pages/backstage/toasts.js` — routing branch for `type==='forum_report'`.
- `public/pages/backstage/layout.php` — sidebar entry `Forum` (verify + add if missing).
- Public forum view partials — report link on post + topic header, locked banner, moderator-edited caption.

---

### Task 1: Migrations 047, 048, 049 + IsolationTestCase bump

**Wave:** W0 (solo). **Files:**
- Create: `database/migrations/047_add_locked_to_forum_topics.sql`
- Create: `database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql`
- Create: `database/migrations/049_extend_dismissals_enum_forum_report.sql`
- Create: `tests/Integration/Migration/Migration047Test.php`, `Migration048Test.php`, `Migration049Test.php`
- Modify: `tests/Isolation/IsolationTestCase.php` (bump to `runMigrationsUpTo(49)`)

- [ ] **Step 1: Write migration 047** — `database/migrations/047_add_locked_to_forum_topics.sql`:

```sql
ALTER TABLE forum_topics
    ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0 AFTER pinned;
```

- [ ] **Step 2: Write Migration047Test** — `tests/Integration/Migration/Migration047Test.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration047Test extends MigrationTestCase
{
    public function test_locked_column_added_as_tinyint_default_0(): void
    {
        $this->runMigrationsUpTo(46);
        $this->runMigration('047_add_locked_to_forum_topics.sql');

        $row = $this->pdo->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forum_topics'
               AND COLUMN_NAME = 'locked'"
        )?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString('tinyint', strtolower((string) $row['COLUMN_TYPE']));
        self::assertSame('0', (string) $row['COLUMN_DEFAULT']);
        self::assertSame('NO', $row['IS_NULLABLE']);
    }
}
```

- [ ] **Step 3: Run 047 test — expect PASS**

`vendor/bin/phpunit --testsuite Integration --filter Migration047Test` → 1 passed.

- [ ] **Step 4: Write migration 048** — `database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql`:

```sql
CREATE TABLE forum_reports (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    reporter_user_id  CHAR(36)     NOT NULL,
    reason_category   ENUM('spam','harassment','off_topic','hate_speech','misinformation','other') NOT NULL,
    reason_detail     VARCHAR(500) NULL,
    status            ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_at       DATETIME     NULL,
    resolved_by       CHAR(36)     NULL,
    resolution_note   VARCHAR(500) NULL,
    resolution_action ENUM('deleted','locked','warned','edited','dismissed') NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reporter_target (reporter_user_id, target_type, target_id),
    KEY idx_fr_tenant_status (tenant_id, status, created_at),
    KEY idx_fr_target (target_type, target_id),
    CONSTRAINT fk_fr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_moderation_audit (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic','category') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    action            ENUM(
        'deleted','locked','unlocked','pinned','unpinned','edited',
        'category_created','category_updated','category_deleted','warned'
    ) NOT NULL,
    original_payload  JSON         NULL,
    new_payload       JSON         NULL,
    reason            VARCHAR(500) NULL,
    performed_by      CHAR(36)     NOT NULL,
    related_report_id CHAR(36)     NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fma_target (target_type, target_id),
    KEY idx_fma_performer (performed_by),
    KEY idx_fma_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_user_warnings (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    user_id           CHAR(36)     NOT NULL,
    reason            VARCHAR(500) NOT NULL,
    related_report_id CHAR(36)     NULL,
    issued_by         CHAR(36)     NOT NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fuw_user (user_id, created_at),
    KEY idx_fuw_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fuw_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE forum_posts
    ADD COLUMN edited_at DATETIME NULL AFTER created_at;
```

- [ ] **Step 5: Write Migration048Test** — `tests/Integration/Migration/Migration048Test.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration048Test extends MigrationTestCase
{
    public function test_all_three_tables_plus_edited_at_created(): void
    {
        $this->runMigrationsUpTo(47);
        $this->runMigration('048_create_forum_reports_audit_warnings_and_edited_at.sql');

        foreach (['forum_reports', 'forum_moderation_audit', 'forum_user_warnings'] as $table) {
            $exists = $this->pdo->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"
            )?->fetchColumn();
            self::assertSame('1', (string) $exists, "$table should exist");
        }

        $editedAt = $this->pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forum_posts'
               AND COLUMN_NAME = 'edited_at'"
        )?->fetchColumn();
        self::assertSame('YES', (string) $editedAt);
    }

    public function test_forum_reports_unique_constraint_on_reporter_target(): void
    {
        $this->runMigrationsUpTo(47);
        $this->runMigration('048_create_forum_reports_audit_warnings_and_edited_at.sql');

        $idx = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forum_reports'
               AND INDEX_NAME = 'uq_reporter_target'"
        )?->fetchColumn();
        self::assertSame(3, (int) $idx, 'unique index covers 3 columns');
    }
}
```

- [ ] **Step 6: Run 048 test — expect PASS** (2 tests).

- [ ] **Step 7: Write migration 049** — `database/migrations/049_extend_dismissals_enum_forum_report.sql`:

```sql
ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal','forum_report') NOT NULL;
```

- [ ] **Step 8: Write Migration049Test** — `tests/Integration/Migration/Migration049Test.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration049Test extends MigrationTestCase
{
    public function test_forum_report_added_to_app_type_enum(): void
    {
        $this->runMigrationsUpTo(48);
        $this->runMigration('049_extend_dismissals_enum_forum_report.sql');

        $type = (string) $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_application_dismissals'
               AND COLUMN_NAME = 'app_type'"
        )?->fetchColumn();
        foreach (['member', 'supporter', 'project_proposal', 'forum_report'] as $v) {
            self::assertStringContainsString($v, $type);
        }
    }
}
```

- [ ] **Step 9: Run 049 test — expect PASS**.

- [ ] **Step 10: Bump `IsolationTestCase`** — `tests/Isolation/IsolationTestCase.php`: change `runMigrationsUpTo(46)` → `runMigrationsUpTo(49)` (single line).

- [ ] **Step 11: Run full Integration suite — confirm no regressions**

`vendor/bin/phpunit --testsuite Integration` → all green.

- [ ] **Step 12: Commit**

```
git add database/migrations/047_add_locked_to_forum_topics.sql database/migrations/048_create_forum_reports_audit_warnings_and_edited_at.sql database/migrations/049_extend_dismissals_enum_forum_report.sql tests/Integration/Migration/Migration047Test.php tests/Integration/Migration/Migration048Test.php tests/Integration/Migration/Migration049Test.php tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): migrations 047-049 — locked, reports, audit, warnings, edited_at, dismissals enum"
```

---

### Task 2: ForumTopic + ForumPost entities + ForumRepositoryInterface extensions + SQL + InMemory

**Wave:** W1 (solo — touches shared files). **Files:**
- Modify: `src/Domain/Forum/ForumTopic.php` — add `bool $locked` ctor arg + getter.
- Modify: `src/Domain/Forum/ForumPost.php` — add `?string $editedAt` ctor arg + getter.
- Modify: `src/Domain/Forum/ForumRepositoryInterface.php` — 12 new methods.
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php` — implement + hydrate updates.
- Modify/Create: `tests/Support/Fake/InMemoryForumRepository.php` — mirror methods.
- Create: `tests/Unit/Domain/Forum/ForumTopicTest.php`, `ForumPostTest.php` (if not present — add `locked`/`editedAt` cases).

- [ ] **Step 1: Extend `ForumTopic`** — add `locked` as the **last** ctor arg (default `false` so existing callers compile). New constructor signature:

```php
public function __construct(
    private readonly ForumTopicId $id,
    private readonly TenantId $tenantId,
    private readonly string $categoryId,
    private readonly ?string $userId,
    private readonly string $slug,
    private readonly string $title,
    private readonly string $authorName,
    private readonly string $avatarInitials,
    private readonly ?string $avatarColor,
    private readonly bool $pinned,
    private readonly int $replyCount,
    private readonly int $viewCount,
    private readonly string $lastActivityAt,
    private readonly string $lastActivityBy,
    private readonly string $createdAt,
    private readonly bool $locked = false,
) {}

public function locked(): bool { return $this->locked; }
```

- [ ] **Step 2: Extend `ForumPost`** — append `?string $editedAt = null` as last ctor arg + getter:

```php
// (append to ctor param list)
    private readonly ?string $editedAt = null,
) {}

public function editedAt(): ?string { return $this->editedAt; }
```

- [ ] **Step 3: Extend `ForumRepositoryInterface`** — append these methods (full signatures, no placeholders):

```php
public function setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void;
public function setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void;
public function deleteTopicForTenant(string $topicId, TenantId $tenantId): void;
public function deletePostForTenant(string $postId, TenantId $tenantId): void;
public function updatePostContentForTenant(string $postId, TenantId $tenantId, string $content, string $editedAt): void;
public function findPostByIdForTenant(string $postId, TenantId $tenantId): ?ForumPost;
public function findTopicByIdForTenant(string $topicId, TenantId $tenantId): ?ForumTopic;
/** @param array<string, mixed> $filters @return ForumTopic[] */
public function listRecentTopicsForTenant(TenantId $tenantId, int $limit, array $filters): array;
/** @param array<string, mixed> $filters @return ForumPost[] */
public function listRecentPostsForTenant(TenantId $tenantId, int $limit, array $filters): array;
public function countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int;
public function updateCategoryForTenant(ForumCategory $category): void;
public function deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void;
```

- [ ] **Step 4: Implement new methods in `SqlForumRepository`**:

```php
public function setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void
{
    $this->db->execute(
        'UPDATE forum_topics SET pinned = ? WHERE id = ? AND tenant_id = ?',
        [$pinned ? 1 : 0, $topicId, $tenantId->value()],
    );
}

public function setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void
{
    $this->db->execute(
        'UPDATE forum_topics SET locked = ? WHERE id = ? AND tenant_id = ?',
        [$locked ? 1 : 0, $topicId, $tenantId->value()],
    );
}

public function deleteTopicForTenant(string $topicId, TenantId $tenantId): void
{
    // FK cascade on forum_posts is not set; delete posts first explicitly.
    $this->db->execute(
        'DELETE FROM forum_posts WHERE topic_id = ? AND tenant_id = ?',
        [$topicId, $tenantId->value()],
    );
    $this->db->execute(
        'DELETE FROM forum_topics WHERE id = ? AND tenant_id = ?',
        [$topicId, $tenantId->value()],
    );
}

public function deletePostForTenant(string $postId, TenantId $tenantId): void
{
    $this->db->execute(
        'DELETE FROM forum_posts WHERE id = ? AND tenant_id = ?',
        [$postId, $tenantId->value()],
    );
}

public function updatePostContentForTenant(string $postId, TenantId $tenantId, string $content, string $editedAt): void
{
    $this->db->execute(
        'UPDATE forum_posts SET content = ?, edited_at = ? WHERE id = ? AND tenant_id = ?',
        [$content, $editedAt, $postId, $tenantId->value()],
    );
}

public function findPostByIdForTenant(string $postId, TenantId $tenantId): ?ForumPost
{
    $row = $this->db->queryOne(
        'SELECT * FROM forum_posts WHERE id = ? AND tenant_id = ?',
        [$postId, $tenantId->value()],
    );
    return $row !== null ? $this->hydratePost($row) : null;
}

public function findTopicByIdForTenant(string $topicId, TenantId $tenantId): ?ForumTopic
{
    $row = $this->db->queryOne(
        'SELECT * FROM forum_topics WHERE id = ? AND tenant_id = ?',
        [$topicId, $tenantId->value()],
    );
    return $row !== null ? $this->hydrateTopic($row) : null;
}

public function listRecentTopicsForTenant(TenantId $tenantId, int $limit, array $filters): array
{
    $sql = 'SELECT * FROM forum_topics WHERE tenant_id = ?';
    $args = [$tenantId->value()];

    if (!empty($filters['category_id']) && is_string($filters['category_id'])) {
        $sql .= ' AND category_id = ?';
        $args[] = $filters['category_id'];
    }
    if (!empty($filters['pinned_only'])) {
        $sql .= ' AND pinned = 1';
    }
    if (!empty($filters['locked_only'])) {
        $sql .= ' AND locked = 1';
    }
    if (!empty($filters['q']) && is_string($filters['q'])) {
        $sql .= ' AND title LIKE ?';
        $args[] = '%' . $filters['q'] . '%';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ?';
    $args[] = $limit;

    return array_map($this->hydrateTopic(...), $this->db->query($sql, $args));
}

public function listRecentPostsForTenant(TenantId $tenantId, int $limit, array $filters): array
{
    $sql = 'SELECT * FROM forum_posts WHERE tenant_id = ?';
    $args = [$tenantId->value()];
    if (!empty($filters['topic_id']) && is_string($filters['topic_id'])) {
        $sql .= ' AND topic_id = ?';
        $args[] = $filters['topic_id'];
    }
    if (!empty($filters['q']) && is_string($filters['q'])) {
        $sql .= ' AND content LIKE ?';
        $args[] = '%' . $filters['q'] . '%';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ?';
    $args[] = $limit;

    return array_map($this->hydratePost(...), $this->db->query($sql, $args));
}

public function countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int
{
    $row = $this->db->queryOne(
        'SELECT COUNT(*) AS c FROM forum_topics WHERE category_id = ? AND tenant_id = ?',
        [$categoryId, $tenantId->value()],
    );
    $c = $row['c'] ?? 0;
    return is_numeric($c) ? (int) $c : 0;
}

public function updateCategoryForTenant(ForumCategory $category): void
{
    $this->db->execute(
        'UPDATE forum_categories
            SET slug = ?, name = ?, icon = ?, description = ?, sort_order = ?
          WHERE id = ? AND tenant_id = ?',
        [
            $category->slug(),
            $category->name(),
            $category->icon(),
            $category->description(),
            $category->sortOrder(),
            $category->id()->value(),
            $category->tenantId()->value(),
        ],
    );
}

public function deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void
{
    $this->db->execute(
        'DELETE FROM forum_categories WHERE id = ? AND tenant_id = ?',
        [$categoryId, $tenantId->value()],
    );
}
```

- [ ] **Step 5: Update `hydrateTopic` + `saveTopic`** — include `locked`. In `hydrateTopic`, append:

```php
(bool) ($row['locked'] ?? false),
```

as the final constructor argument. Update `saveTopic` to insert `locked` column:

```php
'INSERT INTO forum_topics
    (id, tenant_id, category_id, user_id, slug, title, author_name, avatar_initials, avatar_color,
     pinned, reply_count, view_count, last_activity_at, last_activity_by, created_at, locked)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
 ON DUPLICATE KEY UPDATE
    title            = VALUES(title),
    author_name      = VALUES(author_name),
    avatar_initials  = VALUES(avatar_initials),
    avatar_color     = VALUES(avatar_color),
    pinned           = VALUES(pinned),
    locked           = VALUES(locked),
    reply_count      = VALUES(reply_count),
    view_count       = VALUES(view_count),
    last_activity_at = VALUES(last_activity_at),
    last_activity_by = VALUES(last_activity_by)',
```
Append `$topic->locked() ? 1 : 0` to the bind array.

- [ ] **Step 6: Update `hydratePost` + `savePost`** — include `edited_at`. In `hydratePost`, append:

```php
self::strOrNull($row, 'edited_at'),
```

as the final ctor arg. Update `savePost` INSERT columns to include `edited_at` and bind `$post->editedAt()`.

- [ ] **Step 7: Create/extend `InMemoryForumRepository`** — `tests/Support/Fake/InMemoryForumRepository.php`. If file exists, extend. If missing, create it implementing `ForumRepositoryInterface` with in-memory arrays (`$topics`, `$posts`, `$categories` keyed by id). Implement every new method directly (mutate the arrays). Mirror the shape of the existing InMemory fakes in `tests/Support/Fake/`. Guard filters (`listRecentTopicsForTenant`, `listRecentPostsForTenant`) with the same keys as SQL.

- [ ] **Step 8: Write `tests/Unit/Domain/Forum/ForumTopicLockedTest.php`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ForumTopicLockedTest extends TestCase
{
    public function test_default_locked_is_false(): void
    {
        $t = new ForumTopic(
            ForumTopicId::fromString('11111111-1111-4111-8111-111111111111'),
            TenantId::fromString('22222222-2222-4222-8222-222222222222'),
            'cat',
            null,
            'slug',
            'Title',
            'Author',
            'AU',
            null,
            false,
            0,
            0,
            '2026-01-01 00:00:00',
            '',
            '2026-01-01 00:00:00',
        );
        self::assertFalse($t->locked());
    }

    public function test_locked_true_reflected(): void
    {
        $t = new ForumTopic(
            ForumTopicId::fromString('11111111-1111-4111-8111-111111111111'),
            TenantId::fromString('22222222-2222-4222-8222-222222222222'),
            'cat', null, 'slug', 'Title', 'A', 'A', null, false, 0, 0,
            '2026-01-01 00:00:00', '', '2026-01-01 00:00:00', true,
        );
        self::assertTrue($t->locked());
    }
}
```

- [ ] **Step 9: Run new unit + full Unit suite**

`vendor/bin/phpunit --testsuite Unit --filter ForumTopicLockedTest` — expect 2 passed. Then `vendor/bin/phpunit --testsuite Unit` — all green (no regressions from ctor arg addition).

- [ ] **Step 10: Run PHPStan**

`composer analyse` — 0 errors. Fix any PHPStan level-9 issues introduced (typical: array param `array<string, mixed>` annotations on new list methods; add missing `@return` phpdocs).

- [ ] **Step 11: Commit**

```
git add src/Domain/Forum/ForumTopic.php src/Domain/Forum/ForumPost.php src/Domain/Forum/ForumRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php tests/Support/Fake/InMemoryForumRepository.php tests/Unit/Domain/Forum/ForumTopicLockedTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ForumTopic.locked + ForumPost.editedAt + 12 repo methods (pin/lock/delete/edit/list/category CRUD)"
```

---

### Task 3: `ForumReport` domain + repo + SQL + InMemory

**Wave:** W2 (parallel with 4, 5). **Files:**
- Create: `src/Domain/Forum/ForumReportId.php`, `ForumReport.php`, `AggregatedForumReport.php`, `ForumReportRepositoryInterface.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php`
- Create: `tests/Support/Fake/InMemoryForumReportRepository.php`
- Create: `tests/Unit/Domain/Forum/ForumReportTest.php`

- [ ] **Step 1: `ForumReportId` value object** — mirror `ForumTopicId`:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Shared\AbstractUuid;

final class ForumReportId extends AbstractUuid {}
```

(Follow the base-class pattern used by sibling `ForumTopicId`; if no `AbstractUuid`, copy the exact `ForumTopicId` structure with `fromString` + `value` + `generate`.)

- [ ] **Step 2: `ForumReport` entity**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumReport
{
    public const TARGET_POST  = 'post';
    public const TARGET_TOPIC = 'topic';

    public const STATUS_OPEN      = 'open';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const REASON_CATEGORIES = [
        'spam','harassment','off_topic','hate_speech','misinformation','other',
    ];

    public function __construct(
        private readonly ForumReportId $id,
        private readonly TenantId $tenantId,
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly string $reporterUserId,
        private readonly string $reasonCategory,
        private readonly ?string $reasonDetail,
        private readonly string $status,
        private readonly ?string $resolvedAt,
        private readonly ?string $resolvedBy,
        private readonly ?string $resolutionNote,
        private readonly ?string $resolutionAction,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumReportId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function reporterUserId(): string { return $this->reporterUserId; }
    public function reasonCategory(): string { return $this->reasonCategory; }
    public function reasonDetail(): ?string { return $this->reasonDetail; }
    public function status(): string { return $this->status; }
    public function resolvedAt(): ?string { return $this->resolvedAt; }
    public function resolvedBy(): ?string { return $this->resolvedBy; }
    public function resolutionNote(): ?string { return $this->resolutionNote; }
    public function resolutionAction(): ?string { return $this->resolutionAction; }
    public function createdAt(): string { return $this->createdAt; }
}
```

- [ ] **Step 3: `AggregatedForumReport` read-model DTO**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

final class AggregatedForumReport
{
    /**
     * @param array<string,int> $reasonCounts reason_category => count
     * @param list<string>      $rawReportIds
     */
    public function __construct(
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly int $reportCount,
        public readonly array $reasonCounts,
        public readonly array $rawReportIds,
        public readonly string $earliestCreatedAt,
        public readonly string $latestCreatedAt,
        public readonly string $status,
    ) {}

    public function compoundKey(): string
    {
        return $this->targetType . ':' . $this->targetId;
    }
}
```

- [ ] **Step 4: `ForumReportRepositoryInterface`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use DateTimeImmutable;
use Daems\Domain\Tenant\TenantId;

interface ForumReportRepositoryInterface
{
    public function upsert(ForumReport $report): void;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport;

    /**
     * @param array{status?:string,target_type?:string} $filters
     * @return list<AggregatedForumReport>
     */
    public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array;

    /** @return list<ForumReport> */
    public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array;

    public function resolveAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolutionAction,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void;

    public function dismissAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void;

    public function countOpenForTenant(TenantId $tenantId): int;
}
```

- [ ] **Step 5: `SqlForumReportRepository`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use DateTimeImmutable;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumReportRepository implements ForumReportRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function upsert(ForumReport $report): void
    {
        $this->db->execute(
            'INSERT INTO forum_reports
                (id, tenant_id, target_type, target_id, reporter_user_id,
                 reason_category, reason_detail, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                reason_category = VALUES(reason_category),
                reason_detail   = VALUES(reason_detail),
                status          = VALUES(status),
                created_at      = VALUES(created_at),
                resolved_at     = NULL,
                resolved_by     = NULL,
                resolution_note = NULL,
                resolution_action = NULL',
            [
                $report->id()->value(),
                $report->tenantId()->value(),
                $report->targetType(),
                $report->targetId(),
                $report->reporterUserId(),
                $report->reasonCategory(),
                $report->reasonDetail(),
                $report->status(),
                $report->createdAt(),
            ],
        );
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ForumReport
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_reports WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listAggregatedForTenant(TenantId $tenantId, array $filters = []): array
    {
        $status = $filters['status'] ?? ForumReport::STATUS_OPEN;
        $args = [$tenantId->value(), $status];
        $sql = 'SELECT target_type, target_id, status,
                       COUNT(*) AS report_count,
                       MIN(created_at) AS earliest,
                       MAX(created_at) AS latest,
                       GROUP_CONCAT(id) AS ids,
                       GROUP_CONCAT(reason_category) AS cats
                  FROM forum_reports
                 WHERE tenant_id = ? AND status = ?';
        if (!empty($filters['target_type']) && is_string($filters['target_type'])) {
            $sql .= ' AND target_type = ?';
            $args[] = $filters['target_type'];
        }
        $sql .= ' GROUP BY target_type, target_id, status ORDER BY latest DESC';

        $rows = $this->db->query($sql, $args);
        $out = [];
        foreach ($rows as $r) {
            $cats = array_filter(explode(',', (string) ($r['cats'] ?? '')));
            $counts = [];
            foreach ($cats as $c) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
            $ids = array_values(array_filter(explode(',', (string) ($r['ids'] ?? ''))));
            $out[] = new AggregatedForumReport(
                (string) $r['target_type'],
                (string) $r['target_id'],
                (int) $r['report_count'],
                $counts,
                $ids,
                (string) $r['earliest'],
                (string) $r['latest'],
                (string) $r['status'],
            );
        }
        return $out;
    }

    public function listRawForTargetForTenant(string $targetType, string $targetId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_reports
              WHERE target_type = ? AND target_id = ? AND tenant_id = ?
              ORDER BY created_at DESC',
            [$targetType, $targetId, $tenantId->value()],
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function resolveAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolutionAction,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->db->execute(
            'UPDATE forum_reports
                SET status = ?, resolved_at = ?, resolved_by = ?,
                    resolution_note = ?, resolution_action = ?
              WHERE target_type = ? AND target_id = ? AND tenant_id = ? AND status = ?',
            [
                ForumReport::STATUS_RESOLVED,
                $now->format('Y-m-d H:i:s'),
                $resolvedBy,
                $note,
                $resolutionAction,
                $targetType,
                $targetId,
                $tenantId->value(),
                ForumReport::STATUS_OPEN,
            ],
        );
    }

    public function dismissAllForTarget(
        string $targetType,
        string $targetId,
        TenantId $tenantId,
        string $resolvedBy,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->db->execute(
            'UPDATE forum_reports
                SET status = ?, resolved_at = ?, resolved_by = ?,
                    resolution_note = ?, resolution_action = ?
              WHERE target_type = ? AND target_id = ? AND tenant_id = ? AND status = ?',
            [
                ForumReport::STATUS_DISMISSED,
                $now->format('Y-m-d H:i:s'),
                $resolvedBy,
                $note,
                'dismissed',
                $targetType,
                $targetId,
                $tenantId->value(),
                ForumReport::STATUS_OPEN,
            ],
        );
    }

    public function countOpenForTenant(TenantId $tenantId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(DISTINCT CONCAT(target_type,":",target_id)) AS c
               FROM forum_reports WHERE tenant_id = ? AND status = ?',
            [$tenantId->value(), ForumReport::STATUS_OPEN],
        );
        $c = $row['c'] ?? 0;
        return is_numeric($c) ? (int) $c : 0;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ForumReport
    {
        return new ForumReport(
            ForumReportId::fromString((string) $row['id']),
            TenantId::fromString((string) $row['tenant_id']),
            (string) $row['target_type'],
            (string) $row['target_id'],
            (string) $row['reporter_user_id'],
            (string) $row['reason_category'],
            isset($row['reason_detail']) && is_string($row['reason_detail']) ? $row['reason_detail'] : null,
            (string) $row['status'],
            isset($row['resolved_at']) && is_string($row['resolved_at']) ? $row['resolved_at'] : null,
            isset($row['resolved_by']) && is_string($row['resolved_by']) ? $row['resolved_by'] : null,
            isset($row['resolution_note']) && is_string($row['resolution_note']) ? $row['resolution_note'] : null,
            isset($row['resolution_action']) && is_string($row['resolution_action']) ? $row['resolution_action'] : null,
            (string) $row['created_at'],
        );
    }
}
```

- [ ] **Step 6: `InMemoryForumReportRepository`** — mirror the interface. Store reports keyed by id; maintain a second index `($reporter . ':' . $target_type . ':' . $target_id) → id` for dedup. `listAggregatedForTenant` groups in-memory by `(target_type, target_id)` where `status === open` (or filter override).

- [ ] **Step 7: Unit test** — `tests/Unit/Domain/Forum/ForumReportTest.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ForumReportTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $r = new ForumReport(
            ForumReportId::fromString('a1111111-1111-4111-8111-111111111111'),
            TenantId::fromString('b2222222-2222-4222-8222-222222222222'),
            ForumReport::TARGET_POST,
            'p-1',
            'u-1',
            'spam',
            null,
            ForumReport::STATUS_OPEN,
            null, null, null, null,
            '2026-04-20 10:00:00',
        );
        self::assertSame('spam', $r->reasonCategory());
        self::assertSame(ForumReport::STATUS_OPEN, $r->status());
    }

    public function test_reason_categories_constant_covers_enum(): void
    {
        self::assertContains('hate_speech', ForumReport::REASON_CATEGORIES);
        self::assertCount(6, ForumReport::REASON_CATEGORIES);
    }
}
```

- [ ] **Step 8: Run Unit + PHPStan**

`vendor/bin/phpunit --testsuite Unit --filter ForumReportTest` — 2 passed. `composer analyse` — 0 errors.

- [ ] **Step 9: Commit**

```
git add src/Domain/Forum/ForumReport*.php src/Domain/Forum/AggregatedForumReport.php src/Infrastructure/Adapter/Persistence/Sql/SqlForumReportRepository.php tests/Support/Fake/InMemoryForumReportRepository.php tests/Unit/Domain/Forum/ForumReportTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ForumReport domain + repo (upsert, aggregated listing, resolve, dismiss)"
```

---

### Task 4: `ForumModerationAuditEntry` domain + repo + SQL + InMemory

**Wave:** W2 (parallel with 3, 5). **Files:**
- Create: `src/Domain/Forum/ForumModerationAuditId.php`, `ForumModerationAuditEntry.php`, `ForumModerationAuditRepositoryInterface.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php`
- Create: `tests/Support/Fake/InMemoryForumModerationAuditRepository.php`

- [ ] **Step 1: `ForumModerationAuditId`** — `final class ForumModerationAuditId extends AbstractUuid {}` (same pattern as `ForumReportId`).

- [ ] **Step 2: `ForumModerationAuditEntry` entity**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumModerationAuditEntry
{
    public const ACTION_DELETED           = 'deleted';
    public const ACTION_LOCKED            = 'locked';
    public const ACTION_UNLOCKED          = 'unlocked';
    public const ACTION_PINNED            = 'pinned';
    public const ACTION_UNPINNED          = 'unpinned';
    public const ACTION_EDITED            = 'edited';
    public const ACTION_CATEGORY_CREATED  = 'category_created';
    public const ACTION_CATEGORY_UPDATED  = 'category_updated';
    public const ACTION_CATEGORY_DELETED  = 'category_deleted';
    public const ACTION_WARNED            = 'warned';

    /**
     * @param array<string,mixed>|null $originalPayload
     * @param array<string,mixed>|null $newPayload
     */
    public function __construct(
        private readonly ForumModerationAuditId $id,
        private readonly TenantId $tenantId,
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly string $action,
        private readonly ?array $originalPayload,
        private readonly ?array $newPayload,
        private readonly ?string $reason,
        private readonly string $performedBy,
        private readonly ?string $relatedReportId,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumModerationAuditId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function action(): string { return $this->action; }
    /** @return array<string,mixed>|null */
    public function originalPayload(): ?array { return $this->originalPayload; }
    /** @return array<string,mixed>|null */
    public function newPayload(): ?array { return $this->newPayload; }
    public function reason(): ?string { return $this->reason; }
    public function performedBy(): string { return $this->performedBy; }
    public function relatedReportId(): ?string { return $this->relatedReportId; }
    public function createdAt(): string { return $this->createdAt; }
}
```

- [ ] **Step 3: `ForumModerationAuditRepositoryInterface`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumModerationAuditRepositoryInterface
{
    public function record(ForumModerationAuditEntry $entry): void;

    /**
     * @param array{action?:string, performer?:string} $filters
     * @return list<ForumModerationAuditEntry>
     */
    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = []): array;
}
```

- [ ] **Step 4: `SqlForumModerationAuditRepository`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumModerationAuditRepository implements ForumModerationAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(ForumModerationAuditEntry $entry): void
    {
        $this->db->execute(
            'INSERT INTO forum_moderation_audit
                (id, tenant_id, target_type, target_id, action,
                 original_payload, new_payload, reason, performed_by,
                 related_report_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $entry->id()->value(),
                $entry->tenantId()->value(),
                $entry->targetType(),
                $entry->targetId(),
                $entry->action(),
                $entry->originalPayload() !== null ? json_encode($entry->originalPayload(), JSON_THROW_ON_ERROR) : null,
                $entry->newPayload() !== null ? json_encode($entry->newPayload(), JSON_THROW_ON_ERROR) : null,
                $entry->reason(),
                $entry->performedBy(),
                $entry->relatedReportId(),
                $entry->createdAt(),
            ],
        );
    }

    public function listRecentForTenant(TenantId $tenantId, int $limit = 200, array $filters = []): array
    {
        $sql = 'SELECT * FROM forum_moderation_audit WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if (!empty($filters['action']) && is_string($filters['action'])) {
            $sql .= ' AND action = ?';
            $args[] = $filters['action'];
        }
        if (!empty($filters['performer']) && is_string($filters['performer'])) {
            $sql .= ' AND performed_by = ?';
            $args[] = $filters['performer'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $args[] = $limit;

        $rows = $this->db->query($sql, $args);
        return array_map(function (array $r): ForumModerationAuditEntry {
            $orig = null;
            if (isset($r['original_payload']) && is_string($r['original_payload']) && $r['original_payload'] !== '') {
                $d = json_decode($r['original_payload'], true);
                $orig = is_array($d) ? $d : null;
            }
            $new = null;
            if (isset($r['new_payload']) && is_string($r['new_payload']) && $r['new_payload'] !== '') {
                $d = json_decode($r['new_payload'], true);
                $new = is_array($d) ? $d : null;
            }
            return new ForumModerationAuditEntry(
                ForumModerationAuditId::fromString((string) $r['id']),
                TenantId::fromString((string) $r['tenant_id']),
                (string) $r['target_type'],
                (string) $r['target_id'],
                (string) $r['action'],
                $orig,
                $new,
                isset($r['reason']) && is_string($r['reason']) ? $r['reason'] : null,
                (string) $r['performed_by'],
                isset($r['related_report_id']) && is_string($r['related_report_id']) ? $r['related_report_id'] : null,
                (string) $r['created_at'],
            );
        }, $rows);
    }
}
```

- [ ] **Step 5: `InMemoryForumModerationAuditRepository`** — simple array; push to list on `record`, filter+limit on `listRecentForTenant`.

- [ ] **Step 6: Run PHPStan** — `composer analyse` → 0 errors.

- [ ] **Step 7: Commit**

```
git add src/Domain/Forum/ForumModerationAudit*.php src/Infrastructure/Adapter/Persistence/Sql/SqlForumModerationAuditRepository.php tests/Support/Fake/InMemoryForumModerationAuditRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ForumModerationAuditEntry domain + repo (record, listRecent with filters)"
```

---

### Task 5: `ForumUserWarning` domain + repo + SQL + InMemory

**Wave:** W2 (parallel with 3, 4). **Files:**
- Create: `src/Domain/Forum/ForumUserWarningId.php`, `ForumUserWarning.php`, `ForumUserWarningRepositoryInterface.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlForumUserWarningRepository.php`
- Create: `tests/Support/Fake/InMemoryForumUserWarningRepository.php`

- [ ] **Step 1: `ForumUserWarningId`** — `extends AbstractUuid`.

- [ ] **Step 2: `ForumUserWarning` entity**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumUserWarning
{
    public function __construct(
        private readonly ForumUserWarningId $id,
        private readonly TenantId $tenantId,
        private readonly string $userId,
        private readonly string $reason,
        private readonly ?string $relatedReportId,
        private readonly string $issuedBy,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumUserWarningId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): string { return $this->userId; }
    public function reason(): string { return $this->reason; }
    public function relatedReportId(): ?string { return $this->relatedReportId; }
    public function issuedBy(): string { return $this->issuedBy; }
    public function createdAt(): string { return $this->createdAt; }
}
```

- [ ] **Step 3: `ForumUserWarningRepositoryInterface`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumUserWarningRepositoryInterface
{
    public function record(ForumUserWarning $warning): void;

    /** @return list<ForumUserWarning> */
    public function listForUserForTenant(string $userId, TenantId $tenantId): array;
}
```

- [ ] **Step 4: `SqlForumUserWarningRepository`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Forum\ForumUserWarning;
use Daems\Domain\Forum\ForumUserWarningId;
use Daems\Domain\Forum\ForumUserWarningRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumUserWarningRepository implements ForumUserWarningRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(ForumUserWarning $w): void
    {
        $this->db->execute(
            'INSERT INTO forum_user_warnings
                (id, tenant_id, user_id, reason, related_report_id, issued_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $w->id()->value(), $w->tenantId()->value(), $w->userId(),
                $w->reason(), $w->relatedReportId(), $w->issuedBy(), $w->createdAt(),
            ],
        );
    }

    public function listForUserForTenant(string $userId, TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_user_warnings
              WHERE user_id = ? AND tenant_id = ?
              ORDER BY created_at DESC',
            [$userId, $tenantId->value()],
        );
        return array_map(static fn(array $r): ForumUserWarning => new ForumUserWarning(
            ForumUserWarningId::fromString((string) $r['id']),
            TenantId::fromString((string) $r['tenant_id']),
            (string) $r['user_id'],
            (string) $r['reason'],
            isset($r['related_report_id']) && is_string($r['related_report_id']) ? $r['related_report_id'] : null,
            (string) $r['issued_by'],
            (string) $r['created_at'],
        ), $rows);
    }
}
```

- [ ] **Step 5: `InMemoryForumUserWarningRepository`** — `$warnings = []` list; push + filter.

- [ ] **Step 6: Add `TopicLockedException`** — `src/Domain/Forum/TopicLockedException.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Forum;

use RuntimeException;

final class TopicLockedException extends RuntimeException {}
```

- [ ] **Step 7: Run PHPStan** — 0 errors.

- [ ] **Step 8: Commit**

```
git add src/Domain/Forum/ForumUserWarning*.php src/Domain/Forum/TopicLockedException.php src/Infrastructure/Adapter/Persistence/Sql/SqlForumUserWarningRepository.php tests/Support/Fake/InMemoryForumUserWarningRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ForumUserWarning domain + repo + TopicLockedException"
```

---

### Task 6: `ReportForumTarget` use case (user-side, TDD)

**Wave:** W3 (parallel with 7, 20). **Files:**
- Create: `src/Application/Forum/ReportForumTarget/{ReportForumTarget,ReportForumTargetInput,ReportForumTargetOutput}.php`
- Create: `tests/Unit/Application/Forum/ReportForumTargetTest.php`

**Contract:** Authenticated member reports a post or topic. Validates target exists and is in acting tenant. Upserts report (dedup by unique constraint). Clears matching dismissal so toast re-surfaces.

- [ ] **Step 1: Write failing test** — `tests/Unit/Application/Forum/ReportForumTargetTest.php`:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Application\Forum;

use Daems\Application\Forum\ReportForumTarget\ReportForumTarget;
use Daems\Application\Forum\ReportForumTarget\ReportForumTargetInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class ReportForumTargetTest extends TestCase
{
    private TenantId $tenant;
    private ActingUser $user;
    private InMemoryForumRepository $forum;
    private InMemoryForumReportRepository $reports;
    private InMemoryAdminApplicationDismissalRepository $dismissals;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $this->user   = ActingUserFactory::registeredInTenant('u-1', $this->tenant);
        $this->forum  = new InMemoryForumRepository();
        $this->reports = new InMemoryForumReportRepository();
        $this->dismissals = new InMemoryAdminApplicationDismissalRepository();
    }

    public function test_reports_a_post_and_upserts_once(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, 'p-1');
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);

        $out = $uc->execute(new ReportForumTargetInput($this->user, 'post', 'p-1', 'spam', 'scam link'));

        self::assertTrue($out->success);
        self::assertCount(1, $this->reports->all());
        $r = $this->reports->all()[0];
        self::assertSame(ForumReport::TARGET_POST, $r->targetType());
        self::assertSame('spam', $r->reasonCategory());
    }

    public function test_same_reporter_same_target_upserts_updated_reason(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, 'p-1');
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);

        $uc->execute(new ReportForumTargetInput($this->user, 'post', 'p-1', 'spam', null));
        $uc->execute(new ReportForumTargetInput($this->user, 'post', 'p-1', 'harassment', 'new reason'));

        self::assertCount(1, $this->reports->all());
        self::assertSame('harassment', $this->reports->all()[0]->reasonCategory());
    }

    public function test_unknown_target_throws_not_found(): void
    {
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $this->expectException(NotFoundException::class);
        $uc->execute(new ReportForumTargetInput($this->user, 'post', 'nonexistent', 'spam', null));
    }

    public function test_invalid_reason_category_rejected(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, 'p-1');
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $this->expectException(\InvalidArgumentException::class);
        $uc->execute(new ReportForumTargetInput($this->user, 'post', 'p-1', 'not_a_reason', null));
    }

    public function test_dismissal_cleared_on_new_report(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, 'p-1');
        $this->dismissals->dismiss($this->tenant, 'admin-1', 'forum_report', 'post:p-1');

        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $uc->execute(new ReportForumTargetInput($this->user, 'post', 'p-1', 'spam', null));

        self::assertFalse($this->dismissals->isDismissed($this->tenant, 'admin-1', 'forum_report', 'post:p-1'));
    }
}
```

> **Helper scaffolding** — if `ForumSeed` / `ActingUserFactory` don't exist, create them in `tests/Support/`. `ForumSeed::seedPost($forum, $tenant, $id)` inserts a dummy topic+post so `findPostByIdForTenant` returns non-null. `ActingUserFactory::registeredInTenant($id, $tenant)` builds an `ActingUser` whose `activeTenant()` returns `$tenant` and whose role set contains `registered`.

- [ ] **Step 2: Run test — expect FAIL** (class missing).

- [ ] **Step 3: Implement `ReportForumTargetInput`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Forum\ReportForumTarget;

use Daems\Domain\Auth\ActingUser;

final class ReportForumTargetInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly string $reasonCategory,
        public readonly ?string $reasonDetail,
    ) {}
}
```

- [ ] **Step 4: Implement `ReportForumTargetOutput`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Forum\ReportForumTarget;

final class ReportForumTargetOutput
{
    public function __construct(public readonly bool $success) {}
}
```

- [ ] **Step 5: Implement `ReportForumTarget`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Forum\ReportForumTarget;

use DateTimeImmutable;
use Daems\Domain\AdminInbox\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

final class ReportForumTarget
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly \Daems\Domain\Forum\ForumReportRepositoryInterface $reports,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
    ) {}

    public function execute(ReportForumTargetInput $in): ReportForumTargetOutput
    {
        $tenantId = $in->acting->activeTenant()->id;

        if (!in_array($in->reasonCategory, ForumReport::REASON_CATEGORIES, true)) {
            throw new InvalidArgumentException('invalid_reason_category');
        }

        if ($in->targetType === ForumReport::TARGET_POST) {
            if ($this->forum->findPostByIdForTenant($in->targetId, $tenantId) === null) {
                throw new NotFoundException('post_not_found');
            }
        } elseif ($in->targetType === ForumReport::TARGET_TOPIC) {
            if ($this->forum->findTopicByIdForTenant($in->targetId, $tenantId) === null) {
                throw new NotFoundException('topic_not_found');
            }
        } else {
            throw new InvalidArgumentException('invalid_target_type');
        }

        $detail = $in->reasonDetail !== null ? trim($in->reasonDetail) : null;
        if ($detail === '') {
            $detail = null;
        }
        if ($detail !== null && strlen($detail) > 500) {
            $detail = substr($detail, 0, 500);
        }

        $now = new DateTimeImmutable();
        $report = new ForumReport(
            ForumReportId::generate(),
            $tenantId,
            $in->targetType,
            $in->targetId,
            $in->acting->id,
            $in->reasonCategory,
            $detail,
            ForumReport::STATUS_OPEN,
            null, null, null, null,
            $now->format('Y-m-d H:i:s'),
        );
        $this->reports->upsert($report);

        $this->dismissals->clearForAppIdAnyAdmin($tenantId, 'forum_report', $in->targetType . ':' . $in->targetId);

        return new ReportForumTargetOutput(true);
    }
}
```

- [ ] **Step 6: Add `clearForAppIdAnyAdmin` to `AdminApplicationDismissalRepositoryInterface`** (+ SQL + InMemory impls) if not already present. Signature:

```php
public function clearForAppIdAnyAdmin(TenantId $tenantId, string $appType, string $appId): void;
```

SQL body:
```sql
DELETE FROM admin_application_dismissals WHERE tenant_id = ? AND app_type = ? AND app_id = ?
```

- [ ] **Step 7: Run tests — expect PASS**

`vendor/bin/phpunit --testsuite Unit --filter ReportForumTargetTest` → 5 passed.

- [ ] **Step 8: Run PHPStan** — 0 errors.

- [ ] **Step 9: Commit**

```
git add src/Application/Forum/ReportForumTarget src/Domain/AdminInbox/AdminApplicationDismissalRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/Sql*AdminApplicationDismissal*.php tests/Support/Fake/InMemoryAdminApplicationDismissalRepository.php tests/Unit/Application/Forum/ReportForumTargetTest.php tests/Support/ForumSeed.php tests/Support/ActingUserFactory.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ReportForumTarget user-side use case (dedup upsert + dismissal clear)"
```

---

### Task 7: `ListForumReportsForAdmin` + `GetForumReportDetail` (TDD, combined)

**Wave:** W3 (parallel with 6, 20). **Files:**
- Create: `src/Application/Backstage/Forum/ListForumReportsForAdmin/`
- Create: `src/Application/Backstage/Forum/GetForumReportDetail/`
- Create: `tests/Unit/Application/Backstage/Forum/ListForumReportsForAdminTest.php`
- Create: `tests/Unit/Application/Backstage/Forum/GetForumReportDetailTest.php`

- [ ] **Step 1: Write failing `ListForumReportsForAdminTest`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdminInput;
use Daems\Domain\Shared\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class ListForumReportsForAdminTest extends TestCase
{
    public function test_returns_aggregated_rows_for_admin(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('admin', $tenant);
        $repo   = new InMemoryForumReportRepository();
        $forum  = new InMemoryForumRepository();

        $repo->seedOpen($tenant, 'post', 'p-1', 'user-1', 'spam');
        $repo->seedOpen($tenant, 'post', 'p-1', 'user-2', 'spam');
        $repo->seedOpen($tenant, 'topic', 't-1', 'user-3', 'off_topic');

        $uc = new ListForumReportsForAdmin($repo, $forum);
        $out = $uc->execute(new ListForumReportsForAdminInput($admin, [], 50));

        self::assertCount(2, $out->items);
        $post = array_filter($out->items, fn($a) => $a->targetType === 'post')[0] ?? null;
        self::assertNotNull($post);
        self::assertSame(2, $post->reportCount);
        self::assertSame(['spam' => 2], $post->reasonCounts);
    }

    public function test_filters_by_target_type(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('a', $tenant);
        $repo   = new InMemoryForumReportRepository();
        $repo->seedOpen($tenant, 'post', 'p-1', 'u1', 'spam');
        $repo->seedOpen($tenant, 'topic', 't-1', 'u2', 'spam');

        $out = (new ListForumReportsForAdmin($repo, new InMemoryForumRepository()))
            ->execute(new ListForumReportsForAdminInput($admin, ['target_type' => 'topic'], 50));

        self::assertCount(1, $out->items);
        self::assertSame('topic', $out->items[0]->targetType);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $member = ActingUserFactory::registeredInTenant('m', $tenant);
        $this->expectException(ForbiddenException::class);
        (new ListForumReportsForAdmin(new InMemoryForumReportRepository(), new InMemoryForumRepository()))
            ->execute(new ListForumReportsForAdminInput($member, [], 50));
    }
}
```

- [ ] **Step 2: Implement `ListForumReportsForAdminInput` / `Output` / handler**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListForumReportsForAdminInput
{
    /** @param array{status?:string, target_type?:string} $filters */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly array $filters,
        public readonly int $limit,
    ) {}
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Forum\AggregatedForumReport;

final class ListForumReportsForAdminOutput
{
    /** @param list<AggregatedForumReport> $items */
    public function __construct(public readonly array $items) {}
}
```

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\Forum\ListForumReportsForAdmin;

use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\ForbiddenException;

final class ListForumReportsForAdmin
{
    public function __construct(
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListForumReportsForAdminInput $in): ListForumReportsForAdminOutput
    {
        $tenantId = $in->acting->activeTenant()->id;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $items = $this->reports->listAggregatedForTenant($tenantId, $in->filters);
        // Trim to limit
        if (count($items) > $in->limit) {
            $items = array_slice($items, 0, $in->limit);
        }
        return new ListForumReportsForAdminOutput($items);
    }
}
```

- [ ] **Step 3: Run `ListForumReportsForAdminTest` — expect PASS** (3 tests).

- [ ] **Step 4: Write failing `GetForumReportDetailTest`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetailInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\ForumSeed;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class GetForumReportDetailTest extends TestCase
{
    public function test_returns_aggregated_plus_raw_plus_target_content(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('a', $tenant);
        $reports = new InMemoryForumReportRepository();
        $forum   = new InMemoryForumRepository();
        ForumSeed::seedPost($forum, $tenant, 'p-1', 'offending content');
        $reports->seedOpen($tenant, 'post', 'p-1', 'u1', 'spam');
        $reports->seedOpen($tenant, 'post', 'p-1', 'u2', 'harassment');

        $uc = new GetForumReportDetail($reports, $forum);
        $out = $uc->execute(new GetForumReportDetailInput($admin, 'post', 'p-1'));

        self::assertSame(2, $out->aggregated->reportCount);
        self::assertCount(2, $out->rawReports);
        self::assertSame('offending content', $out->targetContent['content'] ?? null);
    }

    public function test_unknown_target_throws(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('a', $tenant);
        $uc = new GetForumReportDetail(new InMemoryForumReportRepository(), new InMemoryForumRepository());
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetForumReportDetailInput($admin, 'post', 'missing'));
    }
}
```

- [ ] **Step 5: Implement `GetForumReportDetail`** (handler body):

```php
public function execute(GetForumReportDetailInput $in): GetForumReportDetailOutput
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }

    $raw = $this->reports->listRawForTargetForTenant($in->targetType, $in->targetId, $tenantId);
    if ($raw === []) {
        throw new NotFoundException('no_reports_for_target');
    }

    $targetContent = [];
    if ($in->targetType === 'post') {
        $p = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
        if ($p === null) {
            throw new NotFoundException('post_not_found');
        }
        $targetContent = [
            'author' => $p->authorName(),
            'content' => $p->content(),
            'created_at' => $p->createdAt(),
        ];
    } else {
        $t = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
        if ($t === null) {
            throw new NotFoundException('topic_not_found');
        }
        $targetContent = [
            'author' => $t->authorName(),
            'title' => $t->title(),
            'created_at' => $t->createdAt(),
        ];
    }

    // Build aggregated view from raw
    $counts = [];
    foreach ($raw as $r) {
        $counts[$r->reasonCategory()] = ($counts[$r->reasonCategory()] ?? 0) + 1;
    }
    $ids = array_map(static fn($r) => $r->id()->value(), $raw);
    $createdAts = array_map(static fn($r) => $r->createdAt(), $raw);
    sort($createdAts);
    $aggregated = new \Daems\Domain\Forum\AggregatedForumReport(
        $in->targetType, $in->targetId, count($raw), $counts, $ids,
        $createdAts[0], end($createdAts) ?: $createdAts[0],
        $raw[0]->status(),
    );

    return new GetForumReportDetailOutput($aggregated, $raw, $targetContent);
}
```

- [ ] **Step 6: Run both tests — expect PASS**.

- [ ] **Step 7: Run PHPStan — 0 errors**.

- [ ] **Step 8: Commit**

```
git add src/Application/Backstage/Forum/ListForumReportsForAdmin src/Application/Backstage/Forum/GetForumReportDetail tests/Unit/Application/Backstage/Forum/ListForumReportsForAdminTest.php tests/Unit/Application/Backstage/Forum/GetForumReportDetailTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ListForumReportsForAdmin + GetForumReportDetail use cases"
```

---

### Task 8: `ResolveForumReportByDelete` use case (TDD)

**Wave:** W4. **Files:**
- Create: `src/Application/Backstage/Forum/ResolveForumReportByDelete/{.,Input,Output}.php`
- Create: `tests/Unit/Application/Backstage/Forum/ResolveForumReportByDeleteTest.php`

**Contract:** Given `(acting, targetType, targetId, note)`: if target is `post`, delete post; if `topic`, delete topic (cascades posts). Resolve all open reports for target as `deleted`. Audit row with `action='deleted'`, `original_payload` = pre-delete content. Forbidden for non-admins.

- [ ] **Step 1: Write failing test**:

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDeleteInput;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\ForumSeed;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class ResolveForumReportByDeleteTest extends TestCase
{
    public function test_deletes_post_and_resolves_reports_and_writes_audit(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('admin', $tenant);
        $forum  = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit  = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, 'p-1', 'bad content');
        $reports->seedOpen($tenant, 'post', 'p-1', 'u1', 'spam');
        $reports->seedOpen($tenant, 'post', 'p-1', 'u2', 'spam');

        $uc = new ResolveForumReportByDelete($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByDeleteInput($admin, 'post', 'p-1', 'Spam chain'));

        self::assertNull($forum->findPostByIdForTenant('p-1', $tenant));
        foreach ($reports->all() as $r) {
            self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());
            self::assertSame('deleted', $r->resolutionAction());
        }
        $entries = $audit->listRecentForTenant($tenant);
        self::assertCount(1, $entries);
        self::assertSame('deleted', $entries[0]->action());
        self::assertSame('bad content', $entries[0]->originalPayload()['content'] ?? null);
    }

    public function test_deletes_topic_cascade(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('admin', $tenant);
        $forum  = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit  = new InMemoryForumModerationAuditRepository();
        ForumSeed::seedTopicWithPosts($forum, $tenant, 't-1', ['p-1', 'p-2']);
        $reports->seedOpen($tenant, 'topic', 't-1', 'u1', 'off_topic');

        $uc = new ResolveForumReportByDelete($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByDeleteInput($admin, 'topic', 't-1', null));

        self::assertNull($forum->findTopicByIdForTenant('t-1', $tenant));
        self::assertNull($forum->findPostByIdForTenant('p-1', $tenant));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $member = ActingUserFactory::registeredInTenant('m', $tenant);
        $this->expectException(ForbiddenException::class);
        (new ResolveForumReportByDelete(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new ResolveForumReportByDeleteInput($member, 'post', 'p-1', null));
    }

    public function test_unknown_target_throws_not_found(): void
    {
        $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
        $admin  = ActingUserFactory::adminInTenant('admin', $tenant);
        $this->expectException(NotFoundException::class);
        (new ResolveForumReportByDelete(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new ResolveForumReportByDeleteInput($admin, 'post', 'missing', null));
    }
}
```

- [ ] **Step 2: Implement handler**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\Forum\ResolveForumReportByDelete;

use DateTimeImmutable;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;

final class ResolveForumReportByDelete
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumReportRepositoryInterface $reports,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(ResolveForumReportByDeleteInput $in): void
    {
        $tenantId = $in->acting->activeTenant()->id;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $originalPayload = [];
        if ($in->targetType === 'post') {
            $p = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
            if ($p === null) throw new NotFoundException('post_not_found');
            $originalPayload = [
                'author_id' => $p->userId(),
                'content' => $p->content(),
                'topic_id' => $p->topicId(),
                'created_at' => $p->createdAt(),
            ];
            $this->forum->deletePostForTenant($in->targetId, $tenantId);
        } elseif ($in->targetType === 'topic') {
            $t = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
            if ($t === null) throw new NotFoundException('topic_not_found');
            $originalPayload = [
                'author_id' => $t->userId(),
                'title' => $t->title(),
                'category_id' => $t->categoryId(),
                'created_at' => $t->createdAt(),
            ];
            $this->forum->deleteTopicForTenant($in->targetId, $tenantId);
        } else {
            throw new NotFoundException('invalid_target_type');
        }

        $now = new DateTimeImmutable();
        $this->reports->resolveAllForTarget(
            $in->targetType, $in->targetId, $tenantId,
            'deleted', $in->acting->id, $in->note, $now,
        );
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            $in->targetType,
            $in->targetId,
            'deleted',
            $originalPayload,
            null,
            $in->note,
            $in->acting->id,
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
```

- [ ] **Step 3: `ResolveForumReportByDeleteInput`**:

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\Forum\ResolveForumReportByDelete;

use Daems\Domain\Auth\ActingUser;

final class ResolveForumReportByDeleteInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly ?string $note,
    ) {}
}
```

- [ ] **Step 4: Run test — expect PASS (4 tests)**.

- [ ] **Step 5: Commit**

```
git add src/Application/Backstage/Forum/ResolveForumReportByDelete tests/Unit/Application/Backstage/Forum/ResolveForumReportByDeleteTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ResolveForumReportByDelete (hard-delete target + resolve + audit)"
```

---

### Task 9: `ResolveForumReportByLock` use case (TDD)

**Wave:** W4. **Files:**
- Create: `src/Application/Backstage/Forum/ResolveForumReportByLock/`
- Create: `tests/Unit/Application/Backstage/Forum/ResolveForumReportByLockTest.php`

**Contract:** Target must be `topic`. Set `locked=1`, resolve reports as `locked`, audit `action='locked'`. Post target → `InvalidArgumentException('cannot_lock_post')`. Already-locked is idempotent (no double-audit — the use case checks current state; if already locked, still resolve reports + audit for traceability).

- [ ] **Step 1: Write failing test** with 4 cases:
  1. `test_locks_topic_and_resolves_and_audits` — topic → locked, reports → resolved action=locked, audit entry present.
  2. `test_post_target_rejected` — throws `InvalidArgumentException('cannot_lock_post')`.
  3. `test_unknown_topic_throws_not_found`.
  4. `test_non_admin_forbidden`.

Use the same scaffolding pattern as Task 8 test — `ForumSeed::seedTopic`, `ActingUserFactory::adminInTenant`, etc.

- [ ] **Step 2: Implement handler**:

```php
public function execute(ResolveForumReportByLockInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    if ($in->targetType !== 'topic') {
        throw new \InvalidArgumentException('cannot_lock_post');
    }
    $t = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
    if ($t === null) throw new NotFoundException('topic_not_found');

    $this->forum->setTopicLockedForTenant($in->targetId, $tenantId, true);
    $now = new DateTimeImmutable();
    $this->reports->resolveAllForTarget(
        'topic', $in->targetId, $tenantId, 'locked', $in->acting->id, $in->note, $now,
    );
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'topic', $in->targetId,
        'locked',
        ['locked' => $t->locked()],
        ['locked' => true],
        $in->note,
        $in->acting->id,
        null,
        $now->format('Y-m-d H:i:s'),
    ));
}
```

- [ ] **Step 3: Run test — expect PASS (4 tests)**.

- [ ] **Step 4: Commit**

```
git add src/Application/Backstage/Forum/ResolveForumReportByLock tests/Unit/Application/Backstage/Forum/ResolveForumReportByLockTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ResolveForumReportByLock (topic only; resolve + audit)"
```

---

### Task 10: `ResolveForumReportByWarn` use case (TDD)

**Wave:** W4. **Files:**
- Create: `src/Application/Backstage/Forum/ResolveForumReportByWarn/`
- Create: `tests/Unit/Application/Backstage/Forum/ResolveForumReportByWarnTest.php`

**Contract:** Resolve target's reports; issue `ForumUserWarning` to the target's author (post.userId or topic.userId). Audit `action='warned'`. If target author is null (legacy/anonymous), throw `InvalidArgumentException('no_author_to_warn')`.

- [ ] **Step 1: Test cases**:
  1. `test_warns_post_author_and_resolves_and_audits` — post with userId='u-a' → one warning row for 'u-a' tenant-scoped, reports resolved action=warned, audit action=warned.
  2. `test_warns_topic_author_when_target_is_topic`.
  3. `test_no_author_throws`.
  4. `test_non_admin_forbidden`.
  5. `test_unknown_target_throws_not_found`.

- [ ] **Step 2: Implement handler**:

```php
public function execute(ResolveForumReportByWarnInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }

    $authorId = null;
    if ($in->targetType === 'post') {
        $p = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
        if ($p === null) throw new NotFoundException('post_not_found');
        $authorId = $p->userId();
    } elseif ($in->targetType === 'topic') {
        $t = $this->forum->findTopicByIdForTenant($in->targetId, $tenantId);
        if ($t === null) throw new NotFoundException('topic_not_found');
        $authorId = $t->userId();
    } else {
        throw new NotFoundException('invalid_target_type');
    }

    if ($authorId === null) {
        throw new \InvalidArgumentException('no_author_to_warn');
    }

    $now = new DateTimeImmutable();
    $this->warnings->record(new ForumUserWarning(
        ForumUserWarningId::generate(),
        $tenantId,
        $authorId,
        (string) $in->note,
        null,
        $in->acting->id,
        $now->format('Y-m-d H:i:s'),
    ));
    $this->reports->resolveAllForTarget(
        $in->targetType, $in->targetId, $tenantId,
        'warned', $in->acting->id, $in->note, $now,
    );
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, $in->targetType, $in->targetId,
        'warned',
        ['author_id' => $authorId],
        null,
        $in->note,
        $in->acting->id,
        null,
        $now->format('Y-m-d H:i:s'),
    ));
}
```

Constructor takes `(ForumRepositoryInterface, ForumReportRepositoryInterface, ForumUserWarningRepositoryInterface, ForumModerationAuditRepositoryInterface)`.

- [ ] **Step 3: Run test — expect PASS (5 tests)**.

- [ ] **Step 4: Commit** — `Feat(forum): ResolveForumReportByWarn (issue warning to target author + audit)`.

---

### Task 11: `ResolveForumReportByEdit` use case (TDD)

**Wave:** W5. **Files:**
- Create: `src/Application/Backstage/Forum/ResolveForumReportByEdit/`
- Create: `tests/Unit/Application/Backstage/Forum/ResolveForumReportByEditTest.php`

**Contract:** Target must be `post`. Overwrite post content, stamp `edited_at` = now. Resolve reports as `edited`. Audit `action='edited'`, `original_payload.content` = old content, `new_payload.content` = new content.

- [ ] **Step 1: Test cases**:
  1. `test_overwrites_post_and_resolves_and_audits` — post had 'original', after resolve post has 'clean', audit original_payload.content='original' new_payload.content='clean'.
  2. `test_topic_target_rejected` — `InvalidArgumentException('cannot_edit_topic')`.
  3. `test_empty_content_rejected` — `InvalidArgumentException('content_required')`.
  4. `test_non_admin_forbidden`.
  5. `test_unknown_post_throws`.

- [ ] **Step 2: Implement**:

```php
public function execute(ResolveForumReportByEditInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    if ($in->targetType !== 'post') {
        throw new \InvalidArgumentException('cannot_edit_topic');
    }
    $newContent = trim($in->newContent);
    if ($newContent === '') {
        throw new \InvalidArgumentException('content_required');
    }
    $p = $this->forum->findPostByIdForTenant($in->targetId, $tenantId);
    if ($p === null) throw new NotFoundException('post_not_found');

    $now = new DateTimeImmutable();
    $nowStr = $now->format('Y-m-d H:i:s');

    $this->forum->updatePostContentForTenant($in->targetId, $tenantId, $newContent, $nowStr);
    $this->reports->resolveAllForTarget(
        'post', $in->targetId, $tenantId, 'edited', $in->acting->id, $in->note, $now,
    );
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'post', $in->targetId,
        'edited',
        ['content' => $p->content()],
        ['content' => $newContent, 'edited_at' => $nowStr],
        $in->note,
        $in->acting->id,
        null,
        $nowStr,
    ));
}
```

- [ ] **Step 3: Run test — expect PASS (5 tests)**.

- [ ] **Step 4: Commit** — `Feat(forum): ResolveForumReportByEdit (overwrite post content + preserve original in audit)`.

---

### Task 12: `DismissForumReport` use case (TDD)

**Wave:** W5. **Files:**
- Create: `src/Application/Backstage/Forum/DismissForumReport/`
- Create: `tests/Unit/Application/Backstage/Forum/DismissForumReportTest.php`

**Contract:** Mark all open reports for `(targetType, targetId)` as `dismissed`. No moderation audit row. Optional note captured on report rows.

- [ ] **Step 1: Test cases**:
  1. `test_dismisses_all_open_reports_for_target_without_audit` — 3 open reports → all dismissed, audit list empty.
  2. `test_already_dismissed_is_idempotent` — calling twice leaves status dismissed, no exception.
  3. `test_non_admin_forbidden`.
  4. `test_no_open_reports_is_noop` — call with zero open reports, returns without throwing.

- [ ] **Step 2: Implement**:

```php
public function execute(DismissForumReportInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $now = new DateTimeImmutable();
    $this->reports->dismissAllForTarget(
        $in->targetType, $in->targetId, $tenantId,
        $in->acting->id, $in->note, $now,
    );
    // No audit row — dismissed status on reports is the trail.
}
```

- [ ] **Step 3: Run test — expect PASS (4 tests)**.

- [ ] **Step 4: Commit** — `Feat(forum): DismissForumReport (no audit row — status is the trail)`.

---

### Task 13: Direct `Pin` / `Unpin` / `Lock` / `Unlock` topic use cases (TDD, combined)

**Wave:** W5. **Files:**
- Create: `src/Application/Backstage/Forum/PinForumTopic/`, `UnpinForumTopic/`, `LockForumTopic/`, `UnlockForumTopic/` (4 directories each with handler + Input + Output)
- Create: 4 test classes under `tests/Unit/Application/Backstage/Forum/`

**Contract (all four):** Admin-only. Unknown topic → `NotFoundException`. Flip state + audit with `original_payload`/`new_payload` showing the prior + new flag.

- [ ] **Step 1: Write `PinForumTopicTest`** with 3 cases: pins + audits, non-admin forbidden, unknown topic throws. Pattern mirrors Task 8's test structure.

- [ ] **Step 2: Implement `PinForumTopic`**:

```php
final class PinForumTopic
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(PinForumTopicInput $in): void
    {
        $tenantId = $in->acting->activeTenant()->id;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }
        $t = $this->forum->findTopicByIdForTenant($in->topicId, $tenantId);
        if ($t === null) throw new NotFoundException('topic_not_found');

        $this->forum->setTopicPinnedForTenant($in->topicId, $tenantId, true);
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId, 'topic', $in->topicId,
            'pinned',
            ['pinned' => $t->pinned()],
            ['pinned' => true],
            null, $in->acting->id, null,
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ));
    }
}
```

- [ ] **Step 3: Clone for `UnpinForumTopic`** — identical shape; `setTopicPinnedForTenant(..., false)`, action=`'unpinned'`, `new_payload` = `['pinned' => false]`.

- [ ] **Step 4: Clone for `LockForumTopic` + `UnlockForumTopic`** — identical shape; use `setTopicLockedForTenant`, action=`'locked'`/`'unlocked'`, payload toggles.

- [ ] **Step 5: Run 4 test classes — expect PASS (12 tests total)**.

- [ ] **Step 6: Commit**

```
git add src/Application/Backstage/Forum/PinForumTopic src/Application/Backstage/Forum/UnpinForumTopic src/Application/Backstage/Forum/LockForumTopic src/Application/Backstage/Forum/UnlockForumTopic tests/Unit/Application/Backstage/Forum/PinForumTopicTest.php tests/Unit/Application/Backstage/Forum/UnpinForumTopicTest.php tests/Unit/Application/Backstage/Forum/LockForumTopicTest.php tests/Unit/Application/Backstage/Forum/UnlockForumTopicTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): direct Pin/Unpin/Lock/Unlock topic use cases + audit"
```

---

### Task 14: `DeleteForumTopicAsAdmin` + `DeleteForumPostAsAdmin` (TDD, combined)

**Wave:** W6. **Files:**
- Create: `src/Application/Backstage/Forum/DeleteForumTopicAsAdmin/`, `DeleteForumPostAsAdmin/`
- Create: 2 test classes.

**Contract:** Direct hard-delete (no report context). Audit `action='deleted'`, `original_payload` = pre-delete content. Forbidden for non-admin. Not-found throws.

- [ ] **Step 1: Tests — 3 cases each** (happy / forbidden / not-found). Same pattern as Task 8 test, but no report repo involved.

- [ ] **Step 2: Implement handlers** — same body as the matching branch of `ResolveForumReportByDelete` (Task 8) **minus** the `reports.resolveAllForTarget` call. Dependencies: `(ForumRepositoryInterface, ForumModerationAuditRepositoryInterface)`.

Topic handler body:
```php
public function execute(DeleteForumTopicAsAdminInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $t = $this->forum->findTopicByIdForTenant($in->topicId, $tenantId);
    if ($t === null) throw new NotFoundException('topic_not_found');
    $payload = ['title' => $t->title(), 'category_id' => $t->categoryId(), 'author_id' => $t->userId()];
    $this->forum->deleteTopicForTenant($in->topicId, $tenantId);
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'topic', $in->topicId,
        'deleted', $payload, null, null, $in->acting->id, null,
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ));
}
```
Post handler is analogous with `findPostByIdForTenant` + `deletePostForTenant` + `targetType='post'`.

- [ ] **Step 3: Run tests — expect PASS (6 tests)**.

- [ ] **Step 4: Commit** — `Feat(forum): DeleteForumTopic/Post as admin (direct hard-delete + audit)`.

---

### Task 15: `EditForumPostAsAdmin` use case (TDD)

**Wave:** W6. **Files:**
- Create: `src/Application/Backstage/Forum/EditForumPostAsAdmin/`
- Create: `tests/Unit/Application/Backstage/Forum/EditForumPostAsAdminTest.php`

**Contract:** Same as `ResolveForumReportByEdit` but **no report resolution**. Audit `action='edited'`, `original_payload.content` = old, `new_payload.content` = new + `edited_at`.

- [ ] **Step 1: Tests — 4 cases**: happy / empty-content / unknown-post / non-admin.

- [ ] **Step 2: Implement** — copy `ResolveForumReportByEdit::execute` body minus the `resolveAllForTarget` call. Dependencies: `(ForumRepositoryInterface, ForumModerationAuditRepositoryInterface)`.

```php
public function execute(EditForumPostAsAdminInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $newContent = trim($in->newContent);
    if ($newContent === '') throw new \InvalidArgumentException('content_required');
    $p = $this->forum->findPostByIdForTenant($in->postId, $tenantId);
    if ($p === null) throw new NotFoundException('post_not_found');

    $now = new DateTimeImmutable();
    $nowStr = $now->format('Y-m-d H:i:s');
    $this->forum->updatePostContentForTenant($in->postId, $tenantId, $newContent, $nowStr);
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'post', $in->postId,
        'edited',
        ['content' => $p->content()],
        ['content' => $newContent, 'edited_at' => $nowStr],
        $in->note, $in->acting->id, null, $nowStr,
    ));
}
```

- [ ] **Step 3: Run — expect PASS (4)**.

- [ ] **Step 4: Commit** — `Feat(forum): EditForumPostAsAdmin (direct edit + original preserved)`.

---

### Task 16: `WarnForumUser` use case (TDD)

**Wave:** W6. **Files:**
- Create: `src/Application/Backstage/Forum/WarnForumUser/`
- Create: `tests/Unit/Application/Backstage/Forum/WarnForumUserTest.php`

**Contract:** Direct admin warning. Validates reason non-empty (+ 500-char cap). Writes `ForumUserWarning`. No audit row — `forum_user_warnings` is the trail.

- [ ] **Step 1: Tests — 4 cases**: happy / empty-reason / non-admin / 500-char-truncate.

- [ ] **Step 2: Implement**:

```php
public function execute(WarnForumUserInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $reason = trim($in->reason);
    if ($reason === '') throw new \InvalidArgumentException('reason_required');
    if (strlen($reason) > 500) $reason = substr($reason, 0, 500);

    $this->warnings->record(new ForumUserWarning(
        ForumUserWarningId::generate(),
        $tenantId, $in->userId, $reason, null, $in->acting->id,
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ));
}
```

- [ ] **Step 3: Run — expect PASS (4)**.

- [ ] **Step 4: Commit** — `Feat(forum): WarnForumUser (direct warning; trail in forum_user_warnings)`.

---

### Task 17: Category CRUD — `Create` + `Update` + `Delete` use cases (TDD, combined)

**Wave:** W7. **Files:**
- Create: `src/Application/Backstage/Forum/CreateForumCategoryAsAdmin/`, `UpdateForumCategoryAsAdmin/`, `DeleteForumCategoryAsAdmin/`
- Create: 3 test classes.
- Create: `src/Domain/Shared/ConflictException.php` (if not present).
- Modify: `ForumRepositoryInterface` + `SqlForumRepository` + `InMemoryForumRepository` — add `findCategoryByIdForTenant`.

- [ ] **Step 1: Add `findCategoryByIdForTenant` to interface + impls**:

Interface:
```php
public function findCategoryByIdForTenant(string $id, TenantId $tenantId): ?ForumCategory;
```

SQL:
```php
public function findCategoryByIdForTenant(string $id, TenantId $tenantId): ?ForumCategory
{
    $row = $this->db->queryOne(
        'SELECT c.*,
                COUNT(DISTINCT t.id) AS topic_count,
                COUNT(DISTINCT p.id) AS post_count
           FROM forum_categories c
           LEFT JOIN forum_topics t ON t.category_id = c.id
           LEFT JOIN forum_posts  p ON p.topic_id    = t.id
          WHERE c.id = ? AND c.tenant_id = ?
          GROUP BY c.id',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrateCategory($row) : null;
}
```

InMemory: array lookup by id filtered by tenant.

- [ ] **Step 2: Add `ConflictException` if missing**:

```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Shared;
use RuntimeException;
final class ConflictException extends RuntimeException {}
```

- [ ] **Step 3: `CreateForumCategoryAsAdminTest` — 4 cases**: creates / slug-required / duplicate-slug / non-admin.

- [ ] **Step 4: Implement `CreateForumCategoryAsAdmin`**:

```php
public function execute(CreateForumCategoryAsAdminInput $in): CreateForumCategoryAsAdminOutput
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $slug = trim($in->slug);
    $name = trim($in->name);
    if ($slug === '') throw new \InvalidArgumentException('slug_required');
    if ($name === '') throw new \InvalidArgumentException('name_required');
    if ($this->forum->findCategoryBySlugForTenant($slug, $tenantId) !== null) {
        throw new ConflictException('slug_taken');
    }

    $cat = new ForumCategory(
        ForumCategoryId::generate(),
        $tenantId, $slug, $name, $in->icon, $in->description, $in->sortOrder, 0, 0,
    );
    $this->forum->saveCategory($cat);
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'category', $cat->id()->value(),
        'category_created',
        null,
        ['slug' => $slug, 'name' => $name, 'icon' => $in->icon, 'description' => $in->description, 'sort_order' => $in->sortOrder],
        null, $in->acting->id, null,
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ));
    return new CreateForumCategoryAsAdminOutput($cat->id()->value(), $slug);
}
```

- [ ] **Step 5: `UpdateForumCategoryAsAdminTest` — 3 cases**: updates-partial / unknown-category / non-admin.

- [ ] **Step 6: Implement `UpdateForumCategoryAsAdmin`**:

```php
public function execute(UpdateForumCategoryAsAdminInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $existing = $this->forum->findCategoryByIdForTenant($in->id, $tenantId);
    if ($existing === null) throw new NotFoundException('category_not_found');

    $updated = new ForumCategory(
        $existing->id(),
        $tenantId,
        $in->slug !== null ? trim($in->slug) : $existing->slug(),
        $in->name !== null ? trim($in->name) : $existing->name(),
        $in->icon ?? $existing->icon(),
        $in->description ?? $existing->description(),
        $in->sortOrder ?? $existing->sortOrder(),
        $existing->topicCount(),
        $existing->postCount(),
    );
    $this->forum->updateCategoryForTenant($updated);
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'category', $existing->id()->value(),
        'category_updated',
        ['slug' => $existing->slug(), 'name' => $existing->name(), 'icon' => $existing->icon(), 'description' => $existing->description(), 'sort_order' => $existing->sortOrder()],
        ['slug' => $updated->slug(), 'name' => $updated->name(), 'icon' => $updated->icon(), 'description' => $updated->description(), 'sort_order' => $updated->sortOrder()],
        null, $in->acting->id, null,
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ));
}
```

- [ ] **Step 7: `DeleteForumCategoryAsAdminTest` — 4 cases**: happy (empty category) / has-topics-rejected / unknown / non-admin.

- [ ] **Step 8: Implement `DeleteForumCategoryAsAdmin`**:

```php
public function execute(DeleteForumCategoryAsAdminInput $in): void
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    $existing = $this->forum->findCategoryByIdForTenant($in->id, $tenantId);
    if ($existing === null) throw new NotFoundException('category_not_found');
    if ($this->forum->countTopicsInCategoryForTenant($in->id, $tenantId) > 0) {
        throw new ConflictException('category_has_topics');
    }
    $this->forum->deleteCategoryForTenant($in->id, $tenantId);
    $this->audit->record(new ForumModerationAuditEntry(
        ForumModerationAuditId::generate(),
        $tenantId, 'category', $in->id,
        'category_deleted',
        ['slug' => $existing->slug(), 'name' => $existing->name()],
        null, null, $in->acting->id, null,
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ));
}
```

- [ ] **Step 9: Run all 3 test classes — expect PASS (11 tests)**.

- [ ] **Step 10: Run PHPStan — 0 errors**.

- [ ] **Step 11: Commit**

```
git add src/Application/Backstage/Forum/CreateForumCategoryAsAdmin src/Application/Backstage/Forum/UpdateForumCategoryAsAdmin src/Application/Backstage/Forum/DeleteForumCategoryAsAdmin src/Domain/Shared/ConflictException.php src/Domain/Forum/ForumRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlForumRepository.php tests/Support/Fake/InMemoryForumRepository.php tests/Unit/Application/Backstage/Forum/CreateForumCategoryAsAdminTest.php tests/Unit/Application/Backstage/Forum/UpdateForumCategoryAsAdminTest.php tests/Unit/Application/Backstage/Forum/DeleteForumCategoryAsAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): category CRUD + topic-count guard + audit"
```

---

### Task 18: `ListForumModerationAuditForAdmin` use case (TDD)

**Wave:** W7. **Files:**
- Create: `src/Application/Backstage/Forum/ListForumModerationAuditForAdmin/`
- Create: test class.

- [ ] **Step 1: Tests — 3 cases**: happy (newest-first) / filter-by-action / non-admin.

- [ ] **Step 2: Implement**:

```php
public function execute(ListForumModerationAuditForAdminInput $in): ListForumModerationAuditForAdminOutput
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    return new ListForumModerationAuditForAdminOutput(
        $this->audit->listRecentForTenant($tenantId, $in->limit, $in->filters),
    );
}
```

- [ ] **Step 3: Run — PASS (3)**.

- [ ] **Step 4: Commit** — `Feat(forum): ListForumModerationAuditForAdmin use case`.

---

### Task 19: `CreateForumPost` locked-topic guard

**Wave:** W7. **Files:**
- Modify: `src/Application/Forum/CreateForumPost/CreateForumPost.php`
- Modify: `tests/Unit/Application/Forum/CreateForumPostTest.php` — add new test method.
- Modify: `tests/Support/ForumSeed.php` — `seedTopic` gains `locked` param (default false).

- [ ] **Step 1: Add failing test**:

```php
public function test_locked_topic_rejects_new_post(): void
{
    $tenant = TenantId::fromString('11111111-1111-4111-8111-111111111111');
    $user   = ActingUserFactory::registeredInTenant('u', $tenant);
    $forum  = new InMemoryForumRepository();
    ForumSeed::seedTopic($forum, $tenant, 't-1', slug: 't-1-slug', locked: true);
    $uc = new CreateForumPost($forum, /* existing deps */);
    $this->expectException(TopicLockedException::class);
    $uc->execute(new CreateForumPostInput($user, 't-1-slug', 'content'));
}
```

- [ ] **Step 2: Run test — expect FAIL**.

- [ ] **Step 3: Add guard** in `CreateForumPost::execute` right after loading the topic:

```php
if ($topic->locked()) {
    throw new TopicLockedException('topic_locked');
}
```

- [ ] **Step 4: Run — expect PASS**. Run full `CreateForumPostTest` — all green.

- [ ] **Step 5: Commit**

```
git add src/Application/Forum/CreateForumPost/CreateForumPost.php tests/Unit/Application/Forum/CreateForumPostTest.php tests/Support/ForumSeed.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): CreateForumPost rejects locked topics with TopicLockedException"
```

---

### Task 20: Extend `ListPendingApplicationsForAdmin` + `DismissApplication` for `forum_report`

**Wave:** W3 (parallel with 6, 7). **Files:**
- Modify: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php`
- Modify: `src/Application/Backstage/DismissApplication/DismissApplication.php`
- Modify: corresponding tests.

**Contract:** LPAFA gains 4th branch for aggregated forum reports. Items honour dismissals by `(admin, 'forum_report', '<type>:<uuid>')`. DismissApplication validates compound id via regex `/^(post|topic):[0-9a-f\-]{36}$/`.

- [ ] **Step 1: Extend LPAFA ctor + handler** — add `ForumReportRepositoryInterface $forumReports` + `ForumRepositoryInterface $forum`. Append 4th branch:

```php
$forumAggregated = $this->forumReports->listAggregatedForTenant($tenantId, ['status' => 'open']);
foreach ($forumAggregated as $agg) {
    $compoundId = $agg->compoundKey();
    if ($this->dismissals->isDismissed($tenantId, $in->acting->id, 'forum_report', $compoundId)) {
        continue;
    }
    if ($agg->targetType === 'post') {
        $p = $this->forum->findPostByIdForTenant($agg->targetId, $tenantId);
        $name = $p !== null ? mb_substr(trim($p->content()), 0, 80) : '(deleted post)';
    } else {
        $t = $this->forum->findTopicByIdForTenant($agg->targetId, $tenantId);
        $name = $t !== null ? $t->title() : '(deleted topic)';
    }
    $items[] = [
        'id' => $compoundId,
        'type' => 'forum_report',
        'name' => $name,
        'created_at' => $agg->latestCreatedAt,
    ];
}
```

- [ ] **Step 2: LPAFA test — 2 new cases**:
  1. `test_includes_aggregated_forum_reports` — 2 open reports on post + 1 on topic → items contain 2 `forum_report` entries with correct names and compound ids.
  2. `test_dismissed_forum_report_excluded` — dismiss `(admin, 'forum_report', 'post:p-1')` → item absent.

- [ ] **Step 3: Extend DismissApplication validation**:

```php
$valid = ['member', 'supporter', 'project_proposal', 'forum_report'];
if (!in_array($in->appType, $valid, true)) {
    throw new \InvalidArgumentException('invalid_app_type');
}
if ($in->appType === 'forum_report' && !preg_match('/^(post|topic):[0-9a-f\-]{36}$/', $in->appId)) {
    throw new \InvalidArgumentException('invalid_forum_report_id');
}
```

- [ ] **Step 4: DismissApplication test — 2 new cases**: accepts compound / rejects malformed.

- [ ] **Step 5: Run all — PASS, no regressions**.

- [ ] **Step 6: Commit**

```
git add src/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdmin.php src/Application/Backstage/DismissApplication tests/Unit/Application/Backstage/ListPendingApplications/ListPendingApplicationsForAdminTest.php tests/Unit/Application/Backstage/DismissApplication/DismissApplicationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): LPAFA + DismissApplication support aggregated forum_report with compound id"
```

---

### Task 21: HTTP controllers — `ForumController::createReport` + ~22 `BackstageController` methods

**Wave:** W8 (solo — modifies two controllers). **Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/ForumController.php` — add `createReport`.
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add ~22 admin methods.

**General rule:** Each handler is thin — unwrap `Request`, call use case, catch domain exceptions → map to HTTP response. Use case throws:
- `ForbiddenException` → 403
- `NotFoundException` → 404
- `ConflictException` → 409
- `TopicLockedException` → 409 with `error: 'topic_locked'`
- `InvalidArgumentException` → 400
- Uncaught → propagates to framework 500 handler.

**Dependency inject via ctor** — for each new use case, add a private readonly property and ctor arg to `BackstageController`. Grouping: all forum admin deps after existing ones.

- [ ] **Step 1: Extend `ForumController`** — add ctor arg + property:

```php
private readonly ReportForumTarget $reportForumTarget,
```

Add method:
```php
public function createReport(Request $request): Response
{
    $acting = $request->requireActingUser();
    $targetType = (string) ($request->string('target_type') ?? '');
    $targetId   = (string) ($request->string('target_id') ?? '');
    $reason     = (string) ($request->string('reason_category') ?? '');
    $detail     = $request->string('reason_detail');

    try {
        $this->reportForumTarget->execute(new ReportForumTargetInput(
            $acting, $targetType, $targetId, $reason, $detail,
        ));
    } catch (NotFoundException) {
        return Response::notFound('Target not found');
    } catch (\InvalidArgumentException $e) {
        return Response::badRequest($e->getMessage());
    }
    return Response::json(['data' => ['ok' => true]], 201);
}
```

- [ ] **Step 2: Extend `BackstageController`** — add new use case dependencies (declare after existing ones). Example single method — `listForumReports`:

```php
public function listForumReports(Request $request): Response
{
    $acting = $request->requireActingUser();
    $status = $request->string('status');
    $targetType = $request->string('target_type');
    $filters = [];
    if (is_string($status) && $status !== '') $filters['status'] = $status;
    if (is_string($targetType) && $targetType !== '') $filters['target_type'] = $targetType;
    $limit = (int) ($request->string('limit') ?? 50);

    try {
        $out = $this->listForumReports->execute(new ListForumReportsForAdminInput($acting, $filters, $limit));
    } catch (ForbiddenException) {
        return Response::forbidden('Admin only');
    }
    return Response::json(['data' => array_map(static fn($a) => [
        'compound_id' => $a->compoundKey(),
        'target_type' => $a->targetType,
        'target_id'   => $a->targetId,
        'report_count'=> $a->reportCount,
        'reason_counts'=> $a->reasonCounts,
        'earliest'    => $a->earliestCreatedAt,
        'latest'      => $a->latestCreatedAt,
        'status'      => $a->status,
    ], $out->items)]);
}
```

- [ ] **Step 3: Implement remaining 21 methods** (same shape — unwrap, execute, map exceptions, return `Response::json`):

| Handler | Use case | Notes |
|---|---|---|
| `getForumReport` | `GetForumReportDetail` | GET; params: `target_type`, `target_id` (derived from `{id}` = compound) |
| `resolveForumReport` | one of 4 resolve use cases based on `action` body field | dispatch in controller: `'delete' → ResolveForumReportByDelete`, `'lock' → …Lock`, `'warn' → …Warn`, `'edit' → …Edit`. Invalid action → 400. |
| `dismissForumReport` | `DismissForumReport` | POST, optional note |
| `listForumTopicsAdmin` | direct `forum->listRecentTopicsForTenant` via a new thin use case OR controller-level call through `ForumRepositoryInterface` | preferred: use case `ListForumTopicsForAdmin` (trivial — admin check + pass-through). Add this use case now in this task (6 lines). |
| `pinForumTopic` | `PinForumTopic` | |
| `unpinForumTopic` | `UnpinForumTopic` | |
| `lockForumTopic` | `LockForumTopic` | |
| `unlockForumTopic` | `UnlockForumTopic` | |
| `deleteForumTopicAdmin` | `DeleteForumTopicAsAdmin` | |
| `listForumPostsAdmin` | `ListForumPostsForAdmin` (add similarly) | |
| `editForumPostAdmin` | `EditForumPostAsAdmin` | |
| `deleteForumPostAdmin` | `DeleteForumPostAsAdmin` | |
| `warnForumUser` | `WarnForumUser` | |
| `listForumCategoriesAdmin` | `forum->findAllCategoriesForTenant` via thin `ListForumCategoriesForAdmin` | or reuse existing public `ListForumCategories` since it returns the same data |
| `createForumCategoryAdmin` | `CreateForumCategoryAsAdmin` | |
| `updateForumCategoryAdmin` | `UpdateForumCategoryAsAdmin` | |
| `deleteForumCategoryAdmin` | `DeleteForumCategoryAsAdmin` | |
| `listForumAudit` | `ListForumModerationAuditForAdmin` | |

For each, the exception-mapping shape is identical to `listForumReports`. Show `resolveForumReport` fully since it has action dispatch:

```php
public function resolveForumReport(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $compoundId = (string) $params['id'];
    if (!preg_match('/^(post|topic):([0-9a-f\-]{36})$/', $compoundId, $m)) {
        return Response::badRequest('Invalid compound id');
    }
    $targetType = $m[1];
    $targetId = $m[2];
    $action = (string) ($request->string('action') ?? '');
    $note = $request->string('note');

    try {
        switch ($action) {
            case 'delete':
                $this->resolveByDelete->execute(
                    new ResolveForumReportByDeleteInput($acting, $targetType, $targetId, $note));
                break;
            case 'lock':
                $this->resolveByLock->execute(
                    new ResolveForumReportByLockInput($acting, $targetType, $targetId, $note));
                break;
            case 'warn':
                $this->resolveByWarn->execute(
                    new ResolveForumReportByWarnInput($acting, $targetType, $targetId, $note));
                break;
            case 'edit':
                $newContent = (string) ($request->string('new_content') ?? '');
                $this->resolveByEdit->execute(
                    new ResolveForumReportByEditInput($acting, $targetType, $targetId, $newContent, $note));
                break;
            default:
                return Response::badRequest('Unknown action');
        }
    } catch (ForbiddenException) {
        return Response::forbidden('Admin only');
    } catch (NotFoundException) {
        return Response::notFound('Target not found');
    } catch (\InvalidArgumentException $e) {
        return Response::badRequest($e->getMessage());
    } catch (ConflictException $e) {
        return Response::conflict($e->getMessage());
    }
    return Response::json(['data' => ['ok' => true]]);
}
```

- [ ] **Step 4: Add `ListForumTopicsForAdmin` + `ListForumPostsForAdmin` thin use cases** — one directory each with Input/Output/handler. Handler:

```php
// ListForumTopicsForAdmin
public function execute(ListForumTopicsForAdminInput $in): ListForumTopicsForAdminOutput
{
    $tenantId = $in->acting->activeTenant()->id;
    if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
        throw new ForbiddenException('not_admin');
    }
    return new ListForumTopicsForAdminOutput(
        $this->forum->listRecentTopicsForTenant($tenantId, $in->limit, $in->filters),
    );
}
```

Same for posts. Write a minimal test for each (happy + forbidden — 2 tests each).

- [ ] **Step 5: Map `TopicLockedException` in existing `ForumController::createPost`** — wrap execute in try/catch:

```php
try {
    $output = $this->createPost->execute(new CreateForumPostInput($acting, $params['slug'], $content));
} catch (TopicLockedException) {
    return Response::conflict('topic_locked');
}
```

- [ ] **Step 6: Run `composer test:all`** — all green.

- [ ] **Step 7: PHPStan — 0 errors**.

- [ ] **Step 8: Commit**

```
git add src/Infrastructure/Adapter/Api/Controller/ForumController.php src/Infrastructure/Adapter/Api/Controller/BackstageController.php src/Application/Backstage/Forum/ListForumTopicsForAdmin src/Application/Backstage/Forum/ListForumPostsForAdmin tests/Unit/Application/Backstage/Forum/ListForumTopicsForAdminTest.php tests/Unit/Application/Backstage/Forum/ListForumPostsForAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): ForumController::createReport + 22 BackstageController admin methods"
```

---

### Task 22: Routes — register ~23 new entries

**Wave:** W9 (solo — single routes file). **Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Register user-side route** after existing forum routes:

```php
$router->post('/forum/reports',
    [TenantContextMiddleware::class, AuthMiddleware::class],
    [ForumController::class, 'createReport']);
```

- [ ] **Step 2: Register admin routes under `/backstage/forum/*`**. Example block:

```php
// Forum moderation
$router->get('/backstage/forum/reports',
    [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'listForumReports']);
$router->get('/backstage/forum/reports/{id}',
    [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'getForumReport']);
$router->post('/backstage/forum/reports/{id}/resolve',
    [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'resolveForumReport']);
$router->post('/backstage/forum/reports/{id}/dismiss',
    [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'dismissForumReport']);

$router->get('/backstage/forum/topics', [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'listForumTopicsAdmin']);
$router->post('/backstage/forum/topics/{id}/pin',    [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'pinForumTopic']);
$router->post('/backstage/forum/topics/{id}/unpin',  [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'unpinForumTopic']);
$router->post('/backstage/forum/topics/{id}/lock',   [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'lockForumTopic']);
$router->post('/backstage/forum/topics/{id}/unlock', [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'unlockForumTopic']);
$router->post('/backstage/forum/topics/{id}/delete', [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'deleteForumTopicAdmin']);

$router->get('/backstage/forum/posts', [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'listForumPostsAdmin']);
$router->post('/backstage/forum/posts/{id}/edit',   [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'editForumPostAdmin']);
$router->post('/backstage/forum/posts/{id}/delete', [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'deleteForumPostAdmin']);

$router->post('/backstage/forum/users/{id}/warn', [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'warnForumUser']);

$router->get('/backstage/forum/categories',           [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'listForumCategoriesAdmin']);
$router->post('/backstage/forum/categories',          [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'createForumCategoryAdmin']);
$router->post('/backstage/forum/categories/{id}',     [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'updateForumCategoryAdmin']);
$router->post('/backstage/forum/categories/{id}/delete', [TenantContextMiddleware::class, AuthMiddleware::class], [BackstageController::class, 'deleteForumCategoryAdmin']);

$router->get('/backstage/forum/audit', [TenantContextMiddleware::class, AuthMiddleware::class],
    [BackstageController::class, 'listForumAudit']);
```

- [ ] **Step 3: Run existing route-registration test (if any) + `composer test:all`** — all green.

- [ ] **Step 4: Commit**

```
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): register 19 backstage forum routes + 1 user-side /forum/reports"
```

---

### Task 23: DI wiring — BOTH containers + live smoke

**Wave:** W10 (solo — shared files, must precede tests). **Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

**Bootstrap pattern** (following `SqlForumRepository` existing binding):

- [ ] **Step 1: In `bootstrap/app.php`, add bindings** after existing forum bindings (around line 660+):

```php
// Forum admin repos
$container->singleton(ForumReportRepositoryInterface::class,
    static fn(Container $c) => new SqlForumReportRepository($c->make(Connection::class)));
$container->singleton(ForumModerationAuditRepositoryInterface::class,
    static fn(Container $c) => new SqlForumModerationAuditRepository($c->make(Connection::class)));
$container->singleton(ForumUserWarningRepositoryInterface::class,
    static fn(Container $c) => new SqlForumUserWarningRepository($c->make(Connection::class)));

// Forum admin use cases — one bind() per use case
$container->bind(ReportForumTarget::class, static fn(Container $c) => new ReportForumTarget(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(AdminApplicationDismissalRepositoryInterface::class),
));
$container->bind(ListForumReportsForAdmin::class, static fn(Container $c) => new ListForumReportsForAdmin(
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumRepositoryInterface::class),
));
$container->bind(GetForumReportDetail::class, static fn(Container $c) => new GetForumReportDetail(
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumRepositoryInterface::class),
));
$container->bind(ResolveForumReportByDelete::class, static fn(Container $c) => new ResolveForumReportByDelete(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(ResolveForumReportByLock::class, static fn(Container $c) => new ResolveForumReportByLock(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(ResolveForumReportByWarn::class, static fn(Container $c) => new ResolveForumReportByWarn(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumUserWarningRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(ResolveForumReportByEdit::class, static fn(Container $c) => new ResolveForumReportByEdit(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumReportRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(DismissForumReport::class, static fn(Container $c) => new DismissForumReport(
    $c->make(ForumReportRepositoryInterface::class),
));
$container->bind(PinForumTopic::class, static fn(Container $c) => new PinForumTopic(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(UnpinForumTopic::class, static fn(Container $c) => new UnpinForumTopic(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(LockForumTopic::class, static fn(Container $c) => new LockForumTopic(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(UnlockForumTopic::class, static fn(Container $c) => new UnlockForumTopic(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(DeleteForumTopicAsAdmin::class, static fn(Container $c) => new DeleteForumTopicAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(DeleteForumPostAsAdmin::class, static fn(Container $c) => new DeleteForumPostAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(EditForumPostAsAdmin::class, static fn(Container $c) => new EditForumPostAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(WarnForumUser::class, static fn(Container $c) => new WarnForumUser(
    $c->make(ForumUserWarningRepositoryInterface::class),
));
$container->bind(CreateForumCategoryAsAdmin::class, static fn(Container $c) => new CreateForumCategoryAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(UpdateForumCategoryAsAdmin::class, static fn(Container $c) => new UpdateForumCategoryAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(DeleteForumCategoryAsAdmin::class, static fn(Container $c) => new DeleteForumCategoryAsAdmin(
    $c->make(ForumRepositoryInterface::class),
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(ListForumModerationAuditForAdmin::class, static fn(Container $c) => new ListForumModerationAuditForAdmin(
    $c->make(ForumModerationAuditRepositoryInterface::class),
));
$container->bind(ListForumTopicsForAdmin::class, static fn(Container $c) => new ListForumTopicsForAdmin(
    $c->make(ForumRepositoryInterface::class),
));
$container->bind(ListForumPostsForAdmin::class, static fn(Container $c) => new ListForumPostsForAdmin(
    $c->make(ForumRepositoryInterface::class),
));
```

- [ ] **Step 2: Update existing `ForumController` binding** to pass `ReportForumTarget`:

```php
$container->bind(ForumController::class, static fn(Container $c) => new ForumController(
    $c->make(ListForumCategories::class),
    $c->make(GetForumCategory::class),
    $c->make(GetForumThread::class),
    $c->make(CreateForumTopic::class),
    $c->make(CreateForumPost::class),
    $c->make(LikeForumPost::class),
    $c->make(IncrementTopicView::class),
    $c->make(ReportForumTarget::class),
));
```

- [ ] **Step 3: Update existing `BackstageController` binding** — append all the new use-case args at the end of the ctor arg list. Order must match the controller's ctor signature (which was updated in Task 21). Example tail:

```php
$container->bind(BackstageController::class, static fn(Container $c) => new BackstageController(
    /* …existing deps… */,
    $c->make(ListForumReportsForAdmin::class),
    $c->make(GetForumReportDetail::class),
    $c->make(ResolveForumReportByDelete::class),
    $c->make(ResolveForumReportByLock::class),
    $c->make(ResolveForumReportByWarn::class),
    $c->make(ResolveForumReportByEdit::class),
    $c->make(DismissForumReport::class),
    $c->make(ListForumTopicsForAdmin::class),
    $c->make(PinForumTopic::class),
    $c->make(UnpinForumTopic::class),
    $c->make(LockForumTopic::class),
    $c->make(UnlockForumTopic::class),
    $c->make(DeleteForumTopicAsAdmin::class),
    $c->make(ListForumPostsForAdmin::class),
    $c->make(EditForumPostAsAdmin::class),
    $c->make(DeleteForumPostAsAdmin::class),
    $c->make(WarnForumUser::class),
    $c->make(CreateForumCategoryAsAdmin::class),
    $c->make(UpdateForumCategoryAsAdmin::class),
    $c->make(DeleteForumCategoryAsAdmin::class),
    $c->make(ListForumModerationAuditForAdmin::class),
    // existing ListPendingApplicationsForAdmin binding still consumed here
));
```

Also update `ListPendingApplicationsForAdmin` binding to add new deps (`ForumReportRepositoryInterface` + `ForumRepositoryInterface`).

- [ ] **Step 4: Mirror all bindings in `tests/Support/KernelHarness.php`** with InMemory fakes instead of Sql repos. Pattern for each new repo:

```php
$this->bind(ForumReportRepositoryInterface::class, new InMemoryForumReportRepository());
$this->bind(ForumModerationAuditRepositoryInterface::class, new InMemoryForumModerationAuditRepository());
$this->bind(ForumUserWarningRepositoryInterface::class, new InMemoryForumUserWarningRepository());
```

Repeat every use-case binding from Step 1 verbatim in harness (same signatures, same order).

- [ ] **Step 5: Live smoke** — start `php -S localhost:8001 -t public` with `APP_DEBUG=true`, then:

```bash
curl -s -X POST http://daems-platform.local/api/v1/forum/reports \
     -H "Cookie: daems_session=<valid>" \
     -H "Content-Type: application/json" \
     -d '{"target_type":"post","target_id":"<seeded>","reason_category":"spam"}' \
     | rtk jq
```

Expected: `201 {"data":{"ok":true}}` (or `404` if no seeded post — that's fine, verifies routing + DI). 500 = bootstrap binding missing.

```bash
curl -s http://daems-platform.local/api/v1/backstage/forum/reports \
     -H "Cookie: daems_session=<admin>" | rtk jq
```

Expected: `200 {"data":[]}` empty array (no reports yet) — verifies full admin pipeline wires.

- [ ] **Step 6: Run `composer test:all`** — all green (E2E proves KernelHarness wiring, Integration proves SQL).

- [ ] **Step 7: Grep check — verify every new class name appears in BOTH files**:

```bash
for cls in ForumReportRepositoryInterface ForumModerationAuditRepositoryInterface ForumUserWarningRepositoryInterface ReportForumTarget ListForumReportsForAdmin GetForumReportDetail ResolveForumReportByDelete ResolveForumReportByLock ResolveForumReportByWarn ResolveForumReportByEdit DismissForumReport PinForumTopic UnpinForumTopic LockForumTopic UnlockForumTopic DeleteForumTopicAsAdmin DeleteForumPostAsAdmin EditForumPostAsAdmin WarnForumUser CreateForumCategoryAsAdmin UpdateForumCategoryAsAdmin DeleteForumCategoryAsAdmin ListForumModerationAuditForAdmin ListForumTopicsForAdmin ListForumPostsForAdmin; do
    b=$(grep -c "\\b$cls\\b" bootstrap/app.php)
    k=$(grep -c "\\b$cls\\b" tests/Support/KernelHarness.php)
    echo "$cls: bootstrap=$b harness=$k"
done
```

Every line must show both counts ≥ 1. If any is 0 → binding missing; add before committing.

- [ ] **Step 8: Commit**

```
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire(forum): bind 22 forum admin use cases + 3 repos in BOTH containers"
```

---

### Task 24: Integration tests — 4 classes

**Wave:** W11 (parallel with 25, 26). **Files (all create):**
- `tests/Integration/Application/ForumReportLifecycleIntegrationTest.php`
- `tests/Integration/Application/ForumCategoryCrudIntegrationTest.php`
- `tests/Integration/Application/ForumLockedTopicRejectsPostsIntegrationTest.php`
- `tests/Integration/Application/ForumReportDismissalToastIntegrationTest.php`

All extend `MigrationTestCase`. Use real SQL repos resolved from a built container (reuse `IntegrationTestCase` pattern from existing `tests/Integration/Application/*`).

- [ ] **Step 1: `ForumReportLifecycleIntegrationTest`** — end-to-end: user reports post → admin resolves via delete → target gone + reports status=resolved + audit present.

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Integration\Application;

use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDeleteInput;
use Daems\Application\Forum\ReportForumTarget\ReportForumTarget;
use Daems\Application\Forum\ReportForumTarget\ReportForumTargetInput;
use Daems\Domain\Forum\ForumReport;
use Daems\Tests\Integration\MigrationTestCase;

final class ForumReportLifecycleIntegrationTest extends MigrationTestCase
{
    public function test_report_then_admin_resolve_delete(): void
    {
        $this->runMigrationsUpTo(49);
        $this->seedTenant('t-1', 'daems');

        // Seed category + topic + post
        $this->pdo->exec("INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES ('c-1','t-1','general','General','','',0)");
        $this->pdo->exec("INSERT INTO forum_topics (id, tenant_id, category_id, slug, title, author_name, avatar_initials, avatar_color, pinned, reply_count, view_count, last_activity_at, last_activity_by, created_at, locked) VALUES ('top-1','t-1','c-1','slug','Title','A','A',NULL,0,0,0,'2026-04-20 10:00:00','','2026-04-20 10:00:00',0)");
        $this->pdo->exec("INSERT INTO forum_posts (id, tenant_id, topic_id, user_id, author_name, avatar_initials, avatar_color, role, role_class, joined_text, content, likes, created_at, edited_at, sort_order) VALUES ('p-1','t-1','top-1','u-a','A','A',NULL,'','','','offending text',0,'2026-04-20 10:00:00',NULL,0)");

        $forum = $this->container->make(\Daems\Domain\Forum\ForumRepositoryInterface::class);
        $reports = $this->container->make(\Daems\Domain\Forum\ForumReportRepositoryInterface::class);
        $dismissals = $this->container->make(\Daems\Domain\AdminInbox\AdminApplicationDismissalRepositoryInterface::class);

        $user = $this->makeActingUser('u-reporter', 't-1', role: 'registered');
        (new ReportForumTarget($forum, $reports, $dismissals))
            ->execute(new ReportForumTargetInput($user, 'post', 'p-1', 'spam', 'scam link'));

        $admin = $this->makeActingUser('u-admin', 't-1', role: 'admin');
        (new ResolveForumReportByDelete(
            $forum, $reports,
            $this->container->make(\Daems\Domain\Forum\ForumModerationAuditRepositoryInterface::class),
        ))->execute(new ResolveForumReportByDeleteInput($admin, 'post', 'p-1', 'Spam chain'));

        // Target gone
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM forum_posts WHERE id='p-1'")->fetchColumn();
        self::assertSame(0, $count);

        // All reports resolved
        $rs = $reports->listRawForTargetForTenant('post', 'p-1', \Daems\Domain\Tenant\TenantId::fromString('t-1'));
        self::assertNotEmpty($rs);
        foreach ($rs as $r) self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());

        // Audit row present
        $auditCount = (int) $this->pdo->query("SELECT COUNT(*) FROM forum_moderation_audit WHERE target_id='p-1' AND action='deleted'")->fetchColumn();
        self::assertSame(1, $auditCount);
    }
}
```

> `seedTenant`, `makeActingUser` are helpers expected on `MigrationTestCase` — use the helpers already present (check by reading `MigrationTestCase`) or add them there if missing, mirroring patterns from existing Integration tests.

- [ ] **Step 2: `ForumCategoryCrudIntegrationTest`** — create → list → update → delete blocked (has topics) → after moving topics → delete succeeds. Use `CreateForumCategoryAsAdmin`, `UpdateForumCategoryAsAdmin`, `DeleteForumCategoryAsAdmin` from container. Assert DB state + audit rows.

- [ ] **Step 3: `ForumLockedTopicRejectsPostsIntegrationTest`** — seed topic, `LockForumTopic`, then try to `CreateForumPost` → expect `TopicLockedException`.

- [ ] **Step 4: `ForumReportDismissalToastIntegrationTest`** — seed report → LPAFA returns item → dismiss via `DismissApplication` → LPAFA no longer returns item → seed a NEW report for same target → LPAFA returns item again (re-surface after new report).

- [ ] **Step 5: Run Integration suite — PASS**

```
vendor/bin/phpunit --testsuite Integration
```

- [ ] **Step 6: Commit**

```
git add tests/Integration/Application/ForumReportLifecycleIntegrationTest.php tests/Integration/Application/ForumCategoryCrudIntegrationTest.php tests/Integration/Application/ForumLockedTopicRejectsPostsIntegrationTest.php tests/Integration/Application/ForumReportDismissalToastIntegrationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(forum): 4 integration classes — report lifecycle, category CRUD, locked rejection, dismissal re-surface"
```

---

### Task 25: Isolation test — `ForumAdminTenantIsolationTest`

**Wave:** W11. **Files:**
- Create: `tests/Isolation/ForumAdminTenantIsolationTest.php`

**Contract:** Every admin use case rejects cross-tenant access. Seed a report + topic + post + category in tenant A; make an admin of tenant B attempt all 20+ operations; each must throw `NotFoundException` or return empty listing.

- [ ] **Step 1: Write test** — extend `IsolationTestCase`, iterate over every admin use case:

```php
public function test_tenant_b_admin_cannot_touch_tenant_a_data(): void
{
    // Seed tenant A
    $tA = TenantId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $tB = TenantId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $this->seedTenantRow($tA, 'daems');
    $this->seedTenantRow($tB, 'sahegroup');

    $this->pdo->exec("INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES ('c-a','{$tA->value()}','cat','Cat','','',0)");
    $this->pdo->exec("INSERT INTO forum_topics (...) VALUES ('top-a','{$tA->value()}','c-a', …, 0)");
    $this->pdo->exec("INSERT INTO forum_posts (...) VALUES ('p-a','{$tA->value()}','top-a', …)");
    $this->pdo->exec("INSERT INTO forum_reports (...) VALUES ('r-a','{$tA->value()}','post','p-a', …, 'open', …)");

    $bAdmin = $this->makeActingUser('admin-b', $tB->value(), role: 'admin');

    // GetForumReportDetail — tenant B admin looking for tenant A's report
    $this->expectException(NotFoundException::class);
    $this->container->make(GetForumReportDetail::class)
        ->execute(new GetForumReportDetailInput($bAdmin, 'post', 'p-a'));
}

public function test_listing_endpoints_scoped(): void
{
    // Seed identical data in A; tenant B admin's list returns empty.
    /* … */
    $out = $this->container->make(ListForumReportsForAdmin::class)
        ->execute(new ListForumReportsForAdminInput($bAdmin, [], 50));
    self::assertSame([], $out->items);
}

// Repeat cross-tenant for: ResolveForumReportByDelete, Lock, Warn, Edit, Dismiss,
// PinForumTopic, UnpinForumTopic, LockForumTopic, UnlockForumTopic,
// DeleteForumTopicAsAdmin, DeleteForumPostAsAdmin, EditForumPostAsAdmin,
// WarnForumUser, CreateForumCategoryAsAdmin (slug conflict ok — just ensures listForAllCategoriesForTenant('b') excludes A),
// UpdateForumCategoryAsAdmin, DeleteForumCategoryAsAdmin, ListForumModerationAuditForAdmin.
```

Write one `test_*` method per use case for clarity; each sets up A data and tenant B admin, then asserts either `NotFoundException` or empty list.

- [ ] **Step 2: Run isolation suite — PASS**

```
vendor/bin/phpunit --testsuite Isolation --filter ForumAdminTenantIsolationTest
```

- [ ] **Step 3: Commit**

```
git add tests/Isolation/ForumAdminTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(isolation): ForumAdminTenantIsolationTest — 20+ cross-tenant checks"
```

---

### Task 26: E2E tests — `ForumAdminEndpointsTest` + `AdminInboxIncludesForumReportsTest`

**Wave:** W11. **Files:**
- Create: `tests/E2E/Backstage/ForumAdminEndpointsTest.php`
- Create: `tests/E2E/Backstage/AdminInboxIncludesForumReportsTest.php`

Extend `KernelHarness` pattern from existing `tests/E2E/Backstage/*Test.php`.

- [ ] **Step 1: `ForumAdminEndpointsTest`** — one test per route happy + error:

```php
public function test_POST_forum_reports_201_on_valid(): void
{
    $this->seedPost('p-1');
    $resp = $this->request('POST', '/api/v1/forum/reports', [
        'Cookie' => $this->sessionCookie('u-reporter', 'daems'),
    ], ['target_type' => 'post', 'target_id' => 'p-1', 'reason_category' => 'spam']);
    self::assertSame(201, $resp['status']);
}

public function test_POST_forum_reports_404_on_missing_target(): void { /* … */ }

public function test_GET_backstage_forum_reports_lists_for_admin(): void { /* … */ }
public function test_POST_backstage_forum_reports_resolve_delete(): void { /* … */ }
public function test_POST_backstage_forum_reports_resolve_lock_topic(): void { /* … */ }
public function test_POST_backstage_forum_topics_pin(): void { /* … */ }
public function test_POST_backstage_forum_topics_lock_then_create_post_409(): void { /* … */ }
public function test_POST_backstage_forum_posts_edit(): void { /* … */ }
public function test_POST_backstage_forum_categories_delete_blocked_with_topics_409(): void { /* … */ }
public function test_GET_backstage_forum_audit_lists_recent(): void { /* … */ }
// Plus: non-admin gets 403 for admin routes; unauthenticated gets 401.
```

~15 test methods covering all routes.

- [ ] **Step 2: `AdminInboxIncludesForumReportsTest`** — seed pending report + member application + project proposal; GET `/api/v1/backstage/applications/pending-count`; assert response `items` contains all three types:

```php
public function test_pending_count_includes_forum_report(): void
{
    $this->seedForumReport('post', 'p-1');
    $resp = $this->request('GET', '/api/v1/backstage/applications/pending-count', [
        'Cookie' => $this->sessionCookie('admin', 'daems'),
    ]);
    self::assertSame(200, $resp['status']);
    $types = array_map(fn($i) => $i['type'], $resp['body']['data']['items']);
    self::assertContains('forum_report', $types);
    $forumItem = array_values(array_filter($resp['body']['data']['items'], fn($i) => $i['type'] === 'forum_report'))[0];
    self::assertStringStartsWith('post:', $forumItem['id']);
}

public function test_dismissed_forum_report_not_in_items(): void { /* … */ }
```

- [ ] **Step 3: Run E2E suite — PASS**

```
vendor/bin/phpunit --testsuite E2E
```

- [ ] **Step 4: Commit**

```
git add tests/E2E/Backstage/ForumAdminEndpointsTest.php tests/E2E/Backstage/AdminInboxIncludesForumReportsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(forum): E2E endpoint coverage + admin inbox includes forum_report"
```

---

### Task 27: Frontend admin page + proxies (daem-society)

**Wave:** W12 (parallel with 28, 29). **Repo:** `C:\laragon\www\sites\daem-society`. **Files (create):**
- `public/pages/backstage/forum/index.php`
- `public/pages/backstage/forum/forum-admin.js`
- `public/pages/backstage/forum/forum-admin.css`
- `public/pages/backstage/forum/edit-post-modal.js`
- `public/pages/backstage/forum/category-modal.js`
- `public/api/backstage/forum.php`

- [ ] **Step 1: Admin page shell** — `public/pages/backstage/forum/index.php`:

Layout mirrors `public/pages/backstage/projects/index.php`. Four tabs (`reports | topics | categories | audit`) driven by `?tab=...` query param, defaulting to `reports` if `?.DAEMS_FORUM_OPEN_REPORTS > 0` else `topics`.

Template outline:
```php
<?php require __DIR__ . '/../layout-open.php'; ?>
<div class="forum-admin" data-active-tab="<?= $activeTab ?>">
    <nav class="forum-admin__tabs">
        <a href="?tab=reports" data-tab="reports">Raportit <span class="badge"><?= $reportCount ?></span></a>
        <a href="?tab=topics"   data-tab="topics">Aiheet</a>
        <a href="?tab=categories" data-tab="categories">Kategoriat</a>
        <a href="?tab=audit" data-tab="audit">Loki</a>
    </nav>
    <section class="forum-admin__pane" data-pane="reports"><?php /* cards rendered by JS fetch */ ?></section>
    <section class="forum-admin__pane" data-pane="topics"><?php /* table rendered by JS */ ?></section>
    <section class="forum-admin__pane" data-pane="categories"><?php /* table + "new" button */ ?></section>
    <section class="forum-admin__pane" data-pane="audit"><?php /* list rendered by JS */ ?></section>
</div>
<script src="/pages/backstage/forum/forum-admin.js" defer></script>
<link rel="stylesheet" href="/pages/backstage/forum/forum-admin.css">
<?php require __DIR__ . '/../layout-close.php'; ?>
```

- [ ] **Step 2: JS** — `forum-admin.js`:

```js
// Fetch + render driver for 4 tabs.
// Endpoints hit via /api/backstage/forum.php?op=*

async function api(op, params = {}, method = 'GET') {
    const res = await fetch('/api/backstage/forum.php?op=' + op, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: method !== 'GET' ? JSON.stringify(params) : undefined,
    });
    return res.json();
}

async function loadReports(filters = {}) { /* GET reports_list, render cards */ }
async function loadTopics(filters = {}) { /* GET topics_list, render table with pin/lock toggles */ }
async function loadCategories() { /* GET categories_list, render table */ }
async function loadAudit() { /* GET audit_list, render read-only table */ }

async function resolveReport(compoundId, action, note, newContent) {
    return api('report_resolve', { compound_id: compoundId, action, note, new_content: newContent }, 'POST');
}
async function dismissReport(compoundId, note) {
    return api('report_dismiss', { compound_id: compoundId, note }, 'POST');
}
// Analogous for pin/unpin, lock/unlock, delete-topic/post, warn-user, category CRUD.
```

Render reports as cards: each card shows target excerpt, reason counts as badges, earliest/latest timestamps, buttons `Delete | Lock | Warn | Edit | Dismiss`. Disable `Lock` when target_type='post', disable `Edit` when target_type='topic'. Actions open inline confirm with optional note textarea.

`edit-post-modal.js`: modal form for editing a post's content with current content prefilled; calls `resolveReport(compoundId, 'edit', note, newContent)` or `post_edit` (direct edit variant used from Topics tab).

`category-modal.js`: modal for create/edit category (slug, name, icon, description, sort_order). Delete disabled when `topic_count > 0`.

- [ ] **Step 3: CSS** — `forum-admin.css`:

```css
.forum-admin { display: flex; flex-direction: column; gap: 16px; }
.forum-admin__tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--border); }
.forum-admin__tabs a { padding: 8px 16px; text-decoration: none; }
.forum-admin__tabs a.active { border-bottom: 2px solid var(--accent); font-weight: 600; }
.forum-admin__pane { display: none; }
.forum-admin__pane.active { display: block; }
.report-card { border: 1px solid var(--border); padding: 12px; margin-bottom: 8px; }
.report-card__actions { display: flex; gap: 8px; margin-top: 8px; }
/* etc. */
```

- [ ] **Step 4: Proxy** — `public/api/backstage/forum.php`:

Single relay, JSON-in JSON-out, auth via session cookie → Authorization header to platform API.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_api-client.php';

$client = new ApiClient();
$op = $_GET['op'] ?? '';
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

$dispatch = [
    'reports_list'      => ['GET', '/backstage/forum/reports'],
    'report_detail'     => ['GET', '/backstage/forum/reports/{id}'],
    'report_resolve'    => ['POST','/backstage/forum/reports/{id}/resolve'],
    'report_dismiss'    => ['POST','/backstage/forum/reports/{id}/dismiss'],
    'topics_list'       => ['GET', '/backstage/forum/topics'],
    'topic_pin'         => ['POST','/backstage/forum/topics/{id}/pin'],
    'topic_unpin'       => ['POST','/backstage/forum/topics/{id}/unpin'],
    'topic_lock'        => ['POST','/backstage/forum/topics/{id}/lock'],
    'topic_unlock'      => ['POST','/backstage/forum/topics/{id}/unlock'],
    'topic_delete'      => ['POST','/backstage/forum/topics/{id}/delete'],
    'posts_list'        => ['GET', '/backstage/forum/posts'],
    'post_edit'         => ['POST','/backstage/forum/posts/{id}/edit'],
    'post_delete'       => ['POST','/backstage/forum/posts/{id}/delete'],
    'user_warn'         => ['POST','/backstage/forum/users/{id}/warn'],
    'categories_list'   => ['GET', '/backstage/forum/categories'],
    'category_create'   => ['POST','/backstage/forum/categories'],
    'category_update'   => ['POST','/backstage/forum/categories/{id}'],
    'category_delete'   => ['POST','/backstage/forum/categories/{id}/delete'],
    'audit_list'        => ['GET', '/backstage/forum/audit'],
];
if (!isset($dispatch[$op])) { http_response_code(400); echo json_encode(['error' => 'unknown_op']); exit; }
[$method, $path] = $dispatch[$op];

// Substitute {id} from compound_id or id
$id = $body['compound_id'] ?? $body['id'] ?? ($_GET['id'] ?? '');
$path = str_replace('{id}', (string) $id, $path);

// Build query/body
$resp = $method === 'GET'
    ? $client->get($path, $body)
    : $client->post($path, $body);

http_response_code($resp['status']);
echo $resp['body'];
```

- [ ] **Step 5: Sidebar entry** — `public/pages/backstage/layout.php`: add under the Projects nav entry:

```php
<a href="/backstage/forum" class="sidebar__link <?= $activePage === 'forum' ? 'active' : '' ?>">
    <i class="bi bi-chat-left-dots"></i> Foorumi
</a>
```

Grep first — if entry already exists, skip.

- [ ] **Step 6: Smoke-test in browser**

1. Start Laragon services. Navigate to `http://daem-society.local/backstage/forum`.
2. Verify sidebar shows "Foorumi".
3. Each tab switch loads data without JS errors (open devtools).
4. Try creating a category → success banner → row appears.
5. Try deleting a category with topics → inline error "Siirrä topicit ensin".

- [ ] **Step 7: Commit** (in daem-society repo)

```
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/forum public/api/backstage/forum.php public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum-admin): backstage admin page with 4 tabs + proxy + sidebar entry"
```

---

### Task 28: Public forum — report dialog + locked banner + moderator-edited caption

**Wave:** W12 (parallel with 27, 29). **Repo:** `C:\laragon\www\sites\daem-society`. **Files (create + modify):**
- Create: `public/pages/forum/_report-dialog.js`, `_report-dialog.css`, `public/api/forum/report.php`.
- Modify: `public/pages/forum/_post.php` (or whichever partial renders a single post) — append Report link + "edited" caption.
- Modify: `public/pages/forum/_thread-header.php` (or equivalent) — append "Raportoi aihe" link.
- Modify: `public/pages/forum/thread.php` — render locked banner + disable reply form when locked.

- [ ] **Step 1: `_report-dialog.js`** — reusable component:

```js
(function(){
  const REASONS = [
    ['spam', 'Roskaposti'],
    ['harassment', 'Häirintä'],
    ['off_topic', 'Ohi aiheen'],
    ['hate_speech', 'Vihapuhe'],
    ['misinformation', 'Virheellinen tieto'],
    ['other', 'Muu'],
  ];

  window.daemsReport = async function(targetType, targetId) {
    const html = `
      <div class="report-dialog" role="dialog">
        <h3>Raportoi ${targetType === 'post' ? 'viesti' : 'aihe'}</h3>
        <label>Syy
          <select name="reason_category">${REASONS.map(([v,l]) => `<option value="${v}">${l}</option>`).join('')}</select>
        </label>
        <label>Selitys (valinnainen)
          <textarea name="reason_detail" maxlength="500" rows="3"></textarea>
        </label>
        <div class="report-dialog__actions">
          <button type="button" data-cancel>Peruuta</button>
          <button type="submit" data-submit>Lähetä</button>
        </div>
        <div class="report-dialog__status"></div>
      </div>`;
    const el = document.createElement('div');
    el.className = 'report-dialog-backdrop';
    el.innerHTML = html;
    document.body.appendChild(el);

    el.querySelector('[data-cancel]').onclick = () => el.remove();
    el.querySelector('[data-submit]').onclick = async () => {
      const reason = el.querySelector('[name=reason_category]').value;
      const detail = el.querySelector('[name=reason_detail]').value.trim();
      const res = await fetch('/api/forum/report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ target_type: targetType, target_id: targetId, reason_category: reason, reason_detail: detail || null }),
      });
      const status = el.querySelector('.report-dialog__status');
      if (res.ok) {
        status.textContent = 'Kiitos raportista. Moderaattori tarkistaa.';
        setTimeout(() => el.remove(), 1500);
      } else {
        const j = await res.json().catch(() => ({}));
        status.textContent = 'Virhe: ' + (j.error || res.status);
      }
    };
  };
})();
```

CSS: simple modal backdrop + centered card.

- [ ] **Step 2: Proxy** — `public/api/forum/report.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_api-client.php';
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$client = new ApiClient();
$resp = $client->post('/forum/reports', $body);
http_response_code($resp['status']);
echo $resp['body'];
```

- [ ] **Step 3: Add report link to post partial**:

```php
<!-- existing post render -->
<button type="button"
        class="post__report"
        onclick="daemsReport('post', <?= htmlspecialchars(json_encode($post['id'])) ?>)">
    ⚠ Raportoi
</button>
<?php if (!empty($post['edited_at'])): ?>
<small class="post__edited">(moderaattorin muokkaama <?= date('Y-m-d', strtotime($post['edited_at'])) ?>)</small>
<?php endif; ?>
```

Ensure thread API output carries `edited_at` (backend already does after Task 2's hydrate update).

- [ ] **Step 4: Add report link to topic header**:

```php
<button type="button"
        class="topic__report"
        onclick="daemsReport('topic', <?= htmlspecialchars(json_encode($topic['id'])) ?>)">
    ⚠ Raportoi aihe
</button>
```

- [ ] **Step 5: Locked banner + reply disable** — in `thread.php`:

```php
<?php if (!empty($topic['locked'])): ?>
<div class="thread__locked-banner">🔒 Keskustelu on lukittu — uusia viestejä ei voi lähettää.</div>
<script>document.querySelector('#reply-form')?.setAttribute('inert', '');</script>
<?php endif; ?>
```

> Backend `GetForumThread` output must include `locked` on the topic payload. Verify; extend if missing (small tweak to use case output shape).

- [ ] **Step 6: Include report-dialog on all forum pages** — add `<script src="/pages/forum/_report-dialog.js" defer></script>` and `<link rel="stylesheet" href="/pages/forum/_report-dialog.css">` to `thread.php` + `category.php`.

- [ ] **Step 7: Smoke-test in browser**

1. Authenticated user on `/forum/general/slug-1`, click "Raportoi" → dialog appears → submit spam → success.
2. Admin locks topic via backstage → reload thread → banner visible + reply form inert.
3. Admin edits a post via backstage → reload thread → "muokattu" caption appears under the edited post.

- [ ] **Step 8: Commit**

```
cd C:/laragon/www/sites/daem-society
git add public/pages/forum public/api/forum
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): public report dialog + locked banner + moderator-edited caption"
```

---

### Task 29: Toast routing for `forum_report` + sidebar verification

**Wave:** W12 (parallel with 27, 28). **Repo:** daem-society.

- [ ] **Step 1: Extend `public/pages/backstage/toasts.js`**:

```js
// Inside the click-handler where item.type is dispatched:
} else if (item.type === 'forum_report') {
    window.location.href = '/backstage/forum?tab=reports&highlight=' + encodeURIComponent(item.id);
} else {
    window.location.href = '/backstage/applications?highlight=' + encodeURIComponent(item.id);
}
```

- [ ] **Step 2: Verify sidebar Forum entry** — open `public/pages/backstage/layout.php`, grep for `/backstage/forum`. If present (from Task 27), skip; else add as shown in Task 27 Step 5.

- [ ] **Step 3: Highlight target in reports tab** — in `forum-admin.js`, after `loadReports`, check `?highlight=` and scroll matching card into view with a brief outline:

```js
const h = new URLSearchParams(location.search).get('highlight');
if (h) {
    const card = document.querySelector(`.report-card[data-compound-id="${CSS.escape(h)}"]`);
    if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); card.classList.add('is-highlighted'); }
}
```

- [ ] **Step 4: Smoke-test** — seed a pending forum report, open any backstage page, wait for toast, click it → lands on `/backstage/forum?tab=reports&highlight=post:<id>` with the card scrolled + highlighted.

- [ ] **Step 5: Commit**

```
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/toasts.js public/pages/backstage/forum/forum-admin.js public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(forum): toast routing + highlight for forum_report"
```

---

### Task 30: Final verification checklist

**Wave:** W13 (solo). **Files:**
- Modify: `CLAUDE.md` (platform repo) — mark Forum moderation done; add follow-ups for category reporting / warning thresholds / rate limiting.

- [ ] **Step 1: Run full platform test suite**

```
cd C:/laragon/www/daems-platform
composer test:all
composer analyse
```
Expected: all green, 0 PHPStan errors.

- [ ] **Step 2: Verify DI wiring script** — re-run the grep loop from Task 23 Step 7. Every class must have ≥ 1 occurrence in both files.

- [ ] **Step 3: Manual UAT per spec §6.5** — 12 checks. Tick each:

1. [ ] Member reports a post → dialog submits → success banner.
2. [ ] Same member re-reports with different reason → existing entry updated.
3. [ ] Different member reports same post → aggregated count = 2.
4. [ ] Any backstage page loads → toast shows "1 forum report" with excerpt.
5. [ ] Click toast → lands on `?tab=reports&highlight=...`, card scrolled into view.
6. [ ] Open detail → both raw reports + current content visible.
7. [ ] Click `Edit` with note → post content replaced, audit entry present, public view shows moderator-edited caption.
8. [ ] Admin locks a topic → banner appears publicly + reply form inert.
9. [ ] API POST to locked topic → 409 `topic_locked`.
10. [ ] Admin dismisses a report → queue count drops, no audit row written.
11. [ ] Admin tries to delete category with topics → inline error "Siirrä topicit ensin".
12. [ ] Audit tab shows last actions in correct order with performer names.

- [ ] **Step 4: Update CLAUDE.md** — amend the roadmap section:

```markdown
Active roadmap (`docs/planning/roadmap.md`, section 1 Admin Panel):
1. ✅ Dashboard overview
2. ✅ Applications + Members pages
3. ✅ Approve-flow + global toast notifications
4. ✅ Events admin
5. ✅ Projects admin
6. ✅ Forum moderation (`/backstage/forum`)
7. ⏭️ Settings (`/backstage/settings`)

Deferred (track as future work):
- Category reporting (`forum_reports.target_type='category'`).
- Automatic ban thresholds derived from `forum_user_warnings` count.
- Rate limiting on `POST /forum/reports`.
- Insights admin featured-UI (carried from projects-admin).
```

- [ ] **Step 5: Commit final**

```
git add CLAUDE.md
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Docs: CLAUDE.md — forum moderation done; track deferred forum work"
```

- [ ] **Step 6: Report final commit SHAs to user**

Collect the SHAs of every commit produced by this plan (expect ~28–30). Report list with one-line subjects. **Do NOT push.** Wait for "pushaa" instruction.

---

## Self-Review Checklist

- [x] Every spec section has a task (migrations → Task 1; domain/repos → 2–5; user-side report → 6; admin list → 7; resolve actions → 8–12; direct ops → 13–16; categories → 17; audit → 18; locked guard → 19; toast → 20; controllers → 21; routes → 22; DI → 23; integration → 24; isolation → 25; E2E → 26; frontend → 27–29; final → 30).
- [x] No placeholders — every code step has full code or a precise reference to the exact file/method pattern already shown earlier in the plan.
- [x] Type consistency — method names (`findPostByIdForTenant`, `setTopicLockedForTenant`, `listAggregatedForTenant`) match across tasks 2, 3, 8, 11, 21, 23.
- [x] Wave dependencies respect the cap of 3 parallel agents and block on shared-file tasks (2, 21, 22, 23).

