<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class RevokeAuthToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(RevokeAuthTokenInput $input): RevokeAuthTokenOutput
    {
        $this->tokens->revoke($input->tokenId, $this->clock->now());
        return new RevokeAuthTokenOutput();
    }
}
