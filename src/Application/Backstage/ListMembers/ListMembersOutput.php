<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListMembers;

use Daems\Domain\Backstage\MemberDirectoryEntry;

final class ListMembersOutput
{
    /**
     * @param list<MemberDirectoryEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * @return array{
     *   data: list<array<string, string|null>>,
     *   meta: array{page:int, per_page:int, total:int, total_pages:int}
     * }
     */
    public function toArray(): array
    {
        $totalPages = $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
        return [
            'data' => array_map(static fn (MemberDirectoryEntry $e) => $e->toArray(), $this->entries),
            'meta' => [
                'page'        => $this->page,
                'per_page'    => $this->perPage,
                'total'       => $this->total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
