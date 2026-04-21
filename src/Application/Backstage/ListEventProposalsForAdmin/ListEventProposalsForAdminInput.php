<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventProposalsForAdmin;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListEventProposalsForAdminInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ActingUser $actor,
        public readonly ?string $status = null,
    ) {
    }
}
