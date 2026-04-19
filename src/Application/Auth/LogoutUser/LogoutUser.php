<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class LogoutUser
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(LogoutUserInput $input): LogoutUserOutput
    {
        $this->tokens->revokeByHash(hash('sha256', $input->rawToken), $this->clock->now());
        return new LogoutUserOutput();
    }
}
