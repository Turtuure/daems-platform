<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlEventRepository implements EventRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findAll(?string $type = null): array
    {
        if ($type !== null) {
            $rows = $this->db->query(
                'SELECT * FROM events WHERE type = ? ORDER BY event_date ASC',
                [$type],
            );
        } else {
            $rows = $this->db->query('SELECT * FROM events ORDER BY event_date ASC');
        }

        return array_map($this->hydrate(...), $rows);
    }

    public function findBySlug(string $slug): ?Event
    {
        $row = $this->db->queryOne(
            'SELECT * FROM events WHERE slug = ?',
            [$slug],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Event $event): void
    {
        $this->db->execute(
            'INSERT INTO events
                (id, slug, title, type, event_date, event_time, location, is_online, description, hero_image, gallery_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                type = VALUES(type),
                event_date = VALUES(event_date),
                event_time = VALUES(event_time),
                location = VALUES(location),
                is_online = VALUES(is_online),
                description = VALUES(description),
                hero_image = VALUES(hero_image),
                gallery_json = VALUES(gallery_json)',
            [
                $event->id()->value(),
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
            ],
        );
    }

    public function register(EventRegistration $registration): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO event_registrations (id, event_id, user_id, registered_at) VALUES (?, ?, ?, ?)',
            [$registration->id(), $registration->eventId(), $registration->userId(), $registration->registeredAt()],
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
        return (int) ($row['cnt'] ?? 0);
    }

    public function findRegistrationsByUserId(string $userId): array
    {
        return $this->db->query(
            'SELECT e.id AS event_id, e.slug, e.title, e.type, e.event_date AS date
             FROM event_registrations er
             JOIN events e ON e.id = er.event_id
             WHERE er.user_id = ?
             ORDER BY e.event_date DESC',
            [$userId],
        );
    }

    private function hydrate(array $row): Event
    {
        return new Event(
            EventId::fromString($row['id']),
            $row['slug'],
            $row['title'],
            $row['type'],
            $row['event_date'],
            $row['event_time'],
            $row['location'],
            (bool) $row['is_online'],
            $row['description'],
            $row['hero_image'],
            json_decode($row['gallery_json'] ?? '[]', true) ?: [],
        );
    }
}
