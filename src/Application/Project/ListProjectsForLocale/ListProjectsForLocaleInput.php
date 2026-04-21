<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjectsForLocale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

final class ListProjectsForLocaleInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly SupportedLocale $locale,
        public readonly ?string $category = null,
        public readonly ?string $status = null,
        public readonly ?string $search = null,
    ) {
    }
}
