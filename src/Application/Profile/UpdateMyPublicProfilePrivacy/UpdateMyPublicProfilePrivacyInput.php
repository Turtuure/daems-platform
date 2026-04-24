<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyPublicProfilePrivacy;

use Daems\Domain\Auth\ActingUser;

final class UpdateMyPublicProfilePrivacyInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly bool $publicAvatarVisible,
    ) {}
}
