<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Platform\ListTenants;

use Daems\Domain\User\UserId;

/**
 * @phpstan-type StatusFilter 'active'|'suspended'|null
 */
final class ListTenantsInput
{
    /** @param StatusFilter $statusFilter */
    public function __construct(
        public readonly UserId $actingUserId,
        public readonly ?string $statusFilter = null,
    ) {}
}
