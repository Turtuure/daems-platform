<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Search\SearchHit;
use Daems\Domain\Search\SearchRepositoryInterface;

final class InMemorySearchRepository implements SearchRepositoryInterface
{
    /** @var SearchHit[] */
    private array $hits = [];

    /** @param SearchHit[] $hits */
    public function seed(array $hits): void { $this->hits = $hits; }

    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array {
        $typeFilter = ($type === null || $type === 'all') ? null : $type;
        $needle = mb_strtolower($query);
        $perDomainCount = [];

        $out = [];
        foreach ($this->hits as $h) {
            if ($typeFilter !== null && $h->entityType !== $typeFilter) continue;
            if ($h->entityType === 'member' && !$actingUserIsAdmin) continue;
            if (!$includeUnpublished && $h->status !== 'published') continue;
            if (mb_stripos($h->title, $needle) === false
                && mb_stripos($h->snippet, $needle) === false) continue;

            $perDomainCount[$h->entityType] = ($perDomainCount[$h->entityType] ?? 0) + 1;
            if ($perDomainCount[$h->entityType] > $limitPerDomain) continue;

            $out[] = $h;
        }
        return $out;
    }
}
