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
        $base  = new \DateTimeImmutable('today');
        $today = $base->format('Y-m-d');

        $publishedDays = [];
        $featuredDays  = [];
        $scheduledDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $base->modify("-{$i} days")->format('Y-m-d');
            $publishedDays[$d] = 0;
            $featuredDays[$d]  = 0;
        }
        for ($i = 1; $i <= 30; $i++) {
            $scheduledDays[$base->modify("+{$i} days")->format('Y-m-d')] = 0;
        }

        $publishedTotal = 0;
        $scheduledTotal = 0;
        $featuredTotal  = 0;

        foreach ($this->bySlug as $insight) {
            if ($insight->tenantId()->value() !== $tenantId->value()) {
                continue;
            }
            $date = $insight->date();
            if ($date <= $today) {
                $publishedTotal++;
                if (isset($publishedDays[$date])) {
                    $publishedDays[$date]++;
                }
                if ($insight->featured()) {
                    $featuredTotal++;
                    if (isset($featuredDays[$date])) {
                        $featuredDays[$date]++;
                    }
                }
            } else {
                $scheduledTotal++;
                if (isset($scheduledDays[$date])) {
                    $scheduledDays[$date]++;
                }
            }
        }

        return [
            'published' => ['value' => $publishedTotal, 'sparkline' => self::seriesFromMap($publishedDays)],
            'scheduled' => ['value' => $scheduledTotal, 'sparkline' => self::seriesFromMap($scheduledDays)],
            'featured'  => ['value' => $featuredTotal,  'sparkline' => self::seriesFromMap($featuredDays)],
        ];
    }

    /**
     * @param array<string, int> $map
     * @return list<array{date: string, value: int}>
     */
    private static function seriesFromMap(array $map): array
    {
        $out = [];
        foreach ($map as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }
}
