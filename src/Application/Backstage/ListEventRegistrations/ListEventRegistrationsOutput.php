<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventRegistrations;

final class ListEventRegistrationsOutput
{
    /** @param list<array{user_id:string,name:string,email:string,registered_at:string}> $items */
    public function __construct(public readonly array $items) {}

    /** @return array{items: list<array{user_id:string,name:string,email:string,registered_at:string}>, total: int} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => count($this->items)];
    }
}
