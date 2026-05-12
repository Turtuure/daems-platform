<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices;
use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoicesInput;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class MarkOverdueInvoicesCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly MarkOverdueInvoices $useCase,
        private readonly LockManager         $lockManager,
        private readonly CronLogger          $logger,
    ) {}

    public function name(): string
    {
        return 'membership:mark-overdue-invoices';
    }

    /**
     * @param array<string,string|bool> $args  Supported: --tenant=<slug>
     */
    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }

        try {
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;
            $tenants = $this->loadTenants($tenantFilter);
            $total = 0;
            $tStart = microtime(true);

            foreach ($tenants as $tenant) {
                try {
                    $count = $this->useCase->handle(new MarkOverdueInvoicesInput(
                        TenantId::fromString($tenant['id'])
                    ));
                    $this->logger->info(['tenant' => $tenant['slug'], 'flagged' => $count]);
                    $total += $count;
                } catch (\Throwable $e) {
                    $this->logger->error(['tenant' => $tenant['slug'], 'message' => $e->getMessage()]);
                }
            }

            $this->logger->info([
                'summary'       => true,
                'total_tenants' => count($tenants),
                'total_flagged' => $total,
                'duration_ms'   => (int) ((microtime(true) - $tStart) * 1000),
            ]);

            return 0;
        } finally {
            $this->lockManager->release($this->name());
        }
    }

    /**
     * @return list<array{id:string, slug:string}>
     */
    private function loadTenants(?string $slug): array
    {
        $sql = 'SELECT id, slug FROM tenants WHERE suspended_at IS NULL';
        $params = [];
        if ($slug !== null) {
            $sql .= ' AND slug = ?';
            $params[] = $slug;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id   = is_string($row['id']   ?? null) ? $row['id']   : null;
            $slug = is_string($row['slug'] ?? null) ? $row['slug'] : null;
            if ($id === null || $slug === null) {
                continue;
            }
            $out[] = ['id' => $id, 'slug' => $slug];
        }
        return $out;
    }
}
