<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;

interface AnnualFeeScheduleRepositoryInterface
{
    public function save(AnnualFeeSchedule $schedule): void;

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule;

    /**
     * Lookup the currently-active row for (tenant, year, fee_type).
     * Returns null if no active schedule exists yet for that combination.
     */
    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule;

    /**
     * All proposed-status rows for a year (used by AnnualFeeSchedulePassedHandler
     * to activate them after a board decision passes).
     *
     * @return list<AnnualFeeSchedule>
     */
    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array;

    /**
     * All rows for a year (admin UI listing).
     *
     * @return list<AnnualFeeSchedule>
     */
    public function listForTenantYear(TenantId $tenantId, int $year): array;
}
