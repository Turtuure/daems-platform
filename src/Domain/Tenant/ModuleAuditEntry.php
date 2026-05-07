<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One row in the module audit log. Records who performed a module-state
 * action against a given tenant + module, when, and optionally why.
 *
 * actorRole is captured at write time (not joined back from users.role) so
 * the log remains accurate even if the actor's role later changes.
 */
final class ModuleAuditEntry
{
    public function __construct(
        private readonly string $id,
        private readonly TenantId $tenantId,
        private readonly string $moduleSlug,
        private readonly ModuleAuditAction $action,
        private readonly UserId $actorUserId,
        private readonly string $actorRole,
        private readonly ?string $reason,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($moduleSlug === '') {
            throw new InvalidArgumentException('moduleSlug must be non-empty');
        }
        if ($actorRole !== 'platform_admin' && $actorRole !== 'tenant_admin') {
            throw new InvalidArgumentException(
                "actorRole must be 'platform_admin' or 'tenant_admin', got '{$actorRole}'"
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

    public function action(): ModuleAuditAction
    {
        return $this->action;
    }

    public function actorUserId(): UserId
    {
        return $this->actorUserId;
    }

    public function actorRole(): string
    {
        return $this->actorRole;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
