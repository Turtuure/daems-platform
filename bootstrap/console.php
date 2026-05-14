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

// Membership Billing — mark-overdue cron (0.7 Wave F)
$registry->register(new \Daems\Application\Membership\Billing\Cron\MarkOverdueInvoicesCommand(
    pdo:         $container->make(\Daems\Infrastructure\Framework\Database\Connection::class)->pdo(),
    useCase:     $container->make(\Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'membership:mark-overdue-invoices', $now),
));

// Membership Billing — lapse cron (0.7 Wave F § 4)
$registry->register(new \Daems\Application\Membership\Billing\Cron\LapseInactiveMembersCommand(
    pdo:         $container->make(\Daems\Infrastructure\Framework\Database\Connection::class)->pdo(),
    invoices:    $container->make(\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class),
    settings:    $container->make(\Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface::class),
    useCase:     $container->make(\Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'membership:lapse-inactive-members', $now),
));

// Communications — mail outbox drain cron (0.8 Wave C § 7.4)
$registry->register(new \DaemsModule\Communications\Infrastructure\Console\MailDrainCommand(
    useCase:     $container->make(\DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'mail-drain', $now),
));

// Communications — payment-reminder cron (0.8 Wave F Task F1, spec § 5.9)
$registry->register(new \DaemsModule\Communications\Infrastructure\Console\EnqueuePaymentRemindersCommand(
    useCase:     $container->make(\DaemsModule\Communications\Application\EnqueuePaymentReminders\EnqueuePaymentReminders::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'mail-enqueue-payment-reminders', $now),
));

// Communications — § 4 lapse-warning cron (0.8 Wave F Task F2, spec § 5.9)
$registry->register(new \DaemsModule\Communications\Infrastructure\Console\EnqueueLapseWarningsCommand(
    useCase:     $container->make(\DaemsModule\Communications\Application\EnqueueLapseWarnings\EnqueueLapseWarnings::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'mail-enqueue-lapse-warnings', $now),
));

// Communications — 24-month retention pseudonymization (0.8 Wave H follow-up #3)
$registry->register(new \DaemsModule\Communications\Infrastructure\Console\MailRetentionCleanupCommand(
    useCase:     $container->make(\DaemsModule\Communications\Application\RetentionCleanup\RetentionCleanup::class),
    lockManager: new LockManager(__DIR__ . '/../var/run'),
    logger:      new CronLogger(__DIR__ . '/../var/log/cron', 'mail-retention-cleanup', $now),
));

return new ConsoleKernel($registry);
