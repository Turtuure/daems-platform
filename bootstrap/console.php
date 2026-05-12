<?php
declare(strict_types=1);

use Daems\Infrastructure\Console\CommandRegistry;
use Daems\Infrastructure\Console\ConsoleKernel;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;

/** @var \Daems\Infrastructure\Framework\Http\Kernel $httpKernel */
$httpKernel = require __DIR__ . '/app.php';
$container  = $httpKernel->container();

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

// Membership Billing — anniversary invoice cron (0.7 Wave C)
$now = new DateTimeImmutable();
$registry->register(new \Daems\Application\Membership\Billing\Cron\GenerateAnniversaryInvoicesCommand(
    pdo:         $container->make(\Daems\Infrastructure\Framework\Database\Connection::class)->pdo(),
    useCase:     $container->make(\Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'membership:generate-anniversary-invoices', $now),
    clock:       $container->make(\Daems\Domain\Shared\Clock::class),
));

return new ConsoleKernel($registry);
