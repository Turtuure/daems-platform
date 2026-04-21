<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEventTranslation;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\SupportedLocale;

final class UpdateEventTranslation
{
    public function __construct(private readonly EventRepositoryInterface $events)
    {
    }

    public function execute(UpdateEventTranslationInput $input): UpdateEventTranslationOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $locale = SupportedLocale::fromString($input->localeRaw);

        $rawTitle = $input->fields['title'] ?? null;
        $safeFields = [
            'title'       => is_scalar($rawTitle) ? (string) $rawTitle : '',
            'location'    => isset($input->fields['location']) && is_string($input->fields['location'])
                ? $input->fields['location']
                : null,
            'description' => isset($input->fields['description']) && is_string($input->fields['description'])
                ? $input->fields['description']
                : null,
        ];
        if (trim($safeFields['title']) === '') {
            throw new \DomainException('title_required');
        }

        $this->events->saveTranslation($input->tenantId, $input->eventId, $locale, $safeFields);

        $event = $this->events->findByIdForTenant($input->eventId, $input->tenantId);
        if ($event === null) {
            throw new \RuntimeException('event_vanished');
        }
        $coverage = $event->translations()->coverage(Event::TRANSLATABLE_FIELDS);
        return new UpdateEventTranslationOutput($coverage);
    }
}
