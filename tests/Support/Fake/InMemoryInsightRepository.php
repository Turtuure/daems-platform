<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryInsightRepository implements InsightRepositoryInterface
{
    /** @var array<string, Insight> */
    public array $bySlug = [];

    public function listForTenant(TenantId $tenantId, ?string $category = null, bool $includeUnpublished = false): array
    {
        return array_values(array_filter(
            $this->bySlug,
            static fn(Insight $i): bool => $i->tenantId()->equals($tenantId)
                && ($category === null || $i->category() === $category),
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

    public function findByIdForTenant(InsightId $id, TenantId $tenantId): ?Insight
    {
        foreach ($this->bySlug as $insight) {
            if ($insight->id()->value() === $id->value() && $insight->tenantId()->equals($tenantId)) {
                return $insight;
            }
        }
        return null;
    }

    public function save(Insight $insight): void
    {
        $this->bySlug[$insight->slug()] = $insight;
    }

    public function delete(InsightId $id, TenantId $tenantId): void
    {
        foreach ($this->bySlug as $slug => $insight) {
            if ($insight->id()->value() === $id->value() && $insight->tenantId()->equals($tenantId)) {
                unset($this->bySlug[$slug]);
                return;
            }
        }
    }

    public function statsForTenant(TenantId $tenantId): array
    {
        return [
            'published' => ['value' => 0, 'sparkline' => []],
            'scheduled' => ['value' => 0, 'sparkline' => []],
            'featured'  => ['value' => 0, 'sparkline' => []],
        ];
    }
}
