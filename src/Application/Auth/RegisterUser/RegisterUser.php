<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RegisterUser;

use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class RegisterUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(RegisterUserInput $input): RegisterUserOutput
    {
        if ($this->users->findByEmail($input->email) !== null) {
            return new RegisterUserOutput(null, 'An account with this email already exists.');
        }

        $user = new User(
            UserId::generate(),
            $input->name,
            $input->email,
            password_hash($input->password, PASSWORD_BCRYPT),
            $input->dateOfBirth,
        );

        $this->users->save($user);

        return new RegisterUserOutput($user->id()->value());
    }
}
