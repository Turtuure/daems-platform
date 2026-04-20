<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectCommentsForAdmin;

final class ListProjectCommentsForAdminOutput
{
    /**
     * @param list<array{comment_id:string,project_id:string,project_title:string,author_name:string,content:string,created_at:string}> $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return array{items: list<array{comment_id:string,project_id:string,project_title:string,author_name:string,content:string,created_at:string}>, total: int}
     */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => count($this->items)];
    }
}
