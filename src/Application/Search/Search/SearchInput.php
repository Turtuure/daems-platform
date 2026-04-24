<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

final class SearchInput
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $rawQuery,
        public readonly ?string $type,
        public readonly bool $includeUnpublished,
        public readonly bool $actingUserIsAdmin,
        public readonly int $limitPerDomain,
        public readonly string $currentLocale,
    ) {}
}
