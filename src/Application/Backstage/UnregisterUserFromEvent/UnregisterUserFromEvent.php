<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UnregisterUserFromEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class UnregisterUserFromEvent
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(UnregisterUserFromEventInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if ($this->events->findByIdForTenant($input->eventId, $tenantId) === null) {
            throw new NotFoundException('event_not_found');
        }
        $this->events->unregister($input->eventId, $input->userId);
    }
}
