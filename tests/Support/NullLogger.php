<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Infrastructure\Framework\Logging\LoggerInterface;

final class NullLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $calls = [];

    public function error(string $message, array $context = []): void
    {
        $this->calls[] = ['message' => $message, 'context' => $context];
    }
}
