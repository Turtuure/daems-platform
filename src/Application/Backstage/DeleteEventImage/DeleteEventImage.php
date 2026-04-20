<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DeleteEventImage;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Storage\ImageStorageInterface;

final class DeleteEventImage
{
    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly ImageStorageInterface $storage,
    ) {}

    public function execute(DeleteEventImageInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        if ($event->heroImage() === $input->url) {
            $this->storage->deleteByUrl($input->url);
            $this->events->updateForTenant($event->id()->value(), $tenantId, ['hero_image' => null]);
            return;
        }

        $gallery = $event->gallery();
        $idx = array_search($input->url, $gallery, true);
        if ($idx !== false) {
            $this->storage->deleteByUrl($input->url);
            unset($gallery[$idx]);
            $this->events->updateForTenant($event->id()->value(), $tenantId, [
                'gallery_json' => json_encode(array_values($gallery)),
            ]);
        }
    }
}
