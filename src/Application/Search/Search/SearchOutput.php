<?php
declare(strict_types=1);

namespace Daems\Application\Search\Search;

use Daems\Domain\Search\SearchHit;

final class SearchOutput
{
    /** @param SearchHit[] $hits */
    public function __construct(
        public readonly array $hits,
        public readonly int $count,
        public readonly string $query,
        public readonly ?string $type,
        public readonly bool $partial,
        public readonly ?string $reason,
    ) {}
}
