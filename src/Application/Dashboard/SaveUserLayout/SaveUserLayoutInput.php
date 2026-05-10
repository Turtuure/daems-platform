<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\SaveUserLayout;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class SaveUserLayoutInput
{
    /**
     * @param list<string> $enabledModules
     * @param list<array{widget_id?: string, span?: int}> $rawLayout
     */
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly MinRole $userRole,
        public readonly array $enabledModules,
        public readonly array $rawLayout,
    ) {}
}
