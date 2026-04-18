<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEvent;

final class GetEventInput
{
    public function __construct(
        public readonly string $slug,
        public readonly ?string $userId = null,
    ) {}
}
