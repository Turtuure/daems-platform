# Events Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `/backstage/events` admin page — CRUD + publish/archive + registrations list + image uploads (server-side WebP re-encode), exactly as specified in `docs/superpowers/specs/2026-04-20-events-admin-design.md`.

**Architecture:** Migration 043 adds `events.status` (draft/published/archived). Nine new Application-layer use cases drive admin operations. `LocalImageStorage` handles GD-based resize + WebP re-encode into `public/uploads/events/<event_id>/<uuid>.webp`. New `MediaController` for uploads; `BackstageController` grows event methods. Frontend adds a single backstage page with a unified create/edit modal + upload widget, relaying through two new PHP proxies.

**Tech Stack:** PHP 8.1, Clean Architecture (Domain / Application / Infrastructure), PDO/MySQL 8, PHPStan level 9, PHPUnit. Frontend: daem-society PHP + vanilla JS. Image processing: GD with WebP support.

**Spec:** `docs/superpowers/specs/2026-04-20-events-admin-design.md`

**Commit identity (every commit):** `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. No `Co-Authored-By` trailer. Never stage `.claude/`. Never auto-push — report SHAs only.

**Project conventions (critical):**
- PHPUnit testsuite names are capitalised: `Unit`, `Integration`, `E2E`. Lowercased silently returns "No tests executed!" — always check the test count in output.
- InMemory fakes live at `tests/Support/Fake/` with namespace `Daems\Tests\Support\Fake` — not under `src/Infrastructure/Adapter/Persistence/InMemory/`.
- DI bindings must be added to BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php`. Missing the production container = 500 in prod, green E2E. See `~/.claude/projects/C--laragon-www-daems-platform/memory/feedback_bootstrap_and_harness_must_both_wire.md`.
- Sql-repo constructors vary: older ones take `Connection`, newer ones take raw `PDO`. Match the ctor signature when wiring (use `$c->make(Connection::class)->pdo()` when PDO is required). Live-smoke with `APP_DEBUG=true` in `.env` + `curl` before declaring a wiring task done.

---

## File Inventory

### Backend — new

**Migrations:**
- `database/migrations/043_add_status_to_events.sql`

**Domain:**
- `src/Domain/Storage/ImageStorageInterface.php`

**Application — use cases:**
- `src/Application/Backstage/ListEventsForAdmin/{ListEventsForAdmin.php, ListEventsForAdminInput.php, ListEventsForAdminOutput.php}`
- `src/Application/Backstage/CreateEvent/{CreateEvent.php, CreateEventInput.php, CreateEventOutput.php}`
- `src/Application/Backstage/UpdateEvent/{UpdateEvent.php, UpdateEventInput.php, UpdateEventOutput.php}`
- `src/Application/Backstage/PublishEvent/{PublishEvent.php, PublishEventInput.php}`
- `src/Application/Backstage/ArchiveEvent/{ArchiveEvent.php, ArchiveEventInput.php}`
- `src/Application/Backstage/ListEventRegistrations/{ListEventRegistrations.php, ListEventRegistrationsInput.php, ListEventRegistrationsOutput.php}`
- `src/Application/Backstage/UnregisterUserFromEvent/{UnregisterUserFromEvent.php, UnregisterUserFromEventInput.php}`
- `src/Application/Backstage/UploadEventImage/{UploadEventImage.php, UploadEventImageInput.php, UploadEventImageOutput.php}`
- `src/Application/Backstage/DeleteEventImage/{DeleteEventImage.php, DeleteEventImageInput.php}`

**Infrastructure:**
- `src/Infrastructure/Storage/LocalImageStorage.php`
- `src/Infrastructure/Adapter/Api/Controller/MediaController.php`

**Test fakes:**
- `tests/Support/Fake/InMemoryImageStorage.php`

**Tests:**
- `tests/Integration/Migration/Migration043Test.php`
- `tests/Unit/Application/Backstage/ListEventsForAdminTest.php`
- `tests/Unit/Application/Backstage/CreateEventTest.php`
- `tests/Unit/Application/Backstage/UpdateEventTest.php`
- `tests/Unit/Application/Backstage/PublishEventTest.php`
- `tests/Unit/Application/Backstage/ArchiveEventTest.php`
- `tests/Unit/Application/Backstage/ListEventRegistrationsTest.php`
- `tests/Unit/Application/Backstage/UnregisterUserFromEventTest.php`
- `tests/Unit/Application/Backstage/UploadEventImageTest.php`
- `tests/Unit/Application/Backstage/DeleteEventImageTest.php`
- `tests/Integration/Application/EventsAdminIntegrationTest.php`
- `tests/Isolation/EventsAdminTenantIsolationTest.php`
- `tests/E2E/Backstage/EventAdminEndpointsTest.php`
- `tests/E2E/Backstage/EventUploadTest.php`

### Backend — modified

- `src/Domain/Event/Event.php` — add `?string $status` field + getter.
- `src/Domain/Event/EventRepositoryInterface.php` — add 4 new methods.
- `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php` — implement new methods; `listForTenant` narrows to `status='published'`; hydrate includes `status`; `save` persists `status`.
- `tests/Support/Fake/InMemoryEventRepository.php` — mirror new methods + status filtering.
- `src/Infrastructure/Adapter/Api/Controller/BackstageController.php` — add event methods.
- `routes/api.php` — add 9 new routes.
- `bootstrap/app.php` — bind new use cases, controllers, storage.
- `tests/Support/KernelHarness.php` — bind InMemory equivalents.
- `tests/Isolation/IsolationTestCase.php` — bump `runMigrationsUpTo(43)`.

### Frontend daem-society — new

- `public/pages/backstage/events/index.php` — list page.
- `public/pages/backstage/events/event-modal.js` — create/edit modal logic.
- `public/pages/backstage/events/event-modal.css` — modal + upload widget styles.
- `public/pages/backstage/events/upload-widget.js` — drag-drop upload + preview.
- `public/api/backstage/events.php` — JSON relay for list/create/update/publish/archive/registrations/remove.
- `public/api/backstage/event-upload.php` — multipart relay (forwards `$_FILES` via cURL).
- `public/uploads/events/.gitkeep` — ensures directory exists in fresh checkouts.

### Frontend daem-society — modified

- `public/pages/events/detail/index.php` (or wherever the public event page renders) — verify gallery-thumb markup matches existing lightbox pattern; prefix image URLs with platform host if needed.

---

## Task Order (dependency-correct)

Tasks 1 (migration) blocks all backend. 2–3 (domain + repo ifc) block 4–12 (use cases). 13 (controllers) blocks 14 (routes). 15 (wiring) blocks integration/E2E tests. Frontend tasks (18–22) can start once backend endpoints are live.

---

### Task 1: Migration 043 — `events.status` column

**Files:**
- Create: `database/migrations/043_add_status_to_events.sql`
- Create: `tests/Integration/Migration/Migration043Test.php`
- Modify: `tests/Isolation/IsolationTestCase.php`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Migration/Migration043Test.php`:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration043Test extends MigrationTestCase
{
    public function test_status_column_exists_with_enum_and_default_published(): void
    {
        $this->runMigrationsUpTo(42);
        $this->runMigration('043_add_status_to_events.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'status'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString("enum('draft','published','archived')", strtolower((string) $row['COLUMN_TYPE']));
        self::assertSame('published', $row['COLUMN_DEFAULT']);
        self::assertSame('NO', $row['IS_NULLABLE']);
    }

    public function test_existing_rows_backfill_to_published(): void
    {
        $this->runMigrationsUpTo(42);
        // Seed an event before running 043 (no status column yet).
        $this->pdo->exec(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date, is_online)
             VALUES ('01959900-0000-7000-8000-000000000001',
                     (SELECT id FROM tenants LIMIT 1),
                     'legacy-evt','Legacy','upcoming','2026-06-01',0)"
        );

        $this->runMigration('043_add_status_to_events.sql');

        $status = $this->pdo->query(
            "SELECT status FROM events WHERE slug = 'legacy-evt'"
        )?->fetch(PDO::FETCH_ASSOC)['status'] ?? null;
        self::assertSame('published', $status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration043Test.php`
Expected: FAIL — migration file does not exist.

- [ ] **Step 3: Create the migration**

`database/migrations/043_add_status_to_events.sql`:
```sql
ALTER TABLE events
    ADD COLUMN status ENUM('draft','published','archived')
        NOT NULL DEFAULT 'published'
        AFTER type;
```

(No explicit `UPDATE` needed — the DDL with `DEFAULT 'published' NOT NULL` backfills existing rows to `published` automatically in MySQL 8 when adding a non-null column with a default.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Migration/Migration043Test.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Bump IsolationTestCase**

Edit `tests/Isolation/IsolationTestCase.php` line 18: change `$this->runMigrationsUpTo(42);` to `$this->runMigrationsUpTo(43);`.

- [ ] **Step 6: Apply migration to dev DB**

Run:
```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -h 127.0.0.1 -u root -psalasana daems_db < database/migrations/043_add_status_to_events.sql
```

Verify:
```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -h 127.0.0.1 -u root -psalasana daems_db -e "SELECT status, COUNT(*) FROM events GROUP BY status;"
```
Expected: all existing rows report `status = 'published'`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/043_add_status_to_events.sql tests/Integration/Migration/Migration043Test.php tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): migration 043 — events.status column (draft/published/archived)"
```

---

### Task 2: Domain — Event entity gains `status`

**Files:**
- Modify: `src/Domain/Event/Event.php`

- [ ] **Step 1: Add `$status` field, getter, and constructor arg**

Replace `src/Domain/Event/Event.php` with:
```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

final class Event
{
    public function __construct(
        private readonly EventId $id,
        private readonly TenantId $tenantId,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $type,
        private readonly string $date,
        private readonly ?string $time,
        private readonly ?string $location,
        private readonly bool $online,
        private readonly ?string $description,
        private readonly ?string $heroImage,
        private readonly array $gallery,
        private readonly string $status = 'published',
    ) {}

    public function id(): EventId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function type(): string { return $this->type; }
    public function date(): string { return $this->date; }
    public function time(): ?string { return $this->time; }
    public function location(): ?string { return $this->location; }
    public function online(): bool { return $this->online; }
    public function description(): ?string { return $this->description; }
    public function heroImage(): ?string { return $this->heroImage; }
    public function gallery(): array { return $this->gallery; }
    public function status(): string { return $this->status; }
}
```

Default `'published'` keeps existing call-sites (constructing Events from legacy data) valid.

- [ ] **Step 2: Run PHPStan to verify nothing broke**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 3: Run Unit tests**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: same test count as before, all pass.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Event/Event.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): Event entity gains status field (default published)"
```

---

### Task 3: EventRepositoryInterface + SQL + InMemory — add admin methods

**Files:**
- Modify: `src/Domain/Event/EventRepositoryInterface.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php`
- Modify: `tests/Support/Fake/InMemoryEventRepository.php`

- [ ] **Step 1: Extend the interface**

Replace `src/Domain/Event/EventRepositoryInterface.php` with:
```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

interface EventRepositoryInterface
{
    /** @return Event[] — PUBLIC path: only published events. */
    public function listForTenant(TenantId $tenantId, ?string $type = null): array;

    /**
     * @param array{status?:string,type?:string} $filters
     * @return Event[] — ADMIN path: all statuses, optional filters.
     */
    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Event;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event;

    public function save(Event $event): void;

    /** @param array<string,mixed> $fields */
    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void;

    public function setStatus(string $id, TenantId $tenantId, string $status): void;

    public function register(EventRegistration $registration): void;

    public function unregister(string $eventId, string $userId): void;

    public function isRegistered(string $eventId, string $userId): bool;

    public function countRegistrations(string $eventId): int;

    /** @return array<array{event_id:string,slug:string,title:string,type:string,date:string}> */
    public function findRegistrationsByUserId(string $userId): array;

    /** @return list<array{user_id:string,name:string,email:string,registered_at:string}> */
    public function listRegistrationsForEvent(string $eventId, TenantId $tenantId): array;
}
```

- [ ] **Step 2: Run PHPStan to see what broke**

Run: `composer analyse`
Expected: FAIL — `SqlEventRepository` and `InMemoryEventRepository` no longer satisfy the interface.

- [ ] **Step 3: Extend SqlEventRepository**

In `src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php`:

Narrow public `listForTenant` — change the SQL to include `AND status = 'published'`:
```php
public function listForTenant(TenantId $tenantId, ?string $type = null): array
{
    if ($type !== null) {
        $rows = $this->db->query(
            'SELECT * FROM events WHERE tenant_id = ? AND status = ? AND type = ? ORDER BY event_date ASC',
            [$tenantId->value(), 'published', $type],
        );
    } else {
        $rows = $this->db->query(
            'SELECT * FROM events WHERE tenant_id = ? AND status = ? ORDER BY event_date ASC',
            [$tenantId->value(), 'published'],
        );
    }

    return array_map($this->hydrate(...), $rows);
}
```

Add new methods (at end of the class, before `hydrate`):
```php
public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
{
    $sql = 'SELECT * FROM events WHERE tenant_id = ?';
    $params = [$tenantId->value()];
    if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
        $sql .= ' AND status = ?';
        $params[] = $filters['status'];
    }
    if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
        $sql .= ' AND type = ?';
        $params[] = $filters['type'];
    }
    $sql .= ' ORDER BY event_date DESC';
    return array_map($this->hydrate(...), $this->db->query($sql, $params));
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?Event
{
    $row = $this->db->queryOne(
        'SELECT * FROM events WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId->value()],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
{
    if ($fields === []) {
        return;
    }
    $allowed = ['title','type','event_date','event_time','location','is_online','description','hero_image','gallery_json'];
    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        if (!in_array($col, $allowed, true)) {
            continue;
        }
        $sets[] = "{$col} = ?";
        $params[] = $val;
    }
    if ($sets === []) {
        return;
    }
    $params[] = $id;
    $params[] = $tenantId->value();
    $this->db->execute(
        'UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
        $params,
    );
}

public function setStatus(string $id, TenantId $tenantId, string $status): void
{
    if (!in_array($status, ['draft','published','archived'], true)) {
        throw new \DomainException('invalid_event_status');
    }
    $this->db->execute(
        'UPDATE events SET status = ? WHERE id = ? AND tenant_id = ?',
        [$status, $id, $tenantId->value()],
    );
}

public function listRegistrationsForEvent(string $eventId, TenantId $tenantId): array
{
    /** @var list<array{user_id:string,name:string,email:string,registered_at:string}> $rows */
    $rows = $this->db->query(
        'SELECT er.user_id AS user_id, u.name AS name, u.email AS email,
                DATE_FORMAT(er.registered_at, "%Y-%m-%d %H:%i:%s") AS registered_at
         FROM event_registrations er
         JOIN events e ON e.id = er.event_id
         JOIN users u ON u.id = er.user_id
         WHERE er.event_id = ? AND e.tenant_id = ?
         ORDER BY er.registered_at DESC',
        [$eventId, $tenantId->value()],
    );
    return $rows;
}
```

In `save()`, extend the INSERT to include `status`:
```php
'INSERT INTO events
    (id, tenant_id, slug, title, type, event_date, event_time, location, is_online, description, hero_image, gallery_json, status)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
 ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    type = VALUES(type),
    event_date = VALUES(event_date),
    event_time = VALUES(event_time),
    location = VALUES(location),
    is_online = VALUES(is_online),
    description = VALUES(description),
    hero_image = VALUES(hero_image),
    gallery_json = VALUES(gallery_json),
    status = VALUES(status)'
```
and append `$event->status(),` to the params array.

In `hydrate()`, add `status` to the Event constructor call:
```php
return new Event(
    // ... existing args ...
    $gallery,
    self::str($row, 'status'),
);
```

- [ ] **Step 4: Extend InMemoryEventRepository**

Read `tests/Support/Fake/InMemoryEventRepository.php` first. Add the new interface methods using the same in-memory array the existing methods use. Implement filtering by status inside the new `listAllStatusesForTenant`. `listForTenant` must filter to `status='published'`.

Minimum added methods:
```php
public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
{
    $out = [];
    foreach ($this->byId as $e) {
        if (!$e->tenantId()->equals($tenantId)) continue;
        if (isset($filters['status']) && $e->status() !== $filters['status']) continue;
        if (isset($filters['type']) && $e->type() !== $filters['type']) continue;
        $out[] = $e;
    }
    return $out;
}

public function findByIdForTenant(string $id, TenantId $tenantId): ?Event
{
    $e = $this->byId[$id] ?? null;
    return ($e !== null && $e->tenantId()->equals($tenantId)) ? $e : null;
}

public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
{
    $e = $this->findByIdForTenant($id, $tenantId);
    if ($e === null) return;
    // Build replacement Event with merged fields.
    // Map DB columns back to ctor args.
    $merged = [
        'title' => $fields['title'] ?? $e->title(),
        'type' => $fields['type'] ?? $e->type(),
        'event_date' => $fields['event_date'] ?? $e->date(),
        'event_time' => array_key_exists('event_time', $fields) ? $fields['event_time'] : $e->time(),
        'location' => array_key_exists('location', $fields) ? $fields['location'] : $e->location(),
        'is_online' => array_key_exists('is_online', $fields) ? (bool) $fields['is_online'] : $e->online(),
        'description' => array_key_exists('description', $fields) ? $fields['description'] : $e->description(),
        'hero_image' => array_key_exists('hero_image', $fields) ? $fields['hero_image'] : $e->heroImage(),
        'gallery_json' => array_key_exists('gallery_json', $fields) ? json_decode((string) $fields['gallery_json'], true) ?: [] : $e->gallery(),
    ];
    $this->byId[$id] = new Event(
        $e->id(), $e->tenantId(), $e->slug(),
        (string) $merged['title'], (string) $merged['type'], (string) $merged['event_date'],
        $merged['event_time'] === null ? null : (string) $merged['event_time'],
        $merged['location'] === null ? null : (string) $merged['location'],
        (bool) $merged['is_online'],
        $merged['description'] === null ? null : (string) $merged['description'],
        $merged['hero_image'] === null ? null : (string) $merged['hero_image'],
        is_array($merged['gallery_json']) ? $merged['gallery_json'] : [],
        $e->status(),
    );
}

public function setStatus(string $id, TenantId $tenantId, string $status): void
{
    $e = $this->findByIdForTenant($id, $tenantId);
    if ($e === null) return;
    $this->byId[$id] = new Event(
        $e->id(), $e->tenantId(), $e->slug(), $e->title(), $e->type(), $e->date(),
        $e->time(), $e->location(), $e->online(), $e->description(),
        $e->heroImage(), $e->gallery(), $status,
    );
}

/** @var list<array{user_id:string,event_id:string,name:string,email:string,registered_at:string}> */
public array $adminRegistrations = [];

public function listRegistrationsForEvent(string $eventId, TenantId $tenantId): array
{
    $e = $this->findByIdForTenant($eventId, $tenantId);
    if ($e === null) return [];
    $out = [];
    foreach ($this->adminRegistrations as $r) {
        if ($r['event_id'] === $eventId) {
            $out[] = [
                'user_id' => $r['user_id'],
                'name' => $r['name'],
                'email' => $r['email'],
                'registered_at' => $r['registered_at'],
            ];
        }
    }
    return $out;
}
```

Adjust `listForTenant` to filter by `status='published'`:
```php
public function listForTenant(TenantId $tenantId, ?string $type = null): array
{
    $out = [];
    foreach ($this->byId as $e) {
        if (!$e->tenantId()->equals($tenantId)) continue;
        if ($e->status() !== 'published') continue;
        if ($type !== null && $e->type() !== $type) continue;
        $out[] = $e;
    }
    return $out;
}
```

Add test helper `seedAdminRegistration(eventId, userId, name, email, registeredAt)` that appends to `$adminRegistrations`.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 6: Run Unit + Integration + E2E**

Run: `vendor/bin/phpunit --testsuite Unit && vendor/bin/phpunit --testsuite E2E`
Expected: all pass. If any existing test depended on `listForTenant` returning draft events (unlikely — drafts don't exist yet), fix it to seed with status='published' or use the new admin method.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Event/EventRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlEventRepository.php tests/Support/Fake/InMemoryEventRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): EventRepository — admin listing + updateForTenant + setStatus + listRegistrationsForEvent; public listForTenant narrows to published"
```

---

### Task 4: `ImageStorageInterface` + `LocalImageStorage` + InMemory fake

**Files:**
- Create: `src/Domain/Storage/ImageStorageInterface.php`
- Create: `src/Infrastructure/Storage/LocalImageStorage.php`
- Create: `tests/Support/Fake/InMemoryImageStorage.php`

- [ ] **Step 1: Create the interface**

`src/Domain/Storage/ImageStorageInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Storage;

interface ImageStorageInterface
{
    /**
     * Store an uploaded image at a canonical location under the given bucket/event.
     * Implementations MUST validate the input is a supported image (JPEG/PNG/WebP/GIF),
     * resize to max 2048px longest edge, strip EXIF, and re-encode as WebP when possible.
     *
     * @param string $eventId UUID7 of the event the image belongs to
     * @param string $tmpPath path to a readable file on disk (typically $_FILES['file']['tmp_name'])
     * @param string $originalMime MIME type as reported by the upload
     * @return string URL path where the stored image can be fetched (e.g. /uploads/events/{id}/{uuid}.webp)
     * @throws \Daems\Domain\Storage\ImageStorageException on invalid input or IO failure
     */
    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string;

    /**
     * Delete a stored image by the URL path returned from storeEventImage.
     * Idempotent: returns silently if the file does not exist.
     */
    public function deleteByUrl(string $url): void;
}
```

Also create `src/Domain/Storage/ImageStorageException.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Domain\Storage;
final class ImageStorageException extends \RuntimeException {}
```

- [ ] **Step 2: Create `LocalImageStorage`**

`src/Infrastructure/Storage/LocalImageStorage.php`:
```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Storage;

use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Storage\ImageStorageException;
use Daems\Domain\Storage\ImageStorageInterface;

final class LocalImageStorage implements ImageStorageInterface
{
    private const MAX_EDGE = 2048;
    private const WEBP_QUALITY = 85;
    private const JPEG_FALLBACK_QUALITY = 85;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly string $publicRoot,
        private readonly string $urlPrefix,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string
    {
        if (!in_array($originalMime, self::ALLOWED_MIME, true)) {
            throw new ImageStorageException('unsupported_mime');
        }
        if (!is_readable($tmpPath)) {
            throw new ImageStorageException('tmp_not_readable');
        }

        $img = $this->openImage($tmpPath, $originalMime);
        if ($img === null) {
            throw new ImageStorageException('cannot_decode_image');
        }

        // Reject animated GIF.
        if ($originalMime === 'image/gif') {
            $raw = @file_get_contents($tmpPath) ?: '';
            if (substr_count($raw, "\x00\x21\xF9\x04") > 1) {
                imagedestroy($img);
                throw new ImageStorageException('animated_gif_not_supported');
            }
        }

        $img = $this->resizeIfTooLarge($img);

        $dir = rtrim($this->publicRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads'
             . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $eventId;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            imagedestroy($img);
            throw new ImageStorageException('mkdir_failed');
        }

        $useWebp = $this->supportsWebp();
        $ext = $useWebp ? 'webp' : 'jpg';
        $fileName = $this->ids->generate() . '.' . $ext;
        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $ok = $useWebp
            ? imagewebp($img, $filePath, self::WEBP_QUALITY)
            : imagejpeg($img, $filePath, self::JPEG_FALLBACK_QUALITY);
        imagedestroy($img);
        if (!$ok) {
            throw new ImageStorageException('write_failed');
        }

        return rtrim($this->urlPrefix, '/') . '/uploads/events/' . $eventId . '/' . $fileName;
    }

    public function deleteByUrl(string $url): void
    {
        // URL shape: {prefix}/uploads/events/{eventId}/{fileName}
        $relative = parse_url($url, PHP_URL_PATH);
        if (!is_string($relative)) return;
        if (!preg_match('#^/uploads/events/[0-9a-f-]{36}/[A-Za-z0-9-]+\.(webp|jpg|jpeg|png|gif)$#', $relative)) {
            return;
        }
        $absolute = rtrim($this->publicRoot, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function openImage(string $path, string $mime): \GdImage|null
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png'  => @imagecreatefrompng($path)  ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/gif'  => @imagecreatefromgif($path)  ?: null,
            default      => null,
        };
    }

    private function resizeIfTooLarge(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= self::MAX_EDGE) {
            return $img;
        }
        $scale = self::MAX_EDGE / $long;
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return $img;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $dst;
    }

    private function supportsWebp(): bool
    {
        $info = gd_info();
        $webp = $info['WebP Support'] ?? false;
        return $webp === true;
    }
}
```

- [ ] **Step 3: Create `InMemoryImageStorage` test fake**

`tests/Support/Fake/InMemoryImageStorage.php`:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Storage\ImageStorageInterface;

final class InMemoryImageStorage implements ImageStorageInterface
{
    /** @var array<string, string> url => mime (for assertions) */
    public array $stored = [];

    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string
    {
        $url = '/uploads/events/' . $eventId . '/' . bin2hex(random_bytes(8)) . '.webp';
        $this->stored[$url] = $originalMime;
        return $url;
    }

    public function deleteByUrl(string $url): void
    {
        unset($this->stored[$url]);
    }
}
```

- [ ] **Step 4: Run PHPStan**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Storage/ src/Infrastructure/Storage/ tests/Support/Fake/InMemoryImageStorage.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(storage): ImageStorageInterface + LocalImageStorage (GD resize + WebP re-encode) + InMemory fake"
```

---

### Task 5: `ListEventsForAdmin` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/ListEventsForAdmin/ListEventsForAdminInput.php`
- Create: `src/Application/Backstage/ListEventsForAdmin/ListEventsForAdminOutput.php`
- Create: `src/Application/Backstage/ListEventsForAdmin/ListEventsForAdmin.php`
- Create: `tests/Unit/Application/Backstage/ListEventsForAdminTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Application/Backstage/ListEventsForAdminTest.php`:
```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin;
use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class ListEventsForAdminTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(platformAdmin: false, role: null), null, null),
        );
    }

    public function test_returns_all_statuses_for_own_tenant(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('a', 'Draft one', 'upcoming', 'draft'));
        $repo->save($this->makeEvent('b', 'Pub one', 'upcoming', 'published'));
        $repo->save($this->makeEvent('c', 'Arch one', 'past', 'archived'));
        $repo->save($this->makeOtherTenantEvent('z', 'Other', 'upcoming', 'published'));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), null, null),
        );
        self::assertCount(3, $out->items);
        $titles = array_column($out->items, 'title');
        self::assertContains('Draft one', $titles);
        self::assertContains('Pub one', $titles);
        self::assertContains('Arch one', $titles);
        self::assertNotContains('Other', $titles);
    }

    public function test_filters_by_status_and_type(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('a', 'DA', 'upcoming', 'draft'));
        $repo->save($this->makeEvent('b', 'DB', 'past', 'draft'));
        $repo->save($this->makeEvent('c', 'PA', 'upcoming', 'published'));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), 'draft', 'upcoming'),
        );
        self::assertCount(1, $out->items);
        self::assertSame('DA', $out->items[0]['title']);
    }

    public function test_output_contains_registration_count(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('a', 'E', 'upcoming', 'published'));
        // Seed a registration (pretend a user registered)
        $repo->register(new \Daems\Domain\Event\EventRegistration(
            'r1', 'a', 'u1', '2026-04-20 12:00:00',
        ));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), null, null),
        );
        self::assertSame(1, $out->items[0]['registration_count']);
    }

    private function makeEvent(string $idSuffix, string $title, string $type, string $status): Event
    {
        return new Event(
            EventId::fromString('01959900-0000-7000-8000-00000000000' . substr($idSuffix, 0, 1)),
            TenantId::fromString(self::TENANT),
            "slug-{$idSuffix}",
            $title, $type, '2026-06-01', null, 'HQ', false, 'Desc',
            null, [], $status,
        );
    }

    private function makeOtherTenantEvent(string $idSuffix, string $title, string $type, string $status): Event
    {
        return new Event(
            EventId::fromString('01959900-0000-7000-8000-00000000001' . substr($idSuffix, 0, 1)),
            TenantId::fromString(self::OTHER_TENANT),
            "slug-other-{$idSuffix}",
            $title, $type, '2026-06-01', null, 'HQ', false, 'Desc',
            null, [], $status,
        );
    }
}
```

The exact `EventId::fromString` values must be valid UUID7. If the ones above fail validation, use `EventId::generate()` instead inside the helper methods.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/ListEventsForAdminTest.php`
Expected: class-not-found.

- [ ] **Step 3: Implement Input + Output + UseCase**

`ListEventsForAdminInput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListEventsForAdmin;
use Daems\Domain\Auth\ActingUser;

final class ListEventsForAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly ?string $status,
        public readonly ?string $type,
    ) {}
}
```

`ListEventsForAdminOutput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListEventsForAdmin;

final class ListEventsForAdminOutput
{
    /** @param list<array{id:string,slug:string,title:string,type:string,status:string,event_date:string,event_time:?string,location:?string,is_online:bool,registration_count:int}> $items */
    public function __construct(public readonly array $items) {}

    public function toArray(): array { return ['items' => $this->items, 'total' => count($this->items)]; }
}
```

`ListEventsForAdmin.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\ListEventsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;

final class ListEventsForAdmin
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(ListEventsForAdminInput $input): ListEventsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $filters = [];
        if ($input->status !== null && $input->status !== '') $filters['status'] = $input->status;
        if ($input->type !== null && $input->type !== '') $filters['type'] = $input->type;

        $items = [];
        foreach ($this->events->listAllStatusesForTenant($tenantId, $filters) as $e) {
            $items[] = [
                'id'                 => $e->id()->value(),
                'slug'               => $e->slug(),
                'title'              => $e->title(),
                'type'               => $e->type(),
                'status'             => $e->status(),
                'event_date'         => $e->date(),
                'event_time'         => $e->time(),
                'location'           => $e->location(),
                'is_online'          => $e->online(),
                'registration_count' => $this->events->countRegistrations($e->id()->value()),
            ];
        }
        return new ListEventsForAdminOutput($items);
    }
}
```

- [ ] **Step 4: Run tests to pass**

Run: `vendor/bin/phpunit tests/Unit/Application/Backstage/ListEventsForAdminTest.php`
Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListEventsForAdmin/ tests/Unit/Application/Backstage/ListEventsForAdminTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): ListEventsForAdmin use case (all statuses + filters + registration count)"
```

---

### Task 6: `CreateEvent` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/CreateEvent/{CreateEvent.php, CreateEventInput.php, CreateEventOutput.php}`
- Create: `tests/Unit/Application/Backstage/CreateEventTest.php`

- [ ] **Step 1: Write failing test covering:**
  - Rejects non-admin (ForbiddenException).
  - Missing title/type/date → ValidationException with specific field.
  - Title < 3 or > 200 chars → ValidationException.
  - Description missing or < 20 chars → ValidationException.
  - Slug auto-generated from title (lowercase, hyphen-separated, ASCII-only).
  - On slug collision within tenant, append `-<short-id>`.
  - Created event has `status='draft'` by default, unless input sets `published=true`.
  - Save called on repo with correct fields.

Write the full test file following the pattern in Task 5's test. Cover each rule in its own test method.

- [ ] **Step 2: Run test — expect failure**

- [ ] **Step 3: Implement Input + Output + UseCase**

`CreateEventInput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\CreateEvent;
use Daems\Domain\Auth\ActingUser;

final class CreateEventInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $title,
        public readonly string $type,
        public readonly string $eventDate,
        public readonly ?string $eventTime,
        public readonly ?string $location,
        public readonly bool $isOnline,
        public readonly string $description,
        public readonly bool $publishImmediately = false,
    ) {}
}
```

`CreateEventOutput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\CreateEvent;
final class CreateEventOutput
{
    public function __construct(public readonly string $id, public readonly string $slug) {}
    public function toArray(): array { return ['id' => $this->id, 'slug' => $this->slug]; }
}
```

`CreateEvent.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\CreateEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;

final class CreateEvent
{
    private const ALLOWED_TYPES = ['upcoming', 'past', 'online'];

    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(CreateEventInput $input): CreateEventOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $errors = [];
        if (strlen($input->title) < 3 || strlen($input->title) > 200) {
            $errors['title'] = 'length_3_to_200';
        }
        if (!in_array($input->type, self::ALLOWED_TYPES, true)) {
            $errors['type'] = 'invalid_value';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
            $errors['event_date'] = 'invalid_date';
        }
        if (strlen(trim($input->description)) < 20) {
            $errors['description'] = 'min_20_chars';
        }
        if (!$input->isOnline && ($input->location === null || trim($input->location) === '')) {
            $errors['location'] = 'required_when_not_online';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $slug = $this->uniqueSlug($input->title, $tenantId);
        $eventId = EventId::fromString($this->ids->generate());

        $event = new Event(
            $eventId, $tenantId, $slug,
            $input->title, $input->type, $input->eventDate,
            $input->eventTime ?: null,
            $input->location ?: null,
            $input->isOnline,
            $input->description,
            null, [],
            $input->publishImmediately ? 'published' : 'draft',
        );
        $this->events->save($event);

        return new CreateEventOutput($eventId->value(), $slug);
    }

    private function uniqueSlug(string $title, \Daems\Domain\Tenant\TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'event';
        $base = trim((string) $base, '-');
        if ($base === '') $base = 'event';
        if ($this->events->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $suffix = substr($this->ids->generate(), 0, 8);
            $candidate = $base . '-' . $suffix;
            if ($this->events->findBySlugForTenant($candidate, $tenantId) === null) {
                return $candidate;
            }
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
```

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/CreateEvent/ tests/Unit/Application/Backstage/CreateEventTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): CreateEvent use case (validation + slug generation + default draft)"
```

---

### Task 7: `UpdateEvent` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/UpdateEvent/{UpdateEvent.php, UpdateEventInput.php, UpdateEventOutput.php}`
- Create: `tests/Unit/Application/Backstage/UpdateEventTest.php`

- [ ] **Step 1: Failing test covering:**
  - Rejects non-admin.
  - Not-found event in same tenant → `NotFoundException('event_not_found')`.
  - Tenant mismatch hits same 404.
  - Partial update: only passed fields touch the DB; others unchanged.
  - Validation errors for bad title/description/date (same rules as Create).
  - Updates to `hero_image` and `gallery_json` accepted.
  - Does NOT allow changing `status` (that's Publish/Archive).

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement**

`UpdateEventInput.php` — constructor takes `ActingUser $acting, string $eventId`, plus nullable fields for each updatable column: `?string $title`, `?string $type`, `?string $eventDate`, `?string $eventTime`, `?string $location`, `?bool $isOnline`, `?string $description`, `?string $heroImage`, `?array $gallery`. Nullable-with-null-as-unset semantic — if field is `null`, it's not updated.

Note: this means you cannot use this Input to explicitly set `hero_image = NULL`. For that case, use `DeleteEventImage` (Task 13). Document this in the class docblock.

`UpdateEventOutput.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\UpdateEvent;
final class UpdateEventOutput
{
    public function __construct(public readonly string $id) {}
    public function toArray(): array { return ['id' => $this->id, 'updated' => true]; }
}
```

`UpdateEvent.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\UpdateEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class UpdateEvent
{
    private const ALLOWED_TYPES = ['upcoming', 'past', 'online'];

    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(UpdateEventInput $input): UpdateEventOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        $errors = [];
        $fields = [];
        if ($input->title !== null) {
            if (strlen($input->title) < 3 || strlen($input->title) > 200) {
                $errors['title'] = 'length_3_to_200';
            } else {
                $fields['title'] = $input->title;
            }
        }
        if ($input->type !== null) {
            if (!in_array($input->type, self::ALLOWED_TYPES, true)) {
                $errors['type'] = 'invalid_value';
            } else {
                $fields['type'] = $input->type;
            }
        }
        if ($input->eventDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
                $errors['event_date'] = 'invalid_date';
            } else {
                $fields['event_date'] = $input->eventDate;
            }
        }
        if ($input->eventTime !== null) $fields['event_time'] = $input->eventTime === '' ? null : $input->eventTime;
        if ($input->location !== null)  $fields['location']   = $input->location === '' ? null : $input->location;
        if ($input->isOnline !== null)  $fields['is_online']  = $input->isOnline ? 1 : 0;
        if ($input->description !== null) {
            if (strlen(trim($input->description)) < 20) {
                $errors['description'] = 'min_20_chars';
            } else {
                $fields['description'] = $input->description;
            }
        }
        if ($input->heroImage !== null) $fields['hero_image'] = $input->heroImage === '' ? null : $input->heroImage;
        if ($input->gallery !== null)   $fields['gallery_json'] = json_encode(array_values($input->gallery));

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($fields !== []) {
            $this->events->updateForTenant($event->id()->value(), $tenantId, $fields);
        }
        return new UpdateEventOutput($event->id()->value());
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/UpdateEvent/ tests/Unit/Application/Backstage/UpdateEventTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): UpdateEvent use case (partial update, validation, tenant-scoped)"
```

---

### Task 8: `PublishEvent` + `ArchiveEvent` use cases (TDD, combined)

**Files:**
- Create: `src/Application/Backstage/PublishEvent/{PublishEvent.php, PublishEventInput.php}`
- Create: `src/Application/Backstage/ArchiveEvent/{ArchiveEvent.php, ArchiveEventInput.php}`
- Create: `tests/Unit/Application/Backstage/PublishEventTest.php`
- Create: `tests/Unit/Application/Backstage/ArchiveEventTest.php`

- [ ] **Step 1: Failing tests**

Both use cases: take `ActingUser $acting, string $eventId` → call `$events->setStatus($id, $tenantId, 'published' or 'archived')`. Cover:
- Non-admin → Forbidden.
- Not-found event → NotFoundException.
- Successful call sets status correctly (assert via `$repo->findByIdForTenant`).

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement both**

Both follow the same skeleton. Example for PublishEvent:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\PublishEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class PublishEvent
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(PublishEventInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if ($this->events->findByIdForTenant($input->eventId, $tenantId) === null) {
            throw new NotFoundException('event_not_found');
        }
        $this->events->setStatus($input->eventId, $tenantId, 'published');
    }
}
```
ArchiveEvent — identical but sets `'archived'`.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/PublishEvent/ src/Application/Backstage/ArchiveEvent/ tests/Unit/Application/Backstage/PublishEventTest.php tests/Unit/Application/Backstage/ArchiveEventTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): PublishEvent + ArchiveEvent use cases"
```

---

### Task 9: `ListEventRegistrations` + `UnregisterUserFromEvent` (TDD)

**Files:**
- Create: `src/Application/Backstage/ListEventRegistrations/{ListEventRegistrations.php, Input.php, Output.php}`
- Create: `src/Application/Backstage/UnregisterUserFromEvent/{UnregisterUserFromEvent.php, Input.php}`
- Create: `tests/Unit/Application/Backstage/ListEventRegistrationsTest.php`
- Create: `tests/Unit/Application/Backstage/UnregisterUserFromEventTest.php`

- [ ] **Step 1: Failing tests**

ListEventRegistrations: returns `items` with `{user_id, name, email, registered_at}` rows from the repo's `listRegistrationsForEvent`. Forbidden if non-admin. NotFound if event does not exist in acting's tenant.

UnregisterUserFromEvent: delegates to `$events->unregister($eventId, $userId)`. Forbidden if non-admin. Event must exist in tenant. User existence is not strictly validated (unregister is idempotent on a row that doesn't exist).

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement**

ListEventRegistrationsInput: `ActingUser $acting, string $eventId`.
ListEventRegistrationsOutput: `array $items` + `toArray()`.
UnregisterUserFromEventInput: `ActingUser $acting, string $eventId, string $userId`.

Full code mirrors Tasks 5 + 9's structure. Inside execute: admin check → `findByIdForTenant` → for list, call `listRegistrationsForEvent`; for unregister, call `unregister`.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/ListEventRegistrations/ src/Application/Backstage/UnregisterUserFromEvent/ tests/Unit/Application/Backstage/ListEventRegistrationsTest.php tests/Unit/Application/Backstage/UnregisterUserFromEventTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): ListEventRegistrations + UnregisterUserFromEvent use cases"
```

---

### Task 10: `UploadEventImage` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/UploadEventImage/{UploadEventImage.php, UploadEventImageInput.php, UploadEventImageOutput.php}`
- Create: `tests/Unit/Application/Backstage/UploadEventImageTest.php`

- [ ] **Step 1: Failing test covering:**
  - Rejects non-admin.
  - NotFound if event doesn't belong to acting's tenant.
  - 15-file cap: count existing images (hero + gallery). If 15 already, throw `ValidationException(['limit' => 'max_15_images'])`.
  - Delegates to `ImageStorageInterface::storeEventImage`.
  - Output carries the returned URL.

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement**

`UploadEventImageInput.php`: `ActingUser $acting, string $eventId, string $tmpPath, string $originalMime`.

`UploadEventImageOutput.php`: `readonly string $url` + `toArray()` returning `['url' => $this->url]`.

`UploadEventImage.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\UploadEventImage;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Storage\ImageStorageInterface;

final class UploadEventImage
{
    private const MAX_IMAGES = 15;

    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly ImageStorageInterface $storage,
    ) {}

    public function execute(UploadEventImageInput $input): UploadEventImageOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        $existing = 0;
        if ($event->heroImage() !== null && $event->heroImage() !== '') $existing++;
        $existing += count($event->gallery());
        if ($existing >= self::MAX_IMAGES) {
            throw new ValidationException(['limit' => 'max_15_images']);
        }

        $url = $this->storage->storeEventImage($event->id()->value(), $input->tmpPath, $input->originalMime);
        return new UploadEventImageOutput($url);
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/UploadEventImage/ tests/Unit/Application/Backstage/UploadEventImageTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): UploadEventImage use case (15-image cap, delegates to ImageStorage)"
```

---

### Task 11: `DeleteEventImage` use case (TDD)

**Files:**
- Create: `src/Application/Backstage/DeleteEventImage/{DeleteEventImage.php, DeleteEventImageInput.php}`
- Create: `tests/Unit/Application/Backstage/DeleteEventImageTest.php`

- [ ] **Step 1: Failing test covering:**
  - Rejects non-admin.
  - NotFound if event doesn't exist in tenant.
  - If URL matches `heroImage`, calls `storage->deleteByUrl` and updates event via `updateForTenant(['hero_image' => null])`.
  - If URL is in `gallery`, calls `deleteByUrl` and updates event with gallery minus that URL.
  - If URL doesn't match, no side effects (idempotent).

- [ ] **Step 2: Run — expect failure**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace Daems\Application\Backstage\DeleteEventImage;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Storage\ImageStorageInterface;

final class DeleteEventImage
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly ImageStorageInterface $storage,
    ) {}

    public function execute(DeleteEventImageInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        if ($event->heroImage() === $input->url) {
            $this->storage->deleteByUrl($input->url);
            $this->events->updateForTenant($event->id()->value(), $tenantId, ['hero_image' => null]);
            return;
        }

        $gallery = $event->gallery();
        $idx = array_search($input->url, $gallery, true);
        if ($idx !== false) {
            $this->storage->deleteByUrl($input->url);
            unset($gallery[$idx]);
            $this->events->updateForTenant($event->id()->value(), $tenantId, [
                'gallery_json' => json_encode(array_values($gallery)),
            ]);
        }
    }
}
```

`DeleteEventImageInput.php`: `ActingUser $acting, string $eventId, string $url`.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add src/Application/Backstage/DeleteEventImage/ tests/Unit/Application/Backstage/DeleteEventImageTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): DeleteEventImage use case (hero + gallery, idempotent)"
```

---

### Task 12: HTTP — `BackstageController` event methods + `MediaController`

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageController.php`
- Create: `src/Infrastructure/Adapter/Api/Controller/MediaController.php`

- [ ] **Step 1: Add methods to `BackstageController`**

Constructor gains: `ListEventsForAdmin, CreateEvent, UpdateEvent, PublishEvent, ArchiveEvent, ListEventRegistrations, UnregisterUserFromEvent`. Preserve all existing params.

Add these methods (all follow the same error-handling pattern as existing `dismissApplication`):

```php
public function listEvents(Request $request): Response
{
    $acting = $request->requireActingUser();
    try {
        $out = $this->listEventsForAdmin->execute(new ListEventsForAdminInput(
            $acting,
            $request->string('status'),
            $request->string('type'),
        ));
        return Response::json($out->toArray());
    } catch (ForbiddenException) {
        return Response::json(['error' => 'forbidden'], 403);
    }
}

public function createEvent(Request $request): Response
{
    $acting = $request->requireActingUser();
    try {
        $out = $this->createEvent->execute(new CreateEventInput(
            $acting,
            (string) $request->string('title'),
            (string) $request->string('type'),
            (string) $request->string('event_date'),
            $request->string('event_time'),
            $request->string('location'),
            (bool) $request->input('is_online'),
            (string) $request->string('description'),
            (bool) $request->input('publish_immediately'),
        ));
        return Response::json(['data' => $out->toArray()], 201);
    } catch (ForbiddenException) {
        return Response::json(['error' => 'forbidden'], 403);
    } catch (ValidationException $e) {
        return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
    }
}

public function updateEvent(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $gallery = $request->input('gallery_json');
        $out = $this->updateEvent->execute(new UpdateEventInput(
            $acting, $id,
            $request->string('title'),
            $request->string('type'),
            $request->string('event_date'),
            $request->string('event_time'),
            $request->string('location'),
            $request->has('is_online') ? (bool) $request->input('is_online') : null,
            $request->string('description'),
            $request->string('hero_image'),
            is_array($gallery) ? $gallery : null,
        ));
        return Response::json(['data' => $out->toArray()]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
      catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
}

public function publishEvent(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $this->publishEvent->execute(new PublishEventInput($acting, $id));
        return Response::json(['data' => ['id' => $id, 'status' => 'published']]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
}

public function archiveEvent(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $this->archiveEvent->execute(new ArchiveEventInput($acting, $id));
        return Response::json(['data' => ['id' => $id, 'status' => 'archived']]);
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
}

public function listEventRegistrations(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $id = (string) ($params['id'] ?? '');
    try {
        $out = $this->listEventRegistrations->execute(new ListEventRegistrationsInput($acting, $id));
        return Response::json($out->toArray());
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
}

public function removeEventRegistration(Request $request, array $params): Response
{
    $acting = $request->requireActingUser();
    $eventId = (string) ($params['id'] ?? '');
    $userId  = (string) ($params['user_id'] ?? '');
    try {
        $this->unregisterUserFromEvent->execute(new UnregisterUserFromEventInput($acting, $eventId, $userId));
        return Response::noContent();
    } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
      catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
}
```

Add the matching `use` statements at the top of the file.

- [ ] **Step 2: Create `MediaController`**

`src/Infrastructure/Adapter/Api/Controller/MediaController.php`:
```php
<?php
declare(strict_types=1);
namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\DeleteEventImage\DeleteEventImage;
use Daems\Application\Backstage\DeleteEventImage\DeleteEventImageInput;
use Daems\Application\Backstage\UploadEventImage\UploadEventImage;
use Daems\Application\Backstage\UploadEventImage\UploadEventImageInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Storage\ImageStorageException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class MediaController
{
    public function __construct(
        private readonly UploadEventImage $uploadEventImage,
        private readonly DeleteEventImage $deleteEventImage,
    ) {}

    public function uploadEventImage(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['type'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'validation_failed', 'errors' => ['file' => 'upload_error']], 422);
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            return Response::json(['error' => 'validation_failed', 'errors' => ['file' => 'too_large']], 422);
        }
        try {
            $out = $this->uploadEventImage->execute(new UploadEventImageInput(
                $acting, $id, (string) $file['tmp_name'], (string) $file['type'],
            ));
            return Response::json(['data' => $out->toArray()], 201);
        } catch (ForbiddenException)     { return Response::json(['error' => 'forbidden'], 403); }
          catch (NotFoundException)      { return Response::json(['error' => 'not_found'], 404); }
          catch (ValidationException $e) { return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422); }
          catch (ImageStorageException $e) { return Response::json(['error' => 'upload_failed', 'reason' => $e->getMessage()], 422); }
    }

    public function deleteEventImage(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $url = (string) $request->string('url');
        if ($url === '') {
            return Response::json(['error' => 'validation_failed', 'errors' => ['url' => 'required']], 422);
        }
        try {
            $this->deleteEventImage->execute(new DeleteEventImageInput($acting, $id, $url));
            return Response::noContent();
        } catch (ForbiddenException) { return Response::json(['error' => 'forbidden'], 403); }
          catch (NotFoundException)  { return Response::json(['error' => 'not_found'], 404); }
    }
}
```

- [ ] **Step 3: Run PHPStan**

Run: `composer analyse`
Expected: `[OK] No errors` (bindings will still be missing but PHPStan doesn't care about DI).

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): BackstageController event methods + MediaController upload/delete"
```

---

### Task 13: Routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add the 9 new routes**

In `routes/api.php`, locate the existing Backstage block (after `POST /backstage/applications/.../dismiss` lines). Add:

```php
// Backstage — Events (admin)
$router->get('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listEvents($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->createEvent($req);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateEvent($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}/publish', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->publishEvent($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}/archive', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->archiveEvent($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->get('/api/v1/backstage/events/{id}/registrations', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listEventRegistrations($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}/registrations/{user_id}/remove', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->removeEventRegistration($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}/images', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class)->uploadEventImage($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);

$router->post('/api/v1/backstage/events/{id}/images/delete', static function (Request $req, array $params) use ($container): Response {
    return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class)->deleteEventImage($req, $params);
}, [TenantContextMiddleware::class, AuthMiddleware::class]);
```

- [ ] **Step 2: Commit (routes-only, no DI yet)**

```bash
git add routes/api.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): register 9 backstage events + media routes"
```

(Wiring follows in Task 14. Between these two commits the container will error on these routes — that is expected.)

---

### Task 14: DI wiring — `bootstrap/app.php` + `KernelHarness`

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

**CRITICAL:** Wire BOTH containers. Missing bootstrap/app.php bindings = live 500s while E2E stays green.

- [ ] **Step 1: Bindings in `bootstrap/app.php`**

Add near the other Backstage bindings (after ChangeMemberStatus):

```php
// Events admin — use cases
$container->bind(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\CreateEvent\CreateEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\CreateEvent\CreateEvent(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UpdateEvent\UpdateEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\PublishEvent\PublishEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\PublishEvent\PublishEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ArchiveEvent\ArchiveEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class,
    static fn(Container $c) => new \Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations(
        $c->make(EventRepositoryInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent(
        $c->make(EventRepositoryInterface::class),
    ),
);

// Image storage
$container->singleton(\Daems\Domain\Storage\ImageStorageInterface::class,
    static fn(Container $c) => new \Daems\Infrastructure\Storage\LocalImageStorage(
        publicRoot: dirname(__DIR__) . '/public',
        urlPrefix:  rtrim((string) ($_ENV['APP_URL'] ?? 'http://daems-platform.local'), '/'),
        ids:        $c->make(\Daems\Domain\Shared\IdGeneratorInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class,
    static fn(Container $c) => new \Daems\Application\Backstage\UploadEventImage\UploadEventImage(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
    ),
);
$container->bind(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class,
    static fn(Container $c) => new \Daems\Application\Backstage\DeleteEventImage\DeleteEventImage(
        $c->make(EventRepositoryInterface::class),
        $c->make(\Daems\Domain\Storage\ImageStorageInterface::class),
    ),
);

// MediaController
$container->bind(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class,
    static fn(Container $c) => new \Daems\Infrastructure\Adapter\Api\Controller\MediaController(
        $c->make(\Daems\Application\Backstage\UploadEventImage\UploadEventImage::class),
        $c->make(\Daems\Application\Backstage\DeleteEventImage\DeleteEventImage::class),
    ),
);
```

Update the `BackstageController` binding (grep for its current binding) to inject the 7 new use cases. Example of the addition (keep existing args, append):
```php
$c->make(\Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin::class),
$c->make(\Daems\Application\Backstage\CreateEvent\CreateEvent::class),
$c->make(\Daems\Application\Backstage\UpdateEvent\UpdateEvent::class),
$c->make(\Daems\Application\Backstage\PublishEvent\PublishEvent::class),
$c->make(\Daems\Application\Backstage\ArchiveEvent\ArchiveEvent::class),
$c->make(\Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations::class),
$c->make(\Daems\Application\Backstage\UnregisterUserFromEvent\UnregisterUserFromEvent::class),
```

- [ ] **Step 2: Mirror in `KernelHarness`**

Bind `ImageStorageInterface` to a new `InMemoryImageStorage` instance on the harness (`public InMemoryImageStorage $imageStorage;` + init in constructor, binding in `buildKernel`). Bind all 9 new use cases + MediaController exactly as above but with `InMemory` deps where relevant.

- [ ] **Step 3: Grep sanity-check**

Run for each symbol below — must appear in BOTH `bootstrap/app.php` AND `tests/Support/KernelHarness.php`:
```
ListEventsForAdmin
CreateEvent
UpdateEvent
PublishEvent
ArchiveEvent
ListEventRegistrations
UnregisterUserFromEvent
UploadEventImage
DeleteEventImage
ImageStorageInterface
MediaController
```

- [ ] **Step 4: Live smoke**

From `C:\laragon\www\daems-platform`:
```bash
echo "APP_DEBUG=true" >> .env
php -S 127.0.0.1:8090 -t public public/index.php > /tmp/srv.log 2>&1 &
sleep 2
curl -i http://127.0.0.1:8090/api/v1/backstage/events -H "Host: daems-platform.local"
kill %1
sed -i '/^APP_DEBUG=true$/d' .env
```
Expected: HTTP 401 (auth required), **not** 500. A 500 with a TypeError means a binding mismatch — fix before committing.

- [ ] **Step 5: Run the whole suite**

```bash
composer analyse
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite E2E
```
All three must be green. Expect Unit count to have grown by ~30+ from the new use-case tests.

- [ ] **Step 6: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Wire: bind 9 events use cases + ImageStorage + MediaController in BOTH containers"
```

---

### Task 15: Integration + Isolation + E2E tests

**Files:**
- Create: `tests/Integration/Application/EventsAdminIntegrationTest.php`
- Create: `tests/Isolation/EventsAdminTenantIsolationTest.php`
- Create: `tests/E2E/Backstage/EventAdminEndpointsTest.php`
- Create: `tests/E2E/Backstage/EventUploadTest.php`

- [ ] **Step 1: Integration test**

Extends `MigrationTestCase`. `setUp` runs migrations to 43, seeds one tenant + one admin user + `user_tenants` admin link. Tests:
1. `test_full_lifecycle_via_real_sql`: CreateEvent (draft) → UpdateEvent → PublishEvent → ArchiveEvent. After each step, assert the `events` row reflects the expected state.
2. `test_registrations_list_joins_user_data`: seed an event + user + registration row; call `ListEventRegistrations`; assert name + email come through correctly.
3. `test_public_list_excludes_drafts_and_archived`: seed one of each status; call public `ListEvents`; assert only published visible.

- [ ] **Step 2: Isolation test**

Extends `IsolationTestCase`. Seeds two tenants + admins. Tests:
1. Admin A cannot list events in tenant B (filters automatically because `listAllStatusesForTenant` is tenant-scoped).
2. Admin A cannot publish/archive/update/delete a tenant-B event (`NotFoundException`).
3. Admin A cannot upload to a tenant-B event.

- [ ] **Step 3: E2E endpoints test**

Mirrors `ApproveAndInviteFlowTest`. Uses `KernelHarness` + `request(method, path, body)`. Covers:
- `POST /backstage/events` with full body → 201 + new id/slug.
- `POST /backstage/events/{id}` with `{title: 'new'}` → 200 + asserts title was updated via a subsequent `GET`.
- `POST /backstage/events/{id}/publish` → 200 with `{status: 'published'}`.
- `POST /backstage/events/{id}/archive` → 200 with `{status: 'archived'}`.
- `GET /backstage/events` with `?status=archived` filter.
- `GET /backstage/events/{id}/registrations` → `items[]`.
- `POST /backstage/events/{id}/registrations/{user_id}/remove` → 204.

- [ ] **Step 4: E2E upload test**

Tricky because `$_FILES` isn't normally populated in harness. Set `$_FILES['file']` manually in the test using a temp file (create a 1x1 PNG with GD), call the kernel, assert 201 + URL present in response. Use `InMemoryImageStorage` (harness binding), so no real disk IO.

Also test: upload 15 times → 16th call returns 422 `max_15_images` (requires seeding the event with 14 existing gallery URLs + 1 hero beforehand, then upload once more).

- [ ] **Step 5: Run all suites**

```bash
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit --testsuite E2E
```
Integration test count must grow by at least 3. E2E by at least 8.

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/Application/EventsAdminIntegrationTest.php tests/Isolation/EventsAdminTenantIsolationTest.php tests/E2E/Backstage/EventAdminEndpointsTest.php tests/E2E/Backstage/EventUploadTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Test(events): integration + isolation + E2E coverage for admin endpoints + uploads"
```

---

### Task 16: daem-society — proxy endpoints

**Files (frontend repo `C:\laragon\www\sites\daem-society`):**
- Create: `public/api/backstage/events.php`
- Create: `public/api/backstage/event-upload.php`

- [ ] **Step 1: JSON relay `events.php`**

Matches the pattern in `public/api/backstage/dismiss.php`. Reads the request method + path parameters from query string; dispatches to the right ApiClient call.

Frontend calls `fetch('/api/backstage/events?op=list')` or `op=create&...` — the proxy inspects `op` and calls the corresponding backend endpoint.

```php
<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin']) && ($u['role'] ?? '') !== 'admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$op = $_GET['op'] ?? '';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

try {
    switch ($op) {
        case 'list':
            $qs = http_build_query(array_filter([
                'status' => $_GET['status'] ?? null,
                'type' => $_GET['type'] ?? null,
            ], fn($v) => $v !== null && $v !== ''));
            $r = ApiClient::get('/backstage/events' . ($qs ? "?{$qs}" : ''));
            echo json_encode($r);
            return;
        case 'create':
            $r = ApiClient::post('/backstage/events', $body);
            http_response_code(201);
            echo json_encode($r);
            return;
        case 'update':
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/events/{$id}", $body);
            echo json_encode($r);
            return;
        case 'publish':
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/events/{$id}/publish", []);
            echo json_encode($r);
            return;
        case 'archive':
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::post("/backstage/events/{$id}/archive", []);
            echo json_encode($r);
            return;
        case 'registrations':
            $id = (string) ($_GET['id'] ?? '');
            $r = ApiClient::get("/backstage/events/{$id}/registrations");
            echo json_encode($r);
            return;
        case 'remove_registration':
            $id = (string) ($_GET['id'] ?? '');
            $userId = (string) ($_GET['user_id'] ?? '');
            ApiClient::post("/backstage/events/{$id}/registrations/{$userId}/remove", []);
            http_response_code(204);
            return;
        case 'delete_image':
            $id = (string) ($_GET['id'] ?? '');
            ApiClient::post("/backstage/events/{$id}/images/delete", ['url' => (string) ($body['url'] ?? '')]);
            http_response_code(204);
            return;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'bad_op']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'proxy_failed']);
}
```

Match the existing proxy's path to `ApiClient` (check `public/api/backstage/dismiss.php` — it might not require an include because `index.php` front controller already loads it).

- [ ] **Step 2: Multipart relay `event-upload.php`**

This one forwards `$_FILES` through cURL because `ApiClient::post` only handles JSON. Use PHP's `CURLFile`:
```php
<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

$u = $_SESSION['user'] ?? null;
if (!$u || (empty($u['is_platform_admin']) && ($u['role'] ?? '') !== 'admin')) {
    http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit;
}

$eventId = (string) ($_GET['id'] ?? '');
if ($eventId === '') { http_response_code(400); echo json_encode(['error' => 'missing_id']); exit; }
if (empty($_FILES['file']) || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
    http_response_code(400); echo json_encode(['error' => 'no_file']); exit;
}

// Resolve backend base URL + auth token from session (same way ApiClient does).
$base  = rtrim($_SESSION['api_base'] ?? 'http://daems-platform.local/api/v1', '/');
$token = (string) ($_SESSION['api_token'] ?? '');

$ch = curl_init("{$base}/backstage/events/{$eventId}/images");
$payload = [
    'file' => new \CURLFile(
        (string) $_FILES['file']['tmp_name'],
        (string) $_FILES['file']['type'],
        (string) $_FILES['file']['name'],
    ),
];
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Host: daems-platform.local',
    ],
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code((int) $code);
echo (string) $body;
```

If `$_SESSION['api_base']` isn't the real session key, check `ApiClient`'s constructor / config to match.

- [ ] **Step 3: Commit (in daem-society repo)**

```bash
cd C:/laragon/www/sites/daem-society
git add public/api/backstage/events.php public/api/backstage/event-upload.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): proxy endpoints for events admin + multipart upload"
```

---

### Task 17: daem-society — events admin page + modal + upload widget

**Files:**
- Create: `public/pages/backstage/events/index.php`
- Create: `public/pages/backstage/events/event-modal.js`
- Create: `public/pages/backstage/events/event-modal.css`
- Create: `public/pages/backstage/events/upload-widget.js`
- Create: `public/uploads/events/.gitkeep`

- [ ] **Step 1: `index.php` — list page**

PHP-side: admin guard (same as other backstage pages), fetch `ApiClient::get('/backstage/events')` server-side for initial render (fall back to empty array on failure). Render the page with:
- Header "+ New event" button (opens modal in create mode).
- Filter bar (status, type, search).
- Table: date · title · type · status pill · registration count · actions (✏ 📢 🗄).
- Participants modal container (hidden by default).

Use `window.DAEMS_EVENTS = <?= json_encode($initial) ?>;` to hand the initial list to JS without a second fetch.

Include `event-modal.css` + `event-modal.js` + `upload-widget.js`.

- [ ] **Step 2: `event-modal.js`**

Vanilla JS. Exposes `window.EventModal.open(mode, event?)` where `mode` is `'create'` or `'edit'`. Renders form inside a hidden modal element in `index.php`. On Save:

1. If create: `POST /api/backstage/events?op=create` with field JSON → receives `{data: {id, slug}}`.
2. Upload any queued images via `upload-widget.js` helpers to `/api/backstage/event-upload?id={id}` — collect returned URLs.
3. `POST /api/backstage/events?op=update&id={id}` with final body including `hero_image` + `gallery_json`.
4. Close modal, refresh list (reload `window.DAEMS_EVENTS` or just `location.reload()` for MVP simplicity).

Edit flow is same minus step 1 (id already known).

Progress UI: `<p class="upload-progress">3/5 images uploaded…</p>`.

- [ ] **Step 3: `upload-widget.js`**

Two responsibilities:
- Renders the drag-drop zone + file-picker + preview thumbnails with delete buttons.
- Exposes `window.UploadWidget.getPending()` → queued `File[]`; `window.UploadWidget.uploadOne(eventId, file)` → `Promise<{url}>`.

Client-side validation: file type in `['image/jpeg','image/png','image/webp','image/gif']`, size <= 10 MiB (mirror server rule). Reject with inline error.

For already-saved images, render each with a 🗑 button wired to `POST /api/backstage/events?op=delete_image&id={id}` with `{url}`.

- [ ] **Step 4: `event-modal.css`**

Style the modal + form grid + upload-widget drag-drop visuals + status pills. Reuse design tokens (`--space-*`, `--color-*`) from existing backstage CSS where they exist; otherwise define locally.

Status pill colours: `draft` gray, `published` green, `archived` amber — match the Members-page pill pattern if present.

- [ ] **Step 5: `.gitkeep` for uploads dir**

```bash
mkdir -p C:/laragon/www/daems-platform/public/uploads/events
touch C:/laragon/www/daems-platform/public/uploads/events/.gitkeep
```

Commit the `.gitkeep` in the backend repo (not daem-society).

- [ ] **Step 6: Manual smoke test (if browser available)**

1. Open `http://daem-society.local/backstage/events`.
2. "+ New event" → modal opens. Fill in required fields. Drop a PNG image into the hero slot. Click "Save draft" → row appears with `draft` pill.
3. Edit the event → change title, upload 3 gallery images, Save → thumbnails render.
4. Publish → pill turns green.
5. Register as a non-admin user via public `/events/{slug}`.
6. Back to admin, participants modal shows the user; click Remove → count drops.
7. Archive → pill amber → event disappears from public `/events` but stays in admin list.
8. Public event-detail page: open an archived event directly by URL — gallery lightbox still works.

If browser not available, report "manual-smoke skipped — no browser access".

- [ ] **Step 7: Commits**

In backend repo:
```bash
cd C:/laragon/www/daems-platform
git add public/uploads/events/.gitkeep
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events): ensure public/uploads/events/ directory exists in repo"
```

In daem-society repo:
```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/events/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(backstage): events admin page — list + create/edit modal + upload widget"
```

---

### Task 18: Public event-detail — verify gallery lightbox integration

**Files (frontend repo):**
- Verify: `public/pages/events/detail/gallery.php`
- Possibly modify: event-detail page that assembles the full event view.

- [ ] **Step 1: Read the public event-detail page**

Find it (`grep -r "event-gallery-thumb" public/pages/events/` or similar). Confirm it already renders each image as `<a class="event-gallery-thumb" data-src="<full-url>" data-alt="..."><img src="<full-url>"></a>`.

- [ ] **Step 2: Absolute URL handling**

Images live at `http://daems-platform.local/uploads/events/...`. If the event-detail page renders `gallery_json` URLs as-is (relative paths), the daem-society host will 404 on them. Choose one:
- **A (simpler):** Backend returns absolute URLs (LocalImageStorage already does — `urlPrefix` is the APP_URL).
- **B:** Frontend prefixes all gallery URLs with a config'd platform host before rendering.

Confirm the URLs returned by `GET /api/v1/events/{slug}` are absolute (inspect the ListEvents output path — Event's `heroImage` and `gallery` contain the strings the backend persisted). If they are absolute, no frontend change needed. If relative, implement B: read `PLATFORM_URL` from daem-society's `.env`/config and prefix.

- [ ] **Step 3: If no change required, note + skip commit**

If URLs are already absolute, this task is just a verification. Report in the task log.

- [ ] **Step 4: If change required**

Edit the event-detail PHP to wrap `htmlspecialchars($url)` with an absolutise helper. Commit:
```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Feat(events-public): resolve gallery image URLs against platform host"
```

---

### Task 19: Final verification checklist

Run all of these from `C:\laragon\www\daems-platform` and confirm each passes:

- [ ] `composer analyse` → `[OK] No errors`
- [ ] `vendor/bin/phpunit --testsuite Unit` → count grew by ~35+ (8 new use-case test classes). No `No tests executed!`.
- [ ] `vendor/bin/phpunit --testsuite Integration` → Migration043Test + EventsAdminIntegrationTest pass.
- [ ] `vendor/bin/phpunit --testsuite E2E` → EventAdminEndpointsTest + EventUploadTest pass.
- [ ] Dev DB migration applied (Task 1 Step 6).
- [ ] Live smoke: `curl http://127.0.0.1:8090/api/v1/backstage/events -H "Host: daems-platform.local"` → 401 (not 500).
- [ ] Manual UAT checklist from Task 17 Step 6, to whatever extent the environment allows.
- [ ] `git status` clean on both repos (except `.claude/` which is never staged).
- [ ] All commits authored `Dev Team <dev@daems.org>`, no `Co-Authored-By`, not yet pushed.

Report commit SHA list + suite counts. Do NOT push — wait for explicit instruction.
