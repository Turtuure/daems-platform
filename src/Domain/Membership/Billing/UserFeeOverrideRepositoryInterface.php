<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface UserFeeOverrideRepositoryInterface
{
    /**
     * Returns the active override for (tenant, user, fee_type) at the given date,
     * or null if none. "Active" = valid_from <= date <= (valid_until OR infinity) AND revoked_at IS NULL.
     */
    public function findActiveFor(
        TenantId $tenantId,
        UserId $userId,
        string $feeType,
        DateTimeImmutable $asOf,
    ): ?UserFeeOverride;

    public function save(UserFeeOverride $override): void;

    /**
     * @return list<UserFeeOverride>
     */
    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array;

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride;
}
