<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class DeleteAccount
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(DeleteAccountInput $input): DeleteAccountOutput
    {
        $target = UserId::fromString($input->userId);
        if (!$input->acting->owns($target) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

        $user = $this->users->findById($input->userId);
        if ($user === null) {
            return new DeleteAccountOutput(false, 'User not found.');
        }

        $this->users->deleteById($input->userId);
        return new DeleteAccountOutput(true);
    }
}
