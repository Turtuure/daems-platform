<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

/**
 * Wave C version: returns no overrides for any query. Wave D replaces this
 * with a real in-memory store; until then GenerateAnniversaryInvoice always
 * applies the full schedule price (no overrides).
 */
final class InMemoryUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        return null;
    }

    public function save(UserFeeOverride $override): void {}

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        return [];
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        return null;
    }
}
