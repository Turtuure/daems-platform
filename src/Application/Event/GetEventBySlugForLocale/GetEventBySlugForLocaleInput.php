<?php

declare(strict_types=1);

namespace Daems\Application\Event\GetEventBySlugForLocale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

final class GetEventBySlugForLocaleInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly SupportedLocale $locale,
        public readonly string $slug,
    ) {
    }
}
