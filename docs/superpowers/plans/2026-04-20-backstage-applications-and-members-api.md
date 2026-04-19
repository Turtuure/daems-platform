# Backstage Applications & Members API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the platform-side REST endpoints that `sites/daem-society/public/pages/backstage/{applications,members}/index.php` already call — GSA/admin can list pending applications, approve/reject them, list/filter/paginate/export tenant members, change member status (GSA-only), and read per-member audit logs.

**Architecture:** New `BackstageController` + 5 focused use cases under `src/Application/Backstage/`. Read-model value objects in `src/Domain/Backstage/` (no new aggregates — existing `User`, `MemberApplication`, `SupporterApplication` are mutated through their existing repositories). Two new migrations (034 decision metadata, 035 member_status_audit). All endpoints behind `TenantContextMiddleware` + `AuthMiddleware`; use cases enforce authorization via `ActingUser::isAdminIn($tenantId)` or `::isPlatformAdmin`.

**Tech Stack:** PHP 8.1+ at PHPStan level 9, MySQL 8.x, PHPUnit 10, Clean Architecture.

**Spec:** `docs/superpowers/specs/2026-04-20-backstage-applications-and-members-api-design.md`

**PR target:** PR 4 (sequential after PR 1–3 of DS_UPGRADE).

**Prerequisite:** Migration 033 applied (`member_register_audit` exists with `application_id` FK); all PR 3 isolation tests green.

---

## File Structure

**Created — database migrations:**
- `database/migrations/034_add_decision_metadata_to_applications.sql`
- `database/migrations/035_create_member_status_audit.sql`

**Created — Domain read models:**
- `src/Domain/Backstage/PendingApplication.php`
- `src/Domain/Backstage/ApplicationDecision.php` (enum)
- `src/Domain/Backstage/MemberDirectoryEntry.php`
- `src/Domain/Backstage/MemberStatusAuditEntry.php`
- `src/Domain/Backstage/MemberDirectoryRepositoryInterface.php`

**Modified — existing Domain interfaces:**
- `src/Domain/Membership/MemberApplicationRepositoryInterface.php` — add 3 methods
- `src/Domain/Membership/SupporterApplicationRepositoryInterface.php` — add 3 methods

**Created — Application use cases:**
- `src/Application/Backstage/ListPendingApplications/{ListPendingApplications,ListPendingApplicationsInput,ListPendingApplicationsOutput}.php`
- `src/Application/Backstage/DecideApplication/{DecideApplication,DecideApplicationInput,DecideApplicationOutput}.php`
- `src/Application/Backstage/ListMembers/{ListMembers,ListMembersInput,ListMembersOutput}.php`
- `src/Application/Backstage/ChangeMemberStatus/{ChangeMemberStatus,ChangeMemberStatusInput,ChangeMemberStatusOutput}.php`
- `src/Application/Backstage/GetMemberAudit/{GetMemberAudit,GetMemberAuditInput,GetMemberAuditOutput}.php`

**Created — Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberDirectoryRepository.php`
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

**Modified — existing Infrastructure:**
- `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php` — implement new interface methods
- `src/Infrastructure/Adapter/Persistence/Sql/SqlSupporterApplicationRepository.php` — implement new interface methods
- `routes/api.php` — add 5 new routes

**Created — test fakes:**
- `tests/Support/Fake/InMemoryMemberDirectoryRepository.php`

**Modified — existing test fakes:**
- `tests/Support/Fake/InMemoryMemberApplicationRepository.php`
- `tests/Support/Fake/InMemorySupporterApplicationRepository.php`
- `tests/Support/KernelHarness.php` — wire new use cases + controller

**Created — tests:**
- `tests/Integration/Migration/Migration034Test.php`
- `tests/Integration/Migration/Migration035Test.php`
- `tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php`
- `tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php`
- `tests/Unit/Application/Backstage/ListPendingApplicationsTest.php`
- `tests/Unit/Application/Backstage/DecideApplicationTest.php`
- `tests/Unit/Application/Backstage/ListMembersTest.php`
- `tests/Unit/Application/Backstage/ChangeMemberStatusTest.php`
- `tests/Unit/Application/Backstage/GetMemberAuditTest.php`
- `tests/E2E/F011_BackstageApplicationsAccessTest.php`
- `tests/E2E/F012_BackstageDecideApplicationTest.php`
- `tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php`
- `tests/Isolation/BackstageTenantIsolationTest.php`

**Modified — docs:**
- `docs/api.md` — append backstage endpoints section
- `docs/database.md` — append migrations 034 & 035

---

## Canonical Patterns

### Route registration pattern

Routes pattern (follows existing convention in `routes/api.php`):

```php
$router->get('/api/v1/backstage/applications/pending', static function (Request $req) use ($container): Response {
    return $container->make(BackstageController::class)->pendingApplications($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

### Use case authorization pattern

```php
public function execute(SomeInput $input): SomeOutput
{
    $tenantId = $input->acting->activeTenant;

    if (!$input->acting->isAdminIn($tenantId)) {
        throw new ForbiddenException('not_tenant_admin');
    }

    // ... business logic
}
```

For GSA-only use cases, check `$input->acting->isPlatformAdmin` instead.

### Input DTO pattern

```php
final class SomeInput
{
    public function __construct(
        public readonly ActingUser $acting,
        // ... other params
    ) {}
}
```

### Controller method pattern

```php
public function someAction(Request $request): Response
{
    $acting = $request->requireActingUser();

    try {
        $output = $this->someUseCase->execute(new SomeInput($acting, ...));
    } catch (ForbiddenException $e) {
        return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
    } catch (NotFoundException $e) {
        return Response::json(['error' => 'not_found'], 404);
    } catch (ValidationException $e) {
        return Response::json(['error' => 'validation_failed', 'fields' => $e->fields()], 422);
    }

    return Response::json(['data' => $output->toArray()]);
}
```

### Commit message + identity

All commits use `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No Co-Authored-By, no auto-push.

---

## Task 1: Migration 034 — application decision metadata

**Files:**
- Create: `database/migrations/034_add_decision_metadata_to_applications.sql`
- Test: `tests/Integration/Migration/Migration034Test.php`

- [ ] **Step 1.1: Write the migration**

```sql
-- Migration 034: add decision metadata to member/supporter applications

ALTER TABLE member_applications
    ADD COLUMN decided_at    DATETIME NULL AFTER status,
    ADD COLUMN decided_by    CHAR(36) NULL AFTER decided_at,
    ADD COLUMN decision_note TEXT     NULL AFTER decided_by,
    ADD CONSTRAINT fk_member_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supporter_applications
    ADD COLUMN decided_at    DATETIME NULL AFTER status,
    ADD COLUMN decided_by    CHAR(36) NULL AFTER decided_at,
    ADD COLUMN decision_note TEXT     NULL AFTER decided_by,
    ADD CONSTRAINT fk_supporter_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;
```

- [ ] **Step 1.2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration034Test extends MigrationTestCase
{
    public function test_adds_decision_columns_to_member_applications(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $cols = $this->columnsOf('member_applications');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }

    public function test_adds_decision_columns_to_supporter_applications(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $cols = $this->columnsOf('supporter_applications');
        self::assertContains('decided_at', $cols);
        self::assertContains('decided_by', $cols);
        self::assertContains('decision_note', $cols);
    }

    public function test_decider_fk_constraint_created(): void
    {
        $this->runMigrationsUpTo(33);
        $this->runMigration('034_add_decision_metadata_to_applications.sql');

        $fks = $this->foreignKeysOf('member_applications');
        self::assertContains('fk_member_applications_decider', $fks);
    }
}
```

- [ ] **Step 1.3: Run test, apply, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration034Test.php
mysql -u root -psalasana daems_db < database/migrations/034_add_decision_metadata_to_applications.sql
git add database/migrations/034_add_decision_metadata_to_applications.sql tests/Integration/Migration/Migration034Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 034 — decision metadata for applications"
```

---

## Task 2: Migration 035 — member_status_audit table

**Files:**
- Create: `database/migrations/035_create_member_status_audit.sql`
- Test: `tests/Integration/Migration/Migration035Test.php`

- [ ] **Step 2.1: Write the migration**

```sql
-- Migration 035: create member_status_audit

CREATE TABLE member_status_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    previous_status VARCHAR(30)  NULL,
    new_status      VARCHAR(30)  NOT NULL,
    reason          TEXT         NOT NULL,
    performed_by    CHAR(36)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY member_status_audit_tenant_idx (tenant_id, created_at),
    KEY member_status_audit_user_idx (user_id, created_at),
    CONSTRAINT fk_member_status_audit_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_status_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_status_audit_performer
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2.2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration035Test extends MigrationTestCase
{
    public function test_creates_member_status_audit_table(): void
    {
        $this->runMigrationsUpTo(34);
        $this->runMigration('035_create_member_status_audit.sql');

        $cols = $this->columnsOf('member_status_audit');
        self::assertContains('id', $cols);
        self::assertContains('tenant_id', $cols);
        self::assertContains('user_id', $cols);
        self::assertContains('previous_status', $cols);
        self::assertContains('new_status', $cols);
        self::assertContains('reason', $cols);
        self::assertContains('performed_by', $cols);
        self::assertContains('created_at', $cols);
    }

    public function test_fk_constraints_present(): void
    {
        $this->runMigrationsUpTo(34);
        $this->runMigration('035_create_member_status_audit.sql');

        $fks = $this->foreignKeysOf('member_status_audit');
        self::assertContains('fk_member_status_audit_tenant', $fks);
        self::assertContains('fk_member_status_audit_user', $fks);
        self::assertContains('fk_member_status_audit_performer', $fks);
    }
}
```

- [ ] **Step 2.3: Run test, apply, commit**

```bash
vendor/bin/phpunit tests/Integration/Migration/Migration035Test.php
mysql -u root -psalasana daems_db < database/migrations/035_create_member_status_audit.sql
git add database/migrations/035_create_member_status_audit.sql tests/Integration/Migration/Migration035Test.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(db): migration 035 — member_status_audit table"
```

---

## Task 3: Backstage domain read models

**Files:**
- Create: `src/Domain/Backstage/PendingApplication.php`
- Create: `src/Domain/Backstage/ApplicationDecision.php`
- Create: `src/Domain/Backstage/MemberDirectoryEntry.php`
- Create: `src/Domain/Backstage/MemberStatusAuditEntry.php`

- [ ] **Step 3.1: Write ApplicationDecision enum**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

enum ApplicationDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

- [ ] **Step 3.2: Write PendingApplication**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class PendingApplication
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,             // 'member' | 'supporter'
        public readonly string $displayName,
        public readonly string $email,
        public readonly string $submittedAt,      // ISO-8601
        public readonly string $motivation,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'displayName' => $this->displayName,
            'email'       => $this->email,
            'submittedAt' => $this->submittedAt,
            'motivation'  => $this->motivation,
        ];
    }
}
```

- [ ] **Step 3.3: Write MemberDirectoryEntry**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class MemberDirectoryEntry
{
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $membershipType,
        public readonly string $membershipStatus,
        public readonly ?string $memberNumber,
        public readonly ?string $roleInTenant,
        public readonly string $joinedAt,        // ISO-8601
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'userId'           => $this->userId,
            'name'             => $this->name,
            'email'            => $this->email,
            'membershipType'   => $this->membershipType,
            'membershipStatus' => $this->membershipStatus,
            'memberNumber'     => $this->memberNumber,
            'roleInTenant'     => $this->roleInTenant,
            'joinedAt'         => $this->joinedAt,
        ];
    }
}
```

- [ ] **Step 3.4: Write MemberStatusAuditEntry**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class MemberStatusAuditEntry
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $previousStatus,
        public readonly string $newStatus,
        public readonly string $reason,
        public readonly string $performedByName,
        public readonly string $createdAt,        // ISO-8601
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'previousStatus'  => $this->previousStatus,
            'newStatus'       => $this->newStatus,
            'reason'          => $this->reason,
            'performedByName' => $this->performedByName,
            'createdAt'       => $this->createdAt,
        ];
    }
}
```

- [ ] **Step 3.5: Verify + commit**

```bash
composer analyse
git add src/Domain/Backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): backstage read models (PendingApplication, MemberDirectoryEntry, MemberStatusAuditEntry, ApplicationDecision)"
```

---

## Task 4: MemberDirectoryRepositoryInterface

**Files:**
- Create: `src/Domain/Backstage/MemberDirectoryRepositoryInterface.php`

- [ ] **Step 4.1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface MemberDirectoryRepositoryInterface
{
    /**
     * @param array{status?:string, type?:string, q?:string} $filters
     * @return array{entries: list<MemberDirectoryEntry>, total: int}
     */
    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,      // 'member_number' | 'name' | 'joined_at' | 'status'
        string $dir,       // 'ASC' | 'DESC'
        int $page,
        int $perPage,
    ): array;

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void;

    /** @return list<MemberStatusAuditEntry> */
    public function getAuditEntriesForMember(
        UserId $userId,
        TenantId $tenantId,
        int $limit,
    ): array;
}
```

- [ ] **Step 4.2: Verify + commit**

```bash
composer analyse
git add src/Domain/Backstage/MemberDirectoryRepositoryInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(domain): MemberDirectoryRepositoryInterface"
```

---

## Task 5: Extend application repository interfaces

**Files:**
- Modify: `src/Domain/Membership/MemberApplicationRepositoryInterface.php`
- Modify: `src/Domain/Membership/SupporterApplicationRepositoryInterface.php`

- [ ] **Step 5.1: Add methods to MemberApplicationRepositoryInterface**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface MemberApplicationRepositoryInterface
{
    public function save(MemberApplication $application): void;

    /** @return list<MemberApplication> */
    public function listPendingForTenant(TenantId $tenantId, int $limit): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,            // 'approved' | 'rejected'
        UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void;
}
```

- [ ] **Step 5.2: Add methods to SupporterApplicationRepositoryInterface (same shape)**

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface SupporterApplicationRepositoryInterface
{
    public function save(SupporterApplication $application): void;

    /** @return list<SupporterApplication> */
    public function listPendingForTenant(TenantId $tenantId, int $limit): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication;

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void;
}
```

- [ ] **Step 5.3: DO NOT commit yet**

Interfaces alone will break PHPStan (no implementations). Continue to Task 6 to add SQL implementations, then commit together.

---

## Task 6: SqlMemberApplicationRepository — implement new methods + test

**Files:**
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php`
- Create: `tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php`

- [ ] **Step 6.1: Extend SqlMemberApplicationRepository**

Append to class body (after existing `save` method):

```php
public function listPendingForTenant(TenantId $tenantId, int $limit): array
{
    $stmt = $this->db->pdo()->prepare(
        "SELECT * FROM member_applications
         WHERE tenant_id = ? AND status = 'pending'
         ORDER BY created_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $tenantId->value());
    $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(fn (array $r): MemberApplication => $this->hydrate($r), is_array($rows) ? $rows : []);
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
{
    $row = $this->db->queryOne(
        'SELECT * FROM member_applications WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function recordDecision(
    string $id,
    TenantId $tenantId,
    string $decision,
    UserId $decidedBy,
    ?string $note,
    \DateTimeImmutable $decidedAt,
): void {
    $this->db->execute(
        'UPDATE member_applications
            SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
          WHERE id = ? AND tenant_id = ?',
        [
            $decision,
            $decidedAt->format('Y-m-d H:i:s'),
            $decidedBy->value(),
            $note,
            $id,
            $tenantId->value(),
        ],
    );

    // Audit entry to member_register_audit (application-scoped)
    $this->db->execute(
        'INSERT INTO member_register_audit (id, tenant_id, application_id, action, performed_by, note)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
            $tenantId->value(),
            $id,
            $decision,
            $decidedBy->value(),
            $note,
        ],
    );
}

/** @param array<string, mixed> $row */
private function hydrate(array $row): MemberApplication
{
    $tenantIdRaw = $row['tenant_id'] ?? null;
    $country     = $row['country'] ?? null;
    $howHeard    = $row['how_heard'] ?? null;

    return new MemberApplication(
        MemberApplicationId::fromString((string) ($row['id'] ?? '')),
        TenantId::fromString(is_string($tenantIdRaw) ? $tenantIdRaw : ''),
        (string) ($row['name'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['date_of_birth'] ?? ''),
        is_string($country) ? $country : null,
        (string) ($row['motivation'] ?? ''),
        is_string($howHeard) ? $howHeard : null,
        (string) ($row['status'] ?? 'pending'),
    );
}
```

Add imports at top: `use Daems\Domain\Tenant\TenantId;`, `use Daems\Domain\User\UserId;`.

- [ ] **Step 6.2: Write integration test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberApplicationRepositoryTest extends MigrationTestCase
{
    private SqlMemberApplicationRepository $repo;
    private TenantId $daemsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(35);

        $this->repo = new SqlMemberApplicationRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->daemsTenant = TenantId::fromString($daemsId);
    }

    public function test_listPendingForTenant_returns_only_pending_same_tenant(): void
    {
        $this->repo->save(new MemberApplication(
            MemberApplicationId::generate(), $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));
        $this->repo->save(new MemberApplication(
            MemberApplicationId::generate(), $this->daemsTenant, 'Bob', 'b@x.com', '1990-01-01', null, 'motive', null, 'approved',
        ));

        $results = $this->repo->listPendingForTenant($this->daemsTenant, 100);

        self::assertCount(1, $results);
        self::assertSame('Alice', $results[0]->name());
    }

    public function test_findByIdForTenant_returns_null_for_wrong_tenant(): void
    {
        $id = MemberApplicationId::generate();
        $this->repo->save(new MemberApplication(
            $id, $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));

        $saheId = TenantId::fromString((string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='sahegroup'")->fetchColumn());

        self::assertNotNull($this->repo->findByIdForTenant($id->value(), $this->daemsTenant));
        self::assertNull($this->repo->findByIdForTenant($id->value(), $saheId));
    }

    public function test_recordDecision_updates_columns_and_writes_audit(): void
    {
        // Need a real user to reference
        $decider = $this->seedAdminUser();

        $id = MemberApplicationId::generate();
        $this->repo->save(new MemberApplication(
            $id, $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));

        $this->repo->recordDecision(
            $id->value(),
            $this->daemsTenant,
            'approved',
            $decider,
            'Welcome.',
            new DateTimeImmutable('2026-04-20 10:00:00'),
        );

        $row = $this->pdo()->query("SELECT status, decided_at, decided_by, decision_note FROM member_applications WHERE id = '" . $id->value() . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('approved', $row['status']);
        self::assertSame('2026-04-20 10:00:00', $row['decided_at']);
        self::assertSame($decider->value(), $row['decided_by']);
        self::assertSame('Welcome.', $row['decision_note']);

        $auditCount = (int) $this->pdo()->query("SELECT COUNT(*) FROM member_register_audit WHERE application_id = '" . $id->value() . "'")->fetchColumn();
        self::assertSame(1, $auditCount);
    }

    private function seedAdminUser(): UserId
    {
        $id = '01958000-0000-7000-8000-decider00001';
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES ('{$id}', 'Decider', 'decider@test', 'x', '1980-01-01', 1)"
        );
        return UserId::fromString($id);
    }
}
```

- [ ] **Step 6.3: Run tests, verify green**

```bash
vendor/bin/phpunit tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php
```

Expected: 3 passing tests.

---

## Task 7: SqlSupporterApplicationRepository — implement new methods

**Files:**
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlSupporterApplicationRepository.php`

- [ ] **Step 7.1: Extend SqlSupporterApplicationRepository**

Apply the SAME pattern as Task 6 but for supporter_applications. Methods:

```php
public function listPendingForTenant(TenantId $tenantId, int $limit): array
{
    $stmt = $this->db->pdo()->prepare(
        "SELECT * FROM supporter_applications
         WHERE tenant_id = ? AND status = 'pending'
         ORDER BY created_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $tenantId->value());
    $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(fn (array $r): SupporterApplication => $this->hydrate($r), is_array($rows) ? $rows : []);
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication
{
    $row = $this->db->queryOne(
        'SELECT * FROM supporter_applications WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function recordDecision(
    string $id,
    TenantId $tenantId,
    string $decision,
    UserId $decidedBy,
    ?string $note,
    \DateTimeImmutable $decidedAt,
): void {
    $this->db->execute(
        'UPDATE supporter_applications
            SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
          WHERE id = ? AND tenant_id = ?',
        [
            $decision,
            $decidedAt->format('Y-m-d H:i:s'),
            $decidedBy->value(),
            $note,
            $id,
            $tenantId->value(),
        ],
    );
    // Supporter applications have no dedicated audit table — decision columns are the audit trail.
}

/** @param array<string, mixed> $row */
private function hydrate(array $row): SupporterApplication
{
    $tenantIdRaw = $row['tenant_id'] ?? null;
    $regNo       = $row['reg_no'] ?? null;
    $country     = $row['country'] ?? null;
    $howHeard    = $row['how_heard'] ?? null;

    return new SupporterApplication(
        SupporterApplicationId::fromString((string) ($row['id'] ?? '')),
        TenantId::fromString(is_string($tenantIdRaw) ? $tenantIdRaw : ''),
        (string) ($row['org_name'] ?? ''),
        (string) ($row['contact_person'] ?? ''),
        is_string($regNo) ? $regNo : null,
        (string) ($row['email'] ?? ''),
        is_string($country) ? $country : null,
        (string) ($row['motivation'] ?? ''),
        is_string($howHeard) ? $howHeard : null,
        (string) ($row['status'] ?? 'pending'),
    );
}
```

Add imports: `use Daems\Domain\Tenant\TenantId;`, `use Daems\Domain\User\UserId;`, `use Daems\Domain\Membership\SupporterApplicationId;`.

- [ ] **Step 7.2: Verify PHPStan**

```bash
composer analyse
```

Expected: 0 errors (both interfaces + both implementations now coherent).

---

## Task 8: SqlMemberDirectoryRepository + integration test

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberDirectoryRepository.php`
- Create: `tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php`

- [ ] **Step 8.1: Write the repository**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlMemberDirectoryRepository implements MemberDirectoryRepositoryInterface
{
    private const ALLOWED_SORT = ['member_number', 'name', 'joined_at', 'status'];
    private const ALLOWED_DIR  = ['ASC', 'DESC'];

    public function __construct(private readonly Connection $db) {}

    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,
        string $dir,
        int $page,
        int $perPage,
    ): array {
        $sortSql = in_array($sort, self::ALLOWED_SORT, true) ? $sort : 'member_number';
        $dirSql  = in_array($dir, self::ALLOWED_DIR, true) ? $dir : 'ASC';

        $sortColumnMap = [
            'member_number' => 'u.member_number',
            'name'          => 'u.name',
            'joined_at'     => 'ut.joined_at',
            'status'        => 'u.membership_status',
        ];
        $sortCol = $sortColumnMap[$sortSql];

        $where  = ['ut.tenant_id = ?', 'ut.left_at IS NULL'];
        $params = [$tenantId->value()];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[]  = 'u.membership_status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $where[]  = 'u.membership_type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where[]  = '(u.name LIKE ? OR u.email LIKE ? OR u.member_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             WHERE {$whereSql}",
            $params,
        );
        $totalRaw = $countRow['n'] ?? 0;
        $total = is_int($totalRaw) ? $totalRaw : (is_string($totalRaw) && is_numeric($totalRaw) ? (int) $totalRaw : 0);

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.id, u.name, u.email, u.membership_type, u.membership_status, u.member_number,
                    ut.role, ut.joined_at
             FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             WHERE {$whereSql}
             ORDER BY {$sortCol} {$dirSql}
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $entries = array_map(fn (array $r): MemberDirectoryEntry => $this->hydrateEntry($r), is_array($rows) ? $rows : []);

        return ['entries' => $entries, 'total' => $total];
    }

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Get previous status (tenant-scoped via user_tenants)
            $prevRow = $this->db->queryOne(
                'SELECT u.membership_status FROM users u
                 JOIN user_tenants ut ON ut.user_id = u.id
                 WHERE u.id = ? AND ut.tenant_id = ? AND ut.left_at IS NULL',
                [$userId->value(), $tenantId->value()],
            );
            if ($prevRow === null) {
                $pdo->rollBack();
                throw new \RuntimeException('Member not found in tenant');
            }
            $prevStatus = is_string($prevRow['membership_status'] ?? null) ? $prevRow['membership_status'] : null;

            $this->db->execute(
                'UPDATE users SET membership_status = ? WHERE id = ?',
                [$newStatus, $userId->value()],
            );

            $this->db->execute(
                'INSERT INTO member_status_audit
                    (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid7::generate()->value(),
                    $tenantId->value(),
                    $userId->value(),
                    $prevStatus,
                    $newStatus,
                    $reason,
                    $performedBy->value(),
                    $at->format('Y-m-d H:i:s'),
                ],
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getAuditEntriesForMember(
        UserId $userId,
        TenantId $tenantId,
        int $limit,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.previous_status, a.new_status, a.reason, a.created_at,
                    COALESCE(performer.name, performer.email, a.performed_by) AS performed_by_name
             FROM member_status_audit a
             LEFT JOIN users performer ON performer.id = a.performed_by
             WHERE a.user_id = ? AND a.tenant_id = ?
             ORDER BY a.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId->value());
        $stmt->bindValue(2, $tenantId->value());
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r): MemberStatusAuditEntry => new MemberStatusAuditEntry(
            id:              (string) ($r['id'] ?? ''),
            previousStatus:  is_string($r['previous_status'] ?? null) ? $r['previous_status'] : null,
            newStatus:       (string) ($r['new_status'] ?? ''),
            reason:          (string) ($r['reason'] ?? ''),
            performedByName: (string) ($r['performed_by_name'] ?? ''),
            createdAt:       (string) ($r['created_at'] ?? ''),
        ), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $r */
    private function hydrateEntry(array $r): MemberDirectoryEntry
    {
        $memberNumber = $r['member_number'] ?? null;
        $role         = $r['role'] ?? null;

        return new MemberDirectoryEntry(
            userId:           (string) ($r['id'] ?? ''),
            name:             (string) ($r['name'] ?? ''),
            email:            (string) ($r['email'] ?? ''),
            membershipType:   (string) ($r['membership_type'] ?? ''),
            membershipStatus: (string) ($r['membership_status'] ?? ''),
            memberNumber:     is_string($memberNumber) ? $memberNumber : null,
            roleInTenant:     is_string($role) ? $role : null,
            joinedAt:         (string) ($r['joined_at'] ?? ''),
        );
    }
}
```

- [ ] **Step 8.2: Write integration test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence\Sql;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberDirectoryRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberDirectoryRepositoryTest extends MigrationTestCase
{
    private SqlMemberDirectoryRepository $repo;
    private TenantId $daemsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(35);

        $this->repo = new SqlMemberDirectoryRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->daemsTenant = TenantId::fromString($daemsId);
    }

    private function seedMember(string $id, string $name, string $email, string $status = 'active', string $type = 'individual', ?string $memberNumber = null): UserId
    {
        $mn = $memberNumber !== null ? "'{$memberNumber}'" : 'NULL';
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, membership_type, membership_status, member_number)
             VALUES ('{$id}', '{$name}', '{$email}', 'x', '1990-01-01', '{$type}', '{$status}', {$mn})"
        );
        $this->pdo()->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('{$id}', '" . $this->daemsTenant->value() . "', 'member', NOW())"
        );
        return UserId::fromString($id);
    }

    public function test_listMembersForTenant_returns_tenant_scoped_entries(): void
    {
        $this->seedMember('01958000-0000-7000-8000-member000001', 'Alice', 'alice@x.com');
        $this->seedMember('01958000-0000-7000-8000-member000002', 'Bob',   'bob@x.com');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, [], 'name', 'ASC', 1, 50);

        self::assertCount(2, $result['entries']);
        self::assertSame(2, $result['total']);
        self::assertSame('Alice', $result['entries'][0]->name);
    }

    public function test_listMembersForTenant_filters_by_status(): void
    {
        $this->seedMember('01958000-0000-7000-8000-member000010', 'Active',    'a@x.com', 'active');
        $this->seedMember('01958000-0000-7000-8000-member000011', 'Suspended', 's@x.com', 'suspended');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, ['status' => 'suspended'], 'name', 'ASC', 1, 50);

        self::assertCount(1, $result['entries']);
        self::assertSame('Suspended', $result['entries'][0]->name);
    }

    public function test_listMembersForTenant_search_q_matches_name_email_number(): void
    {
        $this->seedMember('01958000-0000-7000-8000-member000020', 'Find Me',    'other@x.com', 'active', 'individual', '1001');
        $this->seedMember('01958000-0000-7000-8000-member000021', 'OtherGuy',   'findme@x.com', 'active');
        $this->seedMember('01958000-0000-7000-8000-member000022', 'ThirdUser',  'third@x.com', 'active', 'individual', '2-findme');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, ['q' => 'findme'], 'name', 'ASC', 1, 50);

        self::assertCount(2, $result['entries']);
    }

    public function test_changeStatus_transactionally_updates_user_and_writes_audit(): void
    {
        $userId = $this->seedMember('01958000-0000-7000-8000-target000001', 'Target', 't@x.com', 'active');
        $adminId = $this->seedMember('01958000-0000-7000-8000-admin000001', 'Admin', 'admin@x.com', 'active');

        $this->repo->changeStatus(
            $userId, $this->daemsTenant, 'suspended', 'Late payment', $adminId, new DateTimeImmutable('2026-04-20 12:00:00'),
        );

        $userStatus = (string) $this->pdo()->query("SELECT membership_status FROM users WHERE id = '" . $userId->value() . "'")->fetchColumn();
        self::assertSame('suspended', $userStatus);

        $auditRows = $this->pdo()->query("SELECT previous_status, new_status, reason FROM member_status_audit WHERE user_id = '" . $userId->value() . "'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $auditRows);
        self::assertSame('active', $auditRows[0]['previous_status']);
        self::assertSame('suspended', $auditRows[0]['new_status']);
        self::assertSame('Late payment', $auditRows[0]['reason']);
    }

    public function test_getAuditEntriesForMember_returns_reverse_chronological(): void
    {
        $userId = $this->seedMember('01958000-0000-7000-8000-audituser0001', 'A', 'a@x.com', 'active');
        $adminId = $this->seedMember('01958000-0000-7000-8000-auditadmin001', 'Admin', 'admin@x.com', 'active');

        $this->repo->changeStatus($userId, $this->daemsTenant, 'inactive', 'reason1', $adminId, new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->repo->changeStatus($userId, $this->daemsTenant, 'active',   'reason2', $adminId, new DateTimeImmutable('2026-02-01 10:00:00'));

        $entries = $this->repo->getAuditEntriesForMember($userId, $this->daemsTenant, 10);

        self::assertCount(2, $entries);
        self::assertSame('reason2', $entries[0]->reason); // newest first
        self::assertSame('reason1', $entries[1]->reason);
    }
}
```

- [ ] **Step 8.3: Run test + PHPStan**

```bash
vendor/bin/phpunit tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php
composer analyse
```

Expected: 5 tests pass, PHPStan 0 errors.

---

## Task 9: InMemory fakes for all 3 repositories

**Files:**
- Modify: `tests/Support/Fake/InMemoryMemberApplicationRepository.php`
- Modify: `tests/Support/Fake/InMemorySupporterApplicationRepository.php`
- Create: `tests/Support/Fake/InMemoryMemberDirectoryRepository.php`

- [ ] **Step 9.1: Extend InMemoryMemberApplicationRepository**

Read the existing file first, then add these methods:

```php
public function listPendingForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit): array
{
    $filtered = array_filter(
        $this->applications,
        static fn (MemberApplication $a): bool => $a->tenantId()->equals($tenantId) && $a->status() === 'pending',
    );
    return array_slice(array_values($filtered), 0, $limit);
}

public function findByIdForTenant(string $id, \Daems\Domain\Tenant\TenantId $tenantId): ?MemberApplication
{
    foreach ($this->applications as $a) {
        if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
            return $a;
        }
    }
    return null;
}

public function recordDecision(
    string $id,
    \Daems\Domain\Tenant\TenantId $tenantId,
    string $decision,
    \Daems\Domain\User\UserId $decidedBy,
    ?string $note,
    \DateTimeImmutable $decidedAt,
): void {
    // For the fake, we replace the entity with a new one carrying the updated status.
    // Other metadata (decided_at/by/note) are not re-surfaced on the entity; tests
    // that care about them should use the SQL repo integration tests.
    foreach ($this->applications as $i => $a) {
        if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
            $this->applications[$i] = new MemberApplication(
                $a->id(), $a->tenantId(), $a->name(), $a->email(),
                $a->dateOfBirth(), $a->country(), $a->motivation(), $a->howHeard(),
                $decision,
            );
            $this->decisions[$id] = ['decision' => $decision, 'by' => $decidedBy->value(), 'note' => $note, 'at' => $decidedAt->format('Y-m-d H:i:s')];
            return;
        }
    }
}

/** @var array<string, array{decision:string, by:string, note:?string, at:string}> */
public array $decisions = [];
```

Also at top: `use DateTimeImmutable;`.

- [ ] **Step 9.2: Extend InMemorySupporterApplicationRepository (same shape)**

Apply the same pattern as 9.1 for supporter. Replace `MemberApplication` → `SupporterApplication` and constructor arg list:

```php
$this->applications[$i] = new SupporterApplication(
    $a->id(), $a->tenantId(), $a->orgName(), $a->contactPerson(), $a->regNo(),
    $a->email(), $a->country(), $a->motivation(), $a->howHeard(),
    $decision,
);
```

- [ ] **Step 9.3: Write InMemoryMemberDirectoryRepository**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryMemberDirectoryRepository implements MemberDirectoryRepositoryInterface
{
    /** @var list<MemberDirectoryEntry> */
    public array $entries = [];

    /** @var array<string, list<MemberStatusAuditEntry>> by user_id */
    public array $audit = [];

    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,
        string $dir,
        int $page,
        int $perPage,
    ): array {
        $matches = $this->entries;

        if (isset($filters['status']) && $filters['status'] !== '') {
            $matches = array_values(array_filter($matches, static fn (MemberDirectoryEntry $e): bool => $e->membershipStatus === $filters['status']));
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $matches = array_values(array_filter($matches, static fn (MemberDirectoryEntry $e): bool => $e->membershipType === $filters['type']));
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $q = (string) $filters['q'];
            $matches = array_values(array_filter($matches, static function (MemberDirectoryEntry $e) use ($q): bool {
                return str_contains($e->name, $q) || str_contains($e->email, $q) || ($e->memberNumber !== null && str_contains($e->memberNumber, $q));
            }));
        }

        $total  = count($matches);
        $offset = max(0, ($page - 1) * $perPage);

        return ['entries' => array_slice($matches, $offset, $perPage), 'total' => $total];
    }

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void {
        $prev = null;
        foreach ($this->entries as $i => $e) {
            if ($e->userId === $userId->value()) {
                $prev = $e->membershipStatus;
                $this->entries[$i] = new MemberDirectoryEntry(
                    $e->userId, $e->name, $e->email, $e->membershipType,
                    $newStatus, $e->memberNumber, $e->roleInTenant, $e->joinedAt,
                );
                break;
            }
        }
        $this->audit[$userId->value()][] = new MemberStatusAuditEntry(
            id:              'audit-' . count($this->audit[$userId->value()] ?? []),
            previousStatus:  $prev,
            newStatus:       $newStatus,
            reason:          $reason,
            performedByName: $performedBy->value(),
            createdAt:       $at->format('Y-m-d H:i:s'),
        );
    }

    public function getAuditEntriesForMember(UserId $userId, TenantId $tenantId, int $limit): array
    {
        $list = array_reverse($this->audit[$userId->value()] ?? []);
        return array_slice($list, 0, $limit);
    }
}
```

- [ ] **Step 9.4: Verify + commit (interfaces + implementations + fakes together)**

```bash
composer analyse
composer test
git add src/Domain/Membership/MemberApplicationRepositoryInterface.php \
        src/Domain/Membership/SupporterApplicationRepositoryInterface.php \
        src/Domain/Backstage/MemberDirectoryRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlMemberApplicationRepository.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlSupporterApplicationRepository.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlMemberDirectoryRepository.php \
        tests/Integration/Persistence/Sql/SqlMemberApplicationRepositoryTest.php \
        tests/Integration/Persistence/Sql/SqlMemberDirectoryRepositoryTest.php \
        tests/Support/Fake/InMemoryMemberApplicationRepository.php \
        tests/Support/Fake/InMemorySupporterApplicationRepository.php \
        tests/Support/Fake/InMemoryMemberDirectoryRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(persistence): backstage application + member directory repositories"
```

---

## Task 10: Use case — ListPendingApplications

**Files:**
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplications.php`
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsInput.php`
- Create: `src/Application/Backstage/ListPendingApplications/ListPendingApplicationsOutput.php`
- Create: `tests/Unit/Application/Backstage/ListPendingApplicationsTest.php`

- [ ] **Step 10.1: Write Input**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ActingUser;

final class ListPendingApplicationsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly int $limit = 200,
    ) {}
}
```

- [ ] **Step 10.2: Write Output**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Backstage\PendingApplication;

final class ListPendingApplicationsOutput
{
    /**
     * @param list<PendingApplication> $member
     * @param list<PendingApplication> $supporter
     */
    public function __construct(
        public readonly array $member,
        public readonly array $supporter,
    ) {}

    /** @return array{member: list<array<string, string>>, supporter: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'member'    => array_map(static fn (PendingApplication $p) => $p->toArray(), $this->member),
            'supporter' => array_map(static fn (PendingApplication $p) => $p->toArray(), $this->supporter),
        ];
    }
}
```

- [ ] **Step 10.3: Write the use case**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\PendingApplication;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class ListPendingApplications
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListPendingApplicationsInput $input): ListPendingApplicationsOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $memberApps    = $this->memberApps->listPendingForTenant($tenantId, $input->limit);
        $supporterApps = $this->supporterApps->listPendingForTenant($tenantId, $input->limit);

        $memberOut = array_map(static fn (MemberApplication $a): PendingApplication => new PendingApplication(
            id:          $a->id()->value(),
            type:        'member',
            displayName: $a->name(),
            email:       $a->email(),
            submittedAt: '',                       // application entity does not expose created_at; left blank here
            motivation:  $a->motivation(),
        ), $memberApps);

        $supporterOut = array_map(static fn (SupporterApplication $a): PendingApplication => new PendingApplication(
            id:          $a->id()->value(),
            type:        'supporter',
            displayName: $a->orgName(),
            email:       $a->email(),
            submittedAt: '',
            motivation:  $a->motivation(),
        ), $supporterApps);

        return new ListPendingApplicationsOutput(member: $memberOut, supporter: $supporterOut);
    }
}
```

- [ ] **Step 10.4: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListPendingApplications\ListPendingApplications;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListPendingApplicationsTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(?UserTenantRole $role, bool $platformAdmin = false): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: $platformAdmin, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testThrowsForbiddenWhenNonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $memberRepo    = $this->createMock(MemberApplicationRepositoryInterface::class);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(UserTenantRole::Member)),
        );
    }

    public function testReturnsPendingApplicationsForAdmin(): void
    {
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->tenant,
            'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );

        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('listPendingForTenant')->willReturn([$app]);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->method('listPendingForTenant')->willReturn([]);

        $out = (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(UserTenantRole::Admin)),
        );

        self::assertCount(1, $out->member);
        self::assertSame('Alice', $out->member[0]->displayName);
    }

    public function testPlatformAdminAllowedEvenWithoutTenantRole(): void
    {
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('listPendingForTenant')->willReturn([]);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->method('listPendingForTenant')->willReturn([]);

        $out = (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(role: null, platformAdmin: true)),
        );

        self::assertSame([], $out->member);
    }
}
```

- [ ] **Step 10.5: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Backstage/ListPendingApplicationsTest.php
composer analyse
git add src/Application/Backstage/ListPendingApplications/ tests/Unit/Application/Backstage/ListPendingApplicationsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(app): ListPendingApplications use case"
```

---

## Task 11: Use case — DecideApplication

**Files:**
- Create: `src/Application/Backstage/DecideApplication/DecideApplication.php`
- Create: `src/Application/Backstage/DecideApplication/DecideApplicationInput.php`
- Create: `src/Application/Backstage/DecideApplication/DecideApplicationOutput.php`
- Create: `tests/Unit/Application/Backstage/DecideApplicationTest.php`

- [ ] **Step 11.1: Write Input + Output**

Input:
```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

use Daems\Domain\Auth\ActingUser;

final class DecideApplicationInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $type,       // 'member' | 'supporter'
        public readonly string $id,
        public readonly string $decision,   // 'approved' | 'rejected'
        public readonly ?string $note,
    ) {}
}
```

Output:
```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

final class DecideApplicationOutput
{
    public function __construct(
        public readonly bool $success,
    ) {}

    /** @return array{success: bool} */
    public function toArray(): array
    {
        return ['success' => $this->success];
    }
}
```

- [ ] **Step 11.2: Write the use case**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class DecideApplication
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
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

        $now = $this->clock->now();

        if ($input->type === 'member') {
            $app = $this->memberApps->findByIdForTenant($input->id, $tenantId)
                ?? throw new NotFoundException('application_not_found');
            $this->memberApps->recordDecision($input->id, $tenantId, $input->decision, $input->acting->id, $input->note, $now);
            return new DecideApplicationOutput(true);
        }

        if ($input->type === 'supporter') {
            $app = $this->supporterApps->findByIdForTenant($input->id, $tenantId)
                ?? throw new NotFoundException('application_not_found');
            $this->supporterApps->recordDecision($input->id, $tenantId, $input->decision, $input->acting->id, $input->note, $now);
            return new DecideApplicationOutput(true);
        }

        throw new ValidationException(['type' => 'invalid_value']);
    }
}
```

- [ ] **Step 11.3: Ensure ValidationException exists**

Check if `src/Domain/Shared/ValidationException.php` exists. If not, create it:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use Exception;

final class ValidationException extends Exception
{
    /** @param array<string, string> $fields */
    public function __construct(private readonly array $fields)
    {
        parent::__construct('validation_failed');
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
```

Run: `ls src/Domain/Shared/ValidationException.php` — if not found, create it before the use case compiles.

- [ ] **Step 11.4: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DecideApplicationTest extends TestCase
{
    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-04-20 12:00:00'); }
        };
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Member), 'member', 'id', 'approved', null),
        );
    }

    public function testInvalidDecisionThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'maybe', null),
        );
    }

    public function testApplicationNotFoundThrows404(): void
    {
        $this->expectException(NotFoundException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $m->method('findByIdForTenant')->willReturn(null);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'approved', null),
        );
    }

    public function testRecordsDecisionForMember(): void
    {
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->tenant, 'A', 'a@x', '1990-01-01', null, 'm', null, 'pending',
        );
        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $m->method('findByIdForTenant')->willReturn($app);
        $m->expects($this->once())->method('recordDecision');
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        $out = (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', $app->id()->value(), 'approved', 'welcome'),
        );

        self::assertTrue($out->success);
    }
}
```

- [ ] **Step 11.5: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Backstage/DecideApplicationTest.php
composer analyse
git add src/Application/Backstage/DecideApplication/ src/Domain/Shared/ValidationException.php tests/Unit/Application/Backstage/DecideApplicationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(app): DecideApplication use case"
```

---

## Task 12: Use case — ListMembers

**Files:**
- Create: `src/Application/Backstage/ListMembers/ListMembers.php`
- Create: `src/Application/Backstage/ListMembers/ListMembersInput.php`
- Create: `src/Application/Backstage/ListMembers/ListMembersOutput.php`
- Create: `tests/Unit/Application/Backstage/ListMembersTest.php`

- [ ] **Step 12.1: Write Input**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListMembers;

use Daems\Domain\Auth\ActingUser;

final class ListMembersInput
{
    /** @param array{status?:string, type?:string, q?:string} $filters */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly array $filters = [],
        public readonly string $sort = 'member_number',
        public readonly string $dir = 'ASC',
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}
}
```

- [ ] **Step 12.2: Write Output**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListMembers;

use Daems\Domain\Backstage\MemberDirectoryEntry;

final class ListMembersOutput
{
    /**
     * @param list<MemberDirectoryEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * @return array{
     *   data: list<array<string, string|null>>,
     *   meta: array{page:int, per_page:int, total:int, total_pages:int}
     * }
     */
    public function toArray(): array
    {
        $totalPages = $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
        return [
            'data' => array_map(static fn (MemberDirectoryEntry $e) => $e->toArray(), $this->entries),
            'meta' => [
                'page'        => $this->page,
                'per_page'    => $this->perPage,
                'total'       => $this->total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
```

- [ ] **Step 12.3: Write the use case**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListMembers;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;

final class ListMembers
{
    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
    ) {}

    public function execute(ListMembersInput $input): ListMembersOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $perPage = max(1, min(200, $input->perPage));
        $page    = max(1, $input->page);

        $result = $this->directory->listMembersForTenant(
            $tenantId, $input->filters, $input->sort, $input->dir, $page, $perPage,
        );

        return new ListMembersOutput(
            entries: $result['entries'],
            total:   $result['total'],
            page:    $page,
            perPage: $perPage,
        );
    }
}
```

- [ ] **Step 12.4: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListMembers\ListMembers;
use Daems\Application\Backstage\ListMembers\ListMembersInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListMembersTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ListMembers($repo))->execute(new ListMembersInput($this->acting(UserTenantRole::Member)));
    }

    public function testReturnsEntriesWithPaginationMeta(): void
    {
        $entry = new MemberDirectoryEntry('uid', 'Alice', 'a@x', 'individual', 'active', '1001', 'member', '2026-01-01 00:00:00');

        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->method('listMembersForTenant')->willReturn(['entries' => [$entry], 'total' => 1]);

        $out = (new ListMembers($repo))->execute(new ListMembersInput($this->acting(UserTenantRole::Admin)));

        self::assertCount(1, $out->entries);
        self::assertSame(1, $out->total);
        self::assertSame(1, $out->page);
        self::assertSame(50, $out->perPage);
    }

    public function testPerPageClampedToMax200(): void
    {
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->expects($this->once())->method('listMembersForTenant')
             ->with(self::anything(), self::anything(), self::anything(), self::anything(), 1, 200)
             ->willReturn(['entries' => [], 'total' => 0]);

        (new ListMembers($repo))->execute(
            new ListMembersInput($this->acting(UserTenantRole::Admin), perPage: 99999),
        );
    }
}
```

- [ ] **Step 12.5: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Backstage/ListMembersTest.php
composer analyse
git add src/Application/Backstage/ListMembers/ tests/Unit/Application/Backstage/ListMembersTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(app): ListMembers use case"
```

---

## Task 13: Use case — ChangeMemberStatus (GSA only)

**Files:**
- Create: `src/Application/Backstage/ChangeMemberStatus/ChangeMemberStatus.php`
- Create: `src/Application/Backstage/ChangeMemberStatus/ChangeMemberStatusInput.php`
- Create: `src/Application/Backstage/ChangeMemberStatus/ChangeMemberStatusOutput.php`
- Create: `tests/Unit/Application/Backstage/ChangeMemberStatusTest.php`

- [ ] **Step 13.1: Write Input**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeMemberStatus;

use Daems\Domain\Auth\ActingUser;

final class ChangeMemberStatusInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $memberId,
        public readonly string $newStatus,
        public readonly string $reason,
    ) {}
}
```

- [ ] **Step 13.2: Write Output**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeMemberStatus;

final class ChangeMemberStatusOutput
{
    public function __construct(
        public readonly bool $success,
    ) {}

    /** @return array{success: bool} */
    public function toArray(): array
    {
        return ['success' => $this->success];
    }
}
```

- [ ] **Step 13.3: Write the use case (GSA only)**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeMemberStatus;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\UserId;

final class ChangeMemberStatus
{
    private const ALLOWED_STATUS = ['active', 'inactive', 'suspended', 'cancelled'];

    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
        private readonly Clock $clock,
    ) {}

    public function execute(ChangeMemberStatusInput $input): ChangeMemberStatusOutput
    {
        if (!$input->acting->isPlatformAdmin) {
            throw new ForbiddenException('gsa_only');
        }

        if (!in_array($input->newStatus, self::ALLOWED_STATUS, true)) {
            throw new ValidationException(['status' => 'invalid_value']);
        }

        if (trim($input->reason) === '') {
            throw new ValidationException(['reason' => 'required']);
        }

        $this->directory->changeStatus(
            UserId::fromString($input->memberId),
            $input->acting->activeTenant,
            $input->newStatus,
            $input->reason,
            $input->acting->id,
            $this->clock->now(),
        );

        return new ChangeMemberStatusOutput(true);
    }
}
```

- [ ] **Step 13.4: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChangeMemberStatusTest extends TestCase
{
    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-04-20 12:00:00'); }
        };
    }

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: $platformAdmin, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testTenantAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->clock))->execute(
            new ChangeMemberStatusInput(
                $this->acting(platformAdmin: false, role: UserTenantRole::Admin),
                UserId::generate()->value(), 'suspended', 'reason',
            ),
        );
    }

    public function testInvalidStatusThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'frobnicated', 'reason'),
        );
    }

    public function testEmptyReasonThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'suspended', '   '),
        );
    }

    public function testGsaSucceedsAndCallsRepo(): void
    {
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->expects($this->once())->method('changeStatus');

        $out = (new ChangeMemberStatus($repo, $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'suspended', 'overdue fees'),
        );

        self::assertTrue($out->success);
    }
}
```

- [ ] **Step 13.5: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Backstage/ChangeMemberStatusTest.php
composer analyse
git add src/Application/Backstage/ChangeMemberStatus/ tests/Unit/Application/Backstage/ChangeMemberStatusTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(app): ChangeMemberStatus use case (GSA only)"
```

---

## Task 14: Use case — GetMemberAudit

**Files:**
- Create: `src/Application/Backstage/GetMemberAudit/GetMemberAudit.php`
- Create: `src/Application/Backstage/GetMemberAudit/GetMemberAuditInput.php`
- Create: `src/Application/Backstage/GetMemberAudit/GetMemberAuditOutput.php`
- Create: `tests/Unit/Application/Backstage/GetMemberAuditTest.php`

- [ ] **Step 14.1: Write Input + Output**

Input:
```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetMemberAudit;

use Daems\Domain\Auth\ActingUser;

final class GetMemberAuditInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $memberId,
        public readonly int $limit = 25,
    ) {}
}
```

Output:
```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetMemberAudit;

use Daems\Domain\Backstage\MemberStatusAuditEntry;

final class GetMemberAuditOutput
{
    /** @param list<MemberStatusAuditEntry> $entries */
    public function __construct(public readonly array $entries) {}

    /** @return list<array<string, string|null>> */
    public function toArray(): array
    {
        return array_map(static fn (MemberStatusAuditEntry $e) => $e->toArray(), $this->entries);
    }
}
```

- [ ] **Step 14.2: Write the use case**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetMemberAudit;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\User\UserId;

final class GetMemberAudit
{
    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
    ) {}

    public function execute(GetMemberAuditInput $input): GetMemberAuditOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $entries = $this->directory->getAuditEntriesForMember(
            UserId::fromString($input->memberId), $tenantId, max(1, min(500, $input->limit)),
        );

        return new GetMemberAuditOutput($entries);
    }
}
```

- [ ] **Step 14.3: Write unit test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\GetMemberAudit\GetMemberAudit;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAuditInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class GetMemberAuditTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new GetMemberAudit($repo))->execute(
            new GetMemberAuditInput($this->acting(UserTenantRole::Member), UserId::generate()->value()),
        );
    }

    public function testAdminGetsEntries(): void
    {
        $entry = new MemberStatusAuditEntry('id-1', 'active', 'suspended', 'reason', 'Admin Name', '2026-04-20 12:00:00');
        $repo  = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->method('getAuditEntriesForMember')->willReturn([$entry]);

        $out = (new GetMemberAudit($repo))->execute(
            new GetMemberAuditInput($this->acting(UserTenantRole::Admin), UserId::generate()->value()),
        );

        self::assertCount(1, $out->entries);
        self::assertSame('suspended', $out->entries[0]->newStatus);
    }
}
```

- [ ] **Step 14.4: Run + commit**

```bash
vendor/bin/phpunit tests/Unit/Application/Backstage/GetMemberAuditTest.php
composer analyse
git add src/Application/Backstage/GetMemberAudit/ tests/Unit/Application/Backstage/GetMemberAuditTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(app): GetMemberAudit use case"
```

---

## Task 15: BackstageController

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`

- [ ] **Step 15.1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAudit;
use Daems\Application\Backstage\GetMemberAudit\GetMemberAuditInput;
use Daems\Application\Backstage\ListMembers\ListMembers;
use Daems\Application\Backstage\ListMembers\ListMembersInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplications;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BackstageController
{
    public function __construct(
        private readonly ListPendingApplications $listPending,
        private readonly DecideApplication $decide,
        private readonly ListMembers $listMembers,
        private readonly ChangeMemberStatus $changeStatus,
        private readonly GetMemberAudit $getAudit,
    ) {}

    public function pendingApplications(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $limit  = (int) ($request->query('limit') ?? 200);
        $limit  = max(1, min(500, $limit));

        try {
            $out = $this->listPending->execute(new ListPendingApplicationsInput($acting, $limit));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function decideApplication(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $type     = (string) ($params['type'] ?? '');
        $id       = (string) ($params['id'] ?? '');
        $decision = trim((string) ($request->string('decision') ?? ''));
        $note     = $request->string('note') ?: null;

        try {
            $out = $this->decide->execute(new DecideApplicationInput($acting, $type, $id, $decision, $note));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (NotFoundException $e) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'fields' => $e->fields()], 422);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function members(Request $request): Response
    {
        $acting = $request->requireActingUser();

        $filters = array_filter([
            'status' => $request->string('status') ?: null,
            'type'   => $request->string('type') ?: null,
            'q'      => $request->string('q') ?: null,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $sort    = $request->string('sort') ?: 'member_number';
        $dir     = strtoupper($request->string('dir') ?: 'ASC');
        $page    = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 50);

        try {
            /** @var array{status?:string, type?:string, q?:string} $filters */
            $out = $this->listMembers->execute(new ListMembersInput($acting, $filters, $sort, $dir, $page, $perPage));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        if ($request->query('export') === 'csv') {
            return $this->csvResponse($out->entries, $acting->activeTenant->value());
        }

        return Response::json($out->toArray());
    }

    public function changeMemberStatus(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $memberId = (string) ($params['id'] ?? '');
        $status   = (string) ($request->string('status') ?? '');
        $reason   = (string) ($request->string('reason') ?? '');

        try {
            $out = $this->changeStatus->execute(new ChangeMemberStatusInput($acting, $memberId, $status, $reason));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'fields' => $e->fields()], 422);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    public function memberAudit(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $memberId = (string) ($params['id'] ?? '');
        $limit    = (int) ($request->query('limit') ?? 25);

        try {
            $out = $this->getAudit->execute(new GetMemberAuditInput($acting, $memberId, $limit));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        }

        return Response::json(['data' => $out->toArray()]);
    }

    /**
     * @param list<\Daems\Domain\Backstage\MemberDirectoryEntry> $entries
     */
    private function csvResponse(array $entries, string $tenantSlugOrId): Response
    {
        $filename = 'members-' . $tenantSlugOrId . '-' . date('Y-m-d') . '.csv';

        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return Response::json(['error' => 'csv_failed'], 500);
        }
        fputcsv($fh, ['member_number', 'name', 'email', 'membership_type', 'membership_status', 'role_in_tenant', 'joined_at']);
        foreach ($entries as $e) {
            fputcsv($fh, [$e->memberNumber ?? '', $e->name, $e->email, $e->membershipType, $e->membershipStatus, $e->roleInTenant ?? '', $e->joinedAt]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
```

**Note:** This requires `Response::make(string $body, int $status, array $headers): Response` method. If it doesn't exist, add it (or use existing equivalent). Inspect `src/Infrastructure/Framework/Http/Response.php` first — if `make()` is missing but `Response::text()` or similar exists, use that pattern.

- [ ] **Step 15.2: Verify `Response::make` or equivalent exists**

```bash
grep -n "public static function " src/Infrastructure/Framework/Http/Response.php
```

If `make()` is missing, add this method to Response.php:

```php
/** @param array<string, string> $headers */
public static function make(string $body, int $status = 200, array $headers = []): self
{
    return new self($status, $headers, $body);
}
```

- [ ] **Step 15.3: Verify PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 15.4: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageController.php src/Infrastructure/Framework/Http/Response.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): BackstageController with 5 endpoints"
```

---

## Task 16: Routes + KernelHarness wiring

**Files:**
- Modify: `routes/api.php` (add 5 routes)
- Modify: `tests/Support/KernelHarness.php` (wire use cases + controller)

- [ ] **Step 16.1: Add routes**

Append to `routes/api.php` (before the closing `};`):

```php
// Backstage — tenant-admin / GSA
$router->get('/api/v1/backstage/applications/pending', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->pendingApplications($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/applications/{type}/{id}/decision', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->decideApplication($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/members', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->members($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/members/{id}/status', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->changeMemberStatus($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/members/{id}/audit', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->memberAudit($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 16.2: Wire KernelHarness**

Read `tests/Support/KernelHarness.php`. Add these additions (after existing bindings):

Property:
```php
public InMemoryMemberDirectoryRepository $memberDirectory;
```

Constructor (alongside other repo inits):
```php
$this->memberDirectory = new InMemoryMemberDirectoryRepository();
```

Container bindings (with the other repo singletons):
```php
$container->singleton(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class, fn() => $this->memberDirectory);
```

Use case bindings (with other use case binds):
```php
$container->bind(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class, static fn(Container $c) => new \Daems\Application\Backstage\ListPendingApplications\ListPendingApplications(
    $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
    $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
));
$container->bind(\Daems\Application\Backstage\DecideApplication\DecideApplication::class, static fn(Container $c) => new \Daems\Application\Backstage\DecideApplication\DecideApplication(
    $c->make(\Daems\Domain\Membership\MemberApplicationRepositoryInterface::class),
    $c->make(\Daems\Domain\Membership\SupporterApplicationRepositoryInterface::class),
    $c->make(Clock::class),
));
$container->bind(\Daems\Application\Backstage\ListMembers\ListMembers::class, static fn(Container $c) => new \Daems\Application\Backstage\ListMembers\ListMembers(
    $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
));
$container->bind(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class, static fn(Container $c) => new \Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus(
    $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
    $c->make(Clock::class),
));
$container->bind(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class, static fn(Container $c) => new \Daems\Application\Backstage\GetMemberAudit\GetMemberAudit(
    $c->make(\Daems\Domain\Backstage\MemberDirectoryRepositoryInterface::class),
));
```

Controller binding:
```php
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class, static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageController(
    $c->make(\Daems\Application\Backstage\ListPendingApplications\ListPendingApplications::class),
    $c->make(\Daems\Application\Backstage\DecideApplication\DecideApplication::class),
    $c->make(\Daems\Application\Backstage\ListMembers\ListMembers::class),
    $c->make(\Daems\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus::class),
    $c->make(\Daems\Application\Backstage\GetMemberAudit\GetMemberAudit::class),
));
```

Also add `use Daems\Tests\Support\Fake\InMemoryMemberDirectoryRepository;` to the use-statements at top.

- [ ] **Step 16.3: Run full suite + PHPStan**

```bash
composer analyse
composer test
```

Expected: 0 errors, all tests pass (unit tests for use cases already green; no E2E yet for backstage routes).

- [ ] **Step 16.4: Commit**

```bash
git add routes/api.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(http): backstage routes + KernelHarness wiring"
```

---

## Task 17: E2E tests

**Files:**
- Create: `tests/E2E/F011_BackstageApplicationsAccessTest.php`
- Create: `tests/E2E/F012_BackstageDecideApplicationTest.php`
- Create: `tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php`

- [ ] **Step 17.1: Write F011 (access control)**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F011_BackstageApplicationsAccessTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testAnonymousReturns401(): void
    {
        $resp = $this->h->request('GET', '/api/v1/backstage/applications/pending');
        $this->assertSame(401, $resp->status());
    }

    public function testRegisteredUserReturns403(): void
    {
        $u = $this->h->seedUser('user@x.com', 'pass1234', 'registered');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending', $token);
        $this->assertSame(403, $resp->status());
    }

    public function testAdminReturns200(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending', $token);
        $this->assertSame(200, $resp->status());
    }
}
```

- [ ] **Step 17.2: Write F012 (decide flow)**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F012_BackstageDecideApplicationTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testAdminCanApproveMemberApplication(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/' . $app->id()->value() . '/decision', $token, [
            'decision' => 'approved',
            'note'     => 'welcome',
        ]);

        $this->assertSame(200, $resp->status());
        $decisions = $this->h->memberApps->decisions;
        $this->assertArrayHasKey($app->id()->value(), $decisions);
        $this->assertSame('approved', $decisions[$app->id()->value()]['decision']);
    }

    public function testApplicationNotFoundReturns404(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/unknown-id/decision', $token, [
            'decision' => 'approved',
        ]);
        $this->assertSame(404, $resp->status());
    }

    public function testInvalidDecisionReturns422(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Bob', 'b@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/' . $app->id()->value() . '/decision', $token, [
            'decision' => 'maybe',
        ]);
        $this->assertSame(422, $resp->status());
    }
}
```

- [ ] **Step 17.3: Write F013 (GSA-only status change)**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F013_BackstageMembersGsaOnlyStatusTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testTenantAdminCannotChangeMemberStatus(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $this->h->memberDirectory->entries[] = new MemberDirectoryEntry(
            'target-user-id', 'Target', 't@x.com', 'individual', 'active', '1001', 'member', '2026-01-01 00:00:00',
        );

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/members/target-user-id/status', $token, [
            'status' => 'suspended',
            'reason' => 'test',
        ]);

        $this->assertSame(403, $resp->status());
    }

    public function testGsaCanChangeMemberStatus(): void
    {
        // Seed user and directly flip is_platform_admin via InMemoryUserRepository's underlying data if possible,
        // otherwise use a separate GSA helper. For simplicity: use KernelHarness seedUser then patch.
        $gsa = $this->h->seedUser('gsa@x.com', 'pass1234', 'admin');
        // KernelHarness stub: flag this user as platform admin before creating token
        // (see InMemoryUserRepository — if it has a way; otherwise add a helper seedPlatformAdmin)

        // Fallback: attach GSA by updating the isPlatformAdmin on the in-memory record
        $this->h->users->setPlatformAdmin($gsa->id(), true);

        $token = $this->h->tokenFor($gsa);

        $this->h->memberDirectory->entries[] = new MemberDirectoryEntry(
            'target-user-id-2', 'Target2', 't2@x.com', 'individual', 'active', '1002', 'member', '2026-01-01 00:00:00',
        );

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/members/target-user-id-2/status', $token, [
            'status' => 'suspended',
            'reason' => 'payment overdue',
        ]);

        $this->assertSame(200, $resp->status());
        $this->assertCount(1, $this->h->memberDirectory->audit['target-user-id-2'] ?? []);
    }
}
```

- [ ] **Step 17.4: Ensure InMemoryUserRepository::setPlatformAdmin exists**

Check `tests/Support/Fake/InMemoryUserRepository.php`. If it doesn't have a `setPlatformAdmin(UserId, bool)` helper, add one:

```php
public function setPlatformAdmin(UserId $id, bool $value): void
{
    foreach ($this->users as $i => $u) {
        if ($u->id()->value() === $id->value()) {
            // Replace the User with one that has isPlatformAdmin = $value.
            // This requires User to have isPlatformAdmin as a field. If it does not, extend the fake to track a side-map:
            $this->platformAdminFlags[$id->value()] = $value;
            return;
        }
    }
}

/** @var array<string, bool> */
public array $platformAdminFlags = [];
```

Then in `AuthMiddleware` or wherever `ActingUser::$isPlatformAdmin` is materialised in tests, consult this flag. **If this is too invasive**, use a simpler alternative: seed the user directly with a `role === 'global_system_administrator'`-like sentinel AND adjust the test to verify the fake treats it as GSA. Inspect `KernelHarness::seedUser` first — if `$role === 'admin'` already sets `isPlatformAdmin`, use that.

If the existing harness has NO clean way to express "platform admin", add a `seedPlatformAdmin(email): User` helper to `KernelHarness` that sets the `is_platform_admin` column path through the InMemoryUserRepository. Report this as a harness gap if discovered.

- [ ] **Step 17.5: Run E2E + commit**

```bash
composer test:e2e
composer analyse
git add tests/E2E/F011_BackstageApplicationsAccessTest.php tests/E2E/F012_BackstageDecideApplicationTest.php tests/E2E/F013_BackstageMembersGsaOnlyStatusTest.php tests/Support/Fake/InMemoryUserRepository.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Tests(e2e): backstage applications access, decide flow, GSA-only status change"
```

---

## Task 18: Isolation test — BackstageTenantIsolationTest

**Files:**
- Create: `tests/Isolation/BackstageTenantIsolationTest.php`

- [ ] **Step 18.1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Membership\MemberApplicationId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberDirectoryRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class BackstageTenantIsolationTest extends IsolationTestCase
{
    private SqlMemberApplicationRepository $appRepo;
    private SqlMemberDirectoryRepository $dirRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        $this->appRepo = new SqlMemberApplicationRepository($conn);
        $this->dirRepo = new SqlMemberDirectoryRepository($conn);
    }

    private function seedApp(string $tenantSlug, string $name): string
    {
        $id = MemberApplicationId::generate()->value();
        $stmt = $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, '1990-01-01', 'motive', 'pending')"
        );
        $stmt->execute([$id, $tenantSlug, $name, $name . '@x.com']);
        return $id;
    }

    private function seedMember(string $userId, string $tenantSlug): void
    {
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, membership_type, membership_status)
             VALUES ('{$userId}', '{$userId}', '{$userId}@x.com', 'x', '1990-01-01', 'individual', 'active')"
        );
        $this->pdo()->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('{$userId}', (SELECT id FROM tenants WHERE slug = '{$tenantSlug}'), 'member', NOW())"
        );
    }

    public function test_listPendingForTenant_isolates_by_tenant(): void
    {
        $daemsAppId = $this->seedApp('daems', 'Daems App');
        $saheAppId  = $this->seedApp('sahegroup', 'Sahe App');

        $daemsResults = $this->appRepo->listPendingForTenant($this->tenantId('daems'), 100);
        self::assertCount(1, $daemsResults);
        self::assertSame($daemsAppId, $daemsResults[0]->id()->value());
    }

    public function test_findByIdForTenant_rejects_cross_tenant_lookup(): void
    {
        $saheAppId = $this->seedApp('sahegroup', 'Sahe App');

        self::assertNull($this->appRepo->findByIdForTenant($saheAppId, $this->tenantId('daems')));
        self::assertNotNull($this->appRepo->findByIdForTenant($saheAppId, $this->tenantId('sahegroup')));
    }

    public function test_listMembersForTenant_isolates_by_tenant(): void
    {
        $this->seedMember('01958000-0000-7000-8000-daemsmbr0001', 'daems');
        $this->seedMember('01958000-0000-7000-8000-sahembrr0001', 'sahegroup');

        $daemsResult = $this->dirRepo->listMembersForTenant($this->tenantId('daems'), [], 'member_number', 'ASC', 1, 50);
        self::assertSame(1, $daemsResult['total']);
        self::assertSame('01958000-0000-7000-8000-daemsmbr0001', $daemsResult['entries'][0]->userId);
    }
}
```

- [ ] **Step 18.2: Run + commit**

```bash
vendor/bin/phpunit tests/Isolation/BackstageTenantIsolationTest.php
git add tests/Isolation/BackstageTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Tests(isolation): backstage applications + members tenant isolation"
```

---

## Task 19: Documentation

**Files:**
- Modify: `docs/api.md`
- Modify: `docs/database.md`

- [ ] **Step 19.1: Append backstage section to api.md**

Find the end of the "Endpoints" section (or a suitable anchor) and append:

```markdown
## Backstage — admin endpoints

All backstage endpoints require `TenantContextMiddleware` + `AuthMiddleware`. Use cases enforce authorization:
- List / read / decide applications: `ActingUser::isAdminIn(activeTenant)` required
- List / audit members: `ActingUser::isAdminIn(activeTenant)` required
- **Change member status:** `ActingUser::isPlatformAdmin` required (GSA only)

### GET /api/v1/backstage/applications/pending

Query params: `limit` (default 200, max 500).

Response 200:
```json
{"data": {"member": [...], "supporter": [...]}}
```

### POST /api/v1/backstage/applications/{type}/{id}/decision

`type` ∈ `{member, supporter}`. Body: `{"decision": "approved"|"rejected", "note": "..."}`.

Response 200: `{"data": {"success": true}}`
403 `forbidden`, 404 `not_found`, 422 `validation_failed` on invalid decision.

### GET /api/v1/backstage/members

Query params: `status`, `type`, `q`, `sort` (`member_number|name|joined_at|status`), `dir` (`ASC|DESC`), `page`, `per_page` (max 200), `export=csv`.

Response 200:
```json
{"data": [...], "meta": {"page": 1, "per_page": 50, "total": 127, "total_pages": 3}}
```

With `export=csv`: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="members-<tenant>-<date>.csv"`.

### POST /api/v1/backstage/members/{id}/status (GSA only)

Body: `{"status": "active|inactive|suspended|cancelled", "reason": "..."}`.

Response 200: `{"data": {"success": true}}`
403 `forbidden` for non-GSA, 422 `validation_failed` on invalid status/empty reason.

### GET /api/v1/backstage/members/{id}/audit

Query: `limit` (default 25, max 500).

Response 200:
```json
{"data": [{"id": "...", "previousStatus": "active", "newStatus": "suspended", "reason": "...", "performedByName": "...", "createdAt": "..."}]}
```
```

- [ ] **Step 19.2: Append migrations 034 + 035 to database.md**

Append to the migration index table:

```markdown
| `034_add_decision_metadata_to_applications.sql` | Adds `decided_at`, `decided_by`, `decision_note` to `member_applications` and `supporter_applications` |
| `035_create_member_status_audit.sql`            | Creates `member_status_audit` for user `membership_status` change history (distinct from application-scoped `member_register_audit`) |
```

- [ ] **Step 19.3: Commit**

```bash
git add docs/api.md docs/database.md
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Docs: backstage API + migrations 034/035"
```

---

## Task 20: Final verification

- [ ] **Step 20.1: Full clean run**

```bash
composer analyse
composer test
composer test:e2e
```

Expected:
- `composer analyse`: 0 errors at level 9
- `composer test`: all unit + integration + isolation tests pass
- `composer test:e2e`: all E2E tests pass (including new F011–F013)

- [ ] **Step 20.2: Verify commit log is clean**

```bash
git log --oneline dev~20..HEAD
```

Expected ~10-13 commits following the task structure. Every commit authored `Dev Team <dev@daems.org>`.

- [ ] **Step 20.3: Report**

**STATUS:** DONE

- Migrations applied on dev DB: 034, 035
- 5 new backstage endpoints live
- Test counts: X unit+integration + X E2E
- PHPStan: 0 errors
- **Do NOT push** — report commit SHAs and wait for explicit push instruction.

---

## Notes for the executing engineer

- **PR 3 must be merged first** — all `*ForTenant` repository methods and `tenant_id` columns must exist before this plan can run.
- **Every commit uses:** `git -c user.name="Dev Team" -c user.email="dev@daems.org"`. No `Co-Authored-By:`, no auto-push, do not stage `.claude/`.
- **Pre-existing file read rule:** Use the `Read` tool (or equivalent) before using `Edit` on any existing file. The harness tracks this.
- **PHPStan level 9:** narrow mixed values via `is_string()`/`is_int()` helpers. Do NOT bypass with `@phpstan-ignore`.
- **Do not use** `mcp__code-review-graph__build_or_update_graph_tool` — this tool hung a prior session indefinitely on PR 3 Task 1.
- **If a subagent is dispatched for a task**, pass it the relevant Canonical Patterns section + the full task body. Do not have it re-read the whole plan.
