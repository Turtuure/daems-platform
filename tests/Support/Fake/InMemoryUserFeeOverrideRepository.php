<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    /** @var array<string, UserFeeOverride> */
    private array $byId = [];

    public function save(UserFeeOverride $override): void
    {
        $this->byId[$override->id()->value()] = $override;
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        foreach ($this->byId as $o) {
            if (!$o->tenantId()->equals($tenantId)) {
                continue;
            }
            if (!$o->userId()->equals($userId)) {
                continue;
            }
            if ($o->feeType()->value !== $feeType) {
                continue;
            }
            if (!$o->isActiveAt($asOf)) {
                continue;
            }
            return $o;
        }
        return null;
    }

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        $now = new DateTimeImmutable();
        $out = [];
        foreach ($this->byId as $o) {
            if (!$o->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($activeOnly && !$o->isActiveAt($now)) {
                continue;
            }
            $out[] = $o;
        }
        return array_values($out);
    }
}
