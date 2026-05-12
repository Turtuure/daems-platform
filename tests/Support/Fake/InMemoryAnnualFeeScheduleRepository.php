<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;

final class InMemoryAnnualFeeScheduleRepository implements AnnualFeeScheduleRepositoryInterface
{
    /** @var array<string, AnnualFeeSchedule> */
    private array $byId = [];

    public function save(AnnualFeeSchedule $schedule): void
    {
        $this->byId[$schedule->id()->value()] = $schedule;
    }

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule
    {
        foreach ($this->byId as $s) {
            if ($s->tenantId()->equals($tenantId)
                && $s->year() === $year
                && $s->feeType() === $feeType
                && $s->status() === AnnualFeeScheduleStatus::Active
            ) {
                return $s;
            }
        }
        return null;
    }

    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array
    {
        $out = [];
        foreach ($this->byId as $s) {
            if (!$s->tenantId()->equals($tenantId)) continue;
            if ($s->year() !== $year) continue;
            if ($s->status() !== AnnualFeeScheduleStatus::Proposed) continue;
            if ($decisionId !== null && $s->decisionId() !== $decisionId) continue;
            $out[] = $s;
        }
        return $out;
    }

    public function findProposedByDecision(string $decisionId): array
    {
        $out = [];
        foreach ($this->byId as $s) {
            if ($s->status() === AnnualFeeScheduleStatus::Proposed
                && $s->decisionId() === $decisionId
            ) {
                $out[] = $s;
            }
        }
        return $out;
    }

    public function listForTenantYear(TenantId $tenantId, int $year): array
    {
        $out = [];
        foreach ($this->byId as $s) {
            if ($s->tenantId()->equals($tenantId) && $s->year() === $year) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
