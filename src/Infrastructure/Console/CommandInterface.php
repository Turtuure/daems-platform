<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

interface CommandInterface
{
    /**
     * Stable command name shown in registry + lock file.
     * Format: `<domain>:<verb>-<noun>` (e.g. `membership:generate-anniversary-invoices`).
     */
    public function name(): string;

    /**
     * Run the command. Return 0 on success, non-zero on failure.
     *
     * @param array<string,string|bool> $args Parsed CLI arguments (--option=value)
     */
    public function execute(array $args): int;
}
