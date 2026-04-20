<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProposalsForAdmin;

final class ListProposalsForAdminOutput
{
    /**
     * @param list<array{id:string,user_id:string,author_name:string,author_email:string,title:string,category:string,summary:string,description:string,status:string,created_at:string}> $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return array{items: list<array{id:string,user_id:string,author_name:string,author_email:string,title:string,category:string,summary:string,description:string,status:string,created_at:string}>, total: int}
     */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => count($this->items)];
    }
}
