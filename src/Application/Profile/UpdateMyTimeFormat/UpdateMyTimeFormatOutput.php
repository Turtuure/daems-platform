<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyTimeFormat;

final class UpdateMyTimeFormatOutput
{
    public function __construct(
        public readonly ?string $timeFormatOverride,
        public readonly string  $effectiveTimeFormat,
    ) {}
}
