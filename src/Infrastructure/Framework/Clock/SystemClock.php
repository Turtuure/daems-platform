<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Clock;

use Daems\Domain\Shared\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
