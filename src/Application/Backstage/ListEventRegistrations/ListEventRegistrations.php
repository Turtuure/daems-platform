<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventRegistrations;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class ListEventRegistrations
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(ListEventRegistrationsInput $input): ListEventRegistrationsOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if ($this->events->findByIdForTenant($input->eventId, $tenantId) === null) {
            throw new NotFoundException('event_not_found');
        }
        $items = $this->events->listRegistrationsForEvent($input->eventId, $tenantId);
        return new ListEventRegistrationsOutput($items);
    }
}
