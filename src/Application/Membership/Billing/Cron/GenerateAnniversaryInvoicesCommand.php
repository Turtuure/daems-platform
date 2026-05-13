<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoiceInput;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class GenerateAnniversaryInvoicesCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                        $pdo,
        private readonly GenerateAnniversaryInvoice $useCase,
        private readonly LockManager                $lockManager,
        private readonly CronLogger                 $logger,
        private readonly Clock                      $clock,
    ) {}

    public function name(): string
    {
        return 'membership:generate-anniversary-invoices';
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
            $today = $this->clock->now();
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;

            $tenants = $this->loadTenants($tenantFilter);
            $totalCreated = 0;
            $tStart = microtime(true);

            foreach ($tenants as $tenant) {
                $tenantId = TenantId::fromString($tenant['id']);
                $slug = $tenant['slug'];

                $candidates = $this->loadCandidates($tenantId, $today);
                $created = 0;
                $skipped = 0;
                $errors = 0;

                foreach ($candidates as $userId) {
                    try {
                        $out = $this->useCase->handle(new GenerateAnniversaryInvoiceInput($tenantId, $userId));
                        if ($out->created) {
                            $created++;
                        } else {
                            $skipped++;
                        }
                    } catch (NoActiveFeeScheduleException $e) {
                        $errors++;
                        $this->logger->error([
                            'tenant'  => $slug,
                            'user_id' => $userId->value(),
                            'message' => $e->getMessage(),
                            'code'    => 'NO_ACTIVE_SCHEDULE',
                        ]);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->logger->error([
                            'tenant'  => $slug,
                            'user_id' => $userId->value(),
                            'message' => $e->getMessage(),
                            'code'    => 'UNEXPECTED',
                        ]);
                    }
                }

                $this->logger->info([
                    'tenant'    => $slug,
                    'processed' => count($candidates),
                    'created'   => $created,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                ]);
                $totalCreated += $created;
            }

            $this->logger->info([
                'summary'       => true,
                'total_tenants' => count($tenants),
                'total_created' => $totalCreated,
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

    /**
     * @return list<UserId>
     */
    private function loadCandidates(TenantId $tenantId, \DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id
             FROM users u
             INNER JOIN user_tenants ut ON ut.user_id = u.id
             WHERE ut.tenant_id            = :tid
               AND u.membership_status     = 'active'
               AND u.membership_type      != 'HONORARY'
               AND u.membership_started_at IS NOT NULL
               AND MONTH(u.membership_started_at) = :m
               AND DAY(u.membership_started_at)   = :d
               AND YEAR(u.membership_started_at)  < :y"
        );
        $stmt->execute([
            'tid' => $tenantId->value(),
            'm'   => (int) $today->format('n'),
            'd'   => (int) $today->format('j'),
            'y'   => (int) $today->format('Y'),
        ]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;
            if ($id === null) {
                continue;
            }
            $out[] = UserId::fromString($id);
        }
        return $out;
    }
}
