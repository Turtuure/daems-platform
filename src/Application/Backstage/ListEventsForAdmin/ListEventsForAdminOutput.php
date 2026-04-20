<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventsForAdmin;

final class ListEventsForAdminOutput
{
    /** @param list<array{id:string,slug:string,title:string,type:string,status:string,event_date:string,event_time:?string,location:?string,is_online:bool,registration_count:int}> $items */
    public function __construct(public readonly array $items) {}

    /** @return array{items: list<array{id:string,slug:string,title:string,type:string,status:string,event_date:string,event_time:?string,location:?string,is_online:bool,registration_count:int}>, total: int} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => count($this->items)];
    }
}
