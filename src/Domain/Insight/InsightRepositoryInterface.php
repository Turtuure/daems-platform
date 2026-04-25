<?php

declare(strict_types=1);

namespace Daems\Domain\Insight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

interface InsightRepositoryInterface
{
    /**
     * @param TenantId $tenantId
     * @param string|null $category           Filter by category slug; null for all
     * @param bool $includeUnpublished        When false (default = public view), filters
     *                                        rows with published_date > CURDATE() out.
     *                                        Admin backstage path passes true.
     * @return Insight[]
     */
    public function listForTenant(TenantId $tenantId, ?string $category = null, bool $includeUnpublished = false): array;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Insight;

    public function findByIdForTenant(InsightId $id, TenantId $tenantId): ?Insight;

    public function save(Insight $insight): void;

    public function delete(InsightId $id, TenantId $tenantId): void;

    /**
     * Aggregate stats for the backstage dashboard.
     *
     * @return array{
     *   published: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   scheduled: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   featured:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     *
     * Sparkline arrays are exactly 30 entries:
     *   - published: count of insights whose published_date falls on each of the
     *     last 30 days (today = entry 29, 29 days ago = entry 0).
     *   - scheduled: count of insights whose published_date falls on each of the
     *     next 30 days (today+1 = entry 0, today+30 = entry 29).
     *   - featured:  same window as published, but only featured = 1.
     *
     * Missing days are zero-filled. Date strings are 'YYYY-MM-DD'.
     */
    public function statsForTenant(TenantId $tenantId): array;
}
