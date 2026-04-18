<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

use Daems\Domain\Auth\AuthTokenId;

final class RevokeAuthTokenInput
{
    public function __construct(public readonly AuthTokenId $tokenId) {}
}
