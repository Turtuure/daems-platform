<?php

declare(strict_types=1);

namespace Daems\Domain\Insight;

use Daems\Domain\Tenant\TenantId;

interface InsightRepositoryInterface
{
    /** @return Insight[] */
    public function listForTenant(TenantId $tenantId, ?string $category = null): array;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Insight;

    public function save(Insight $insight): void;
}
