<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

interface EventRepositoryInterface
{
    /** @return Event[] */
    public function listForTenant(TenantId $tenantId, ?string $type = null): array;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Event;

    public function save(Event $event): void;

    public function register(EventRegistration $registration): void;

    public function unregister(string $eventId, string $userId): void;

    public function isRegistered(string $eventId, string $userId): bool;

    public function countRegistrations(string $eventId): int;

    /** @return array<array{event_id:string,slug:string,title:string,type:string,date:string}> */
    public function findRegistrationsByUserId(string $userId): array;
}
