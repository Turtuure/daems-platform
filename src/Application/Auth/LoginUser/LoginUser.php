<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class LoginUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
    ) {}

    public function execute(LoginUserInput $input): LoginUserOutput
    {
        $now = $this->clock->now();

        if (strlen($input->password) > 72) {
            $this->attempts->record($input->ip, $input->email, false, $now);
            return new LoginUserOutput(null, 'Invalid email or password.');
        }

        $user = $this->users->findByEmail($input->email);
        $ok = $user !== null && password_verify($input->password, $user->passwordHash());
        $this->attempts->record($input->ip, $input->email, $ok, $now);

        if (!$ok) {
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
