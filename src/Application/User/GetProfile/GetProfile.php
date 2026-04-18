<?php

declare(strict_types=1);

namespace Daems\Application\User\GetProfile;

use Daems\Domain\User\UserRepositoryInterface;

final class GetProfile
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(GetProfileInput $input): GetProfileOutput
    {
        $user = $this->users->findById($input->userId);

        if ($user === null) {
            return new GetProfileOutput(null, 'User not found.');
        }

        $nameParts = explode(' ', $user->name(), 2);

        return new GetProfileOutput([
            'id'               => $user->id()->value(),
            'name'             => $user->name(),
            'first_name'       => $nameParts[0],
            'last_name'        => $nameParts[1] ?? '',
            'email'            => $user->email(),
            'dob'              => $user->dateOfBirth(),
            'role'             => $user->role(),
            'country'          => $user->country(),
            'address_street'   => $user->addressStreet(),
            'address_zip'      => $user->addressZip(),
            'address_city'     => $user->addressCity(),
            'address_country'  => $user->addressCountry(),
            'membership_type'  => $user->membershipType(),
            'membership_status'=> $user->membershipStatus(),
            'member_number'    => $user->memberNumber(),
            'created_at'       => $user->createdAt(),
        ]);
    }
}
