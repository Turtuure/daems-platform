<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectsForAdmin;

final class ListProjectsForAdminOutput
{
    /** @param list<array{id:string,slug:string,title:string,category:string,status:string,featured:bool,owner_id:?string,participants_count:int,comments_count:int,created_at:string}> $items */
    public function __construct(public readonly array $items) {}

    /** @return array{items: list<array{id:string,slug:string,title:string,category:string,status:string,featured:bool,owner_id:?string,participants_count:int,comments_count:int,created_at:string}>, total: int} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => count($this->items)];
    }
}
