<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class UpdateEvent
{
    private const ALLOWED_TYPES = ['upcoming', 'past', 'online'];

    public function __construct(private readonly EventRepositoryInterface $events) {}

    public function execute(UpdateEventInput $input): UpdateEventOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        $event = $this->events->findByIdForTenant($input->eventId, $tenantId)
            ?? throw new NotFoundException('event_not_found');

        $errors = [];
        $fields = [];

        if ($input->title !== null) {
            if (strlen($input->title) < 3 || strlen($input->title) > 200) {
                $errors['title'] = 'length_3_to_200';
            } else {
                $fields['title'] = $input->title;
            }
        }
        if ($input->type !== null) {
            if (!in_array($input->type, self::ALLOWED_TYPES, true)) {
                $errors['type'] = 'invalid_value';
            } else {
                $fields['type'] = $input->type;
            }
        }
        if ($input->eventDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
                $errors['event_date'] = 'invalid_date';
            } else {
                $fields['event_date'] = $input->eventDate;
            }
        }
        if ($input->eventTime !== null) {
            $fields['event_time'] = $input->eventTime === '' ? null : $input->eventTime;
        }
        if ($input->location !== null) {
            $fields['location'] = $input->location === '' ? null : $input->location;
        }
        if ($input->isOnline !== null) {
            $fields['is_online'] = $input->isOnline ? 1 : 0;
        }
        if ($input->description !== null) {
            if (strlen(trim($input->description)) < 20) {
                $errors['description'] = 'min_20_chars';
            } else {
                $fields['description'] = $input->description;
            }
        }
        if ($input->heroImage !== null) {
            $fields['hero_image'] = $input->heroImage === '' ? null : $input->heroImage;
        }
        if ($input->gallery !== null) {
            $fields['gallery_json'] = json_encode(array_values($input->gallery));
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($fields !== []) {
            $this->events->updateForTenant($event->id()->value(), $tenantId, $fields);
        }
        return new UpdateEventOutput($event->id()->value());
    }
}
