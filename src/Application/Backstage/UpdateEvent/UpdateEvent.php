<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;
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
        $chromeFields = [];
        $translationFields = [];

        if ($input->title !== null) {
            if (strlen($input->title) < 3 || strlen($input->title) > 200) {
                $errors['title'] = 'length_3_to_200';
            } else {
                $translationFields['title'] = $input->title;
            }
        }
        if ($input->type !== null) {
            if (!in_array($input->type, self::ALLOWED_TYPES, true)) {
                $errors['type'] = 'invalid_value';
            } else {
                $chromeFields['type'] = $input->type;
            }
        }
        if ($input->eventDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
                $errors['event_date'] = 'invalid_date';
            } else {
                $chromeFields['event_date'] = $input->eventDate;
            }
        }
        if ($input->eventTime !== null) {
            $chromeFields['event_time'] = $input->eventTime === '' ? null : $input->eventTime;
        }
        if ($input->location !== null) {
            $translationFields['location'] = $input->location === '' ? null : $input->location;
        }
        if ($input->isOnline !== null) {
            $chromeFields['is_online'] = $input->isOnline ? 1 : 0;
        }
        if ($input->description !== null) {
            if (strlen(trim($input->description)) < 20) {
                $errors['description'] = 'min_20_chars';
            } else {
                $translationFields['description'] = $input->description;
            }
        }
        if ($input->heroImage !== null) {
            $chromeFields['hero_image'] = $input->heroImage === '' ? null : $input->heroImage;
        }
        if ($input->gallery !== null) {
            $chromeFields['gallery_json'] = json_encode(array_values($input->gallery));
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($chromeFields !== []) {
            $this->events->updateForTenant($event->id()->value(), $tenantId, $chromeFields);
        }
        if ($translationFields !== []) {
            $existing = $event->translations()->rowFor(SupportedLocale::uiDefault()) ?? [];
            $merged = [
                'title'       => array_key_exists('title', $translationFields)
                    ? $translationFields['title']
                    : ($existing['title'] ?? ''),
                'location'    => array_key_exists('location', $translationFields)
                    ? $translationFields['location']
                    : ($existing['location'] ?? null),
                'description' => array_key_exists('description', $translationFields)
                    ? $translationFields['description']
                    : ($existing['description'] ?? null),
            ];
            $this->events->saveTranslation(
                $tenantId,
                $event->id()->value(),
                SupportedLocale::uiDefault(),
                $merged,
            );
        }
        return new UpdateEventOutput($event->id()->value());
    }
}
