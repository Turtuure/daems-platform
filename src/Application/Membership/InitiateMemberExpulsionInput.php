<?php
declare(strict_types=1);

namespace Daems\Application\Membership;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InitiateMemberExpulsionInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $targetUserId,
        public readonly UserId $proposedByUserId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $at,
    ) {}
}
