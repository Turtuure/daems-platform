<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember;
use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMemberInput;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class LapseInactiveMembersCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                                         $pdo,
        private readonly MemberFeeInvoiceRepositoryInterface         $invoices,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly LapseInactiveMember                         $useCase,
        private readonly LockManager                                 $lockManager,
        private readonly CronLogger                                  $logger,
    ) {}

    public function name(): string
    {
        return 'membership:lapse-inactive-members';
    }

    /**
     * @param array<string,string|bool> $args  Supported: --tenant=<slug>, --dry-run
     */
    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }
        try {
            $isDryRun = isset($args['dry-run']) && $args['dry-run'] !== false;
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;

            $tenants = $this->loadTenants($tenantFilter);
            $totalLapsed = 0;
            $totalCandidates = 0;
            $tStart = microtime(true);

            foreach ($tenants as $tenant) {
                $tenantId = TenantId::fromString($tenant['id']);
                $tenantSettings = $this->settings->find($tenantId);
                if ($tenantSettings === null || !$tenantSettings->lapseCheckEnabled()) {
                    $this->logger->info([
                        'tenant'  => $tenant['slug'],
                        'message' => 'lapse_check_enabled=false, skipped',
                    ]);
                    continue;
                }

                $candidates = $this->invoices->findUsersWithConsecutiveOverdueYears($tenantId);
                $lapsed = 0;
                foreach ($candidates as $row) {
                    if ($isDryRun) {
                        $this->logger->info([
                            'tenant'  => $tenant['slug'],
                            'user_id' => $row['user_id']->value(),
                            'years'   => $row['years'],
                            'dry_run' => true,
                        ]);
                        continue;
                    }
                    try {
                        $out = $this->useCase->handle(new LapseInactiveMemberInput(
                            tenantId:     $tenantId,
                            userId:       $row['user_id'],
                            overdueYears: $row['years'],
                        ));
                        if ($out->lapsed) {
                            $lapsed++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error([
                            'tenant'  => $tenant['slug'],
                            'user_id' => $row['user_id']->value(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
                $this->logger->info([
                    'tenant'     => $tenant['slug'],
                    'candidates' => count($candidates),
                    'lapsed'     => $isDryRun ? 0 : $lapsed,
                    'dry_run'    => $isDryRun,
                ]);
                $totalCandidates += count($candidates);
                $totalLapsed += $lapsed;
            }

            $this->logger->info([
                'summary'          => true,
                'total_tenants'    => count($tenants),
                'total_candidates' => $totalCandidates,
                'total_lapsed'     => $totalLapsed,
                'dry_run'          => $isDryRun,
                'duration_ms'      => (int) ((microtime(true) - $tStart) * 1000),
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
