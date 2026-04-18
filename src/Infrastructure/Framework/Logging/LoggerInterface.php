<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Logging;

interface LoggerInterface
{
    public function error(string $message, array $context = []): void;
}
