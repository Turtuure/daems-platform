<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEvent;

use Daems\Domain\Auth\ActingUser;

/**
 * Partial-update semantics: a null field means "do not update this field".
 * To explicitly set hero_image to NULL, use DeleteEventImage instead.
 */
final class UpdateEventInput
{
    /**
     * @param array<string>|null $gallery null = no change; empty array = clear gallery
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
        public readonly ?string $title,
        public readonly ?string $type,
        public readonly ?string $eventDate,
        public readonly ?string $eventTime,
        public readonly ?string $location,
        public readonly ?bool $isOnline,
        public readonly ?string $description,
        public readonly ?string $heroImage,
        public readonly ?array $gallery,
    ) {}
}
