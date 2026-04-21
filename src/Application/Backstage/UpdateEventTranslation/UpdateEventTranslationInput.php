<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateEventTranslation;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class UpdateEventTranslationInput
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $eventId,
        public readonly string $localeRaw,
        public readonly array $fields,
        public readonly ActingUser $actor,
    ) {
    }
}
