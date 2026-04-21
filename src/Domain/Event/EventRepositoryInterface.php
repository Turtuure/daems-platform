<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Locale\SupportedLocale;
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

    /**
     * Upsert one locale's translation row for an event within a tenant.
     * Throws \DomainException('event_not_found_in_tenant') if the event does not
     * belong to the provided tenant.
     *
     * @param array<string, ?string> $fields title + location + description
     */
    public function saveTranslation(
        TenantId $tenantId,
        string $eventId,
        SupportedLocale $locale,
        array $fields,
    ): void;
}
