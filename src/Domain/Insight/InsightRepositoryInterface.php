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
}
