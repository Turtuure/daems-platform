<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

use Daems\Domain\Search\SearchRepositoryInterface;

final class Search
{
    private const MIN_QUERY_LEN = 2;
    private const ALLOWED_TYPES = ['all', 'events', 'projects', 'forum', 'insights', 'members'];
    /** Map use-case type names to SearchHit entity_type values. */
    private const TYPE_TO_ENTITY = [
        'events' => 'event',
        'projects' => 'project',
        'forum' => 'forum_topic',
        'insights' => 'insight',
        'members' => 'member',
    ];

    public function __construct(private readonly SearchRepositoryInterface $repo) {}

    public function execute(SearchInput $in): SearchOutput
    {
        $query = trim($in->rawQuery);
        if (mb_strlen($query) < self::MIN_QUERY_LEN) {
            return new SearchOutput([], 0, $query, null, false, 'query_too_short');
        }

        $type = in_array($in->type, self::ALLOWED_TYPES, true) ? $in->type : null;
        $type = ($type === 'all') ? null : $type;

        $entityType = $type === null ? null : self::TYPE_TO_ENTITY[$type];

        $hits = $this->repo->search(
            tenantId: $in->tenantId,
            query: $query,
            type: $entityType,
            includeUnpublished: $in->includeUnpublished,
            actingUserIsAdmin: $in->actingUserIsAdmin,
            limitPerDomain: $in->limitPerDomain,
            currentLocale: $in->currentLocale,
        );

        return new SearchOutput($hits, count($hits), $query, $type, false, null);
    }
}
