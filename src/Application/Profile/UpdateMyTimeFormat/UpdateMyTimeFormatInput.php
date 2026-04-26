<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyTimeFormat;

use Daems\Domain\Auth\ActingUser;

final class UpdateMyTimeFormatInput
{
    public function __construct(
        public readonly ActingUser $actor,
        /** '12' | '24' | null (null = clear override → inherit tenant default). */
        public readonly ?string $timeFormat,
    ) {}
}
