<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;

final class ListEventsForAdmin
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(ListEventsForAdminInput $input): ListEventsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $filters = [];
        if ($input->status !== null && $input->status !== '') {
            $filters['status'] = $input->status;
        }
        if ($input->type !== null && $input->type !== '') {
            $filters['type'] = $input->type;
        }

        $items = [];
        foreach ($this->events->listAllStatusesForTenant($tenantId, $filters) as $e) {
            $items[] = [
                'id'                 => $e->id()->value(),
                'slug'               => $e->slug(),
                'title'              => $e->title(),
                'type'               => $e->type(),
                'status'             => $e->status(),
                'event_date'         => $e->date(),
                'event_time'         => $e->time(),
                'location'           => $e->location(),
                'is_online'          => $e->online(),
                'registration_count' => $this->events->countRegistrations($e->id()->value()),
            ];
        }
        return new ListEventsForAdminOutput($items);
    }
}
