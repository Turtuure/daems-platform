<?php

declare(strict_types=1);

namespace Daems\Application\User\GetUserActivity;

final class GetUserActivityInput
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
