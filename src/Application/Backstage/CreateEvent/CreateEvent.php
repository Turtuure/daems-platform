<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\CreateEvent;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;

final class CreateEvent
{
    private const ALLOWED_TYPES = ['upcoming', 'past', 'online'];

    public function __construct(
        private readonly EventRepositoryInterface $events,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(CreateEventInput $input): CreateEventOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $errors = [];
        if (strlen($input->title) < 3 || strlen($input->title) > 200) {
            $errors['title'] = 'length_3_to_200';
        }
        if (!in_array($input->type, self::ALLOWED_TYPES, true)) {
            $errors['type'] = 'invalid_value';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
            $errors['event_date'] = 'invalid_date';
        }
        if (strlen(trim($input->description)) < 20) {
            $errors['description'] = 'min_20_chars';
        }
        if (!$input->isOnline && ($input->location === null || trim($input->location) === '')) {
            $errors['location'] = 'required_when_not_online';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $slug = $this->uniqueSlug($input->title, $tenantId);
        $eventId = EventId::fromString($this->ids->generate());

        $event = new Event(
            $eventId, $tenantId, $slug,
            $input->title, $input->type, $input->eventDate,
            $input->eventTime ?: null,
            $input->location ?: null,
            $input->isOnline,
            $input->description,
            null, [],
            $input->publishImmediately ? 'published' : 'draft',
        );
        $this->events->save($event);

        return new CreateEventOutput($eventId->value(), $slug);
    }

    private function uniqueSlug(string $title, TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'event';
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'event';
        }
        if ($this->events->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $suffix = substr($this->ids->generate(), 0, 8);
            $candidate = $base . '-' . $suffix;
            if ($this->events->findBySlugForTenant($candidate, $tenantId) === null) {
                return $candidate;
            }
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
