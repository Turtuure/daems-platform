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

        if ($input->firstName !== null && $input->firstName === '') {
            return new UpdateProfileOutput('First name cannot be empty.');
        }

        if ($input->email !== null && ($input->email === '' || !filter_var($input->email, FILTER_VALIDATE_EMAIL))) {
            return new UpdateProfileOutput('A valid email address is required.');
        }

        $fields = [];
        if ($input->firstName !== null || $input->lastName !== null) {
            $existing = $this->users->findById($input->userId);
            if ($existing === null) {
                return new UpdateProfileOutput('User not found.');
            }
            [$currentFirst, $currentLast] = array_pad(explode(' ', $existing->name(), 2), 2, '');
            $first = $input->firstName ?? $currentFirst;
            $last = $input->lastName ?? $currentLast;
            $fields['name'] = trim($first . ' ' . $last);
        }
        if ($input->email !== null)          { $fields['email']           = $input->email; }
        if ($input->dob !== null)            { $fields['date_of_birth']   = $input->dob; }
        if ($input->country !== null)        { $fields['country']         = $input->country; }
        if ($input->addressStreet !== null)  { $fields['address_street']  = $input->addressStreet; }
        if ($input->addressZip !== null)     { $fields['address_zip']     = $input->addressZip; }
        if ($input->addressCity !== null)    { $fields['address_city']    = $input->addressCity; }
        if ($input->addressCountry !== null) { $fields['address_country'] = $input->addressCountry; }

        if ($fields === []) {
            return new UpdateProfileOutput();
        }

        try {
            $this->users->updateProfile($input->userId, $fields);
        } catch (ValidationException $e) {
            return new UpdateProfileOutput($e->getMessage());
        }

        return new UpdateProfileOutput();
    }
}
