<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;

final class InMemoryEventRepository implements EventRepositoryInterface
{
    /** @var array<string, Event> by slug */
    public array $bySlug = [];

    /** @var array<string, Event> by id */
    public array $byId = [];

    /** @var list<EventRegistration> */
    public array $registrations = [];

    /** @var list<array{user_id:string,event_id:string,name:string,email:string,registered_at:string}> */
    public array $adminRegistrations = [];

    /** @var array<string, array<string, array<string, ?string>>> translations keyed by eventId, then locale */
    public array $translations = [];

    public function listForTenant(TenantId $tenantId, ?string $type = null): array
    {
        $out = [];
        foreach ($this->byId as $e) {
            if (!$e->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($e->status() !== 'published') {
                continue;
            }
            if ($type !== null && $e->type() !== $type) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
    {
        $out = [];
        foreach ($this->byId as $e) {
            if (!$e->tenantId()->equals($tenantId)) {
                continue;
            }
            if (isset($filters['status']) && $e->status() !== $filters['status']) {
                continue;
            }
            if (isset($filters['type']) && $e->type() !== $filters['type']) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Event
    {
        $e = $this->byId[$id] ?? null;
        return ($e !== null && $e->tenantId()->equals($tenantId)) ? $e : null;
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event
    {
        $event = $this->bySlug[$slug] ?? null;
        if ($event === null) {
            return null;
        }
        return $event->tenantId()->equals($tenantId) ? $event : null;
    }

    public function save(Event $event): void
    {
        $this->bySlug[$event->slug()] = $event;
        $this->byId[$event->id()->value()] = $event;
    }

    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
    {
        $e = $this->findByIdForTenant($id, $tenantId);
        if ($e === null) {
            return;
        }
        // Build replacement Event with merged fields.
        $merged = [
            'title'        => $fields['title'] ?? $e->title(),
            'type'         => $fields['type'] ?? $e->type(),
            'event_date'   => $fields['event_date'] ?? $e->date(),
            'event_time'   => array_key_exists('event_time', $fields) ? $fields['event_time'] : $e->time(),
            'location'     => array_key_exists('location', $fields) ? $fields['location'] : $e->location(),
            'is_online'    => array_key_exists('is_online', $fields) ? (bool) $fields['is_online'] : $e->online(),
            'description'  => array_key_exists('description', $fields) ? $fields['description'] : $e->description(),
            'hero_image'   => array_key_exists('hero_image', $fields) ? $fields['hero_image'] : $e->heroImage(),
            'gallery_json' => array_key_exists('gallery_json', $fields)
                ? (json_decode((string) $fields['gallery_json'], true) ?: [])
                : $e->gallery(),
        ];
        $updated = new Event(
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
        $this->byId[$id] = $updated;
        $this->bySlug[$updated->slug()] = $updated;
    }

    public function setStatus(string $id, TenantId $tenantId, string $status): void
    {
        $e = $this->findByIdForTenant($id, $tenantId);
        if ($e === null) {
            return;
        }
        $updated = new Event(
            $e->id(), $e->tenantId(), $e->slug(), $e->title(), $e->type(), $e->date(),
            $e->time(), $e->location(), $e->online(), $e->description(),
            $e->heroImage(), $e->gallery(), $status,
        );
        $this->byId[$id] = $updated;
        $this->bySlug[$updated->slug()] = $updated;
    }

    public function register(EventRegistration $registration): void
    {
        $this->registrations[] = $registration;
    }

    public function unregister(string $eventId, string $userId): void
    {
        $this->registrations = array_values(array_filter(
            $this->registrations,
            static fn(EventRegistration $r): bool => !($r->eventId() === $eventId && $r->userId() === $userId),
        ));
        $this->adminRegistrations = array_values(array_filter(
            $this->adminRegistrations,
            static fn(array $r): bool => !($r['event_id'] === $eventId && $r['user_id'] === $userId),
        ));
    }

    public function isRegistered(string $eventId, string $userId): bool
    {
        foreach ($this->registrations as $r) {
            if ($r->eventId() === $eventId && $r->userId() === $userId) {
                return true;
            }
        }
        return false;
    }

    public function countRegistrations(string $eventId): int
    {
        $n = 0;
        foreach ($this->registrations as $r) {
            if ($r->eventId() === $eventId) {
                $n++;
            }
        }
        return $n;
    }

    public function findRegistrationsByUserId(string $userId): array
    {
        return [];
    }

    public function listRegistrationsForEvent(string $eventId, TenantId $tenantId): array
    {
        $e = $this->findByIdForTenant($eventId, $tenantId);
        if ($e === null) {
            return [];
        }
        $out = [];
        foreach ($this->adminRegistrations as $r) {
            if ($r['event_id'] === $eventId) {
                $out[] = [
                    'user_id'       => $r['user_id'],
                    'name'          => $r['name'],
                    'email'         => $r['email'],
                    'registered_at' => $r['registered_at'],
                ];
            }
        }
        return $out;
    }

    public function saveTranslation(
        TenantId $tenantId,
        string $eventId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $e = $this->byId[$eventId] ?? null;
        if ($e === null || !$e->tenantId()->equals($tenantId)) {
            throw new \DomainException('event_not_found_in_tenant');
        }
        $newRow = [
            'title'       => isset($fields['title']) ? (string) $fields['title'] : '',
            'location'    => $fields['location'] ?? null,
            'description' => $fields['description'] ?? null,
        ];
        $this->translations[$eventId][$locale->value()] = $newRow;

        // Rebuild a fresh TranslationMap merging existing rows with the new write,
        // matching SQL repo semantics where hydrate() reads from events_i18n.
        $merged = $e->translations()->raw();
        $merged[$locale->value()] = $newRow;
        $newMap = new TranslationMap($merged);

        $convenience = self::firstAvailableRow($newMap);
        $title       = $convenience['title'] ?? '';
        $location    = $convenience['location'] ?? null;
        $description = $convenience['description'] ?? null;

        $updated = new Event(
            $e->id(), $e->tenantId(), $e->slug(),
            $title, $e->type(), $e->date(),
            $e->time(), $location, $e->online(),
            $description, $e->heroImage(), $e->gallery(),
            $e->status(), $newMap,
        );
        $this->byId[$eventId] = $updated;
        $this->bySlug[$updated->slug()] = $updated;
    }

    /**
     * Mirror SqlEventRepository::firstAvailable for each translatable field.
     * @return array{title: string, location: ?string, description: ?string}
     */
    private static function firstAvailableRow(TranslationMap $translations): array
    {
        $out = ['title' => '', 'location' => null, 'description' => null];
        foreach (Event::TRANSLATABLE_FIELDS as $field) {
            $value = null;
            foreach ([SupportedLocale::UI_DEFAULT, SupportedLocale::CONTENT_FALLBACK] as $loc) {
                $row = $translations->rowFor(SupportedLocale::fromString($loc));
                if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                    $value = (string) $row[$field];
                    break;
                }
            }
            if ($value === null) {
                foreach (SupportedLocale::supportedValues() as $loc) {
                    $row = $translations->rowFor(SupportedLocale::fromString($loc));
                    if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                        $value = (string) $row[$field];
                        break;
                    }
                }
            }
            if ($field === 'title') {
                $out['title'] = $value ?? '';
            } else {
                $out[$field] = $value;
            }
        }
        return $out;
    }

    public function seedAdminRegistration(
        string $eventId,
        string $userId,
        string $name,
        string $email,
        string $registeredAt,
    ): void {
        $this->adminRegistrations[] = [
            'event_id'      => $eventId,
            'user_id'       => $userId,
            'name'          => $name,
            'email'         => $email,
            'registered_at' => $registeredAt,
        ];
    }
}
