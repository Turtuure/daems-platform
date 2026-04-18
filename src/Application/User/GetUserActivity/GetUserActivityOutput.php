<?php

declare(strict_types=1);

namespace Daems\Application\User\GetUserActivity;

final class GetUserActivityOutput
{
    public function __construct(
        public readonly array $data,
    ) {}
}
