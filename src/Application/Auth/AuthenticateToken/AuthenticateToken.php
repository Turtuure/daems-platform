<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class AuthenticateToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(AuthenticateTokenInput $input): AuthenticateTokenOutput
    {
        $hash = hash('sha256', $input->rawToken);
        $token = $this->tokens->findByHash($hash);
        if ($token === null) {
            return new AuthenticateTokenOutput(null, null, 'token-not-found');
        }

        $now = $this->clock->now();
        if (!$token->isValidAt($now)) {
            return new AuthenticateTokenOutput(null, null, 'token-invalid');
        }

        $user = $this->users->findById($token->userId()->value());
        if ($user === null) {
            return new AuthenticateTokenOutput(null, null, 'user-not-found');
        }

        $slidingExpiry = $now->modify('+7 days');
        $hardCap = $token->issuedAt()->modify('+30 days');
        $newExpiry = $slidingExpiry < $hardCap ? $slidingExpiry : $hardCap;
        $this->tokens->touchLastUsed($token->id(), $now, $newExpiry);

        return new AuthenticateTokenOutput(
            new ActingUser($user->id(), $user->role()),
            $token->id(),
            null,
        );
    }
}
