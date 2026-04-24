<?php
declare(strict_types=1);

namespace Daems\Application\Profile\UpdateMyPublicProfilePrivacy;

use Daems\Domain\User\UserRepositoryInterface;

final class UpdateMyPublicProfilePrivacy
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function execute(UpdateMyPublicProfilePrivacyInput $input): UpdateMyPublicProfilePrivacyOutput
    {
        $this->users->updatePublicAvatarVisible($input->actor->id->value(), $input->publicAvatarVisible);
        return new UpdateMyPublicProfilePrivacyOutput($input->publicAvatarVisible);
    }
}
