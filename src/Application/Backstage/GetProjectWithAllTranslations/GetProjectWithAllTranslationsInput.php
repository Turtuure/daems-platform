<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetProjectWithAllTranslations;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class GetProjectWithAllTranslationsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $projectId,
        public readonly ActingUser $actor,
    ) {
    }
}
