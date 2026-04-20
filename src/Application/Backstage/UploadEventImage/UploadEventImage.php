<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UploadEventImage;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Storage\ImageStorageInterface;

final class UploadEventImage
{
    private const MAX_IMAGES = 15;

    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly ImageStorageInterface $storage,
    ) {}

    public function execute(UploadEventImageInput $input): UploadEventImageOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        $existing = 0;
        if ($event->heroImage() !== null && $event->heroImage() !== '') {
            $existing++;
        }
        $existing += count($event->gallery());
        if ($existing >= self::MAX_IMAGES) {
            throw new ValidationException(['limit' => 'max_15_images']);
        }

        $url = $this->storage->storeEventImage($event->id()->value(), $input->tmpPath, $input->originalMime);
        return new UploadEventImageOutput($url);
    }
}
