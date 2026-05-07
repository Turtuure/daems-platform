<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A tenant_modules row — pairs (TenantId, moduleSlug) and tracks the
 * availability/enabled/disabled timestamps + actor users.
 *
 * Invariant: enabledAt may not be set unless availableAt is set first
 * (you cannot enable a module that was never made available). Once a row
 * is revoked, availableAt + enabledAt are both nulled and disabledAt is set.
 */
final class TenantModule
{
    public function __construct(
        private readonly string $id,
        private readonly TenantId $tenantId,
        private readonly string $moduleSlug,
        private readonly ?DateTimeImmutable $availableAt,
        private readonly ?UserId $availableBy,
        private readonly ?DateTimeImmutable $enabledAt,
        private readonly ?UserId $enabledBy,
        private readonly ?DateTimeImmutable $disabledAt,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {
        if ($moduleSlug === '') {
            throw new InvalidArgumentException('moduleSlug must be non-empty');
        }
        if ($enabledAt !== null && $availableAt === null) {
            throw new InvalidArgumentException(
                "TenantModule '{$moduleSlug}': cannot be enabled without being available first"
            );
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function moduleSlug(): string
    {
        return $this->moduleSlug;
    }

    public function availableAt(): ?DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function availableBy(): ?UserId
    {
        return $this->availableBy;
    }

    public function enabledAt(): ?DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function enabledBy(): ?UserId
    {
        return $this->enabledBy;
    }

    public function disabledAt(): ?DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isAvailable(): bool
    {
        return $this->availableAt !== null;
    }

    public function isEnabled(): bool
    {
        return $this->isAvailable() && $this->enabledAt !== null;
    }
}
