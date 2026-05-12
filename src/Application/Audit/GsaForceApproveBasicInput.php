<?php
declare(strict_types=1);

namespace Daems\Application\Audit;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GsaForceApproveBasicInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $gsaUserId,
        public readonly string $applicationId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $at,
    ) {}
}
