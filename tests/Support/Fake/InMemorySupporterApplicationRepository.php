<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use DateTimeImmutable;

final class InMemorySupporterApplicationRepository implements SupporterApplicationRepositoryInterface
{
    /** @var list<SupporterApplication> */
    public array $applications = [];

    /** @var array<string, array{decision:string, by:string, note:?string, at:string}> */
    public array $decisions = [];

    public function save(SupporterApplication $application): void
    {
        $this->applications[] = $application;
    }

    public function listPendingForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit): array
    {
        $filtered = array_filter(
            $this->applications,
            static fn (SupporterApplication $a): bool => $a->tenantId()->equals($tenantId) && $a->status() === 'pending',
        );
        return array_slice(array_values($filtered), 0, $limit);
    }

    public function findByIdForTenant(string $id, \Daems\Domain\Tenant\TenantId $tenantId): ?SupporterApplication
    {
        foreach ($this->applications as $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                return $a;
            }
        }
        return null;
    }

    public function recordDecision(
        string $id,
        \Daems\Domain\Tenant\TenantId $tenantId,
        string $decision,
        \Daems\Domain\User\UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void {
        foreach ($this->applications as $i => $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                $this->applications[$i] = new SupporterApplication(
                    $a->id(), $a->tenantId(), $a->orgName(), $a->contactPerson(), $a->regNo(),
                    $a->email(), $a->country(), $a->motivation(), $a->howHeard(),
                    $decision, $a->createdAt(),
                );
                $this->decisions[$id] = ['decision' => $decision, 'by' => $decidedBy->value(), 'note' => $note, 'at' => $decidedAt->format('Y-m-d H:i:s')];
                return;
            }
        }
    }

    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // The fake doesn't track created_at / decided_at timestamps with daily granularity,
        // so derive simple value counts from the in-memory state and emit zero-filled
        // sparklines. E2E/use-case tests assert shape + values, not per-day buckets.
        $pending = 0;
        foreach ($this->applications as $a) {
            if ($a->tenantId()->equals($tenantId) && $a->status() === 'pending') {
                $pending++;
            }
        }

        $approved          = 0;
        $rejected          = 0;
        $decidedCount      = 0;
        $decidedTotalHours = 0;
        foreach ($this->decisions as $appId => $d) {
            // Only count decisions whose underlying application belongs to this tenant.
            $found = null;
            foreach ($this->applications as $a) {
                if ($a->id()->value() === $appId && $a->tenantId()->equals($tenantId)) {
                    $found = $a;
                    break;
                }
            }
            if ($found === null) {
                continue;
            }
            if ($d['decision'] === 'approved') {
                $approved++;
            } elseif ($d['decision'] === 'rejected') {
                $rejected++;
            }
            $decidedCount++;
        }

        $today      = new \DateTimeImmutable('today');
        $emptySpark = [];
        for ($i = 29; $i >= 0; $i--) {
            $emptySpark[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => 0,
            ];
        }

        return [
            'pending'             => ['value' => $pending,  'sparkline' => $emptySpark],
            'approved_30d'        => ['value' => $approved, 'sparkline' => $emptySpark],
            'rejected_30d'        => ['value' => $rejected, 'sparkline' => $emptySpark],
            'decided_count'       => $decidedCount,
            'decided_total_hours' => $decidedTotalHours,
        ];
    }

    public function notificationStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // Derive pending count from in-memory state. Sparkline + oldest-age are
        // zero-stubs (the fake doesn't track per-day created_at granularity).
        $pending = 0;
        foreach ($this->applications as $a) {
            if ($a->tenantId()->equals($tenantId) && $a->status() === 'pending') {
                $pending++;
            }
        }

        $today  = new \DateTimeImmutable('today');
        $spark  = [];
        for ($i = 29; $i >= 0; $i--) {
            $spark[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => 0,
            ];
        }

        return [
            'pending_count'           => $pending,
            'created_at_daily_30d'    => $spark,
            'oldest_pending_age_days' => 0,
        ];
    }
}
