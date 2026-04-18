<?php

declare(strict_types=1);

namespace Daems\Application\User\UpdateProfile;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class UpdateProfile
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(UpdateProfileInput $input): UpdateProfileOutput
    {
        $target = UserId::fromString($input->userId);
        if (!$input->acting->owns($target) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

        if ($input->firstName === '') {
            return new UpdateProfileOutput('First name is required.');
        }

        if ($input->email === '' || !filter_var($input->email, FILTER_VALIDATE_EMAIL)) {
            return new UpdateProfileOutput('A valid email address is required.');
        }

        $name = trim($input->firstName . ' ' . $input->lastName);

        try {
            $this->users->updateProfile($input->userId, [
                'name'            => $name,
                'email'           => $input->email,
                'date_of_birth'   => $input->dob,
                'country'         => $input->country,
                'address_street'  => $input->addressStreet,
                'address_zip'     => $input->addressZip,
                'address_city'    => $input->addressCity,
                'address_country' => $input->addressCountry,
            ]);
        } catch (ValidationException $e) {
            return new UpdateProfileOutput($e->getMessage());
        }

        return new UpdateProfileOutput();
    }
}
