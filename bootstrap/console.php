<?php
declare(strict_types=1);

use Daems\Infrastructure\Console\CommandRegistry;
use Daems\Infrastructure\Console\ConsoleKernel;

$registry = new CommandRegistry();

// Smoke-test command — verifies infra works before any real command is wired.
// Remove or leave in place; it's harmless and useful for ops debugging.
$registry->register(new class implements \Daems\Infrastructure\Console\CommandInterface {
    public function name(): string { return 'console:hello'; }
    public function execute(array $args): int {
        $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : 'world';
        fwrite(STDOUT, "Hello, {$name}\n");
        return 0;
    }
});

// Real commands are registered in later tasks (Wave C: anniversary-cron, Wave F: overdue + lapse).

return new ConsoleKernel($registry);
