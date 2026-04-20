<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\PublishEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class PublishEvent
{
    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(PublishEventInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if ($this->events->findByIdForTenant($input->eventId, $tenantId) === null) {
            throw new NotFoundException('event_not_found');
        }
        $this->events->setStatus($input->eventId, $tenantId, 'published');
    }
}
