<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UploadEventImage;

use Daems\Domain\Auth\ActingUser;

final class UploadEventImageInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $eventId,
        public readonly string $tmpPath,
        public readonly string $originalMime,
    ) {}
}
