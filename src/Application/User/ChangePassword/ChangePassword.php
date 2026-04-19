<?php

declare(strict_types=1);

namespace Daems\Application\User\ChangePassword;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class ChangePassword
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(ChangePasswordInput $input): ChangePasswordOutput
    {
        if (!$input->acting->owns(UserId::fromString($input->userId))) {
            throw new ForbiddenException();
        }

        if (strlen($input->newPassword) < 8) {
            return new ChangePasswordOutput('New password must be at least 8 characters.');
        }

        if (strlen($input->newPassword) > 72) {
            return new ChangePasswordOutput('Password must be at most 72 bytes.');
        }

        $user = $this->users->findById($input->userId);

        if ($user === null) {
            return new ChangePasswordOutput('User not found.');
        }

        if (!password_verify($input->currentPassword, $user->passwordHash())) {
            return new ChangePasswordOutput('Current password is incorrect.');
        }

        $this->users->updatePassword($input->userId, password_hash($input->newPassword, PASSWORD_BCRYPT));

        return new ChangePasswordOutput();
    }
}
