<?php

declare(strict_types=1);

namespace Daems\Application\Membership\SubmitSupporterApplication;

final class SubmitSupporterApplicationOutput
{
    public function __construct(
        public readonly string $id,
    ) {}
}
