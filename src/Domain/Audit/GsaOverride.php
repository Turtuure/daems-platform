<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GsaOverride
{
    public function __construct(
        public readonly GsaOverrideId $id,
        public readonly UserId $gsaUserId,
        public readonly TenantId $tenantId,
        public readonly GsaOverrideAction $action,
        public readonly string $targetId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $performedAt,
    ) {
        if (strlen(trim($reason)) < 10) {
            throw new GsaOverrideRequiresReason(
                'GSA override requires a reason of at least 10 characters'
            );
        }
    }
}
