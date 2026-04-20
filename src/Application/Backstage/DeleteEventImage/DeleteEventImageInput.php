<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DeleteEventImage;

use Daems\Domain\Auth\ActingUser;

final class DeleteEventImageInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
        public readonly string $url,
    ) {}
}
