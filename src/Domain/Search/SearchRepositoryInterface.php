<?php
declare(strict_types=1);

namespace Daems\Domain\Search;

interface SearchRepositoryInterface
{
    /**
     * Find matching entities within a single tenant.
     *
     * @param string $tenantId
     * @param string $query           trimmed, ≥2 chars (caller must validate)
     * @param string|null $type       null or 'all' = every domain the actor may see;
     *                                specific value = only that domain
     * @param bool $includeUnpublished true on backstage path (drafts etc. returned)
     * @param bool $actingUserIsAdmin  controls whether 'members' domain runs
     * @param int $limitPerDomain     5 for typeahead, 20 for dedicated page
     * @param string $currentLocale   for i18n fallback resolution (events/projects)
     * @return SearchHit[]
     */
    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array;
}
