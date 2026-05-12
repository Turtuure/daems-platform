<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface MemberFeeInvoiceRepositoryInterface
{
    public function save(MemberFeeInvoice $invoice): void;

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice;

    /**
     * Lookup for the (tenant, user, year) anniversary-cron idempotency check.
     */
    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice;

    /**
     * All open (PENDING|OVERDUE|REDUCED) invoices past their due_date + grace.
     * Used by MarkOverdueInvoices cron in Wave F.
     *
     * @return list<MemberFeeInvoice>
     */
    public function findOverdueCandidates(TenantId $tenantId, \DateTimeImmutable $asOf, int $graceDays): array;

    /**
     * Find users with 2 consecutive years OVERDUE — used by LapseInactiveMember cron.
     * Returns array of (UserId, [year1, year2]) tuples.
     *
     * @return list<array{user_id: UserId, years: list<int>}>
     */
    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array;

    /**
     * Backstage UI list with filters.
     *
     * @param array{year?:int, status?:string, fee_type?:string, user_id?:string} $filter
     * @return list<MemberFeeInvoice>
     */
    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array;

    /**
     * All open invoices for a specific user. Used by WaiveOpenInvoicesOnHonoraryChange (Wave G).
     *
     * @return list<MemberFeeInvoice>
     */
    public function listOpenForUser(TenantId $tenantId, UserId $userId): array;
}
