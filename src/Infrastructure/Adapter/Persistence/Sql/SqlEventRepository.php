<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlEventRepository implements EventRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

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

    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
    {
        $sql = 'SELECT * FROM events WHERE tenant_id = ?';
        $params = [$tenantId->value()];
        if (isset($filters['status']) && $filters['status'] !== '') {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
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

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event
    {
        $row = $this->db->queryOne(
            'SELECT * FROM events WHERE slug = ? AND tenant_id = ?',
            [$slug, $tenantId->value()],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Event $event): void
    {
        $this->db->execute(
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
                status = VALUES(status)',
            [
                $event->id()->value(),
                $event->tenantId()->value(),
                $event->slug(),
                $event->title(),
                $event->type(),
                $event->date(),
                $event->time(),
                $event->location(),
                $event->online() ? 1 : 0,
                $event->description(),
                $event->heroImage(),
                json_encode($event->gallery()),
                $event->status(),
            ],
        );
    }

    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $allowed = ['title', 'type', 'event_date', 'event_time', 'location', 'is_online', 'description', 'hero_image', 'gallery_json'];
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
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \DomainException('invalid_event_status');
        }
        $this->db->execute(
            'UPDATE events SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $tenantId->value()],
        );
    }

    public function register(EventRegistration $registration): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO event_registrations (id, tenant_id, event_id, user_id, registered_at)
             VALUES (?, (SELECT tenant_id FROM events WHERE id = ?), ?, ?, ?)',
            [
                $registration->id(),
                $registration->eventId(),
                $registration->eventId(),
                $registration->userId(),
                $registration->registeredAt(),
            ],
        );
    }

    public function unregister(string $eventId, string $userId): void
    {
        $this->db->execute(
            'DELETE FROM event_registrations WHERE event_id = ? AND user_id = ?',
            [$eventId, $userId],
        );
    }

    public function isRegistered(string $eventId, string $userId): bool
    {
        $row = $this->db->queryOne(
            'SELECT 1 FROM event_registrations WHERE event_id = ? AND user_id = ? LIMIT 1',
            [$eventId, $userId],
        );
        return $row !== null;
    }

    public function countRegistrations(string $eventId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS cnt FROM event_registrations WHERE event_id = ?',
            [$eventId],
        );
        $cnt = $row['cnt'] ?? null;
        return is_int($cnt) ? $cnt : (is_string($cnt) && is_numeric($cnt) ? (int) $cnt : 0);
    }

    /** @return array<array{event_id: string, slug: string, title: string, type: string, date: string}> */
    public function findRegistrationsByUserId(string $userId): array
    {
        /** @var array<array{event_id: string, slug: string, title: string, type: string, date: string}> $rows */
        $rows = $this->db->query(
            'SELECT e.id AS event_id, e.slug, e.title, e.type, e.event_date AS date
             FROM event_registrations er
             JOIN events e ON e.id = er.event_id
             WHERE er.user_id = ?
             ORDER BY e.event_date DESC',
            [$userId],
        );

        return $rows;
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

    public function saveTranslation(
        TenantId $tenantId,
        string $eventId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $exists = $this->db->queryOne(
            'SELECT 1 FROM events WHERE id = ? AND tenant_id = ?',
            [$eventId, $tenantId->value()],
        );
        if ($exists === null) {
            throw new \DomainException('event_not_found_in_tenant');
        }
        $this->db->execute(
            'INSERT INTO events_i18n (event_id, locale, title, location, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), location=VALUES(location),
                description=VALUES(description), updated_at=CURRENT_TIMESTAMP',
            [
                $eventId,
                $locale->value(),
                (string) ($fields['title'] ?? ''),
                $fields['location'] ?? null,
                $fields['description'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Event
    {
        $galleryRaw = $row['gallery_json'] ?? null;
        $galleryJson = is_string($galleryRaw) ? $galleryRaw : '[]';
        $gallery = json_decode($galleryJson, true);
        $gallery = is_array($gallery) ? $gallery : [];

        return new Event(
            EventId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'slug'),
            self::str($row, 'title'),
            self::str($row, 'type'),
            self::str($row, 'event_date'),
            self::strOrNull($row, 'event_time'),
            self::strOrNull($row, 'location'),
            (bool) ($row['is_online'] ?? false),
            self::strOrNull($row, 'description'),
            self::strOrNull($row, 'hero_image'),
            $gallery,
            self::str($row, 'status'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }
}
