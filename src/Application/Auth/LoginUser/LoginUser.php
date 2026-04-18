<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

use Daems\Domain\User\UserRepositoryInterface;

final class LoginUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(LoginUserInput $input): LoginUserOutput
    {
        $user = $this->users->findByEmail($input->email);

        if ($user === null || !password_verify($input->password, $user->passwordHash())) {
            return new LoginUserOutput(null, 'Invalid email or password.');
        }

        return new LoginUserOutput([
            'id'               => $user->id()->value(),
            'name'             => $user->name(),
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
