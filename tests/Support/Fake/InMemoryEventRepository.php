<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryEventRepository implements EventRepositoryInterface
{
    /** @var array<string, Event> by slug */
    public array $bySlug = [];

    /** @var list<EventRegistration> */
    public array $registrations = [];

    public function listForTenant(TenantId $tenantId, ?string $type = null): array
    {
        return array_values(array_filter(
            $this->bySlug,
            static fn(Event $e): bool => $e->tenantId()->equals($tenantId),
        ));
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
}
