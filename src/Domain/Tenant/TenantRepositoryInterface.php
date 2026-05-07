<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

interface TenantRepositoryInterface
{
    public function findById(TenantId $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    /**
     * Insert a new tenant row. Persists every field on the entity (slug,
     * name, i18n maps, supported_locales, default_locale, member_number_prefix,
     * default_time_format, created_at). Used by the GSA CreateTenant use case.
     */
    public function save(Tenant $tenant): void;

    public function findByDomain(string $domain): ?Tenant;

    /** @return list<Tenant> */
    public function findAll(): array;

    public function updatePrefix(TenantId $tenantId, ?string $prefix): void;

    public function updateDefaultTimeFormat(TenantId $tenantId, string $format): void;

    /**
     * Persist all editable fields of $tenant (display name i18n, public
     * description i18n, supported_locales, default_locale, etc.). The
     * immutable slug is NOT updated; the use case enforces that.
     */
    public function update(Tenant $tenant): void;

    public function suspend(TenantId $tenantId, string $reason, \DateTimeImmutable $now): void;

    public function reactivate(TenantId $tenantId): void;
}
