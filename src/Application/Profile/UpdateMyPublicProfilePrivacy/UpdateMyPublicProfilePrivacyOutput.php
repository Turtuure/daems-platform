<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyPublicProfilePrivacy;

final class UpdateMyPublicProfilePrivacyOutput
{
    public function __construct(public readonly bool $publicAvatarVisible) {}
}
