<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryInsightRepository implements InsightRepositoryInterface
{
    /** @var array<string, Insight> */
    public array $bySlug = [];

    public function listForTenant(TenantId $tenantId, ?string $category = null): array
    {
        return array_values(array_filter(
            $this->bySlug,
            static fn(Insight $i): bool => $i->tenantId()->equals($tenantId),
        ));
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Insight
    {
        $insight = $this->bySlug[$slug] ?? null;
        if ($insight === null) {
            return null;
        }
        return $insight->tenantId()->equals($tenantId) ? $insight : null;
    }

    public function save(Insight $insight): void
    {
        $this->bySlug[$insight->slug()] = $insight;
    }
}
