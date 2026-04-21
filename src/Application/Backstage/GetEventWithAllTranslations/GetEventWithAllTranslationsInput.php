<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetEventWithAllTranslations;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class GetEventWithAllTranslationsInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $eventId,
        public readonly ActingUser $actor,
    ) {
    }
}
