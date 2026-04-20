<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RedeemInvite;

use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Invite\UserInviteRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\UserRepositoryInterface;

final class RedeemInvite
{
    public function __construct(
        private readonly UserInviteRepositoryInterface $invites,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(RedeemInviteInput $input): RedeemInviteOutput
    {
        if (strlen($input->password) < 8) {
            throw new ValidationException(['password' => 'password_too_short']);
        }
        if (strlen($input->password) > 72) {
            throw new ValidationException(['password' => 'password_too_long']);
        }

        $hash   = InviteToken::fromRaw($input->rawToken)->hash;
        $invite = $this->invites->findByTokenHash($hash);

        if ($invite === null) {
            throw new ValidationException(['token' => 'invite_invalid']);
        }

        $now = $this->clock->now();

        if ($invite->usedAt !== null) {
            throw new ValidationException(['token' => 'invite_used']);
        }

        if ($now >= $invite->expiresAt) {
            throw new ValidationException(['token' => 'invite_expired']);
        }

        $user = $this->users->findById($invite->userId)
            ?? throw new ValidationException(['token' => 'invite_invalid']);

        $this->users->updatePassword($user->id()->value(), password_hash($input->password, PASSWORD_BCRYPT));
        $this->invites->markUsed($invite->id, $now);

        $fresh = $this->users->findById($user->id()->value());
        if ($fresh === null) {
            throw new ValidationException(['token' => 'invite_invalid']);
        }

        return new RedeemInviteOutput($fresh);
    }
}
