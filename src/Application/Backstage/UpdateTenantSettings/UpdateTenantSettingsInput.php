<?php
declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateTenantSettings;

use Daems\Domain\Auth\ActingUser;

final class UpdateTenantSettingsInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly ?string $memberNumberPrefix,
    ) {}
}
